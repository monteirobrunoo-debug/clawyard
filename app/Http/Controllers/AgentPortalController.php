<?php

namespace App\Http\Controllers;

use App\Models\AgentShare;
use App\Models\Tender;
use App\Models\TenderCollaborator;
use App\Services\AgentShareAccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Landing page at /p/{portalToken} — single entry point when the owner
 * has shared multiple agents with the same client. Presents a dashboard
 * of every agent in the bundle and runs a unified OTP gate so the
 * visitor only authenticates once for the whole portal.
 */
class AgentPortalController extends Controller
{
    public function show(Request $request, string $portalToken)
    {
        $shares = AgentShare::where('portal_token', $portalToken)->get();
        if ($shares->isEmpty()) {
            return view('agent-shares.expired', ['reason' => 'portal_not_found']);
        }

        $anyActive = $shares->first(fn($s) => $s->isValid());
        if (!$anyActive) {
            return view('agent-shares.expired', ['reason' => 'portal_revoked']);
        }

        $svc       = app(AgentShareAccessService::class);
        $sessionId = app(AgentShareController::class)->sharePortalSessionId($portalToken);
        $status    = $svc->portalSessionStatus($portalToken, $sessionId, $request);

        if ($status === 'revoked') {
            return view('agent-shares.expired', ['reason' => 'portal_revoked']);
        }

        if (in_array($status, ['otp_required', 'new_device'], true)) {
            return view('agent-shares.portal-otp', [
                'portal_token' => $portalToken,
                'client_name'  => $anyActive->client_name,
                'new_device'   => $status === 'new_device',
                'masked_email' => $this->maskEmail($anyActive->client_email ?? ''),
            ]);
        }

        // ── Tenders block (optional) ───────────────────────────────────────
        //
        // User request: "quero que incluas o dashboard ao cimo deste painel
        // para quem tem acesso aos concursos utilizar". The portal visitor
        // is OTP-verified against `client_email`, so we use that email as
        // the identity and look them up in two places:
        //
        //   1. TenderCollaborator.email (explicit)     → primary
        //   2. TenderCollaborator.user.email           → fallback
        //
        // If a match is found AND they have active, non-expired tenders,
        // we pass them to the view which renders a compact list above the
        // agent grid. If there's no match, or no active tenders, the block
        // is simply not rendered and the portal looks exactly as before.
        $tenderBundle = $this->resolveTenderBundleFor((string) ($anyActive->client_email ?? ''));

        // Session is ok — render the portal with every agent.
        return view('agent-shares.portal', [
            'portal_token'         => $portalToken,
            'shares'               => $shares->filter(fn($s) => $s->isValid())->values(),
            'agentMeta'            => AgentShare::agentMeta(),
            'client_name'          => $anyActive->client_name,
            'tenderCollaborator'   => $tenderBundle['collaborator'],
            'tenders'              => $tenderBundle['tenders'],
            'hasClawyardAccount'   => $tenderBundle['hasAccount'],
        ]);
    }

    /**
     * Look up whether the portal visitor (identified by the share's
     * `client_email`) is also a TenderCollaborator with active tenders.
     *
     * Returns a bundle: [collaborator?, tenders (may be empty), hasAccount].
     * Never throws — any error (malformed email, DB hiccup) collapses to
     * an empty bundle so the portal still renders.
     */
    private function resolveTenderBundleFor(string $clientEmail): array
    {
        $empty = ['collaborator' => null, 'tenders' => collect(), 'hasAccount' => false];

        $clientEmail = strtolower(trim($clientEmail));
        if ($clientEmail === '' || !filter_var($clientEmail, FILTER_VALIDATE_EMAIL)) {
            return $empty;
        }

        try {
            // Prefer the explicit email match; fall back to the linked User.
            $collaborator = TenderCollaborator::query()
                ->with('user')
                ->where('is_active', true)
                ->where(function ($q) use ($clientEmail) {
                    $q->whereRaw('LOWER(email) = ?', [$clientEmail])
                      ->orWhereHas('user', fn($uq) => $uq->whereRaw('LOWER(email) = ?', [$clientEmail]));
                })
                ->first();

            if (!$collaborator) return $empty;

            // Active, not-expired, deadline-ascending — same bucket the
            // "Os meus concursos" strip on /tenders uses internally so the
            // external portal matches the manager-side view.
            $expiredCut = now()->copy()->subDays(Tender::OVERDUE_WINDOW_DAYS);
            $tenders = $collaborator->tenders()
                ->active()
                ->where(function ($q) use ($expiredCut) {
                    $q->whereNull('deadline_at')->orWhere('deadline_at', '>=', $expiredCut);
                })
                ->orderByRaw('deadline_at IS NULL, deadline_at ASC')
                ->limit(25)
                ->get();

            return [
                'collaborator' => $collaborator,
                'tenders'      => $tenders,
                // If user_id is set, they have a real ClawYard login and can
                // click through to the full dashboard. If not, they only see
                // the info here and need IT to provision the account first.
                'hasAccount'   => $collaborator->user_id !== null,
            ];
        } catch (\Throwable $e) {
            Log::warning('Portal: resolveTenderBundleFor failed', [
                'email' => $clientEmail,
                'error' => $e->getMessage(),
            ]);
            return $empty;
        }
    }

    public function requestOtp(Request $request, string $portalToken)
    {
        $email = (string) $request->input('email', '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return back()->withErrors(['email' => 'Email inválido.']);
        }

        $shares = AgentShare::where('portal_token', $portalToken)->get();
        if ($shares->isEmpty()) abort(404);

        $sessionId = app(AgentShareController::class)->sharePortalSessionId($portalToken);
        app(AgentShareAccessService::class)->issuePortalOtp($portalToken, $email, $sessionId, $request);

        return view('agent-shares.portal-otp', [
            'portal_token' => $portalToken,
            'client_name'  => $shares->first()->client_name,
            'otp_sent'     => true,
            'sent_to'      => $this->maskEmail($email),
        ]);
    }

    public function verifyOtp(Request $request, string $portalToken)
    {
        $email = strtolower(trim((string) $request->input('email', '')));
        $code  = preg_replace('/\D/', '', (string) $request->input('code', ''));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($code) !== 6) {
            return back()->withErrors(['code' => 'Código inválido.']);
        }

        $sessionId = app(AgentShareController::class)->sharePortalSessionId($portalToken);
        $ok = app(AgentShareAccessService::class)->verifyPortalOtp($portalToken, $email, $sessionId, $code, $request);

        if (!$ok) {
            return back()->withErrors(['code' => 'Código incorrecto ou expirado.']);
        }

        return redirect('/p/' . $portalToken);
    }

    private function maskEmail(string $email): string
    {
        $parts = explode('@', $email, 2);
        if (count($parts) !== 2) return $email;
        [$local, $domain] = $parts;
        $visible = mb_substr($local, 0, min(2, mb_strlen($local)));
        return $visible . str_repeat('*', max(0, mb_strlen($local) - 2)) . '@' . $domain;
    }
}
