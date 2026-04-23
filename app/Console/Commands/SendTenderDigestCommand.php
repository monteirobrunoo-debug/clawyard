<?php

namespace App\Console\Commands;

use App\Mail\TenderDailyDigest;
use App\Services\TenderDailyDigestService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Sends the morning (07:30) or evening (17:00) tender digest.
 *
 *   php artisan tenders:send-digest                  # auto-detects slot by hour
 *   php artisan tenders:send-digest --slot=morning
 *   php artisan tenders:send-digest --slot=evening
 *   php artisan tenders:send-digest --dry-run        # log only, no sends
 *   php artisan tenders:send-digest --only=user@x    # debug a single recipient
 *
 * Design notes:
 *   • No queueing — runs synchronously inside the scheduler tick. The
 *     volume is tiny (≤ handful of recipients, ≤ ~300 rows total); adding
 *     a queue for this alone would be over-engineering given the rest of
 *     the app also sends mail synchronously.
 *   • Stamps `last_digest_sent_at` on every row included so a later report
 *     can show "when was this last pushed to the user?" without mining logs.
 *   • Empty buckets are silently skipped (we don't want to wake users at
 *     07:30 only to tell them they have nothing to do).
 */
class SendTenderDigestCommand extends Command
{
    protected $signature = 'tenders:send-digest
        {--slot= : morning|evening (default: auto-detect from Europe/Lisbon hour)}
        {--dry-run : Log recipients and counts but do not send}
        {--only= : Restrict to this recipient email (for debugging)}';

    protected $description = 'Email the daily concursos digest to assigned users and super-users';

    public function handle(TenderDailyDigestService $service): int
    {
        $slot = $this->option('slot') ?: $this->autoDetectSlot();
        if (!in_array($slot, ['morning', 'evening'], true)) {
            $this->error("Invalid slot: {$slot}. Use morning|evening.");
            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $onlyTo = $this->option('only') ? strtolower($this->option('only')) : null;

        $this->info("Building digest bundles (slot={$slot}, dry-run=" . ($dryRun ? 'yes' : 'no') . ')…');

        $recipients = $service->buildRecipients();
        if ($onlyTo) {
            $recipients = array_filter(
                $recipients,
                fn($r) => strtolower($r['user']->email) === $onlyTo
            );
        }

        if (empty($recipients)) {
            $this->line('No recipients have actionable tenders. Nothing to send.');
            return self::SUCCESS;
        }

        $sent    = 0;
        $failed  = 0;
        $touched = 0;

        foreach ($recipients as $r) {
            /** @var \App\Models\User $user */
            $user   = $r['user'];
            $role   = $r['role'];
            $groups = $r['groups'];
            $total  = $r['total'];

            $this->line(sprintf(
                '  → %s <%s> (%s) — %d rows',
                $user->name, $user->email, $role, $total
            ));

            if ($dryRun) continue;

            try {
                Mail::to($user->email)->send(
                    new TenderDailyDigest($user, $role, $groups, $total, $slot)
                );
                $sent++;
                $touched += $this->stampIncluded($groups);
            } catch (\Throwable $e) {
                $failed++;
                $this->error("     ✗ {$e->getMessage()}");
                Log::error('TenderDailyDigest send failed', [
                    'user'  => $user->email,
                    'slot'  => $slot,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->newLine();
        $this->info("✅ {$sent} sent, {$failed} failed, {$touched} rows stamped with last_digest_sent_at.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Best-effort slot auto-detection when the scheduler doesn't pass --slot.
     * Before noon Lisbon → morning; otherwise → evening.
     */
    private function autoDetectSlot(): string
    {
        return now()->setTimezone('Europe/Lisbon')->hour < 12 ? 'morning' : 'evening';
    }

    /**
     * Bulk-update last_digest_sent_at on every included tender. We do one
     * UPDATE per recipient (not one per row) to keep this cheap.
     *
     * @param array<string, \Illuminate\Support\Collection> $groups
     */
    private function stampIncluded(array $groups): int
    {
        $ids = [];
        foreach ($groups as $rows) {
            foreach ($rows as $t) $ids[] = $t->id;
        }
        if (empty($ids)) return 0;

        return DB::table('tenders')
            ->whereIn('id', array_unique($ids))
            ->update(['last_digest_sent_at' => now()]);
    }
}
