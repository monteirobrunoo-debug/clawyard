<?php

namespace App\Http\Controllers;

use App\Models\AgentSwarmRun;
use App\Models\LeadOpportunity;
use App\Models\Message;
use App\Models\PartOrder;
use App\Models\Report;
use App\Models\Tender;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

/**
 * Live feed for the dashboard ticker.
 *
 * Pulls a unified stream of "what just happened" from the most active
 * tables (tenders, leads, messages, swarm runs, parts orders, reports)
 * and returns the top N items ordered by recency. Cached for 30s — the
 * ticker polls every 30s, so the cache window matches the refresh rate
 * and we don't hammer the DB on a busy dashboard.
 */
class ActivityFeedController extends Controller
{
    public function index(): JsonResponse
    {
        $user = Auth::user();
        if (!$user) abort(401);

        // Cache key per-role since manager+ sees more (all leads, all
        // tenders); regular users see only their own scope.
        $cacheKey = 'activity_feed:v1:' . ($user->isManager() ? 'manager' : 'user_' . $user->id);

        $items = Cache::remember($cacheKey, 30, function () use ($user) {
            return $this->collectItems($user);
        });

        return response()->json([
            'updated_at' => now()->toIso8601String(),
            'items'      => $items,
        ]);
    }

    private function collectItems($user): array
    {
        $items = [];

        // ── Tenders imported in the last 24h ──────────────────────────
        $tenderQ = Tender::query()
            ->whereDate('created_at', '>=', now()->subDay())
            ->orderByDesc('created_at')
            ->limit(8);
        if (!$user->isManager()) $tenderQ->forUser($user->id);
        foreach ($tenderQ->get() as $t) {
            $items[] = [
                'icon'  => '📋',
                'label' => 'Concurso importado: ' . mb_substr((string) $t->title, 0, 60),
                'url'   => route('tenders.show', $t),
                'at'    => $t->created_at->toIso8601String(),
                'ago'   => $t->created_at->diffForHumans(['short' => true]),
            ];
        }

        // ── Leads scored ≥ 70 in the last 7d ──────────────────────────
        if ($user->isManager()) {
            foreach (LeadOpportunity::query()
                ->where('score', '>=', 70)
                ->where('created_at', '>=', now()->subDays(7))
                ->orderByDesc('created_at')
                ->limit(5)
                ->get() as $l) {
                $items[] = [
                    'icon'  => '⚡',
                    'label' => 'Lead score ' . $l->score . ': ' . mb_substr((string) $l->title, 0, 55),
                    'url'   => route('leads.show', $l),
                    'at'    => $l->created_at->toIso8601String(),
                    'ago'   => $l->created_at->diffForHumans(['short' => true]),
                ];
            }
        }

        // ── Recent agent messages (only show interesting ones) ─────────
        foreach (Message::query()
            ->where('role', 'assistant')
            ->where('created_at', '>=', now()->subHours(6))
            ->orderByDesc('created_at')
            ->limit(8)
            ->get(['id', 'agent', 'created_at', 'conversation_id']) as $m) {
            if (!$m->agent) continue;
            $meta = \App\Services\AgentCatalog::find((string) $m->agent);
            $name = $meta['name'] ?? ucfirst((string) $m->agent);
            $items[] = [
                'icon'  => $meta['emoji'] ?? '🤖',
                'label' => "{$name} respondeu",
                'url'   => $m->conversation_id ? '/conversations/' . $m->conversation_id : null,
                'at'    => $m->created_at->toIso8601String(),
                'ago'   => $m->created_at->diffForHumans(['short' => true]),
            ];
        }

        // ── Swarm runs (last 24h) ─────────────────────────────────────
        foreach (AgentSwarmRun::query()
            ->where('created_at', '>=', now()->subDay())
            ->orderByDesc('created_at')
            ->limit(4)
            ->get(['id', 'chain_name', 'status', 'created_at']) as $r) {
            $emoji = $r->status === 'completed' ? '✓' : ($r->status === 'failed' ? '✗' : '↻');
            $items[] = [
                'icon'  => '🐝',
                'label' => "Swarm {$emoji} {$r->chain_name}",
                'url'   => null,
                'at'    => $r->created_at->toIso8601String(),
                'ago'   => $r->created_at->diffForHumans(['short' => true]),
            ];
        }

        // ── Recent reports (top 3 last 24h) ───────────────────────────
        foreach (Report::query()
            ->where('created_at', '>=', now()->subDay())
            ->orderByDesc('created_at')
            ->limit(3)
            ->get(['id', 'title', 'created_at']) as $rep) {
            $items[] = [
                'icon'  => '📄',
                'label' => 'Relatório: ' . mb_substr((string) $rep->title, 0, 60),
                'url'   => '/reports/' . $rep->id,
                'at'    => $rep->created_at->toIso8601String(),
                'ago'   => $rep->created_at->diffForHumans(['short' => true]),
            ];
        }

        // Sort by timestamp desc, limit final stream to 30 entries.
        usort($items, fn($a, $b) => strcmp($b['at'], $a['at']));
        return array_slice($items, 0, 30);
    }
}
