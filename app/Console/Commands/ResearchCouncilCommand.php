<?php

namespace App\Console\Commands;

use App\Services\Robotparts\ResearchCouncilService;
use Illuminate\Console\Command;

/**
 * php artisan agents:research-council [--topic=...]
 *
 * Convenes a 4-agent research council on a robot improvement topic.
 * Cron schedule: weekly Sunday 04:00 Lisbon (after the Monday shop
 * round so committees have a fresh research report to lean on).
 */
class ResearchCouncilCommand extends Command
{
    protected $signature = 'agents:research-council {--topic= : Custom topic (else random from catalogue)} {--lead= : Custom lead agent key}';

    protected $description = 'Run a research council session — 4 agents debate a robot improvement topic.';

    public function handle(ResearchCouncilService $svc): int
    {
        $topic = $this->option('topic');
        $lead  = $this->option('lead');

        $this->info('Convening research council…');
        if ($topic) $this->line("  Topic: {$topic}");
        if ($lead)  $this->line("  Lead:  {$lead}");

        $report = $svc->run(topic: $topic, leadingAgent: $lead);

        $this->newLine();
        $this->info(sprintf(
            '✓ report #%d  status=%s  topic="%s"  cost=$%.4f',
            $report->id,
            $report->status,
            mb_substr($report->topic, 0, 60),
            (float) $report->total_cost_usd,
        ));

        if ($report->participants) {
            $this->line('  Participants: ' . implode(', ', $report->participants));
        }
        if (!empty($report->proposals)) {
            $this->newLine();
            $this->line('  Proposals:');
            foreach ($report->proposals as $p) {
                $this->line(sprintf('    · [%s] %s', $p['kind'] ?? '?', $p['suggestion'] ?? '?'));
            }
        }

        return self::SUCCESS;
    }
}
