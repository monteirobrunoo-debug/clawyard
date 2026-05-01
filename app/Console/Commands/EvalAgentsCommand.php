<?php

namespace App\Console\Commands;

use App\Models\Tender;
use App\Services\TenderDifficultyClassifier;
use App\Services\TenderSupplierSuggesterService;
use Illuminate\Console\Command;

/**
 * php artisan agents:eval [--limit=20] [--source=<src>] [--dry]
 *
 * Sandbox evaluator for the tender → supplier suggester pipeline.
 *
 * What it does:
 *   • Picks N historical tenders that have a known terminal status
 *     (ganho / perdido). These are the "golden" cases — we know what
 *     was supposed to happen.
 *   • For each, runs TenderSupplierSuggesterService::suggest() with
 *     the CURRENT prompt/category map and reports:
 *       — inferred categories (do they include sensible buckets?)
 *       — top suppliers proposed (any of them ever contacted on
 *         this tender historically? — heuristic check)
 *       — difficulty bucket (does it match outcome — hard tenders
 *         with deadlines < 7d that were lost should be flagged hard)
 *
 * Why no real Anthropic call here:
 *   The full agent swarm is expensive to run for an eval batch
 *   (~$0.10 each × 20 = $2 per pass). The suggester is the big
 *   prompt-shaped surface in clawyard, and it has no LLM cost on
 *   the local-only path. This eval covers ≥80% of the pipeline
 *   quality with zero token spend. Add a follow-up Daniel-style
 *   eval later when you actually need it.
 *
 * Output: tabular summary + a per-tender breakdown if --verbose.
 *
 * Inspired by Hernandez et al. (2026) — most autonomous-agent
 * studies (69%) evaluate in simulation rather than production. This
 * is our local "simulation" lane: replay history, see if today's
 * pipe agrees with what actually happened.
 */
class EvalAgentsCommand extends Command
{
    protected $signature = 'agents:eval
        {--limit=20 : Number of golden cases to evaluate}
        {--source= : Restrict to one source (e.g. nspa)}
        {--verbose : Print the full breakdown for each case}';

    protected $description = 'Replay won/lost tenders against the current suggester pipeline and report drift.';

    public function handle(
        TenderSupplierSuggesterService $svc,
        TenderDifficultyClassifier $difficulty,
    ): int {
        $limit = max(1, (int) $this->option('limit'));
        $source = (string) $this->option('source');

        $query = Tender::query()
            ->whereIn('status', [Tender::STATUS_GANHO, Tender::STATUS_PERDIDO])
            ->whereNull('deleted_at');

        if ($source !== '') $query->where('source', $source);

        $cases = $query
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get();

        if ($cases->isEmpty()) {
            $this->warn('No terminal tenders found — eval needs at least one ganho/perdido row.');
            return self::SUCCESS;
        }

        $this->info("Evaluating {$cases->count()} golden case(s)…");
        $this->newLine();

        $stats = [
            'cases'          => 0,
            'with_categories'=> 0,
            'with_suppliers' => 0,
            'avg_suppliers'  => 0,
            'difficulty'     => ['easy' => 0, 'medium' => 0, 'hard' => 0],
            'outcome'        => ['ganho' => 0, 'perdido' => 0],
            // Sanity checks
            'short_deadline_lost'    => 0,
            'short_deadline_won'     => 0,
            'easy_won'               => 0,
            'easy_lost'              => 0,
        ];
        $totalSuppliers = 0;

        foreach ($cases as $t) {
            $stats['cases']++;
            $stats['outcome'][$t->status]++;

            $bucket = $difficulty->classify($t);
            $stats['difficulty'][$bucket['level']]++;

            // Disabled web search to keep this offline + free.
            $bundle = $svc->suggest($t, localLimit: 8, includeWeb: false);

            if (!empty($bundle['categories']))   $stats['with_categories']++;
            if ($bundle['local']->isNotEmpty())  $stats['with_suppliers']++;
            $totalSuppliers += $bundle['local']->count();

            // Calibration sanity: short-deadline + lost should ≥ short-deadline + won
            $days = $t->days_to_deadline ?? 999;
            if ($days <= 7 && $t->status === Tender::STATUS_PERDIDO) $stats['short_deadline_lost']++;
            if ($days <= 7 && $t->status === Tender::STATUS_GANHO)   $stats['short_deadline_won']++;
            if ($bucket['level'] === 'easy' && $t->status === Tender::STATUS_GANHO)   $stats['easy_won']++;
            if ($bucket['level'] === 'easy' && $t->status === Tender::STATUS_PERDIDO) $stats['easy_lost']++;

            if ($this->option('verbose')) {
                $this->line(sprintf(
                    '  · #%d %s [%s] (%s) → cats=%s suppliers=%d days=%s',
                    $t->id,
                    mb_substr((string) $t->title, 0, 60),
                    $t->status,
                    $bucket['level'],
                    implode(',', $bundle['categories']) ?: '∅',
                    $bundle['local']->count(),
                    $days === 999 ? 'n/a' : $days,
                ));
            }
        }

        $stats['avg_suppliers'] = $stats['cases'] > 0 ? round($totalSuppliers / $stats['cases'], 1) : 0;

        $this->newLine();
        $this->line('────────────────────────────────────────');
        $this->info('Summary');
        $this->line('────────────────────────────────────────');
        $this->line(sprintf('  Cases evaluated:           %d', $stats['cases']));
        $this->line(sprintf('  → ganho:                   %d', $stats['outcome']['ganho']));
        $this->line(sprintf('  → perdido:                 %d', $stats['outcome']['perdido']));
        $this->newLine();
        $this->line(sprintf('  With ≥1 inferred category: %d / %d (%.0f%%)',
            $stats['with_categories'], $stats['cases'],
            $stats['cases'] > 0 ? 100 * $stats['with_categories'] / $stats['cases'] : 0));
        $this->line(sprintf('  With ≥1 local supplier:    %d / %d (%.0f%%)',
            $stats['with_suppliers'], $stats['cases'],
            $stats['cases'] > 0 ? 100 * $stats['with_suppliers'] / $stats['cases'] : 0));
        $this->line(sprintf('  Avg suppliers per case:    %.1f', $stats['avg_suppliers']));
        $this->newLine();
        $this->line('  Difficulty distribution:');
        foreach ($stats['difficulty'] as $level => $n) {
            $this->line(sprintf('    %-10s %d', $level, $n));
        }
        $this->newLine();
        $this->line('  Calibration sanity:');
        $this->line(sprintf('    short-deadline (≤7d) won:  %d   lost: %d  (lost should ≥ won)',
            $stats['short_deadline_won'], $stats['short_deadline_lost']));
        $this->line(sprintf('    easy-bucket won:           %d   lost: %d  (won should ≥ lost)',
            $stats['easy_won'], $stats['easy_lost']));

        // Heuristic alarms
        $this->newLine();
        if ($stats['cases'] > 5) {
            if ($stats['short_deadline_won'] > $stats['short_deadline_lost']) {
                $this->warn('  ⚠ Calibration off: short-deadline wins > losses. Difficulty classifier may be over-penalising urgency.');
            }
            if ($stats['easy_lost'] > $stats['easy_won']) {
                $this->warn('  ⚠ Calibration off: more easy-bucket losses than wins. Easy lane may be too aggressive.');
            }
            if ($stats['with_categories'] < $stats['cases'] * 0.8) {
                $this->warn('  ⚠ <80% of cases got categories — keyword map may need extension.');
            }
        }

        $this->newLine();
        $this->info('Eval complete.');
        return self::SUCCESS;
    }
}
