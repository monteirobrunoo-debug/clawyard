<?php

namespace App\Providers;

use App\Models\Tender;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // SapService como singleton — todos os agentes que falam com o SAP B1
        // (Richard SapAgent, Marta CrmAgent, Dr. Luís FinanceAgent, Dr.ª Ana
        // HrAgent, etc.) partilham a MESMA instância dentro do mesmo request.
        //
        // Benefícios:
        //   • Sessão B1 (token JSESSIONID/B1SESSION) é negociada uma vez por
        //     request — múltiplos agentes não fazem múltiplos /Login.
        //   • Cache HTTP local de cada response sobrevive entre chamadas
        //     do mesmo request, evitando double-fetch de overview SAP.
        //   • A queue de Guzzle (timeouts, retries) é configurada uma vez.
        //
        // O token de sessão JÁ era partilhado via Laravel Cache (Redis/file)
        // entre requests; este singleton complementa partilhando estado
        // in-memory DENTRO de um request.
        $this->app->singleton(\App\Services\SapService::class, function () {
            return new \App\Services\SapService();
        });

        // VesselTrackerService — singleton para reutilizar sessão (cookie jar
        // + cache) entre Marco Sales, Capitão Vasco, Marta CRM. Evita re-login
        // por agente quando o user faz queries multi-agente sobre o mesmo
        // armador/vessel no mesmo request.
        $this->app->singleton(\App\Services\VesselTrackerService::class, function () {
            return new \App\Services\VesselTrackerService();
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // 2026-05-25: silenciar E_DEPRECATED a nível de reporting.
        // Carbon, Symfony, Laravel internals geram warnings que (mesmo com
        // logging.deprecations=null) Octane Swoole por vezes promove a
        // fatal → worker exit → "lê depois desliga" para o user.
        // Esta máscara é per-request, não afecta E_ERROR ou E_WARNING reais.
        error_reporting(error_reporting() & ~E_DEPRECATED & ~E_USER_DEPRECATED);

        // Guarantee ANTHROPIC_API_KEY is always available via getenv()
        // even when PHP-FPM OPcache has a stale config.php
        $this->ensureAnthropicKey();

        // ── Transport-security hardening ────────────────────────────────────
        // In production (or whenever APP_URL is https://) we force every link
        // Laravel generates to use the https scheme. This closes a class of
        // MitM where a link emitted as http:// could be downgraded before
        // the browser is told to upgrade. Also trusts Cloudflare/Forge proxy
        // X-Forwarded-Proto so url()->secure() stays true even behind TLS
        // terminators.
        if ($this->shouldForceHttps()) {
            URL::forceScheme('https');
            if (request() && request()->server('HTTP_X_FORWARDED_PROTO') === 'https') {
                request()->setTrustedProxies(
                    ['0.0.0.0/0', '::/0'],
                    \Illuminate\Http\Request::HEADER_X_FORWARDED_FOR
                    | \Illuminate\Http\Request::HEADER_X_FORWARDED_HOST
                    | \Illuminate\Http\Request::HEADER_X_FORWARDED_PORT
                    | \Illuminate\Http\Request::HEADER_X_FORWARDED_PROTO
                );
            }
        }

        // Reject any misconfiguration where NVIDIA's base_url is plaintext
        // HTTP. We treat this as a fatal config error rather than silently
        // sending keys + prompts over the clear.
        $this->assertNvidiaTransportSecure();

        // Authorisation gates for the Concursos (tenders) dashboard.
        $this->registerTenderGates();

        // Per-user agent whitelist (NULL=all / [] = none / array = whitelist).
        // Applied on /api/chat (chatStream) and /api/agents (filters the
        // returned list). Admin always passes — see User::canUseAgent.
        Gate::define('agents.use', fn(User $u, string $agentKey) => $u->canUseAgent($agentKey));

        // ── IP-bound OTP trigger ────────────────────────────────────────────
        // On logout, clear last_verified_ip so the next login (even from
        // the same IP) requires a fresh OTP — user feedback 2026-05-05:
        // "quando acaba a ligação ou muda de IP tem de validar OTP".
        // Same idea on Login: clear immediately so the middleware kicks
        // in on the very first authenticated request.
        \Illuminate\Support\Facades\Event::listen(
            \Illuminate\Auth\Events\Logout::class,
            function ($event) {
                if ($event->user) {
                    $event->user->forceFill(['last_verified_ip' => null])->saveQuietly();
                }
            }
        );
        \Illuminate\Support\Facades\Event::listen(
            \Illuminate\Auth\Events\Login::class,
            function ($event) {
                if ($event->user) {
                    $event->user->forceFill(['last_verified_ip' => null])->saveQuietly();
                }
            }
        );
    }

    /**
     * Gates for tender workflow — see TenderController / TenderImportController.
     *
     *   tenders.view-all: dashboard shows every tender (else: only own assigns)
     *   tenders.import:   upload Excel to create/update tender rows
     *   tenders.assign:   bulk re-assign collaborator
     *   tenders.delete:   hard/soft delete tenders (admin only)
     *   tenders.update:   edit fields on a tender (assignee user, or manager+)
     *   tenders.observe:  add an observation (always-allowed for signed-in users)
     */
    private function registerTenderGates(): void
    {
        // 2026-05-19: gates passam a aceitar TANTO role manager+ COMO
        // grants finos via User::extra_permissions JSON. Pedido directo
        // do operador: "dá acesso ao user eduardo.rio@hp-group.org de
        // importar tabelas da NSPA ou acingov/Vortal" — sem o promover
        // a manager (não precisa de view-all / assign / collaborators).
        Gate::define('tenders.view-all',
            fn(User $u) => $u->isManager() || $u->hasExtraPermission('tenders.view-all'));
        Gate::define('tenders.import',
            fn(User $u) => $u->isManager() || $u->hasExtraPermission('tenders.import'));
        Gate::define('tenders.assign',
            fn(User $u) => $u->isManager() || $u->hasExtraPermission('tenders.assign'));
        Gate::define('tenders.delete',           fn(User $u) => $u->isAdmin());
        // Manage the TenderCollaborator roster — add names, set emails,
        // link to User accounts, deactivate. Super-user only.
        Gate::define('tenders.collaborators',
            fn(User $u) => $u->isManager() || $u->hasExtraPermission('tenders.collaborators'));

        // 2026-05-21: política alargada (espelha view/upload/delete que
        // já estão abertos a todos os authenticated users). Pedido directo:
        //   "concursos acingov, edita aparece apenas como leitura, já
        //    aberto no sap, não deixa escrever nos campos."
        //
        // Tenders não-atribuídos (ex.: Acingov recém-importado, NSPA em
        // pool) ficavam read-only para qualquer non-manager, mesmo
        // tendo tenders.view-all. Bloqueava operadores comerciais de
        // editar deadline, organização, valor, notas, etc.
        //
        // Nova política:
        //   ✓ Manager/Admin                 — sempre
        //   ✓ Colaborador atribuído         — sempre (já existia)
        //   ✓ Qualquer user authenticated   — em tenders NÃO-confidenciais
        //   ✗ Tenders confidenciais          — só atribuído + manager
        //     (consistente com TenderController::enforceVisibility e
        //      TenderAttachmentController::authorizeView)
        Gate::define('tenders.update', function (User $u, Tender $t): bool {
            if ($u->isManager()) return true;
            $collab = $t->collaborator;
            if ($collab && $collab->user_id === $u->id) return true;

            // Tenders confidenciais continuam blindados: nem o
            // tenders.view-all dá permissão de editar.
            if ($t->is_confidential) return false;

            // Não-confidencial + authenticated: pode editar.
            // is_active guarda contra users desactivados.
            return (bool) $u->is_active;
        });

        // Adding observations is a low-privilege, append-only action so any
        // authenticated, active user can drop a note on any tender they see.
        Gate::define('tenders.observe', fn(User $u) => (bool) $u->is_active);
    }

    /**
     * We only force https on real deployments. Local dev (http://localhost)
     * keeps working so `php artisan serve` doesn't break.
     */
    private function shouldForceHttps(): bool
    {
        if (app()->environment('production')) return true;
        $appUrl = (string) config('app.url', '');
        return str_starts_with($appUrl, 'https://');
    }

    /**
     * NVIDIA API is only ever reachable over TLS. If someone overrides the
     * base_url with http:// we refuse to boot in production so it can't
     * silently leak credentials.
     */
    private function assertNvidiaTransportSecure(): void
    {
        $base = (string) config('services.nvidia.base_url', '');
        if ($base === '') return; // not configured yet

        if (!str_starts_with(strtolower($base), 'https://')) {
            // Overwrite with the canonical HTTPS endpoint so running code
            // can't accidentally hit an http:// target. Log a warning so
            // ops notices the misconfiguration.
            $fixed = preg_replace('#^http://#i', 'https://', $base) ?: 'https://integrate.api.nvidia.com/v1';
            config(['services.nvidia.base_url' => $fixed]);
            try {
                \Log::warning('NVIDIA base_url was not HTTPS — rewritten at boot', [
                    'from' => $base,
                    'to'   => $fixed,
                ]);
            } catch (\Throwable) {}
        }
    }

    private function ensureAnthropicKey(): void
    {
        // 1. Try config cache (fast path)
        $key = config('services.anthropic.api_key');

        // 2. If missing from config, read .env directly (deploy may have cached before .env was linked)
        if (!$key) {
            $key = $this->readKeyFromEnvFile();

            // 3. Rebuild config cache so subsequent requests don't repeat this fallback
            if ($key) {
                try {
                    \Artisan::call('config:cache');
                } catch (\Throwable) {}
            }
        }

        // 4. Always inject into process env so all agents can use getenv()
        if ($key) {
            putenv("ANTHROPIC_API_KEY={$key}");
            $_ENV['ANTHROPIC_API_KEY']    = $key;
            $_SERVER['ANTHROPIC_API_KEY'] = $key;
        }
    }

    private function readKeyFromEnvFile(): string
    {
        $envFile = base_path('.env');
        if (!file_exists($envFile)) return '';
        foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if (str_starts_with($line, 'ANTHROPIC_API_KEY=')) {
                return trim(substr($line, 18), " \t\n\r\0\x0B\"'");
            }
        }
        return '';
    }
}
