<?php

namespace App\Console\Commands;

use App\Mail\TenderDeadlineAlert;
use App\Models\Tender;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * One-shot deadline alert — fires ~24h before a tender's deadline.
 *
 * Scope (per user decision):
 *   • Single reminder per tender (de-duped via deadline_alert_sent_at).
 *   • Only to the assigned collaborator's email (not manager).
 *   • No alert for tenders without a deadline, without an assignee, or
 *     without a routable email.
 *
 * Scheduled hourly (see routes/console.php). The 2-hour window (23–25h to
 * deadline) covers clock drift and hourly grain: once the timestamp is set
 * the tender is skipped, and after the deadline passes it's ineligible too.
 *
 * Usage:
 *   php artisan tenders:send-deadline-alerts                  # live
 *   php artisan tenders:send-deadline-alerts --dry-run        # report only
 *   php artisan tenders:send-deadline-alerts --hours=12       # test with shorter window
 */
class SendTenderDeadlineAlertsCommand extends Command
{
    protected $signature = 'tenders:send-deadline-alerts
        {--dry-run   : Report what would be sent without actually sending}
        {--hours=24  : Hours-before-deadline target (default 24)}';

    protected $description = 'Send individual ~24h-before-deadline alerts to assigned collaborators';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $hours  = max(1, (int) $this->option('hours'));

        // 2-hour window around the target so an hourly scheduler never misses
        // a tender. Once deadline_alert_sent_at is set, the tender is skipped.
        $windowStart = now()->copy()->addHours($hours - 1);
        $windowEnd   = now()->copy()->addHours($hours + 1);

        $candidates = Tender::query()
            ->active()
            ->whereNotNull('deadline_at')
            ->whereBetween('deadline_at', [$windowStart, $windowEnd])
            ->whereNull('deadline_alert_sent_at')
            ->whereNotNull('assigned_collaborator_id')
            ->with('collaborator.user')
            ->get();

        $this->info("Found {$candidates->count()} tender(s) with deadline in "
            . $windowStart->format('H:i') . '..' . $windowEnd->format('H:i')
            . ($dryRun ? ' [DRY RUN]' : ''));

        $sent    = 0;
        $skipped = 0;

        foreach ($candidates as $t) {
            $collab = $t->collaborator;
            $email  = $collab?->digest_email;
            $name   = $collab?->user?->name ?? $collab?->name ?? 'Colaborador';

            if (!$email) {
                $this->line("  · {$t->reference}: skipped (collaborator has no email)");
                $skipped++;
                continue;
            }

            $this->line(sprintf(
                '  %s %s → %s <%s>',
                $dryRun ? '?' : '✓',
                str_pad($t->reference, 20),
                $name,
                $email
            ));

            if ($dryRun) continue;

            try {
                Mail::to($email)->send(new TenderDeadlineAlert($t, $name));

                // Stamp ONLY after successful handoff so a transient failure
                // is retried on the next hourly tick.
                $t->deadline_alert_sent_at = now();
                $t->save();

                $sent++;
            } catch (\Throwable $e) {
                Log::error('Tender deadline alert failed', [
                    'tender_id' => $t->id,
                    'email'     => $email,
                    'error'     => $e->getMessage(),
                ]);
                $this->error("  ✗ {$t->reference}: {$e->getMessage()}");
            }
        }

        $this->newLine();
        $this->line("Sent:    {$sent}");
        $this->line("Skipped: {$skipped}");

        return self::SUCCESS;
    }
}
