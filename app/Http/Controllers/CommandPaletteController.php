<?php

namespace App\Http\Controllers;

use App\Models\LeadOpportunity;
use App\Models\Report;
use App\Models\Supplier;
use App\Models\Tender;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Cross-entity search powering the Cmd+K command palette.
 * Returns top hits per category (max 4 each) shaped as
 * {icon, title, sub, url, type} so the front-end can render
 * them uniformly.
 */
class CommandPaletteController extends Controller
{
    public function search(Request $request): JsonResponse
    {
        $user = Auth::user();
        if (!$user) abort(401);

        $q = trim((string) $request->input('q', ''));
        if (mb_strlen($q) < 2) {
            return response()->json([
                'tenders' => [], 'suppliers' => [], 'leads' => [], 'reports' => [],
            ]);
        }

        $like = '%' . mb_strtolower($q) . '%';

        // Tenders — title / reference / SAP number / source.
        $tenderQ = Tender::query()
            ->where(function ($w) use ($like) {
                $w->whereRaw('LOWER(title) LIKE ?', [$like])
                  ->orWhereRaw('LOWER(reference) LIKE ?', [$like])
                  ->orWhereRaw('LOWER(sap_opportunity_number) LIKE ?', [$like]);
            });
        if (!$user->isManager()) $tenderQ->forUser($user->id);
        $tenders = $tenderQ
            ->orderByDesc('created_at')
            ->limit(4)
            ->get(['id', 'title', 'reference', 'source'])
            ->map(fn($t) => [
                'icon'  => '📋',
                'title' => mb_substr((string) $t->title, 0, 80),
                'sub'   => trim($t->reference . ' · ' . strtoupper((string) $t->source), ' ·'),
                'url'   => '/tenders/' . $t->id,
                'type'  => 'concurso',
            ])->values()->all();

        // Suppliers — name / slug / primary email.
        $suppliers = Supplier::query()
            ->search($q)
            ->orderBy('name')
            ->limit(4)
            ->get(['id', 'name', 'primary_email', 'iqf_score', 'country_code'])
            ->map(fn($s) => [
                'icon'  => '🏭',
                'title' => $s->name,
                'sub'   => trim(
                    ($s->country_code ? '🌍 ' . $s->country_code : '')
                    . ($s->iqf_score !== null ? ' · IQF ' . rtrim(rtrim(number_format((float) $s->iqf_score, 2), '0'), '.') : '')
                    . ($s->primary_email ? ' · ' . $s->primary_email : ''),
                    ' ·'
                ),
                'url'   => '/suppliers/' . $s->id,
                'type'  => 'fornecedor',
            ])->values()->all();

        // Leads (manager-only — leads page itself is gated).
        $leads = [];
        if ($user->isManager()) {
            $leads = LeadOpportunity::query()
                ->where(function ($w) use ($like) {
                    $w->whereRaw('LOWER(title) LIKE ?', [$like])
                      ->orWhereRaw('LOWER(summary) LIKE ?', [$like])
                      ->orWhereRaw('LOWER(customer_hint) LIKE ?', [$like])
                      ->orWhereRaw('LOWER(equipment_hint) LIKE ?', [$like]);
                })
                ->orderByDesc('score')
                ->orderByDesc('created_at')
                ->limit(4)
                ->get(['id', 'title', 'score', 'status', 'customer_hint'])
                ->map(fn($l) => [
                    'icon'  => '⚡',
                    'title' => mb_substr((string) $l->title, 0, 80),
                    'sub'   => 'Score ' . $l->score . ' · ' . ucfirst($l->status)
                              . ($l->customer_hint ? ' · ' . $l->customer_hint : ''),
                    'url'   => '/leads/' . $l->id,
                    'type'  => 'lead',
                ])->values()->all();
        }

        // Reports — title.
        $reports = Report::query()
            ->whereRaw('LOWER(title) LIKE ?', [$like])
            ->orderByDesc('created_at')
            ->limit(4)
            ->get(['id', 'title', 'type', 'created_at'])
            ->map(fn($r) => [
                'icon'  => '📄',
                'title' => mb_substr((string) $r->title, 0, 80),
                'sub'   => $r->type . ' · ' . $r->created_at->diffForHumans(['short' => true]),
                'url'   => '/reports/' . $r->id,
                'type'  => 'relatório',
            ])->values()->all();

        return response()->json([
            'tenders'   => $tenders,
            'suppliers' => $suppliers,
            'leads'     => $leads,
            'reports'   => $reports,
        ]);
    }
}
