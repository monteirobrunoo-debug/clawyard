<?php

namespace App\Console\Commands;

use App\Models\Tender;
use App\Models\TenderCollaborator;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

/**
 * Health-check across the whole user base.
 *
 *   php artisan tenders:audit-all-users
 *   php artisan tenders:audit-all-users --json    # for cron pipelines
 *
 * Surfaces the soft-failure modes that aren't bugs but slowly degrade
 * the dashboard / digest experience:
 *
 *   • User has NO collaborator row at all     → won't receive digest,
 *                                                won't see "Os meus".
 *   • User has 2+ collaborator rows           → tenders may be split
 *                                                across rows, digest may
 *                                                double-count.
 *   • Collaborator email = User.email but     → legacy state; the
 *     user_id link is NULL                      saving hook should have
 *                                                backfilled — needs
 *                                                tenders:link-collaborators.
 *   • Restricted user (allowed_sources non-NULL) → reported with the
 *                                                  restriction so admins
 *                                                  remember who has what.
 *   • User with allowed_sources=[] (full block) → flagged as anomaly:
 *                                                  they get NO tenders
 *                                                  AND no digest.
 *
 * Exit code is 0 if there are no anomalies, 1 if any are found —
 * cron-friendly. Restrictions are NOT anomalies (they're deliberate
 * policy) so they don't fail the exit code.
 */
class TenderAuditAllUsersCommand extends Command
{
    protected $signature = 'tenders:audit-all-users
        {--json : Emit a machine-readable JSON report instead of the table}';

    protected $description = 'Cross-user health-check: missing/duplicate collaborator rows, loose links, and access policy summary';

    public function handle(): int
    {
        $users = User::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        $allCollabs = TenderCollaborator::query()
            ->with('tenders:id,assigned_collaborator_id,source')
            ->get();

        // Pre-bucket collaborators by user_id and by lowercased email so
        // we don't run N+1 queries inside the loop.
        $byUserId = $allCollabs->whereNotNull('user_id')->groupBy('user_id');
        $byEmail  = $allCollabs->whereNotNull('email')
            ->groupBy(fn(TenderCollaborator $c) => strtolower(trim((string) $c->email)));

        $rows = [];
        foreach ($users as $u) {
            $linked = $byUserId->get($u->id, collect());
            $loose  = $byEmail->get(strtolower(trim((string) $u->email)), collect())
                ->filter(fn(TenderCollaborator $c) => empty($c->user_id));

            $anomalies = [];
            if ($linked->isEmpty() && $loose->isEmpty()) {
                $anomalies[] = 'NO_COLLAB_ROW';
            }
            if ($linked->count() > 1) {
                $anomalies[] = 'MULTIPLE_LINKED_ROWS';
            }
            if ($loose->isNotEmpty()) {
                $anomalies[] = 'LOOSE_EMAIL_MATCH';
            }

            // Resolve restriction the same way scopeForUser does.
            $effective    = $linked->isNotEmpty() ? $linked : $loose;
            $unrestricted = $effective->contains(fn(TenderCollaborator $c) => $c->allowed_sources === null);
            if (!$unrestricted && $effective->isNotEmpty()) {
                $allowed = $effective
                    ->flatMap(fn($c) => (array) ($c->allowed_sources ?? []))
                    ->unique()
                    ->values()
                    ->all();
                if (empty($allowed)) {
                    $anomalies[] = 'BLOCKED_FROM_ALL_SOURCES';
                    $restriction = 'BLOCKED';
                } else {
                    $restriction = implode(',', $allowed);
                }
            } else {
                $restriction = '—';
            }

            $tenderCount = (int) Tender::query()->forUser($u->id)->count();

            $rows[] = [
                'user_id'      => $u->id,
                'name'         => $u->name,
                'email'        => $u->email,
                'role'         => $u->role,
                'collab_rows'  => $linked->count(),
                'loose_rows'   => $loose->count(),
                'restriction'  => $restriction,
                'tenders'      => $tenderCount,
                'anomalies'    => $anomalies,
            ];
        }

        if ($this->option('json')) {
            $this->line(json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            return $this->summariseExitCode($rows);
        }

        $this->table(
            ['User', 'Email', 'Role', 'Linked', 'Loose', 'Restriction', 'Tenders', 'Anomalies'],
            array_map(fn($r) => [
                $r['name'],
                $r['email'],
                $r['role'],
                $r['collab_rows'],
                $r['loose_rows'],
                $r['restriction'],
                $r['tenders'],
                empty($r['anomalies']) ? '—' : implode(', ', $r['anomalies']),
            ], $rows)
        );

        $this->summariseHumanReport($rows);
        return $this->summariseExitCode($rows);
    }

    private function summariseHumanReport(array $rows): void
    {
        $withAnomalies = array_filter($rows, fn($r) => !empty($r['anomalies']));
        $this->newLine();
        if (empty($withAnomalies)) {
            $this->info(sprintf('OK — %d active user(s) audited, no anomalies.', count($rows)));
            return;
        }

        $this->warn(sprintf(
            '%d of %d active user(s) have anomalies that need attention:',
            count($withAnomalies),
            count($rows)
        ));
        foreach ($withAnomalies as $r) {
            $this->line(sprintf('  · %s <%s> — %s', $r['name'], $r['email'], implode(', ', $r['anomalies'])));
        }
        $this->newLine();
        $this->line('Suggested fixes:');
        $this->line('  NO_COLLAB_ROW         → /tenders/collaborators (add row), or wait for next import');
        $this->line('  MULTIPLE_LINKED_ROWS  → merge by deactivating duplicates in /tenders/collaborators');
        $this->line('  LOOSE_EMAIL_MATCH     → php artisan tenders:link-collaborators');
        $this->line('  BLOCKED_FROM_ALL_SOURCES → toggle at least one source on /tenders/collaborators');
    }

    private function summariseExitCode(array $rows): int
    {
        return collect($rows)->contains(fn($r) => !empty($r['anomalies']))
            ? self::FAILURE
            : self::SUCCESS;
    }
}
