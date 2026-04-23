<?php

namespace App\Console\Commands;

use App\Models\TenderCollaborator;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Links TenderCollaborator rows (discovered from the Excel `Colaborador`
 * column during import) to real User accounts, so the daily digest can
 * find each user's personal tenders.
 *
 * Two modes:
 *
 *   A) Auto-match by normalised name (default)
 *   -----------------------------------------
 *     php artisan tenders:link-collaborators
 *     php artisan tenders:link-collaborators --dry-run
 *
 *     Normalises TenderCollaborator.name and User.name via the same
 *     TenderCollaborator::normalize() helper, then links exact matches.
 *     Works for "ZÉ INÁCIO" (Excel) ↔ "Zé Inácio" (User.name).
 *
 *   B) Explicit mapping by email (for when names don't match)
 *   ---------------------------------------------------------
 *     php artisan tenders:link-collaborators \
 *       --map="Zé Inácio:ze.inacio@hp-group.org,Catarina:catarina@hp-group.org"
 *
 *     Comma-separated "CollaboratorName:UserEmail" pairs. The name is
 *     matched via normalize() so casing/accents don't have to be exact.
 *
 * Idempotent: if a collaborator already has a user_id, we skip it unless
 * --overwrite is passed.
 */
class LinkTenderCollaboratorsCommand extends Command
{
    protected $signature = 'tenders:link-collaborators
        {--map=       : Comma-separated "Name:Email" explicit mappings}
        {--dry-run    : Report what would change without writing}
        {--overwrite  : Re-link even collaborators that already have user_id}';

    protected $description = 'Link TenderCollaborator rows to User accounts for digest routing';

    public function handle(): int
    {
        $dryRun    = (bool) $this->option('dry-run');
        $overwrite = (bool) $this->option('overwrite');
        $mapSpec   = (string) $this->option('map');

        $explicitMap = $this->parseMap($mapSpec);
        if ($mapSpec && empty($explicitMap)) {
            $this->error('Could not parse --map. Expected "Name:email,Name:email".');
            return self::FAILURE;
        }

        $collabs = TenderCollaborator::query()
            ->when(!$overwrite, fn($q) => $q->whereNull('user_id'))
            ->get();

        if ($collabs->isEmpty()) {
            $this->info($overwrite
                ? 'No collaborators exist.'
                : 'All collaborators are already linked (use --overwrite to re-link).');
            return self::SUCCESS;
        }

        // Pre-index users by normalised name AND by lowercase email so we
        // can look up in O(1).
        $usersByName  = User::all()->keyBy(fn($u) => TenderCollaborator::normalize($u->name));
        $usersByEmail = User::all()->keyBy(fn($u) => strtolower((string) $u->email));

        $linked  = 0;
        $skipped = 0;
        $clashes = [];

        $this->info('Collaborator → User matching'
            . ($dryRun ? ' [DRY RUN]' : '')
            . ($overwrite ? ' [OVERWRITE]' : ''));
        $this->newLine();

        foreach ($collabs as $c) {
            $user = null;
            $via  = null;

            // 1. Explicit map wins.
            $explicitEmail = $explicitMap[$c->normalized_name] ?? null;
            if ($explicitEmail && isset($usersByEmail[$explicitEmail])) {
                $user = $usersByEmail[$explicitEmail];
                $via  = 'explicit';
            }

            // 2. Auto-match by normalised name.
            if (!$user && isset($usersByName[$c->normalized_name])) {
                $user = $usersByName[$c->normalized_name];
                $via  = 'name';
            }

            if (!$user) {
                $this->line(sprintf('  · %-30s → <fg=yellow>no match</>', $c->name));
                $skipped++;
                continue;
            }

            // Warn if we'd overwrite a different existing link
            if ($c->user_id && $c->user_id !== $user->id) {
                $clashes[] = "{$c->name}: currently → {$c->user_id}, would become → {$user->id}";
            }

            $this->line(sprintf(
                '  ✓ %-30s → %s <%s>  [%s]',
                $c->name, $user->name, $user->email, $via
            ));

            if (!$dryRun) {
                $c->user_id = $user->id;
                // Carry the user's email into the digest_email fallback field
                // if the collaborator had no explicit email set. This way
                // DailyDigestService sees a mailable address even without
                // the cross-table join.
                if (empty($c->email) && !empty($user->email)) {
                    $c->email = $user->email;
                }
                $c->save();
            }
            $linked++;
        }

        $this->newLine();
        $this->line("Linked:  {$linked}");
        $this->line("Skipped: {$skipped}");

        if (!empty($clashes)) {
            $this->newLine();
            $this->warn('Existing links overwritten:');
            foreach ($clashes as $c) $this->line("  · {$c}");
        }

        if ($dryRun) {
            $this->newLine();
            $this->warn('Dry run — no rows written. Rerun without --dry-run to persist.');
        }

        return self::SUCCESS;
    }

    /**
     * Parse "Zé Inácio:ze.inacio@hp-group.org,Catarina:catarina@hp-group.org"
     * into ['ze inacio' => 'ze.inacio@hp-group.org', 'catarina' => '…'].
     *
     * @return array<string, string> normalized_name => lowercased email
     */
    private function parseMap(string $spec): array
    {
        if ($spec === '') return [];
        $out = [];
        foreach (explode(',', $spec) as $pair) {
            $pair = trim($pair);
            if ($pair === '' || !str_contains($pair, ':')) continue;
            [$name, $email] = explode(':', $pair, 2);
            $key = TenderCollaborator::normalize($name);
            if ($key !== '') $out[$key] = strtolower(trim($email));
        }
        return $out;
    }
}
