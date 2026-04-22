<?php

namespace App\Console\Commands;

use App\Models\AgentShareAccessLog;
use App\Models\AgentShareOtp;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Retention enforcement for the agent-share security artefacts.
 *
 * Two tables were growing without a TTL:
 *   - agent_share_access_logs  — one row per OTP request, verification,
 *                                 stream call, owner notification, etc.
 *                                 Over time this accumulates IPs, UAs and
 *                                 emails that are never queried past a
 *                                 short incident-response window.
 *   - agent_share_otps         — the OTP row itself. Once the code is
 *                                 used or expired it carries no value,
 *                                 only PII (email + hashed code + IP).
 *
 * Default retention:
 *   - Access logs → AGENT_SHARE_LOG_RETENTION_DAYS (env, default 90)
 *   - OTPs        → anything older than 1 day (they expire in 10 min; a
 *                   day is generous overlap for debugging)
 *
 * Wired into the scheduler (routes/console.php) at `daily()`.
 */
class PruneAgentShareArtifacts extends Command
{
    protected $signature   = 'agentshares:cleanup {--dry-run : Report counts without deleting}';
    protected $description = 'Delete aged agent_share_access_logs + spent agent_share_otps';

    public function handle(): int
    {
        $retentionDays = (int) env('AGENT_SHARE_LOG_RETENTION_DAYS', 90);
        if ($retentionDays < 1) $retentionDays = 90;

        $logCutoff = now()->subDays($retentionDays);
        $otpCutoff = now()->subDay();

        $logQuery = AgentShareAccessLog::where('created_at', '<', $logCutoff);
        $otpQuery = AgentShareOtp::where('created_at', '<', $otpCutoff);

        $logCount = (clone $logQuery)->count();
        $otpCount = (clone $otpQuery)->count();

        if ($this->option('dry-run')) {
            $this->info("[dry-run] would delete {$logCount} access logs (>{$retentionDays}d) and {$otpCount} OTP rows (>1d).");
            return self::SUCCESS;
        }

        $logQuery->delete();
        $otpQuery->delete();

        $this->info("✅ cleaned {$logCount} access logs + {$otpCount} OTP rows.");
        Log::info('agentshares:cleanup', [
            'logs_deleted'    => $logCount,
            'otps_deleted'    => $otpCount,
            'retention_days'  => $retentionDays,
            'ts'              => now()->toIso8601String(),
        ]);

        return self::SUCCESS;
    }
}
