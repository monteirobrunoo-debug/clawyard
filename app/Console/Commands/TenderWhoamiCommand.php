<?php

namespace App\Console\Commands;

use App\Models\Tender;
use App\Models\TenderCollaborator;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Diagnose which TenderCollaborator row(s) a given user is resolving to
 * on /tenders — and therefore whose tenders they're going to see.
 *
 *   php artisan tenders:whoami catarina.sequeira@hp-group.org
 *
 * Prints, in order:
 *   1) The User row (id, name, email, role).
 *   2) Every TenderCollaborator row matched via the STRICT path
 *      (user_id = user.id).
 *   3) If the strict path is empty, the fallback matches
 *      (user_id IS NULL AND LOWER(email) = LOWER(user.email)).
 *   4) Any "phantom" rows that would have been matched by the OLD
 *      OR-style query but are now correctly ignored — so we can spot
 *      data corruption (someone else's tenders about to leak).
 *   5) Total tender count resolved via Tender::scopeForUser.
 *
 * Added 2026-04-24 after catarina.sequeira was seeing monica.pereira's
 * dashboard. Use this command to audit new or re-linked users before
 * assuming their view is correct.
 */
class TenderWhoamiCommand extends Command
{
    protected $signature = 'tenders:whoami {email : User email to diagnose}';

    protected $description = 'Show which TenderCollaborator rows a given user resolves to on /tenders';

    public function handle(): int
    {
        $email = strtolower(trim((string) $this->argument('email')));
        if ($email === '') {
            $this->error('Missing email argument.');
            return self::FAILURE;
        }

        $user = User::whereRaw('LOWER(email) = ?', [$email])->first();
        if (!$user) {
            $this->error("No User with email '{$email}'.");
            return self::FAILURE;
        }

        $this->info("User #{$user->id}: {$user->name} <{$user->email}> — role={$user->role} active=".((int) $user->is_active));
        $this->newLine();

        // 1. Strict path — user_id link.
        $strict = TenderCollaborator::where('user_id', $user->id)->get();
        $this->line("<fg=cyan>Strict match (user_id = {$user->id}):</>");
        if ($strict->isEmpty()) {
            $this->line('  <fg=yellow>(none)</>');
        } else {
            foreach ($strict as $c) {
                $tenderCount = $c->tenders()->count();
                $this->line(sprintf('  ✓ collab #%d "%s" email=%s tenders=%d',
                    $c->id, $c->name, $c->email ?: '—', $tenderCount
                ));
                $this->line(sprintf('       allowed_sources=%s · allowed_statuses=%s · is_active=%d',
                    $c->allowed_sources === null ? 'NULL' : json_encode($c->allowed_sources),
                    $c->allowed_statuses === null ? 'NULL' : json_encode($c->allowed_statuses),
                    (int) $c->is_active
                ));
            }
        }
        $this->newLine();

        // 2. Fallback path — only fires when strict is empty.
        $this->line('<fg=cyan>Fallback (user_id IS NULL AND email matches, case-insensitive):</>');
        $fallback = TenderCollaborator::query()
            ->whereNull('user_id')
            ->whereRaw('LOWER(TRIM(email)) = ?', [$email])
            ->get();
        if ($fallback->isEmpty()) {
            $this->line('  <fg=yellow>(none)</>');
        } else {
            foreach ($fallback as $c) {
                $this->line(sprintf('  ✓ collab #%d "%s" email=%s', $c->id, $c->name, $c->email));
            }
        }
        $this->newLine();

        // 3. Phantom rows — other people's collaborator rows whose email
        //    happens to match this user. Would have leaked under the old
        //    OR query; now correctly ignored. If this list is non-empty,
        //    there is a DATA bug (a collaborator owned by someone else
        //    is carrying this user's email).
        $phantoms = TenderCollaborator::query()
            ->whereNotNull('user_id')
            ->where('user_id', '!=', $user->id)
            ->whereRaw('LOWER(TRIM(email)) = ?', [$email])
            ->with('user:id,name,email')
            ->get();
        if ($phantoms->isNotEmpty()) {
            $this->warn('Phantom email matches (owned by other users, now IGNORED by scopeForUser):');
            foreach ($phantoms as $c) {
                $ownerName = $c->user->name ?? '?';
                $ownerEmail = $c->user->email ?? '?';
                $this->line(sprintf(
                    "  ⚠ collab #%d \"%s\" carries %s's email but is owned by user #%d (%s <%s>)",
                    $c->id, $c->name, $user->email, $c->user_id, $ownerName, $ownerEmail
                ));
            }
            $this->line('  → Fix: edit these rows and clear the wrong email, OR reassign user_id, OR both.');
            $this->newLine();
        }

        // 4. Final scope count — ground truth.
        $count = Tender::query()->forUser($user->id)->count();
        $this->info("scopeForUser({$user->id}) resolves to {$count} tender(s).");
        if ($count > 0) {
            $sources = Tender::query()->forUser($user->id)->distinct()->pluck('source')->sort()->values()->all();
            $this->line('  Sources present: '.implode(', ', $sources));
        }

        return self::SUCCESS;
    }
}
