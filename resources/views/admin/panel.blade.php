<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-3 flex-wrap">
            <h2 class="text-xl font-semibold leading-tight text-gray-800 flex items-center gap-2">
                ⚙️ Admin Panel
                @php
                    $overallTone = match($overall['level']) {
                        'up'       => 'bg-emerald-100 text-emerald-800',
                        'degraded' => 'bg-amber-100 text-amber-800',
                        default    => 'bg-red-100 text-red-800',
                    };
                    $overallText = match($overall['level']) {
                        'up'       => 'Tudo OK',
                        'degraded' => 'Degradado',
                        default    => 'Falhas detectadas',
                    };
                @endphp
                <span class="inline-flex items-center gap-1 rounded-full {{ $overallTone }} px-2 py-0.5 text-[10px] font-bold">
                    <span class="inline-block w-1.5 h-1.5 rounded-full bg-current animate-pulse"></span>
                    {{ $overallText }}
                </span>
            </h2>
            <div class="flex items-center gap-2 text-xs text-gray-500">
                <span>Último probe: {{ $lastRefresh->format('H:i:s') }}</span>
                <form method="POST" action="{{ route('admin.panel.refresh') }}" class="inline">
                    @csrf
                    <button type="submit" class="rounded-md border border-gray-300 px-2 py-1 text-xs hover:bg-gray-50">↻ Re-correr probes</button>
                </form>
            </div>
        </div>
    </x-slot>

    <div class="py-6">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 space-y-4">

            @if(session('status'))
                <div class="rounded-md bg-emerald-50 border border-emerald-200 px-4 py-2 text-sm text-emerald-800">{{ session('status') }}</div>
            @endif

            {{-- ── 0. GESTÃO DE UTILIZADORES — acesso rápido ───────────── --}}
            @php
                $totalUsers  = \App\Models\User::where('is_active', true)->count();
                $adminCount  = \App\Models\User::where('role','admin')->count();
                $managerCount= \App\Models\User::where('role','manager')->count();
            @endphp
            <section class="rounded-lg bg-white border border-gray-100 shadow-sm overflow-hidden">
                <header class="px-4 py-3 border-b border-gray-100 bg-gradient-to-r from-violet-50 to-white flex items-center justify-between">
                    <div>
                        <h3 class="text-sm font-semibold text-gray-800">👥 Gestão de Utilizadores</h3>
                        <p class="text-xs text-gray-500 mt-0.5">
                            {{ $totalUsers }} utilizadores activos ·
                            {{ $adminCount }} admin · {{ $managerCount }} manager
                        </p>
                    </div>
                    <a href="/admin/users"
                       class="inline-flex items-center gap-1 rounded-md bg-violet-600 px-3 py-1.5 text-xs font-bold text-white hover:bg-violet-500 transition-colors">
                        + Criar utilizador
                    </a>
                </header>
                <div class="grid grid-cols-1 sm:grid-cols-3 divide-y sm:divide-y-0 sm:divide-x divide-gray-100">

                    {{-- Card 1 — Lista de utilizadores --}}
                    <a href="/admin/users"
                       class="flex items-start gap-3 px-5 py-4 hover:bg-violet-50/60 transition-colors group no-underline">
                        <span class="text-2xl mt-0.5">👥</span>
                        <div>
                            <div class="text-sm font-semibold text-gray-800 group-hover:text-violet-700">Utilizadores</div>
                            <div class="text-xs text-gray-500 mt-0.5">Ver, criar e editar contas · alterar role · activar/desactivar</div>
                            <div class="mt-2 inline-flex items-center gap-1 rounded bg-gray-100 px-2 py-0.5 text-[11px] text-gray-600">
                                {{ $totalUsers }} activos
                            </div>
                        </div>
                    </a>

                    {{-- Card 2 — Matriz de agentes --}}
                    <a href="/admin/agent-access"
                       class="flex items-start gap-3 px-5 py-4 hover:bg-blue-50/60 transition-colors group no-underline">
                        <span class="text-2xl mt-0.5">🎭</span>
                        <div>
                            <div class="text-sm font-semibold text-gray-800 group-hover:text-blue-700">Agentes por Utilizador</div>
                            <div class="text-xs text-gray-500 mt-0.5">Seleccionar quais agentes cada user pode usar · presets rápidos</div>
                            <div class="mt-2 inline-flex items-center gap-1 rounded bg-gray-100 px-2 py-0.5 text-[11px] text-gray-600">
                                Matriz users × agentes
                            </div>
                        </div>
                    </a>

                    {{-- Card 3 — Matriz de navegação --}}
                    <a href="/admin/nav-access"
                       class="flex items-start gap-3 px-5 py-4 hover:bg-emerald-50/60 transition-colors group no-underline">
                        <span class="text-2xl mt-0.5">🗺️</span>
                        <div>
                            <div class="text-sm font-semibold text-gray-800 group-hover:text-emerald-700">Navegação por Utilizador</div>
                            <div class="text-xs text-gray-500 mt-0.5">Mostrar/ocultar secções do menu · Reports, Patents, Intel Bus…</div>
                            <div class="mt-2 inline-flex items-center gap-1 rounded bg-gray-100 px-2 py-0.5 text-[11px] text-gray-600">
                                15 secções configuráveis
                            </div>
                        </div>
                    </a>

                </div>
            </section>

            {{-- ── 1. OVERVIEW STRIP ────────────────────────────────────── --}}
            @php
                $up        = $overall['up'];
                $degraded  = $overall['degraded'];
                $down      = $overall['down'];
                $missing   = $overall['missing'];
                $totalCheck= max(1, $up + $degraded + $down + $missing);
            @endphp
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-2">
                @include('partials.ring-chart', ['label' => 'Up',         'value' => $up,        'total' => $totalCheck, 'tone' => 'emerald', 'subline' => 'Probes a responder'])
                @include('partials.ring-chart', ['label' => 'Degraded',   'value' => $degraded,  'total' => $totalCheck, 'tone' => 'amber',   'subline' => 'A funcionar com avisos'])
                @include('partials.ring-chart', ['label' => 'Down',       'value' => $down,      'total' => $totalCheck, 'tone' => 'red',     'subline' => 'A intervir já'])
                @include('partials.ring-chart', ['label' => 'Não config.','value' => $missing,   'total' => $totalCheck, 'tone' => 'gray',    'subline' => 'Opcionais sem chave'])
            </div>

            {{-- ── 2. INTEGRATIONS HEALTH ───────────────────────────────── --}}
            <section class="rounded-lg bg-white border border-gray-100 shadow-sm overflow-hidden">
                <header class="px-4 py-3 border-b border-gray-100 bg-gradient-to-r from-indigo-50 to-white">
                    <h3 class="text-sm font-semibold text-gray-800">🔌 Integrações</h3>
                    <p class="text-xs text-gray-500 mt-0.5">Probes em tempo real (cache 60s). Falhas aparecem em vermelho.</p>
                </header>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-100 text-sm">
                        <thead class="bg-gray-50 text-xs uppercase text-gray-600">
                            <tr>
                                <th class="px-3 py-2 text-left">Serviço</th>
                                <th class="px-3 py-2 text-left">Estado</th>
                                <th class="px-3 py-2 text-left">Detalhe</th>
                                <th class="px-3 py-2 text-right">Latência</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @php
                                $serviceLabels = [
                                    'database'   => ['🗄️', 'PostgreSQL'],
                                    'cache'      => ['⚡', 'Cache'],
                                    'queue'      => ['📋', 'Queue worker'],
                                    'mail'       => ['✉', 'Mail (SMTP)'],
                                    'sap_b1'     => ['🏢', 'SAP Business One'],
                                    'hp_history' => ['🧠', 'hp-history (pgvector)'],
                                    'tavily'     => ['🔍', 'Tavily web search'],
                                    'llm_proxy'  => ['🛡️', 'LLM proxy (PII redactor)'],
                                    'anthropic'  => ['🤖', 'Anthropic Claude'],
                                    'epo'        => ['📜', 'EPO Patents'],
                                    'whatsapp'   => ['💬', 'WhatsApp Business'],
                                ];
                                $stateBadge = [
                                    'up'             => ['bg-emerald-100 text-emerald-800', '✓ UP'],
                                    'degraded'       => ['bg-amber-100   text-amber-800',   '⚠ DEGRADED'],
                                    'down'           => ['bg-red-100     text-red-800',     '✗ DOWN'],
                                    'not_configured' => ['bg-gray-100    text-gray-600',    '· N/C'],
                                ];
                            @endphp
                            @foreach($report as $key => $check)
                                @php
                                    [$emoji, $label] = $serviceLabels[$key] ?? ['·', ucfirst($key)];
                                    [$badgeCls, $badgeText] = $stateBadge[$check['state']] ?? $stateBadge['down'];
                                @endphp
                                <tr class="hover:bg-gray-50 align-middle">
                                    <td class="px-3 py-2 whitespace-nowrap">
                                        <span class="text-base">{{ $emoji }}</span>
                                        <span class="font-medium text-gray-800 ml-1">{{ $label }}</span>
                                    </td>
                                    <td class="px-3 py-2 whitespace-nowrap">
                                        <span class="inline-block rounded-full {{ $badgeCls }} px-2 py-0.5 text-[10px] font-bold">{{ $badgeText }}</span>
                                    </td>
                                    <td class="px-3 py-2 text-xs text-gray-600">{{ $check['detail'] ?? '—' }}</td>
                                    <td class="px-3 py-2 text-xs text-right font-mono text-gray-500">
                                        {{ $check['latency_ms'] !== null ? $check['latency_ms'] . ' ms' : '—' }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>

            {{-- ── 3. CRON SCHEDULE ─────────────────────────────────────── --}}
            <section class="rounded-lg bg-white border border-gray-100 shadow-sm overflow-hidden">
                <header class="px-4 py-3 border-b border-gray-100 bg-gradient-to-r from-amber-50 to-white">
                    <h3 class="text-sm font-semibold text-gray-800">⏰ Tarefas agendadas (cron)</h3>
                    <p class="text-xs text-gray-500 mt-0.5">Output ao vivo de <code>php artisan schedule:list</code>.</p>
                </header>
                @if(empty($crons))
                    <div class="px-4 py-6 text-sm text-gray-500 text-center">Sem tarefas registadas.</div>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-100 text-sm">
                            <thead class="bg-gray-50 text-xs uppercase text-gray-600">
                                <tr>
                                    <th class="px-3 py-2 text-left">Cron</th>
                                    <th class="px-3 py-2 text-left">Comando</th>
                                    <th class="px-3 py-2 text-left">Próximo</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @foreach($crons as $row)
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-3 py-2 font-mono text-xs text-gray-700">{{ $row['expr'] }}</td>
                                        <td class="px-3 py-2 text-xs text-gray-800 font-mono">{{ $row['cmd'] }}</td>
                                        <td class="px-3 py-2 text-xs text-gray-500">{{ $row['next'] ?: '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>

            {{-- ── 4. FEATURE FLAGS ─────────────────────────────────────── --}}
            <section class="rounded-lg bg-white border border-gray-100 shadow-sm overflow-hidden">
                <header class="px-4 py-3 border-b border-gray-100 bg-gradient-to-r from-emerald-50 to-white">
                    <h3 class="text-sm font-semibold text-gray-800">🎚️ Feature flags + configurações</h3>
                    <p class="text-xs text-gray-500 mt-0.5">Toggles operacionais. Persistem em <code>app_settings</code>.</p>
                </header>
                <div class="divide-y divide-gray-100">
                    @foreach($flagsByCat as $cat => $flags)
                        <div class="px-4 py-3">
                            <div class="text-[10px] uppercase tracking-wider text-gray-500 font-bold mb-2">{{ str_replace('_', ' ', $cat) }}</div>
                            <ul class="space-y-2">
                                @foreach($flags as $flag)
                                    <li class="flex items-start justify-between gap-3 py-1">
                                        <div class="flex-1 min-w-0">
                                            <div class="text-sm font-medium text-gray-800 font-mono">{{ $flag['key'] }}</div>
                                            <div class="text-xs text-gray-500">{{ $flag['description'] }}</div>
                                            @if($flag['updated_at'])
                                                <div class="text-[10px] text-gray-400 mt-0.5">Última edição: {{ $flag['updated_at'] }}</div>
                                            @endif
                                        </div>
                                        <form method="POST" action="{{ route('admin.panel.flag') }}" class="inline">
                                            @csrf
                                            <input type="hidden" name="key" value="{{ $flag['key'] }}">
                                            @if($flag['type'] === 'bool')
                                                <input type="hidden" name="value" value="{{ $flag['value'] ? '0' : '1' }}">
                                                <button type="submit" class="inline-flex items-center gap-1 rounded-full px-3 py-1 text-xs font-bold {{ $flag['value'] ? 'bg-emerald-600 text-white hover:bg-emerald-500' : 'bg-gray-200 text-gray-700 hover:bg-gray-300' }}">
                                                    {{ $flag['value'] ? '✓ Ligado' : '○ Desligado' }}
                                                </button>
                                            @else
                                                <input type="number" name="value" value="{{ $flag['value'] }}"
                                                       class="w-20 rounded-md border-gray-300 text-xs"
                                                       onchange="this.form.submit()">
                                            @endif
                                        </form>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endforeach
                </div>
            </section>

            {{-- ── 5. SECRETS (masked) ──────────────────────────────────── --}}
            <section class="rounded-lg bg-white border border-gray-100 shadow-sm overflow-hidden">
                <header class="px-4 py-3 border-b border-gray-100 bg-gradient-to-r from-red-50 to-white">
                    <h3 class="text-sm font-semibold text-gray-800">🔐 Secrets / chaves</h3>
                    <p class="text-xs text-gray-500 mt-0.5">
                        Inventário de variáveis de ambiente esperadas. Valores são <strong>sempre mascarados</strong>
                        (últimos 4 caracteres apenas). Para editar, faz SSH ao Forge:
                        <code class="text-gray-700">vim /home/forge/clawyard.partyard.eu/.env</code> +
                        <code class="text-gray-700">php artisan config:cache</code>.
                    </p>
                </header>
                <div class="divide-y divide-gray-100">
                    @php
                        $catLabels = [
                            'core'      => '🔑 Core',
                            'llm'       => '🤖 LLM',
                            'search'    => '🔍 Search',
                            'erp'       => '🏢 ERP / SAP',
                            'memory'    => '🧠 Memória',
                            'mail'      => '✉ Mail',
                            'cloud'     => '☁️ Cloud / S3',
                            'messaging' => '💬 Messaging',
                            'portals'   => '📜 Portais',
                            'security'  => '🛡️ Security',
                        ];
                    @endphp
                    @foreach($secrets as $cat => $entries)
                        <div class="px-4 py-3">
                            <div class="text-[10px] uppercase tracking-wider text-gray-500 font-bold mb-2">
                                {{ $catLabels[$cat] ?? $cat }}
                            </div>
                            <div class="grid grid-cols-1 lg:grid-cols-2 gap-2">
                                @foreach($entries as $s)
                                    <div class="rounded-md border {{ $s['set'] ? 'border-emerald-200 bg-emerald-50/40' : 'border-gray-200 bg-gray-50' }} px-3 py-2">
                                        <div class="flex items-center justify-between gap-2">
                                            <code class="text-xs font-mono text-gray-800">{{ $s['env'] }}</code>
                                            @if($s['set'])
                                                <span class="text-[10px] font-bold text-emerald-700">✓ SET</span>
                                            @else
                                                <span class="text-[10px] font-bold text-gray-500">○ NÃO DEFINIDO</span>
                                            @endif
                                        </div>
                                        @if($s['set'])
                                            <div class="text-[11px] font-mono text-gray-500 mt-1 break-all">{{ $s['preview'] }}</div>
                                        @endif
                                        @if($s['desc'])
                                            <div class="text-[11px] text-gray-500 mt-1">{{ $s['desc'] }}</div>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            </section>
        </div>
    </div>
</x-app-layout>
