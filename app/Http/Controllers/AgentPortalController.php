<?php

namespace App\Http\Controllers;

use App\Models\AgentShare;
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

        // Session is ok — render the portal with every agent.
        return view('agent-shares.portal', [
            'portal_token' => $portalToken,
            'shares'       => $shares->filter(fn($s) => $s->isValid())->values(),
            'agentMeta'    => AgentShare::agentMeta(),
            'client_name'  => $anyActive->client_name,
        ]);
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
