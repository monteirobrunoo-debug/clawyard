<?php

namespace App\Http\Controllers;

use App\Models\LeadOpportunity;
use App\Models\RewardEvent;
use App\Models\User;
use App\Services\LeadOutreachService;
use App\Services\Rewards\RewardRecorder;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;

/**
 * /leads — surface and triage agent-swarm-discovered opportunities.
 *
 * Manager+ only. Regular users don't get to see the lead pipeline
 * (they would interpret it as their work queue when actually it's
 * raw discovery output that needs human gating before the team
 * pursues it).
 *
 * Lifecycle (driven from this UI):
 *
 *   draft / review / confident   ← created by AgentSwarmRunner
 *         ↓ assign + acknowledge
 *   contacted
 *         ↓ outcome
 *   won | lost | discarded
 *
 * Drill-down: each lead links to its swarm_run, which carries the
 * full chain_log so the team can audit "Marina said the market is
 * Y, Marta said no existing customer, Marco wrote pitch X".
 */
class LeadOpportunityController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware(function ($request, $next) {
                if (!Auth::user()?->isManager()) abort(403);
                return $next($request);
            }),
        ];
    }

    public function index(Request $request)
    {
        $filters = [
            'status' => $request->string('status')->trim()->value(),
            'min_score' => (int) $request->input('min_score', 0),
            'q' => trim((string) $request->input('q', '')),
        ];

        $query = LeadOpportunity::query()
            ->with(['swarmRun:id,chain_name,signal_type,signal_id,cost_usd', 'assignedUser:id,name']);

        // Default: hide drafts unless the user explicitly asks for them.
        // Drafts are score<30 — too low-confidence to surface by default.
        if ($filters['status'] !== '') {
            $query->where('status', $filters['status']);
        } else {
            $query->whereNotIn('status', [LeadOpportunity::STATUS_DRAFT, LeadOpportunity::STATUS_DISCARDED]);
        }

        if ($filters['min_score'] > 0) {
            $query->where('score', '>=', $filters['min_score']);
        }

        if ($filters['q'] !== '') {
            $needle = '%' . mb_strtolower($filters['q']) . '%';
            $query->where(function ($w) use ($needle) {
                $w->whereRaw('LOWER(title) LIKE ?', [$needle])
                  ->orWhereRaw('LOWER(summary) LIKE ?', [$needle])
                  ->orWhereRaw('LOWER(customer_hint) LIKE ?', [$needle])
                  ->orWhereRaw('LOWER(equipment_hint) LIKE ?', [$needle]);
            });
        }

        $leads = $query
            ->orderByDesc('score')
            ->orderByDesc('created_at')
            ->paginate(30)
            ->withQueryString();

        // Aggregate counters for the header badges.
        $counts = LeadOpportunity::query()
            ->selectRaw('status, count(*) as n')
            ->groupBy('status')
            ->pluck('n', 'status');

        $assignableUsers = User::query()
            ->where('is_active', true)
            ->whereIn('role', ['user', 'manager', 'admin'])
            ->orderBy('name')
            ->get(['id', 'name', 'role']);

        return view('leads.index', [
            'leads'           => $leads,
            'counts'          => $counts,
            'filters'         => $filters,
            'assignableUsers' => $assignableUsers,
            'statuses'        => [
                LeadOpportunity::STATUS_REVIEW,
                LeadOpportunity::STATUS_CONFIDENT,
                LeadOpportunity::STATUS_CONTACTED,
                LeadOpportunity::STATUS_WON,
                LeadOpportunity::STATUS_LOST,
                LeadOpportunity::STATUS_DISCARDED,
                LeadOpportunity::STATUS_DRAFT,
            ],
        ]);
    }

    public function show(LeadOpportunity $lead)
    {
        $lead->load(['swarmRun', 'assignedUser:id,name,email']);
        return view('leads.show', ['lead' => $lead]);
    }

    /**
     * PATCH /leads/{lead} — update status, assignment, notes from the
     * triage UI. Single endpoint to keep the UI form simple; only
     * the fields actually present in the payload are touched.
     */
    public function update(Request $request, LeadOpportunity $lead, RewardRecorder $rewards)
    {
        $data = $request->validate([
            'status'            => ['nullable', Rule::in([
                LeadOpportunity::STATUS_DRAFT,
                LeadOpportunity::STATUS_REVIEW,
                LeadOpportunity::STATUS_CONFIDENT,
                LeadOpportunity::STATUS_CONTACTED,
                LeadOpportunity::STATUS_WON,
                LeadOpportunity::STATUS_LOST,
                LeadOpportunity::STATUS_DISCARDED,
            ])],
            'assigned_user_id'  => ['nullable', 'integer', 'exists:users,id'],
            'notes'             => ['nullable', 'string', 'max:5000'],
        ]);

        $oldStatus = $lead->status;
        $lead->fill(array_filter($data, fn($v) => $v !== null));

        // Auto-stamp contacted_at when the status flips to contacted.
        if (($data['status'] ?? null) === LeadOpportunity::STATUS_CONTACTED && !$lead->contacted_at) {
            $lead->contacted_at = now();
        }

        $lead->save();

        // C2 — reward the user for status transitions, and credit the
        // contributing agents when the deal lands. The recorder swallows
        // its own failures, so this never blocks the UI response.
        $newStatus = $lead->status;
        if ($oldStatus !== $newStatus) {
            $userId = Auth::id();
            $eventType = match ($newStatus) {
                LeadOpportunity::STATUS_CONFIDENT => RewardEvent::TYPE_LEAD_QUALIFIED,
                LeadOpportunity::STATUS_CONTACTED => RewardEvent::TYPE_LEAD_CONTACTED,
                LeadOpportunity::STATUS_WON       => RewardEvent::TYPE_LEAD_WON,
                default                            => null,
            };

            if ($eventType !== null) {
                $rewards->record(
                    eventType: $eventType,
                    userId:    $userId,
                    subject:   $lead,
                    metadata:  ['from' => $oldStatus, 'to' => $newStatus, 'score' => $lead->score],
                );
            }

            // When a lead is WON, credit each agent that contributed
            // to its swarm run with leads_won. We use the chain_log
            // from the parent run for attribution.
            if ($newStatus === LeadOpportunity::STATUS_WON) {
                $run = $lead->swarmRun()->first();
                if ($run !== null) {
                    $contributingAgents = collect($run->chain_log ?? [])
                        ->filter(fn($s) => ($s['event'] ?? null) === 'agent_call' && ($s['ok'] ?? false))
                        ->pluck('agent')
                        ->unique()
                        ->filter();

                    foreach ($contributingAgents as $agentKey) {
                        $rewards->record(
                            eventType: RewardEvent::TYPE_LEAD_WON,
                            agentKey:  $agentKey,
                            subject:   $lead,
                            metadata:  ['lead_id' => $lead->id, 'score' => $lead->score],
                            // Points already counted on the user-scoped event above —
                            // this call is purely for the agent_metric bump.
                            points:    0,
                        );
                    }
                }
            }
        }

        return back()->with('status', "Lead actualizado.");
    }

    // ─── Outreach pipeline ────────────────────────────────────────────────
    // The cron generates drafts; managers approve/reject/send through
    // these actions. We intentionally keep "approve" and "send" as
    // separate gestures — see migration 2026_04_30_000001 for rationale.

    /** POST /leads/{lead}/outreach/draft — manually trigger or regenerate
     *  a draft (the cron does this automatically nightly, this button is
     *  for when the manager wants a fresh take). */
    public function draftOutreach(LeadOpportunity $lead, LeadOutreachService $svc)
    {
        $regenerate = $lead->outreach_drafted_at !== null;

        $res = $svc->draftFor($lead, regenerate: $regenerate);
        if (!$res['ok']) {
            $err = $res['error'] ?? 'unknown';
            $msg = match ($err) {
                'not_confident_status' => 'Lead tem de estar em "confident" para gerar draft.',
                'parse_failed'         => 'O modelo devolveu um formato que não conseguimos interpretar. Tenta de novo.',
                'already_drafted'      => 'Já existe um draft (usar regenerar se queres reescrever).',
                default                => 'Falhou: ' . $err,
            };
            return back()->withErrors(['outreach' => $msg]);
        }

        return back()->with('status', $regenerate ? 'Draft regenerado.' : 'Draft gerado.');
    }

    /** PATCH /leads/{lead}/outreach — manager edits subject/body/recipient
     *  before approving. Edits are allowed in DRAFT_PENDING and APPROVED
     *  states (after approve, an edit kicks it back to DRAFT_PENDING). */
    public function updateOutreach(Request $request, LeadOpportunity $lead)
    {
        if (!in_array($lead->outreach_status, [
            LeadOpportunity::OUTREACH_DRAFT_PENDING,
            LeadOpportunity::OUTREACH_APPROVED,
        ], true)) {
            return back()->withErrors(['outreach' => 'O draft já foi enviado ou rejeitado e não pode ser editado.']);
        }

        $data = $request->validate([
            'outreach_draft_subject' => ['required', 'string', 'max:255'],
            'outreach_draft_body'    => ['required', 'string', 'max:8000'],
            'outreach_to_email'      => ['nullable', 'email:filter', 'max:255'],
            'outreach_to_name'       => ['nullable', 'string', 'max:255'],
        ]);

        $lead->fill($data);

        // Editing a previously approved draft re-opens the approval gate.
        if ($lead->outreach_status === LeadOpportunity::OUTREACH_APPROVED) {
            $lead->outreach_status         = LeadOpportunity::OUTREACH_DRAFT_PENDING;
            $lead->outreach_approved_at    = null;
            $lead->outreach_approved_by_user_id = null;
        }

        $lead->save();
        return back()->with('status', 'Draft actualizado.');
    }

    /** POST /leads/{lead}/outreach/approve — explicit "this draft is OK"
     *  gesture from a manager. Doesn't send — the send button is separate. */
    public function approveOutreach(LeadOpportunity $lead)
    {
        if ($lead->outreach_status !== LeadOpportunity::OUTREACH_DRAFT_PENDING) {
            return back()->withErrors(['outreach' => 'Só drafts pendentes podem ser aprovados.']);
        }
        if (empty($lead->outreach_to_email)) {
            return back()->withErrors(['outreach' => 'Falta o email do destinatário antes de aprovar.']);
        }

        $lead->outreach_status              = LeadOpportunity::OUTREACH_APPROVED;
        $lead->outreach_approved_at         = now();
        $lead->outreach_approved_by_user_id = Auth::id();
        $lead->save();

        return back()->with('status', 'Draft aprovado — pronto a enviar.');
    }

    /** POST /leads/{lead}/outreach/reject — kill the draft (with reason). */
    public function rejectOutreach(Request $request, LeadOpportunity $lead)
    {
        if (!in_array($lead->outreach_status, [
            LeadOpportunity::OUTREACH_DRAFT_PENDING,
            LeadOpportunity::OUTREACH_APPROVED,
        ], true)) {
            return back()->withErrors(['outreach' => 'Não há draft activo para rejeitar.']);
        }

        $data = $request->validate([
            'outreach_reject_reason' => ['nullable', 'string', 'max:500'],
        ]);

        $lead->outreach_status        = LeadOpportunity::OUTREACH_REJECTED;
        $lead->outreach_reject_reason = $data['outreach_reject_reason'] ?? null;
        $lead->save();

        return back()->with('status', 'Draft rejeitado.');
    }

    /** POST /leads/{lead}/outreach/send — fires the actual SMTP send.
     *  Requires APPROVED status. Records sent_at + sent_by_user_id and
     *  flips the parent lead status to "contacted" (which also stamps
     *  contacted_at in the lead model — same logic as the manual UI). */
    public function sendOutreach(LeadOpportunity $lead, RewardRecorder $rewards)
    {
        if ($lead->outreach_status !== LeadOpportunity::OUTREACH_APPROVED) {
            return back()->withErrors(['outreach' => 'O draft tem de estar aprovado antes de enviar.']);
        }
        if (empty($lead->outreach_to_email) || empty($lead->outreach_draft_subject) || empty($lead->outreach_draft_body)) {
            return back()->withErrors(['outreach' => 'Draft incompleto — verifica destinatário, assunto e corpo.']);
        }

        $to       = $lead->outreach_to_email;
        $subject  = $lead->outreach_draft_subject;
        $bodyText = $lead->outreach_draft_body;

        // Render plain-text body as <p>-wrapped HTML — the LLM uses \n
        // for paragraph breaks, so split on double-newline and wrap.
        // Single newlines become <br> for inline breaks.
        $paragraphs = preg_split('/\n{2,}/', $bodyText) ?: [$bodyText];
        $html = '';
        foreach ($paragraphs as $p) {
            $p = e(trim($p));
            $p = nl2br($p);                      // single \n → <br>
            $html .= "<p style=\"margin:0 0 12px;\">{$p}</p>";
        }

        try {
            Mail::html($html, function ($mail) use ($to, $subject, $lead) {
                $mail->to($to, $lead->outreach_to_name ?: null);
                $mail->subject($subject);
                // Reply-to: the manager who pressed send, so any reply
                // doesn't land in the system mailbox.
                if ($u = Auth::user()) {
                    $mail->replyTo($u->email, $u->name);
                }
            });
        } catch (\Throwable $e) {
            Log::warning('Outreach send failed', [
                'lead_id' => $lead->id,
                'to'      => $to,
                'error'   => $e->getMessage(),
            ]);
            return back()->withErrors(['outreach' => 'Envio falhou: ' . $e->getMessage()]);
        }

        $lead->outreach_status         = LeadOpportunity::OUTREACH_SENT;
        $lead->outreach_sent_at        = now();
        $lead->outreach_sent_by_user_id = Auth::id();

        // Auto-progress the lead status to 'contacted' — it just was.
        $oldStatus = $lead->status;
        if ($lead->status === LeadOpportunity::STATUS_CONFIDENT) {
            $lead->status       = LeadOpportunity::STATUS_CONTACTED;
            $lead->contacted_at = now();
        }
        $lead->save();

        // Mirror the reward path used in update() — a "contacted" event
        // earns the user points the same way as if they'd manually
        // clicked through the lead card.
        if ($oldStatus !== $lead->status && $lead->status === LeadOpportunity::STATUS_CONTACTED) {
            $rewards->record(
                eventType: RewardEvent::TYPE_LEAD_CONTACTED,
                userId:    Auth::id(),
                subject:   $lead,
                metadata:  ['from' => $oldStatus, 'to' => $lead->status, 'via' => 'outreach_send'],
            );
        }

        return back()->with('status', "Email enviado para {$to}.");
    }
}
