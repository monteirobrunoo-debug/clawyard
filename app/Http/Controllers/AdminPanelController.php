<?php

namespace App\Http\Controllers;

use App\Models\AppSetting;
use App\Services\IntegrationHealthChecker;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;

/**
 * /admin/panel — single-page admin console.
 *
 * Five sections rendered as cards on one scroll:
 *   1. Overview — aggregate health pill + last refresh
 *   2. Integrations — per-service liveness probe with detail line
 *   3. Cron schedule — list of scheduled tasks + last/next run
 *   4. Feature flags — toggle UI components (ticker, presence, etc.)
 *   5. Secrets — list of expected env vars: status (set/missing) +
 *      last 4 chars only when set; never plaintext
 *
 * Strict admin gate (User::isAdmin) — manager+ is not enough.
 */
class AdminPanelController extends Controller
{
    public function index(Request $request, IntegrationHealthChecker $health)
    {
        $user = Auth::user();
        if (!$user || !$user->isAdmin()) abort(403);

        $force  = $request->boolean('refresh');
        $report = $health->report(forceRefresh: $force);

        return view('admin.panel', [
            'report'      => $report,
            'overall'     => $this->aggregateState($report),
            'flagsByCat'  => AppSetting::all_grouped(),
            'crons'       => $this->collectCrons(),
            'secrets'     => $this->collectSecrets(),
            'lastRefresh' => now(),
        ]);
    }

    /** POST /admin/panel/flag — toggle a feature flag. */
    public function setFlag(Request $request)
    {
        $user = Auth::user();
        if (!$user || !$user->isAdmin()) abort(403);

        $data = $request->validate([
            'key'   => ['required', 'string', 'max:100'],
            'value' => ['nullable'],
        ]);
        if (!array_key_exists($data['key'], AppSetting::KNOWN)) {
            abort(400, 'Unknown setting key.');
        }

        $type  = AppSetting::KNOWN[$data['key']]['type'];
        $val   = match ($type) {
            'bool' => $request->boolean('value'),
            'int'  => (int) $request->input('value', 0),
            default => (string) $request->input('value', ''),
        };
        AppSetting::set($data['key'], $val, $user->id);

        return back()->with('status', "Flag {$data['key']} actualizada.");
    }

    /** POST /admin/panel/health-refresh — force a fresh probe pass. */
    public function refreshHealth(IntegrationHealthChecker $health)
    {
        $user = Auth::user();
        if (!$user || !$user->isAdmin()) abort(403);

        $health->report(forceRefresh: true);
        return back()->with('status', '✓ Health probes re-corridos.');
    }

    // ── Helpers ────────────────────────────────────────────────────────

    private function aggregateState(array $report): array
    {
        $down       = 0;
        $degraded   = 0;
        $up         = 0;
        $missing    = 0;
        foreach ($report as $check) {
            $state = $check['state'] ?? 'down';
            if ($state === 'down')           $down++;
            elseif ($state === 'degraded')   $degraded++;
            elseif ($state === 'not_configured') $missing++;
            else                              $up++;
        }
        $level = $down > 0
            ? 'down'
            : ($degraded > 0 ? 'degraded' : 'up');
        return compact('down', 'degraded', 'up', 'missing', 'level');
    }

    /**
     * List the registered scheduled tasks in routes/console.php
     * with their cron expression + next-due timestamp.
     */
    private function collectCrons(): array
    {
        try {
            // Run the artisan command in-process and capture its output.
            // It already prints a nicely formatted table — we re-parse
            // it into rows for the view.
            Artisan::call('schedule:list');
            $output = Artisan::output();
        } catch (\Throwable $e) {
            return [['expr' => '?', 'cmd' => 'schedule:list failed: ' . $e->getMessage(), 'next' => '']];
        }

        $rows = [];
        foreach (preg_split('/\r?\n/', $output) as $line) {
            $line = trim($line);
            if ($line === '') continue;
            // Strip ANSI colour escapes that the artisan output wraps
            // around symbols on some PHP versions.
            $line = preg_replace('/\033\[[0-9;]*m/', '', $line) ?? $line;
            if (preg_match('/^(\S+\s+\S+\s+\S+\s+\S+\s+\S+)\s+(.*?)\s+Next Due:\s*(.+)$/', $line, $m)) {
                $rows[] = [
                    'expr' => trim($m[1]),
                    'cmd'  => trim($m[2]),
                    'next' => trim($m[3]),
                ];
            } elseif (preg_match('/^(\S+\s+\S+\s+\S+\s+\S+\s+\S+)\s+(.*)$/', $line, $m)) {
                $rows[] = [
                    'expr' => trim($m[1]),
                    'cmd'  => trim($m[2]),
                    'next' => '',
                ];
            }
        }
        return $rows;
    }

    /**
     * Inventory of expected secrets/integration env vars.
     * For each: configured (bool) + masked tail (last 4 chars when set).
     * NEVER returns the full plaintext value.
     */
    private function collectSecrets(): array
    {
        $items = [
            ['env' => 'APP_KEY',                'category' => 'core',         'desc' => 'Encriptação do Laravel (chats, sessions cifradas)'],
            ['env' => 'DB_PASSWORD',            'category' => 'core',         'desc' => 'PostgreSQL (forge user)'],
            ['env' => 'ANTHROPIC_API_KEY',      'category' => 'llm',          'desc' => 'Claude — chave directa ou via llm-proxy'],
            ['env' => 'ANTHROPIC_BASE_URL',     'category' => 'llm',          'desc' => 'Base URL do proxy PII (recomendado)'],
            ['env' => 'ANTHROPIC_REDACT_PII',   'category' => 'llm',          'desc' => 'Scrubbing de emails/NIF/IBAN antes de enviar'],
            ['env' => 'NVIDIA_API_KEY',         'category' => 'llm',          'desc' => 'NVIDIA NIM (Carlos NVIDIA agent)'],
            ['env' => 'TAVILY_API_KEY',         'category' => 'search',       'desc' => 'Web search para enriquecimento + fornecedores'],
            ['env' => 'EPO_CONSUMER_KEY',       'category' => 'search',       'desc' => 'European Patent Office OAuth'],
            ['env' => 'EPO_CONSUMER_SECRET',    'category' => 'search',       'desc' => ''],
            ['env' => 'PATENTSVIEW_API_KEY',    'category' => 'search',       'desc' => 'USPTO PatentsView'],
            ['env' => 'SAP_B1_URL',             'category' => 'erp',          'desc' => 'Service Layer base URL'],
            ['env' => 'SAP_B1_USER',            'category' => 'erp',          'desc' => ''],
            ['env' => 'SAP_B1_PASSWORD',        'category' => 'erp',          'desc' => ''],
            ['env' => 'SAP_B1_COMPANY',         'category' => 'erp',          'desc' => 'Database name'],
            ['env' => 'HP_HISTORY_BASE_URL',    'category' => 'memory',       'desc' => 'pgvector droplet endpoint'],
            ['env' => 'HP_HISTORY_HMAC_SECRET', 'category' => 'memory',       'desc' => 'Shared secret HMAC-SHA256'],
            ['env' => 'HP_HISTORY_ENABLED',     'category' => 'memory',       'desc' => 'On/off flag'],
            ['env' => 'MAIL_MAILER',            'category' => 'mail',         'desc' => 'Driver (smtp / log / ses / ...)'],
            ['env' => 'MAIL_HOST',              'category' => 'mail',         'desc' => ''],
            ['env' => 'MAIL_USERNAME',          'category' => 'mail',         'desc' => ''],
            ['env' => 'MAIL_PASSWORD',          'category' => 'mail',         'desc' => ''],
            ['env' => 'POSTMARK_API_KEY',       'category' => 'mail',         'desc' => ''],
            ['env' => 'RESEND_API_KEY',         'category' => 'mail',         'desc' => ''],
            ['env' => 'AWS_ACCESS_KEY_ID',      'category' => 'cloud',        'desc' => 'AWS / S3 credentials'],
            ['env' => 'AWS_SECRET_ACCESS_KEY',  'category' => 'cloud',        'desc' => ''],
            ['env' => 'META_WHATSAPP_TOKEN',    'category' => 'messaging',    'desc' => 'WhatsApp Business API'],
            ['env' => 'META_WHATSAPP_PHONE_ID', 'category' => 'messaging',    'desc' => ''],
            ['env' => 'META_APP_SECRET',        'category' => 'messaging',    'desc' => 'Validação de assinatura webhook'],
            ['env' => 'SLACK_BOT_USER_OAUTH_TOKEN','category' => 'messaging', 'desc' => 'Slack notifications'],
            ['env' => 'ACINGOV_USERNAME',       'category' => 'portals',      'desc' => 'Concursos públicos PT'],
            ['env' => 'ACINGOV_PASSWORD',       'category' => 'portals',      'desc' => ''],
            ['env' => 'VORTAL_USERNAME',        'category' => 'portals',      'desc' => ''],
            ['env' => 'VORTAL_PASSWORD',        'category' => 'portals',      'desc' => ''],
            ['env' => 'SAM_GOV_API_KEY',        'category' => 'portals',      'desc' => 'US Federal contracts'],
            ['env' => 'UNGM_USERNAME',          'category' => 'portals',      'desc' => ''],
            ['env' => 'UNGM_PASSWORD',          'category' => 'portals',      'desc' => ''],
            ['env' => 'PY_PROXY_SHARED_KEY',    'category' => 'security',     'desc' => 'HMAC para split-VM proxy auth'],
        ];

        $out = [];
        foreach ($items as $item) {
            $val = env($item['env']);
            $set = $val !== null && $val !== '';
            $out[] = [
                'env'        => $item['env'],
                'category'   => $item['category'],
                'desc'       => $item['desc'],
                'set'        => $set,
                'preview'    => $set ? $this->maskValue((string) $val) : null,
            ];
        }
        // Group by category.
        $grouped = [];
        foreach ($out as $row) {
            $grouped[$row['category']] = $grouped[$row['category']] ?? [];
            $grouped[$row['category']][] = $row;
        }
        return $grouped;
    }

    /**
     * Mask a secret so the admin can identify it without seeing the
     * value. Preserves the last 4 chars; everything else becomes •.
     * For very short values (URLs, booleans) we just show the value.
     */
    private function maskValue(string $value): string
    {
        $value = trim($value);
        // Booleans + obvious "is configured" tokens — show them, they're
        // not secret. Helps admin distinguish "true" from "yes" etc.
        $low = strtolower($value);
        if (in_array($low, ['true', 'false', '0', '1', 'on', 'off', 'yes', 'no', 'log', 'smtp', 'redis', 'database', 'file', 'sync', 'array'], true)) {
            return $value;
        }
        // URLs — show the host, hide the rest.
        if (preg_match('~^https?://([^/]+)~i', $value, $m)) {
            return 'https://' . $m[1] . '/…';
        }
        $len = mb_strlen($value);
        if ($len <= 8) return str_repeat('•', $len);
        $tail = mb_substr($value, -4);
        return str_repeat('•', min(20, $len - 4)) . $tail;
    }
}
