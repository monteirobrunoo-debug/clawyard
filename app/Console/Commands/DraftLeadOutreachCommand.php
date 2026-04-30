<?php

namespace App\Console\Commands;

use App\Models\LeadOpportunity;
use App\Services\LeadOutreachService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * php artisan leads:draft-outreach [--limit=20] [--id=<lead_id>] [--regenerate]
 *
 * Walks confident leads (status=confident, outreach_status=none) and
 * generates a cold-outreach email draft for each via Anthropic.
 *
 * Drafts surface in /leads/{id} for the manager to review, edit,
 * approve, and send. We never auto-send — see LeadOpportunityController.
 *
 * Cron: daily at 09:00 Lisbon (registered in routes/console.php).
 * Daily is the right cadence because:
 *   • Confident leads don't appear in bursts; the swarm produces a
 *     handful per day at most.
 *   • Token cost: ~1.2k input + 400 output ≈ $0.007/lead with Sonnet.
 *     20 leads/day = $0.14/day = ~$50/year. Trivially affordable.
 *   • Manager engagement: a once-a-day digest is enough; spamming
 *     the dashboard with new drafts every hour would create noise.
 *
 * --limit caps per-run cost. --id is for hand-debugging one lead.
 * --regenerate forces a fresh draft over an existing one (resets the
 * approval chain).
 */
class DraftLeadOutreachCommand extends Command
{
    protected $signature = 'leads:draft-outreach
        {--limit=20 : Max drafts per run}
        {--id= : Draft only the given lead id}
        {--regenerate : Overwrite an existing draft (resets approval state)}';

    protected $description = 'Generate cold-outreach email drafts for confident leads.';

    public function handle(LeadOutreachService $svc): int
    {
        if (!config('services.anthropic.api_key')) {
            $this->warn('Anthropic API key not configured — skipping outreach drafting.');
            return self::SUCCESS;
        }

        $regenerate = (bool) $this->option('regenerate');

        $query = LeadOpportunity::query();

        if ($id = $this->option('id')) {
            $query->where('id', (int) $id);
        } elseif ($regenerate) {
            // --regenerate without --id: refresh the most recent drafts.
            $query->where('status', LeadOpportunity::STATUS_CONFIDENT)
                  ->whereNotNull('outreach_drafted_at')
                  ->where('outreach_status', '!=', LeadOpportunity::OUTREACH_SENT);
        } else {
            $query->needsOutreachDraft();
        }

        $leads = $query
            ->orderByDesc('score')
            ->orderByDesc('created_at')
            ->limit((int) $this->option('limit'))
            ->get();

        if ($leads->isEmpty()) {
            $this->info('Nothing to draft.');
            return self::SUCCESS;
        }

        $this->info("Drafting outreach for {$leads->count()} lead(s)…");

        $stats = ['drafted' => 0, 'errors' => 0, 'skipped' => 0];
        $totalCost = 0.0;

        foreach ($leads as $lead) {
            $res = $svc->draftFor($lead, regenerate: $regenerate);
            if ($res['ok']) {
                $stats['drafted']++;
                $totalCost += (float) $lead->outreach_draft_cost_usd;
                $to = $lead->outreach_to_email ?: '(sem email)';
                $this->line(sprintf(
                    '  ✓ #%d "%s" — %s — $%.4f',
                    $lead->id,
                    mb_substr((string) $lead->title, 0, 60),
                    $to,
                    (float) $lead->outreach_draft_cost_usd,
                ));
            } else {
                $err = $res['error'] ?? 'unknown';
                if (in_array($err, ['already_drafted', 'not_confident_status'], true)) {
                    $stats['skipped']++;
                } else {
                    $stats['errors']++;
                    $this->line("  ✗ #{$lead->id} — {$err}");
                }
            }
        }

        $this->newLine();
        $this->info(sprintf(
            '✓ Done — drafted %d, errors %d, skipped %d, cost $%.4f',
            $stats['drafted'], $stats['errors'], $stats['skipped'], $totalCost,
        ));

        Log::info('leads:draft-outreach done', $stats + ['cost_usd' => $totalCost]);
        return self::SUCCESS;
    }
}
