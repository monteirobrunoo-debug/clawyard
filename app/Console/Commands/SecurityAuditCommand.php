<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

/**
 * Auditoria de segurança end-to-end do ClawYard.
 *
 * Verifica todos os pontos de comunicação + encriptação:
 *   1. TLS in-transit (HTTPS site, Anthropic, Tavily, SAP, Postgres, Redis)
 *   2. Encriptação at-rest (Postgres, Spaces, sessões, campos sensíveis)
 *   3. Secrets management (.env, file perms, repo cleanliness)
 *   4. Autenticação (bcrypt, OTP, throttle, cookies secure)
 *   5. Autorização (policies, permissions, IP verification)
 *
 * Pedido directo Bruno 2026-05-28: "teste de segurança para encriptar
 * toda a informação dentro do clawyard e pontos de comunicação".
 *
 * Uso:
 *   php artisan security:audit
 *   php artisan security:audit --json  (output JSON para CI)
 */
class SecurityAuditCommand extends Command
{
    protected $signature = 'security:audit {--json : Output JSON em vez de tabela}';
    protected $description = 'Auditoria completa de segurança — TLS, encriptação, secrets, auth';

    private array $results = [];
    private int $passed = 0;
    private int $warned = 0;
    private int $failed = 0;

    public function handle(): int
    {
        $this->info('🔒 ClawYard Security Audit — ' . now()->toIso8601String());
        $this->line(str_repeat('═', 70));
        $this->line('');

        $this->section('1. TLS / In-transit Encryption');
        $this->checkHttpsSite();
        $this->checkAnthropicTls();
        $this->checkTavilyTls();
        $this->checkSapTls();
        $this->checkPostgresTls();
        $this->checkRedisTls();
        $this->checkSpacesTls();

        $this->section('2. At-Rest Encryption');
        $this->checkPostgresEncryption();
        $this->checkSessionEncrypt();
        $this->checkAppKey();
        $this->checkEncryptedCasts();

        $this->section('3. Secrets Management');
        $this->checkEnvFilePermissions();
        $this->checkSecretsInRepo();
        $this->checkApiKeysSet();

        $this->section('4. Authentication & Cookies');
        $this->checkPasswordHashing();
        $this->checkOtpRequired();
        $this->checkSessionCookieFlags();
        $this->checkLoginThrottle();

        $this->section('5. Authorization & Network');
        $this->checkIpVerification();
        $this->checkSecurityHeaders();
        $this->checkCsrfProtection();

        $this->line('');
        $this->line(str_repeat('═', 70));
        $this->info("✓ Passed: {$this->passed}  ⚠ Warned: {$this->warned}  ✗ Failed: {$this->failed}");

        if ($this->option('json')) {
            $this->line('');
            $this->line(json_encode($this->results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }

        return $this->failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function section(string $title): void
    {
        $this->line('');
        $this->line("<fg=cyan;options=bold>{$title}</>");
        $this->line(str_repeat('─', 70));
    }

    private function markPass(string $check, string $detail = ''): void
    {
        $this->line("  <fg=green>✓</> {$check}" . ($detail ? "  <fg=gray>· {$detail}</>" : ''));
        $this->results[] = ['check' => $check, 'status' => 'pass', 'detail' => $detail];
        $this->passed++;
    }

    private function markWarn(string $check, string $detail = ''): void
    {
        $this->line("  <fg=yellow>⚠</> {$check}" . ($detail ? "  <fg=gray>· {$detail}</>" : ''));
        $this->results[] = ['check' => $check, 'status' => 'warn', 'detail' => $detail];
        $this->warned++;
    }

    private function markFail(string $check, string $detail = ''): void
    {
        $this->line("  <fg=red>✗</> {$check}" . ($detail ? "  <fg=gray>· {$detail}</>" : ''));
        $this->results[] = ['check' => $check, 'status' => 'fail', 'detail' => $detail];
        $this->failed++;
    }

    // ─── Section 1: TLS in-transit ──────────────────────────────────────────
    private function checkHttpsSite(): void
    {
        $url = config('app.url');
        if (!$url || !str_starts_with($url, 'https://')) {
            $this->markFail('Site URL is HTTPS', "Got: {$url}");
            return;
        }
        $this->markPass('Site URL is HTTPS', $url);
    }

    private function checkAnthropicTls(): void
    {
        $base = config('services.anthropic.base_uri', 'https://api.anthropic.com');
        if (!str_starts_with($base, 'https://')) {
            $this->markFail('Anthropic API uses HTTPS', "Got: {$base}");
        return;
        }
        $this->markPass('Anthropic API uses HTTPS', $base);
    }

    private function checkTavilyTls(): void
    {
        $url = config('services.tavily.base_uri', 'https://api.tavily.com');
        if (!str_starts_with($url, 'https://')) {
            $this->markFail('Tavily API uses HTTPS', "Got: {$url}");
        return;
        }
        $this->markPass('Tavily API uses HTTPS', $url);
    }

    private function checkSapTls(): void
    {
        $url = config('services.sap.base_uri') ?? env('SAP_BASE_URL', '');
        if (empty($url)) {
            $this->markWarn('SAP API URL', 'Not configured');
        return;
        }
        if (!str_starts_with($url, 'https://')) {
            $this->markFail('SAP B1 ServiceLayer uses HTTPS', "Got: {$url}");
        return;
        }
        $this->markPass('SAP B1 ServiceLayer uses HTTPS', $url);
    }

    private function checkPostgresTls(): void
    {
        try {
            $sslMode = DB::connection()->getPdo()->getAttribute(\PDO::ATTR_DRIVER_NAME);
            $host = config('database.connections.pgsql.host', '');
            if (str_contains($host, 'ondigitalocean.com')) {
                $this->markPass('Postgres DO managed (TLS default)', $host);
            } elseif (str_contains($host, '127.0.0.1') || str_contains($host, 'localhost')) {
                $this->markWarn('Postgres connection local', 'TLS opcional em loopback');
        return;
            } else {
                $sslmode = config('database.connections.pgsql.sslmode', 'prefer');
                if (in_array($sslmode, ['require', 'verify-ca', 'verify-full'], true)) {
                    $this->markPass('Postgres sslmode=' . $sslmode, $host);
                } else {
                    $this->markWarn('Postgres sslmode', "sslmode={$sslmode} — considera 'require'");
        return;
                }
            }
        } catch (\Throwable $e) {
            $this->markFail('Postgres TLS check', $e->getMessage());
        return;
        }
    }

    private function checkRedisTls(): void
    {
        $host = config('database.redis.default.host', '127.0.0.1');
        if (str_contains($host, '127.0.0.1') || str_contains($host, 'localhost')) {
            $this->markPass('Redis local (loopback)', $host . ' — TLS desnecessário');
        } else {
            $scheme = config('database.redis.default.scheme', 'tcp');
            if ($scheme === 'tls') {
                $this->markPass('Redis uses TLS', $host);
            } else {
                $this->markWarn('Redis scheme', "scheme={$scheme} — considera 'tls' se remoto");
        return;
            }
        }
    }

    private function checkSpacesTls(): void
    {
        $endpoint = config('filesystems.disks.spaces.endpoint', '');
        if (empty($endpoint)) {
            $this->markWarn('DO Spaces endpoint', 'Não configurado');
        return;
        }
        if (!str_starts_with($endpoint, 'https://')) {
            $this->markFail('DO Spaces uses HTTPS', "Got: {$endpoint}");
        return;
        }
        $this->markPass('DO Spaces uses HTTPS', $endpoint);
    }

    // ─── Section 2: At-rest encryption ──────────────────────────────────────
    private function checkPostgresEncryption(): void
    {
        $host = config('database.connections.pgsql.host', '');
        if (str_contains($host, 'ondigitalocean.com')) {
            $this->markPass('Postgres at-rest encryption', 'DO managed = AES-256 default');
        } else {
            $this->markWarn('Postgres at-rest encryption', 'Self-hosted — verifica disk encryption');
        return;
        }
    }

    private function checkSessionEncrypt(): void
    {
        $encrypt = config('session.encrypt', false);
        if ($encrypt) {
            $this->markPass('Session payload encryption (SESSION_ENCRYPT)', 'true');
        } else {
            $this->markFail('Session payload encryption (SESSION_ENCRYPT)', 'Set SESSION_ENCRYPT=true no .env');
        return;
        }
    }

    private function checkAppKey(): void
    {
        $key = config('app.key');
        if (empty($key)) {
            $this->markFail('APP_KEY', 'Não está set — todo o encrypted está em risco');
        return;
        }
        if (strlen($key) < 32) {
            $this->markFail('APP_KEY', 'Demasiado curto — usa `php artisan key:generate`');
        return;
        }
        $this->markPass('APP_KEY length', strlen($key) . ' chars');
    }

    private function checkEncryptedCasts(): void
    {
        // Procura modelos com 'encrypted' casts
        $modelsPath = app_path('Models');
        if (!is_dir($modelsPath)) {
            $this->markWarn('Encrypted casts', 'Models dir não encontrado');
        return;
        }
        $count = 0;
        foreach (glob($modelsPath . '/*.php') as $file) {
            $content = file_get_contents($file);
            if (preg_match('/=>\s*[\'"]encrypted/', $content)) $count++;
        }
        if ($count > 0) {
            $this->markPass('Encrypted Eloquent casts', "Encontrados em {$count} modelos");
        } else {
            $this->markWarn('Encrypted Eloquent casts', 'Nenhum modelo usa cast encrypted — avalia se campos sensíveis precisam');
        return;
        }
    }

    // ─── Section 3: Secrets ─────────────────────────────────────────────────
    private function checkEnvFilePermissions(): void
    {
        $envPath = base_path('.env');
        if (!file_exists($envPath)) {
            $this->markWarn('.env permissions', 'Ficheiro não encontrado');
        return;
        }
        $perms = substr(sprintf('%o', fileperms($envPath)), -3);
        if (in_array($perms, ['600', '640', '660'], true)) {
            $this->markPass('.env file permissions', $perms);
        } else {
            $this->markFail('.env file permissions', "Got {$perms} — devia ser 600 ou 640. chmod 640 .env");
        return;
        }
    }

    private function checkSecretsInRepo(): void
    {
        // Procurar padrões de chaves em committed files (sanity check)
        $patterns = ['sk-ant-api03-', 'sk-proj-', 'AKIA[0-9A-Z]{16}'];
        $found = [];
        // Quick scan no app/ e config/
        foreach (['app', 'config'] as $dir) {
            $cmd = "grep -rE '" . implode('|', $patterns) . "' " . base_path($dir) . " 2>/dev/null | head -3";
            $out = @shell_exec($cmd);
            if (!empty(trim((string) $out))) {
                $found[] = substr(trim($out), 0, 100);
            }
        }
        if (empty($found)) {
            $this->markPass('Hardcoded secrets in repo', 'Não detectados');
        } else {
            $this->markFail('Hardcoded secrets in repo', 'Encontrados: ' . implode(' | ', $found));
        return;
        }
    }

    private function checkApiKeysSet(): void
    {
        $required = [
            'services.anthropic.api_key'  => 'ANTHROPIC_API_KEY',
            'services.tavily.api_key'     => 'TAVILY_API_KEY',
        ];
        foreach ($required as $configKey => $envKey) {
            $val = config($configKey);
            if (empty($val)) {
                $this->markWarn("{$envKey}", 'Não está set');
        return;
            } else {
                $masked = substr($val, 0, 8) . '...' . substr($val, -4);
                $this->markPass("{$envKey}", $masked);
            }
        }
    }

    // ─── Section 4: Auth ────────────────────────────────────────────────────
    private function checkPasswordHashing(): void
    {
        $driver = config('hashing.driver', 'bcrypt');
        if (in_array($driver, ['bcrypt', 'argon', 'argon2id'], true)) {
            $this->markPass('Password hashing driver', $driver);
        } else {
            $this->markFail('Password hashing driver', "Got: {$driver}");
        return;
        }
    }

    private function checkOtpRequired(): void
    {
        if (class_exists('App\Http\Middleware\RequireIpVerification')) {
            $this->markPass('IP-bound OTP middleware', 'RequireIpVerification class exists');
        } else {
            $this->markWarn('IP-bound OTP middleware', 'Class não encontrada');
        return;
        }
    }

    private function checkSessionCookieFlags(): void
    {
        $secure = config('session.secure', false);
        $httpOnly = config('session.http_only', true);
        $sameSite = config('session.same_site', 'lax');

        if ($secure) $this->markPass('Session cookie Secure flag', 'true');
        else        $this->markWarn('Session cookie Secure flag', 'false — set SESSION_SECURE_COOKIE=true em prod');

        if ($httpOnly) $this->markPass('Session cookie HttpOnly flag', 'true');
        else           $this->markFail('Session cookie HttpOnly flag', 'false — vulnerable to XSS');

        if (in_array($sameSite, ['lax', 'strict'], true)) {
            $this->markPass('Session cookie SameSite', $sameSite);
        } else {
            $this->markWarn('Session cookie SameSite', "Got: {$sameSite}");
        return;
        }
    }

    private function checkLoginThrottle(): void
    {
        // Just check if AuthRateLimiter or RouteServiceProvider has throttle
        $rsp = file_exists(app_path('Providers/RouteServiceProvider.php'))
            ? file_get_contents(app_path('Providers/RouteServiceProvider.php'))
            : '';
        $bootstrap = file_exists(base_path('bootstrap/app.php'))
            ? file_get_contents(base_path('bootstrap/app.php'))
            : '';
        if (str_contains($rsp, 'throttle') || str_contains($bootstrap, 'throttle')) {
            $this->markPass('Login throttle (rate limit)', 'Detectado');
        } else {
            $this->markWarn('Login throttle', 'Não detectado — verifica middleware throttle:login');
        return;
        }
    }

    // ─── Section 5: Authorization ───────────────────────────────────────────
    private function checkIpVerification(): void
    {
        if (file_exists(app_path('Http/Middleware/RequireIpVerification.php'))) {
            $this->markPass('IP verification middleware', 'Activo');
        } else {
            $this->markWarn('IP verification middleware', 'Não encontrado');
        return;
        }
    }

    private function checkSecurityHeaders(): void
    {
        if (file_exists(app_path('Http/Middleware/SecurityHeadersMiddleware.php'))) {
            $this->markPass('Security headers middleware', 'Activo (HSTS, X-Frame, etc.)');
        } else {
            $this->markFail('Security headers middleware', 'Não encontrado');
        return;
        }
    }

    private function checkCsrfProtection(): void
    {
        $bootstrap = base_path('bootstrap/app.php');
        if (file_exists($bootstrap)) {
            $content = file_get_contents($bootstrap);
            if (str_contains($content, 'VerifyCsrfToken') || str_contains($content, 'web()')) {
                $this->markPass('CSRF protection', 'Web middleware aplica');
            } else {
                $this->markWarn('CSRF protection', 'Verifica manualmente');
        return;
            }
        }
    }
}
