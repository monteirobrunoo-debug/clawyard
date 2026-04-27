<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\AgentCatalog;
use Illuminate\Console\Command;

/**
 * Cross-user health-check for the per-user agent whitelist
 * (users.allowed_agents).
 *
 * Runs offline (no network), suitable for a daily cron. Surfaces:
 *
 *   • BLOCKED_FROM_ALL  — user with allowed_agents=[]: can log in
 *                          but the picker is empty. Almost always
 *                          unintentional.
 *   • THIN_WHITELIST    — user with 1 or 2 agents: probably forgotten
 *                          mid-configuration; admin meant to apply
 *                          a preset.
 *   • ORPHAN_AGENT      — agent in the catalog that NO user has
 *                          access to (excluding admins, who always
 *                          have access by gate). Deletable from the
 *                          catalog if business has dropped that flow.
 *   • UNIVERSAL_AGENT   — agent every user has access to (excluding
 *                          admins). Probably worth having as the
 *                          NULL default — informational.
 *
 * Restrictions are NOT anomalies (they're deliberate policy). The
 * exit code only fails on the first two — orphan and universal are
 * informational.
 *
 * Usage:
 *   php artisan users:audit-agent-access            # human-readable table
 *   php artisan users:audit-agent-access --json     # machine-readable
 */
class UsersAuditAgentAccessCommand extends Command
{
    protected $signature = 'users:audit-agent-access
        {--json : Emit a JSON report instead of the human table}';

    protected $description = 'Health-check the per-user agent whitelist (orphans, blocked, thin lists)';

    public function handle(): int
    {
        $catalog = AgentCatalog::byKey();
        // Routing meta-agents always available — exclude from the audit
        // surface so we don't report 'auto' as orphaned.
        $userFacing = array_diff(array_keys($catalog), ['auto', 'orchestrator']);

        $users = User::query()
            ->where('is_active', true)
            ->whereIn('role', ['user', 'manager'])    // admin always passes the gate
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'role', 'allowed_agents']);

        // Per-user inspection: count what they can see + flag anomalies.
        $rows = [];
        $accessByAgent = array_fill_keys($userFacing, 0);  // agent_key → user count
        foreach ($users as $u) {
            $allowed = $u->allowed_agents;
            $anomalies = [];

            if ($allowed === null) {
                $visible = $userFacing;            // sees all
            } elseif (empty($allowed)) {
                $visible = [];
                $anomalies[] = 'BLOCKED_FROM_ALL';
            } else {
                $visible = array_values(array_intersect($userFacing, $allowed));
                if (count($visible) <= 2) $anomalies[] = 'THIN_WHITELIST';
            }

            foreach ($visible as $key) {
                $accessByAgent[$key] = ($accessByAgent[$key] ?? 0) + 1;
            }

            $rows[] = [
                'user_id'    => $u->id,
                'name'       => $u->name,
                'email'      => $u->email,
                'role'       => $u->role,
                'visible'    => count($visible),
                'restricted' => $allowed !== null,
                'anomalies'  => $anomalies,
            ];
        }

        // Agent-side inspection: orphans (no users) and universals.
        $orphans = [];
        $universals = [];
        $userCount = count($rows);
        foreach ($accessByAgent as $key => $n) {
            if ($n === 0)               $orphans[]    = $key;
            if ($n === $userCount && $userCount > 0) $universals[] = $key;
        }

        $report = [
            'total_users'       => $userCount,
            'total_agents'      => count($userFacing),
            'rows'              => $rows,
            'orphan_agents'     => $orphans,
            'universal_agents'  => $universals,
        ];

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            return $this->exitCode($rows);
        }

        // Human-readable mode.
        $this->table(
            ['User', 'Email', 'Role', 'Visible', 'Restricted?', 'Anomalies'],
            array_map(fn($r) => [
                $r['name'],
                $r['email'],
                $r['role'],
                $r['visible'].'/'.count($userFacing),
                $r['restricted'] ? 'yes' : 'no',
                empty($r['anomalies']) ? '—' : implode(', ', $r['anomalies']),
            ], $rows)
        );

        $this->newLine();

        $anomalyCount = collect($rows)->reduce(fn($c, $r) => $c + count($r['anomalies']), 0);
        if ($anomalyCount === 0) {
            $this->info(sprintf('OK — %d active user(s) audited, no anomalies.', $userCount));
        } else {
            $this->warn(sprintf('Found %d anomaly/anomalies across %d user(s).', $anomalyCount, $userCount));
        }

        if (!empty($orphans)) {
            $this->newLine();
            $this->warn('Orphan agents (no non-admin user has access — consider removing or auditing):');
            foreach ($orphans as $key) {
                $name = $catalog[$key]['name'] ?? $key;
                $this->line(sprintf('  · %s (%s)', $name, $key));
            }
        }
        if (!empty($universals)) {
            $this->newLine();
            $this->line('Universal agents (everyone has access — could be the NULL default):');
            foreach ($universals as $key) {
                $name = $catalog[$key]['name'] ?? $key;
                $this->line(sprintf('  · %s (%s)', $name, $key));
            }
        }

        return $this->exitCode($rows);
    }

    /** Exit non-zero when at least one user has an anomaly. */
    private function exitCode(array $rows): int
    {
        return collect($rows)->contains(fn($r) => !empty($r['anomalies']))
            ? self::FAILURE
            : self::SUCCESS;
    }
}
