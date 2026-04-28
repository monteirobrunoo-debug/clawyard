<?php

namespace App\Console\Commands;

use App\Models\AgentSwarmRun;
use App\Models\LeadOpportunity;
use App\Models\Tender;
use App\Services\AgentSwarm\AgentSwarmRunner;
use App\Services\AgentSwarm\ChainSpec;
use Illuminate\Console\Command;

/**
 * Daily lead-discovery cron. Picks the most promising business
 * signals from the last 24h, runs them through the swarm, persists
 * the resulting leads, and reports a summary.
 *
 * Default signal source for B1: recent active TENDERS without an
 * existing swarm run. B2 will add email signals + SAP changes.
 *
 * Idempotency:
 *   • Each tender produces at most ONE swarm run (signal_hash
 *     enforced at the DB level via UNIQUE).
 *   • Re-running the cron the same day skips already-processed
 *     tenders and only picks up genuinely new ones.
 *
 * Cost guards:
 *   --max-runs N    cap on how many signals are processed per
 *                   invocation. Default 10. The runner has its own
 *                   $/day cap on top — both enforce, lower wins.
 *   --dry-run       walk the candidate list and report what WOULD
 *                   be queued, but don't actually fire the chains.
 *
 * Usage in cron (suggested 06:00 daily):
 *
 *   0 6 * * *  cd /home/forge/clawyard.partyard.eu/current && \
 *              php artisan agents:discover-leads --max-runs=10
 */
class AgentsDiscoverLeadsCommand extends Command
{
    protected $signature = 'agents:discover-leads
        {--max-runs=10 : Maximum signals to process this invocation}
        {--dry-run : Report candidates without firing chains}
        {--chain= : Override chain name (defaults to per-signal-type default)}';

    protected $description = 'Run the agent swarm over fresh signals to surface new lead opportunities';

    public function handle(AgentSwarmRunner $swarm): int
    {
        $maxRuns = max(1, (int) $this->option('max-runs'));
        $dryRun  = (bool) $this->option('dry-run');
        $chain   = $this->option('chain') ?: null;
        if ($chain && !in_array($chain, ChainSpec::names(), true)) {
            $this->error("Unknown chain: {$chain}. Known: " . implode(', ', ChainSpec::names()));
            return self::FAILURE;
        }

        $signals = $this->pickTenderSignals($maxRuns);

        $this->info(sprintf(
            'Found %d tender signal(s) without prior swarm runs%s',
            count($signals),
            $dryRun ? ' [DRY RUN]' : ''
        ));

        if (count($signals) === 0) {
            $this->line('Nothing to process. The cron will retry on the next tick.');
            return self::SUCCESS;
        }

        $created  = 0;
        $aborted  = 0;
        $failed   = 0;
        $leadsTot = 0;

        foreach ($signals as $tender) {
            $this->line(sprintf('  · %-12s %s', $tender->reference, \Illuminate\Support\Str::limit($tender->title, 60)));
            if ($dryRun) continue;

            $payload = [
                'tender_id' => $tender->id,
                'reference' => $tender->reference,
                'title'     => $tender->title,
                'source'    => $tender->source,
                'status'    => $tender->status,
                'deadline'  => $tender->deadline_at?->toIso8601String(),
                'collaborator' => $tender->collaborator?->name,
            ];

            $run = $swarm->run(
                signalType: 'tender',
                signalId: (string) $tender->id,
                signalPayload: $payload,
                chainName: $chain,
            );

            switch ($run->status) {
                case AgentSwarmRun::STATUS_DONE:
                    $created++;
                    $leadsTot += $run->leads()->count();
                    break;
                case AgentSwarmRun::STATUS_ABORTED:
                    $aborted++;
                    $this->warn("    ↳ aborted ($run->cost_usd USD spent so far today)");
                    break 2;   // outer foreach — daily budget hit
                case AgentSwarmRun::STATUS_FAILED:
                    $failed++;
                    break;
            }
        }

        $this->newLine();
        $this->info(sprintf(
            'Done. Created runs: %d · Leads produced: %d · Aborted: %d · Failed: %d',
            $created, $leadsTot, $aborted, $failed
        ));
        $this->line('Spend this run: $' . number_format(
            (float) AgentSwarmRun::query()->whereDate('created_at', today())->sum('cost_usd'),
            4
        ));

        return self::SUCCESS;
    }

    /**
     * Pick recent active tenders that haven't been processed yet.
     * Strategy: live pipeline (active + not expired), no existing
     * swarm run for this tender id, ordered by deadline ASC so the
     * most urgent ones go first.
     *
     * @return \Illuminate\Support\Collection<int, Tender>
     */
    private function pickTenderSignals(int $limit): \Illuminate\Support\Collection
    {
        $alreadyProcessed = AgentSwarmRun::query()
            ->where('signal_type', 'tender')
            ->pluck('signal_id')
            ->all();

        return Tender::query()
            ->livePipeline()
            ->whereNotIn('id', $alreadyProcessed)
            ->orderByRaw('deadline_at IS NULL, deadline_at ASC')
            ->limit($limit)
            ->with('collaborator:id,name')
            ->get();
    }
}
