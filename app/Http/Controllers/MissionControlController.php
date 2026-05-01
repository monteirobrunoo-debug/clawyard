<?php

namespace App\Http\Controllers;

use App\Models\LeadOpportunity;
use App\Models\Supplier;
use App\Models\Tender;
use Illuminate\Support\Facades\Auth;

/**
 * /mission — single-pane "Mission Control" view (manager+).
 *
 * Three columns rendered side-by-side:
 *   • CONCURSOS — pipeline activo (urgente / críticos / orgãos top)
 *   • LEADS     — confident + review, sorted by score
 *   • DIRECTÓRIO — supplier health (com email, recém-contactados,
 *                  pendentes de revisão)
 *
 * Auto-refresh 60s via meta refresh (zero JS dependency). The page is
 * deliberately data-dense — manager opens once in the morning and
 * sees everything that needs action without hunting across pages.
 */
class MissionControlController extends Controller
{
    public function index()
    {
        $user = Auth::user();
        if (!$user || !$user->isManager()) abort(403);

        // ── Concursos ────────────────────────────────────────────────
        $criticalTenders = Tender::query()
            ->active()
            ->whereNotNull('deadline_at')
            ->whereBetween('deadline_at', [now(), now()->addDays(7)])
            ->orderBy('deadline_at')
            ->limit(8)
            ->get();

        $overdueTenders = Tender::query()
            ->overdue()
            ->orderBy('deadline_at')
            ->limit(6)
            ->get();

        $needSapTenders = Tender::query()
            ->needingSapOpportunity()
            ->orderByDesc('created_at')
            ->limit(5)
            ->get();

        // ── Leads ────────────────────────────────────────────────────
        $confidentLeads = LeadOpportunity::query()
            ->where('status', LeadOpportunity::STATUS_CONFIDENT)
            ->orderByDesc('score')
            ->limit(10)
            ->get();

        $reviewLeads = LeadOpportunity::query()
            ->where('status', LeadOpportunity::STATUS_REVIEW)
            ->orderByDesc('score')
            ->limit(8)
            ->get();

        $pendingDrafts = LeadOpportunity::query()
            ->where('outreach_status', LeadOpportunity::OUTREACH_DRAFT_PENDING)
            ->orderByDesc('outreach_drafted_at')
            ->limit(8)
            ->get();

        // ── Directório (suppliers) ──────────────────────────────────
        $supplierStats = [
            'total'          => Supplier::count(),
            'approved'       => Supplier::where('status', Supplier::STATUS_APPROVED)->count(),
            'pending'        => Supplier::where('status', Supplier::STATUS_PENDING)->count(),
            'with_email'     => Supplier::whereNotNull('primary_email')->count(),
            'enriched_today' => Supplier::whereDate('enriched_at', today())->count(),
        ];

        $recentlyContacted = Supplier::query()
            ->whereNotNull('last_contacted_at')
            ->orderByDesc('last_contacted_at')
            ->limit(6)
            ->get();

        // ── Tender pipeline counters (used in column headers) ────────
        $pipelineStats = [
            'live_tenders'    => Tender::query()->livePipeline()->count(),
            'overdue'         => Tender::query()->overdue()->count(),
            'need_sap'        => Tender::query()->needingSapOpportunity()->count(),
            'confident_leads' => LeadOpportunity::where('status', LeadOpportunity::STATUS_CONFIDENT)->count(),
            'review_leads'    => LeadOpportunity::where('status', LeadOpportunity::STATUS_REVIEW)->count(),
            'pending_drafts'  => LeadOpportunity::where('outreach_status', LeadOpportunity::OUTREACH_DRAFT_PENDING)->count(),
        ];

        return view('mission.index', compact(
            'criticalTenders', 'overdueTenders', 'needSapTenders',
            'confidentLeads', 'reviewLeads', 'pendingDrafts',
            'supplierStats', 'recentlyContacted',
            'pipelineStats',
        ));
    }
}
