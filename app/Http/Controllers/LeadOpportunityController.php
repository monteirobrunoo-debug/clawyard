<?php

namespace App\Http\Controllers;

use App\Models\LeadOpportunity;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Auth;
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
    public function update(Request $request, LeadOpportunity $lead)
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

        $lead->fill(array_filter($data, fn($v) => $v !== null));

        // Auto-stamp contacted_at when the status flips to contacted.
        if (($data['status'] ?? null) === LeadOpportunity::STATUS_CONTACTED && !$lead->contacted_at) {
            $lead->contacted_at = now();
        }

        $lead->save();

        return back()->with('status', "Lead actualizado.");
    }
}
