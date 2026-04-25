<?php

namespace App\Console\Commands;

use App\Models\TenderCollaborator;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Find and fix `tender_collaborators` rows where the `email` field
 * belongs to a different User than the row's `user_id` link.
 *
 * Why this command exists
 * -----------------------
 * On 2026-04-24 a regular user (catarina.sequeira) opened /tenders and
 * was served another user's (monica.pereira) dashboard. Root cause was
 * a single corrupted row:
 *
 *     id  | user_id (points to)    | email                          | name
 *     ----+------------------------+--------------------------------+--------
 *     42  | 7   (Mónica Pereira)   | catarina.sequeira@hp-group.org | Mónica
 *
 * `Tender::scopeForUser` matched this row by email and Catarina got
 * Monica's tenders. The query was hardened in commit 11d8ba1 so the
 * leak no longer happens at filter time — but the corrupt row still
 * sits in the data and would re-bite us if the filter ever regressed.
 *
 * How it works
 * ------------
 * For every active row we compare:
 *
 *     A: User found via the `email` column   (LOWER + TRIM match)
 *     B: User the row claims via `user_id`
 *
 * If A and B both exist and A.id ≠ B.id → MISMATCH (phantom email).
 *
 * Default mode is read-only — it lists every mismatch with enough
 * context to decide what's right.
 *
 * Modes
 *   --fix       Clear the `email` field on each mismatched row. This
 *               is the LEAST destructive auto-repair: the row keeps
 *               its user_id link, the `digest_email` accessor falls
 *               back to the linked User's email, and history /
 *               tender assignments are untouched. The admin can
 *               re-enter the email later if there was a deliberate
 *               reason (alias, distribution list, secondary inbox).
 *
 *   --reattach  Trust the `email` field instead: re-point `user_id`
 *               to the User whose email matches. More invasive
 *               (changes tender attribution if there are tenders
 *               assigned to this collaborator), but right when the
 *               original linkage was the typo and the email is the
 *               source of truth. Use only when you are sure.
 *
 *   Pass neither → audit-only.
 *   Pass both    → error (they're contradictory).
 */
class AuditCollaboratorEmailsCommand extends Command
{
    protected $signature = 'tenders:audit-collaborator-emails
        {--fix       : Clear the email column on every mismatched row}
        {--reattach  : Re-point user_id to the email owner instead of clearing email}';

    protected $description = 'Detect (and optionally fix) collaborator rows whose email belongs to a different User than user_id';

    public function handle(): int
    {
        $fix      = (bool) $this->option('fix');
        $reattach = (bool) $this->option('reattach');

        if ($fix && $reattach) {
            $this->error('--fix and --reattach are mutually exclusive. Pick one.');
            return self::FAILURE;
        }

        // Pre-index Users by lowercased+trimmed email so we can look up
        // an email's owner in O(1) without a per-row query.
        $usersByEmail = User::query()
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->get(['id', 'name', 'email'])
            ->keyBy(fn(User $u) => strtolower(trim((string) $u->email)));

        $rows = TenderCollaborator::query()
            ->whereNotNull('user_id')
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->withCount('tenders')
            ->with('user:id,name,email')
            ->orderBy('id')
            ->get();

        if ($rows->isEmpty()) {
            $this->info('No collaborator rows with both a user_id link AND an email — nothing to audit.');
            return self::SUCCESS;
        }

        $mismatches = [];
        foreach ($rows as $row) {
            $emailKey = strtolower(trim((string) $row->email));
            $emailOwner = $usersByEmail[$emailKey] ?? null;
            if (!$emailOwner) continue;                 // email points nowhere → not a phantom case
            if ($emailOwner->id === $row->user_id) continue; // consistent

            $mismatches[] = [
                'row'         => $row,
                'email_owner' => $emailOwner,
            ];
        }

        if (empty($mismatches)) {
            $this->info(sprintf(
                'OK — scanned %d collaborator row(s) with both fields set, no mismatches found.',
                $rows->count()
            ));
            return self::SUCCESS;
        }

        $this->warn(sprintf(
            'Found %d mismatched row(s) (email belongs to a different User than user_id):',
            count($mismatches)
        ));
        $this->newLine();

        foreach ($mismatches as $m) {
            $row    = $m['row'];
            $owner  = $m['email_owner'];
            $linked = $row->user;

            $this->line(sprintf('  collab #%d "%s"  (tenders_count=%d)', $row->id, $row->name, $row->tenders_count));
            $this->line(sprintf('     user_id=%d → %s <%s>', $row->user_id, $linked->name ?? '?', $linked->email ?? '?'));
            $this->line(sprintf('     email=%s → owned by user #%d (%s)',
                $row->email, $owner->id, $owner->name
            ));
            $this->newLine();
        }

        if (!$fix && !$reattach) {
            $this->line('Read-only audit. Re-run with one of:');
            $this->line('  --fix       (clear the email on each mismatched row, keep user_id)');
            $this->line('  --reattach  (re-point user_id to the email owner)');
            return self::SUCCESS;
        }

        // Apply repair, in a single transaction so a mid-failure can't leave
        // the table half-fixed.
        //
        // We deliberately bypass the model's saving() hook here by writing
        // through the query builder. The hook auto-syncs user_id from
        // email — which is the helpful behaviour for an admin editing the
        // form, but exactly the wrong behaviour for a data-repair pass.
        // E.g. clearing email through the model wipes user_id along with
        // it; setting user_id back through the model would be overridden
        // by the email lookup. The audit command treats both columns as
        // independent and writes them directly.
        DB::transaction(function () use ($mismatches, $fix) {
            foreach ($mismatches as $m) {
                $row    = $m['row'];
                $owner  = $m['email_owner'];
                $before = ['user_id' => $row->user_id, 'email' => $row->email];

                if ($fix) {
                    // Clear the phantom email; user_id stays authoritative.
                    $update = ['email' => null, 'updated_at' => now()];
                } else { // reattach
                    // Email is the source of truth; re-point user_id to
                    // the email's owner. Email kept as-is.
                    $update = ['user_id' => $owner->id, 'updated_at' => now()];
                }
                DB::table('tender_collaborators')->where('id', $row->id)->update($update);

                $after = DB::table('tender_collaborators')
                    ->where('id', $row->id)
                    ->first(['user_id', 'email']);

                Log::warning('AuditCollaboratorEmails: repaired mismatch', [
                    'collab_id' => $row->id,
                    'mode'      => $fix ? 'clear-email' : 'reattach',
                    'before'    => $before,
                    'after'     => ['user_id' => $after->user_id, 'email' => $after->email],
                ]);
            }
        });

        $this->info(sprintf('Repaired %d row(s) [mode=%s].', count($mismatches), $fix ? 'clear-email' : 'reattach'));

        return self::SUCCESS;
    }
}
