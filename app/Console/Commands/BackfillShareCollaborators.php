<?php

namespace App\Console\Commands;

use App\Models\AgentShare;
use App\Models\TenderCollaborator;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * One-shot backfill to register every existing AgentShare recipient as a
 * TenderCollaborator. After this runs, any user who has already received
 * agents via the portal will also appear in the /tenders bulk-assign
 * dropdown — and any tenders later assigned to them will show up in their
 * portal tender-block.
 *
 * Mirrors the inline upsert added to AgentShareController::store() (which
 * handles the forward-going case). Safe to re-run: it's purely additive
 * and idempotent per email address.
 *
 *   php artisan clawyard:backfill-share-collaborators
 *   php artisan clawyard:backfill-share-collaborators --dry-run
 */
class BackfillShareCollaborators extends Command
{
    protected $signature = 'clawyard:backfill-share-collaborators {--dry-run : Report what would change without writing}';
    protected $description = 'Create TenderCollaborator rows for every email that has received an AgentShare';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $this->info('Backfilling TenderCollaborator rows from AgentShare recipients...');
        if ($dry) $this->warn('DRY RUN — no writes will be performed.');

        $shares = AgentShare::query()
            ->where('is_active', true)
            ->whereNull('revoked_at')
            ->where(fn($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>=', now()))
            ->get();

        $this->line("Found {$shares->count()} active shares to scan.");

        $emailsSeen  = [];
        $created     = 0;
        $backfilled  = 0;
        $alreadyOk   = 0;
        $reactivated = 0;
        $skipped     = 0;

        foreach ($shares as $share) {
            foreach ($share->authorisedEmails() as $email) {
                $email = strtolower(trim($email));
                if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $skipped++;
                    continue;
                }

                // First-wins: once an email has been handled in this run,
                // skip repeat work for it (same person may be on multiple
                // shares across a portal bundle).
                if (isset($emailsSeen[$email])) continue;
                $emailsSeen[$email] = true;

                $existing = TenderCollaborator::whereRaw('LOWER(email) = ?', [$email])->first();
                if ($existing) {
                    if ($existing->is_active) {
                        $alreadyOk++;
                    } else {
                        $reactivated++;
                        if (!$dry) {
                            $existing->is_active = true;
                            $existing->save();
                        }
                    }
                    continue;
                }

                $user = User::whereRaw('LOWER(email) = ?', [$email])->first();
                if ($user) {
                    $byUser = TenderCollaborator::where('user_id', $user->id)->first();
                    if ($byUser) {
                        $backfilled++;
                        if (!$dry) {
                            $byUser->email = $email;
                            if (!$byUser->is_active) $byUser->is_active = true;
                            $byUser->save();
                        }
                        continue;
                    }
                }

                $displayName = $user?->name
                    ?: ($share->client_name ?: explode('@', $email)[0]);

                $created++;
                if (!$dry) {
                    TenderCollaborator::create([
                        'name'            => $displayName,
                        'normalized_name' => TenderCollaborator::normalize($displayName),
                        'email'           => $email,
                        'is_active'       => true,
                    ]);
                }
            }
        }

        $this->newLine();
        $this->info('Summary:');
        $this->line("  Emails scanned:     " . count($emailsSeen));
        $this->line("  Already linked:     {$alreadyOk}");
        $this->line("  Re-activated:       {$reactivated}");
        $this->line("  Email backfilled:   {$backfilled}  (existed via user_id, got .email set)");
        $this->line("  Newly created:      {$created}");
        $this->line("  Skipped (invalid):  {$skipped}");

        if ($dry) {
            $this->warn('Dry run complete — re-run without --dry-run to apply changes.');
        } else {
            $this->info('Done.');
        }

        return self::SUCCESS;
    }
}
