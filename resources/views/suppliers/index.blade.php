{{--
    /suppliers — list + filter the H&P supplier directory.

    Three sources visible side-by-side via the "Origem" filter:
      • Excel 2026  → seeded from the annual approved list (812 rows)
      • Auto        → extracted from agent messages (Marco, Acingov, …)
      • Manual      → typed by a manager via /suppliers/create
--}}
<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-3 flex-wrap">
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                🏭 Fornecedores
                <span class="ml-2 text-xs font-normal text-gray-500">{{ number_format($counts['total']) }} no directório</span>
            </h2>
            @if($canEdit)
                <a href="{{ route('suppliers.create') }}"
                   class="rounded-md bg-indigo-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-indigo-500">
                    + Novo fornecedor
                </a>
            @endif
        </div>
    </x-slot>

    <div class="py-6">
        <div class="mx-auto max-w-7xl sm:px-6 lg:px-8 space-y-4">

            @if(session('status'))
                <div class="rounded-md bg-emerald-50 border border-emerald-200 px-4 py-2 text-sm text-emerald-800">
                    {{ session('status') }}
                </div>
            @endif

            {{-- Header chips: aggregate counters. --}}
            <div class="grid grid-cols-2 sm:grid-cols-5 gap-2 text-xs">
                @foreach([
                    ['label' => 'Total',          'value' => number_format($counts['total']),     'tone' => 'bg-gray-50 text-gray-700 border-gray-200'],
                    ['label' => 'Aprovados',      'value' => number_format($counts['approved']),  'tone' => 'bg-emerald-50 text-emerald-800 border-emerald-200'],
                    ['label' => 'Pendentes',      'value' => number_format($counts['pending']),   'tone' => 'bg-amber-50 text-amber-800 border-amber-200'],
                    ['label' => 'Blacklisted',    'value' => number_format($counts['blacklist']), 'tone' => 'bg-red-50 text-red-700 border-red-200'],
                    ['label' => 'Com email',      'value' => number_format($counts['with_email']),'tone' => 'bg-blue-50 text-blue-800 border-blue-200'],
                ] as $c)
                    <div class="rounded-lg border px-3 py-2 {{ $c['tone'] }}">
                        <div class="text-[10px] uppercase tracking-wider opacity-70">{{ $c['label'] }}</div>
                        <div class="text-lg font-bold">{{ $c['value'] }}</div>
                    </div>
                @endforeach
            </div>

            {{-- Filters card --}}
            <section class="rounded-lg bg-white shadow-sm border border-gray-100 p-4">
                <form method="GET" action="{{ route('suppliers.index') }}" class="grid grid-cols-1 gap-3 sm:grid-cols-6">
                    {{-- Lupa --}}
                    <div class="relative sm:col-span-2">
                        <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-2 text-gray-400">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-4.35-4.35M11 19a8 8 0 1 1 0-16 8 8 0 0 1 0 16Z" />
                            </svg>
                        </span>
                        <input type="search" name="q" value="{{ $filters['q'] }}" placeholder="Pesquisar nome / email / slug…"
                               class="w-full rounded-md border-gray-300 text-sm shadow-sm pl-8 focus:border-indigo-500 focus:ring-indigo-500">
                    </div>

                    <select name="category" class="rounded-md border-gray-300 text-sm shadow-sm">
                        <option value="">Todas as categorias</option>
                        @foreach($categories as $code => $label)
                            <option value="{{ $code }}" @selected($filters['category'] === $code)>{{ $label }}</option>
                        @endforeach
                    </select>

                    <select name="status" class="rounded-md border-gray-300 text-sm shadow-sm">
                        <option value="">Qualquer estado</option>
                        <option value="approved"    @selected($filters['status'] === 'approved')>Aprovado</option>
                        <option value="pending"     @selected($filters['status'] === 'pending')>Pendente revisão</option>
                        <option value="blacklisted" @selected($filters['status'] === 'blacklisted')>Blacklisted</option>
                    </select>

                    <select name="min_iqf" class="rounded-md border-gray-300 text-sm shadow-sm">
                        <option value="">Qualquer IQF</option>
                        @foreach([3, 2.75, 2.5, 2] as $threshold)
                            <option value="{{ $threshold }}" @selected((float) $filters['min_iqf'] === (float) $threshold)>≥ {{ $threshold }}</option>
                        @endforeach
                    </select>

                    <select name="source" class="rounded-md border-gray-300 text-sm shadow-sm">
                        <option value="">Todas as origens</option>
                        <option value="excel_2026"       @selected($filters['source'] === 'excel_2026')>📋 Excel 2026</option>
                        <option value="agent_extraction" @selected($filters['source'] === 'agent_extraction')>🤖 Detectado por agente</option>
                        <option value="manual"           @selected($filters['source'] === 'manual')>✍️ Manual</option>
                    </select>

                    <div class="flex items-center gap-2 sm:col-span-6">
                        <button class="rounded-md bg-indigo-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-indigo-500">Filtrar</button>
                        @if(array_filter($filters, fn($v) => $v !== '' && $v !== null))
                            <a href="{{ route('suppliers.index') }}" class="text-xs text-gray-500 hover:text-gray-700 underline">limpar</a>
                        @endif
                    </div>
                </form>
            </section>

            {{-- Listing --}}
            <section class="rounded-lg bg-white shadow-sm border border-gray-100 overflow-hidden">
                @php
                    // Sort link helper — clicking a column header toggles ASC/DESC.
                    $sortLink = function (string $key, string $label) use ($sort, $dir) {
                        $isActive = $sort === $key;
                        $nextDir = $isActive && $dir === 'asc' ? 'desc' : 'asc';
                        $arrow = $isActive ? ($dir === 'asc' ? '↑' : '↓') : '';
                        $params = array_merge(request()->query(), ['sort' => $key, 'dir' => $nextDir]);
                        return [
                            'url'      => request()->url() . '?' . http_build_query($params),
                            'label'    => $label,
                            'isActive' => $isActive,
                            'arrow'    => $arrow,
                        ];
                    };
                @endphp
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-100 text-sm">
                        <thead class="bg-gray-50 text-xs uppercase text-gray-600">
                            <tr>
                                @foreach([
                                    ['name', 'Fornecedor'],
                                    ['iqf_score', 'IQF'],
                                    [null, 'Categorias'],
                                    [null, 'Email'],
                                    [null, 'Origem'],
                                    ['last_contacted_at', 'Último contacto'],
                                ] as [$key, $label])
                                    <th class="px-3 py-2 text-left">
                                        @if($key)
                                            @php $h = $sortLink($key, $label); @endphp
                                            <a href="{{ $h['url'] }}" class="inline-flex items-center gap-1 select-none {{ $h['isActive'] ? 'text-indigo-700' : 'hover:text-gray-700' }}">
                                                <span>{{ $h['label'] }}</span>
                                                @if($h['arrow'])<span class="text-indigo-600">{{ $h['arrow'] }}</span>@else<span class="text-gray-300">⇅</span>@endif
                                            </a>
                                        @else
                                            {{ $label }}
                                        @endif
                                    </th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse($suppliers as $sup)
                                <tr class="hover:bg-gray-50 align-middle">
                                    <td class="px-3 py-2 max-w-md">
                                        <a href="{{ route('suppliers.show', $sup) }}" class="font-medium text-indigo-700 hover:underline">
                                            {{ \Illuminate\Support\Str::limit($sup->name, 80) }}
                                        </a>
                                        @if(!empty($sup->brands))
                                            <div class="mt-0.5 flex gap-1 flex-wrap">
                                                @foreach(array_slice((array) $sup->brands, 0, 4) as $b)
                                                    <span class="inline-block rounded bg-blue-50 text-blue-700 border border-blue-100 px-1.5 py-0.5 text-[10px]">{{ $b }}</span>
                                                @endforeach
                                            </div>
                                        @endif
                                    </td>
                                    <td class="px-3 py-2 whitespace-nowrap">
                                        @if($sup->iqf_score !== null)
                                            @php
                                                $iqf = (float) $sup->iqf_score;
                                                $iqfClass = $iqf >= 2.75 ? 'bg-emerald-100 text-emerald-800' : ($iqf >= 2.5 ? 'bg-blue-100 text-blue-800' : ($iqf >= 1.5 ? 'bg-amber-100 text-amber-800' : 'bg-red-100 text-red-700'));
                                            @endphp
                                            <span class="inline-block rounded px-2 py-0.5 text-xs font-bold {{ $iqfClass }}">
                                                {{ rtrim(rtrim(number_format($iqf, 2), '0'), '.') }}
                                            </span>
                                        @else
                                            <span class="text-gray-400 text-xs">—</span>
                                        @endif
                                    </td>
                                    <td class="px-3 py-2 text-xs text-gray-700 max-w-sm">
                                        @if(!empty($sup->categories))
                                            <div class="flex gap-1 flex-wrap">
                                                @foreach(array_slice((array) $sup->categories, 0, 4) as $code)
                                                    <span class="inline-block rounded bg-gray-100 text-gray-700 px-1.5 py-0.5 text-[10px] font-mono"
                                                          title="{{ \App\Services\SupplierCategories::labelFor($code) }}">
                                                        {{ $code }}
                                                    </span>
                                                @endforeach
                                                @if(count((array) $sup->categories) > 4)
                                                    <span class="text-gray-400 text-[10px]">+{{ count((array) $sup->categories) - 4 }}</span>
                                                @endif
                                            </div>
                                        @else
                                            <span class="text-gray-400">—</span>
                                        @endif
                                    </td>
                                    <td class="px-3 py-2 text-xs whitespace-nowrap">
                                        @if($sup->primary_email)
                                            <a href="mailto:{{ $sup->primary_email }}" class="text-blue-700 hover:underline font-mono">
                                                ✉ {{ \Illuminate\Support\Str::limit($sup->primary_email, 28) }}
                                            </a>
                                        @else
                                            <span class="text-gray-400">sem email</span>
                                        @endif
                                    </td>
                                    <td class="px-3 py-2 text-xs whitespace-nowrap">
                                        @php
                                            $srcLabel = match($sup->source) {
                                                'excel_2026'       => ['📋', 'Excel 2026',       'text-gray-600'],
                                                'agent_extraction' => ['🤖', 'Agente',           'text-purple-700'],
                                                'manual'           => ['✍️', 'Manual',           'text-emerald-700'],
                                                default            => ['•',  $sup->source,       'text-gray-500'],
                                            };
                                        @endphp
                                        <span class="{{ $srcLabel[2] }}" title="Origem: {{ $sup->source }}">
                                            {{ $srcLabel[0] }} {{ $srcLabel[1] }}
                                        </span>
                                    </td>
                                    <td class="px-3 py-2 text-xs whitespace-nowrap text-gray-600">
                                        @if($sup->last_contacted_at)
                                            <span title="{{ $sup->last_contacted_at->format('d/m/Y H:i') }}">
                                                {{ $sup->last_contacted_at->diffForHumans(null, true) }}
                                            </span>
                                            @if($sup->total_outreach > 0)
                                                <div class="text-gray-400">
                                                    {{ $sup->total_outreach }} envio(s)
                                                    @if($sup->total_replies > 0)
                                                        · {{ $sup->total_replies }} resposta(s)
                                                    @endif
                                                </div>
                                            @endif
                                        @else
                                            <span class="text-gray-400">—</span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="px-3 py-10 text-center text-sm text-gray-500">
                                        Nenhum fornecedor encontrado para os filtros actuais.
                                        <a href="{{ route('suppliers.index') }}" class="ml-1 text-indigo-600 hover:underline">limpar filtros</a>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if($suppliers->hasPages())
                    <div class="px-4 py-3 border-t border-gray-100 bg-gray-50">
                        {{ $suppliers->links() }}
                    </div>
                @endif
            </section>
        </div>
    </div>
</x-app-layout>
