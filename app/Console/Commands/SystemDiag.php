<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One-shot diagnostic for production issues after a deploy.
 *
 *   php artisan system:diag
 *
 * Checks:
 *   - pending migrations (tells you if you need to run migrate --force)
 *   - new columns/tables added by the security hardening PRs
 *   - presence of critical env vars (Anthropic, SAP, mail)
 *   - DB connectivity + table row counts
 */
class SystemDiag extends Command
{
    protected $signature   = 'system:diag';
    protected $description = 'Diagnose common production issues (migrations, env, DB)';

    public function handle(): int
    {
        $this->line('');
        $this->line('═══════════════════════════════════════════════');
        $this->line(' ClawYard · System Diagnostic');
        $this->line('═══════════════════════════════════════════════');
        $this->line(' App env      : ' . app()->environment());
        $this->line(' App URL      : ' . config('app.url'));
        $this->line(' DB driver    : ' . config('database.default'));
        $this->line(' Cache driver : ' . config('cache.default'));
        $this->line('───────────────────────────────────────────────');

        // ── DB connectivity ────────────────────────────────────
        $this->line('');
        $this->info('▸ Database connectivity');
        try {
            DB::connection()->getPdo();
            $this->line('  ✅ connected');
        } catch (\Throwable $e) {
            $this->error('  ❌ DB connection failed: ' . $e->getMessage());
            return self::FAILURE;
        }

        // ── Migrations ─────────────────────────────────────────
        $this->line('');
        $this->info('▸ Migrations');
        try {
            $ran      = DB::table('migrations')->pluck('migration')->all();
            $files    = glob(database_path('migrations/*.php')) ?: [];
            $pending  = [];
            foreach ($files as $f) {
                $name = basename($f, '.php');
                if (!in_array($name, $ran, true)) $pending[] = $name;
            }
            if (empty($pending)) {
                $this->line('  ✅ all migrations applied (' . count($ran) . ' total)');
            } else {
                $this->error('  ❌ ' . count($pending) . ' PENDING migration(s):');
                foreach ($pending as $p) $this->line('     · ' . $p);
                $this->line('');
                $this->warn('  Fix: run  →  php artisan migrate --force');
            }
        } catch (\Throwable $e) {
            $this->error('  ❌ ' . $e->getMessage());
        }

        // ── Expected columns/tables from recent security PRs ──
        $this->line('');
        $this->info('▸ Schema spot-check (security hardening)');
        $checks = [
            // table => [expected columns]
            'shared_contexts'          => ['user_id', 'change_type', 'similarity_score'],
            'agent_shares'             => ['require_otp', 'lock_to_device', 'notify_on_access', 'revoked_at'],
            'agent_share_otps'         => null,  // entire table must exist
            'agent_share_access_logs'  => null,
        ];
        foreach ($checks as $table => $cols) {
            if (!Schema::hasTable($table)) {
                $this->error("  ❌ table MISSING: {$table}");
                continue;
            }
            if ($cols === null) {
                $this->line("  ✅ table {$table} exists");
                continue;
            }
            $missing = array_filter($cols, fn($c) => !Schema::hasColumn($table, $c));
            if (empty($missing)) {
                $this->line("  ✅ {$table} has all expected columns");
            } else {
                $this->error("  ❌ {$table} missing columns: " . implode(', ', $missing));
            }
        }

        // ── Critical env vars ─────────────────────────────────
        $this->line('');
        $this->info('▸ Critical environment variables');
        $envs = [
            'ANTHROPIC_API_KEY'     => config('services.anthropic.api_key'),
            'NVIDIA_API_KEY'        => config('services.nvidia.api_key'),
            'MAIL_HOST'             => config('mail.mailers.smtp.host'),
            'MAIL_USERNAME'         => config('mail.mailers.smtp.username'),
            'MAIL_FROM_ADDRESS'     => config('mail.from.address'),
            'SAP_B1_URL'            => config('services.sap.base_url'),
            'SAP_B1_USER'           => config('services.sap.username'),
        ];
        foreach ($envs as $name => $value) {
            if (empty($value)) {
                $this->error("  ❌ {$name} is EMPTY");
            } else {
                $display = str_contains(strtolower($name), 'key') || str_contains(strtolower($name), 'password')
                    ? substr($value, 0, 6) . '…' . substr($value, -4) . ' (len=' . strlen($value) . ')'
                    : $value;
                $this->line("  ✅ {$name}: {$display}");
            }
        }

        // ── Table row counts ──────────────────────────────────
        $this->line('');
        $this->info('▸ Table sizes');
        foreach (['users','conversations','messages','reports','shared_contexts','agent_shares'] as $table) {
            try {
                $n = Schema::hasTable($table) ? DB::table($table)->count() : 'N/A';
                $this->line("  · {$table} = {$n}");
            } catch (\Throwable $e) {
                $this->error("  · {$table} → {$e->getMessage()}");
            }
        }

        $this->line('');
        $this->line('═══════════════════════════════════════════════');
        $this->line(' Diagnostic complete.');
        $this->line('═══════════════════════════════════════════════');
        return self::SUCCESS;
    }
}
