@php
    /**
     * Upload an Excel (xlsx/xls) and feed it through the right source-specific
     * importer. The source dropdown lists only importers actually registered
     * in TenderImportService::IMPORTERS — we don't show placeholders for
     * sources that can't yet parse.
     *
     * The recent imports list shows the last 10 audit rows with their
     * parse/create/update counts so the user can see at a glance whether a
     * re-import did anything meaningful.
     */
    $sourceLabels = [
        'nspa'    => 'NSPA — NATO Support and Procurement Agency',
        'nato'    => 'NATO (directo)',
        'sam_gov' => 'SAM.gov (US Federal)',
        'ncia'    => 'NCIA',
        'acingov' => 'Acingov (Portugal)',
        'vortal'  => 'Vortal',
        'ungm'    => 'UNGM (UN Global Marketplace)',
        'unido'   => 'UNIDO',
        'other'   => 'Outra fonte',
    ];
@endphp

<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-3">
            <a href="{{ route('tenders.index') }}" class="text-sm text-indigo-600 hover:underline">← Voltar</a>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                Importar concursos
            </h2>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-4xl sm:px-6 lg:px-8 space-y-6">

            @if(session('status'))
                <div class="rounded-md bg-green-50 border border-green-200 p-4 text-sm text-green-800">
                    {{ session('status') }}
                </div>
            @endif

            @if($errors->any())
                <div class="rounded-md bg-red-50 border border-red-200 p-4 text-sm text-red-800">
                    <ul class="list-disc list-inside">
                        @foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach
                    </ul>
                </div>
            @endif

            {{-- ─── Upload form ───────────────────────────────────────────── --}}
            <section class="rounded-lg bg-white shadow-sm border border-gray-100 p-6">
                <h3 class="text-base font-semibold text-gray-800 mb-4">Novo ficheiro</h3>

                <form method="POST" action="{{ route('tenders.import.store') }}" enctype="multipart/form-data" class="space-y-4">
                    @csrf

                    <div>
                        <label class="block text-xs font-medium text-gray-700 mb-1">Fonte</label>
                        <select name="source" required class="w-full rounded-md border-gray-300 text-sm shadow-sm">
                            <option value="">Seleccionar fonte…</option>
                            @foreach($sources as $src)
                                <option value="{{ $src }}" @selected(old('source') === $src)>
                                    {{ $sourceLabels[$src] ?? strtoupper($src) }}
                                </option>
                            @endforeach
                        </select>
                        <p class="mt-1 text-xs text-gray-500">
                            Apenas fontes com parser registado aparecem aqui. Para adicionar
                            novas fontes é necessário criar um <code class="text-gray-700">TenderImporterInterface</code>.
                        </p>
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-gray-700 mb-1">Ficheiro (.xlsx ou .xls, máx. 30 MB)</label>
                        <input type="file" name="file" accept=".xlsx,.xls"
                               required
                               class="block w-full text-sm text-gray-700 file:mr-3 file:rounded-md file:border-0 file:bg-indigo-50 file:px-4 file:py-2 file:text-sm file:font-semibold file:text-indigo-700 hover:file:bg-indigo-100">
                    </div>

                    <div class="flex justify-end">
                        <button type="submit"
                                class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow hover:bg-indigo-500">
                            Importar
                        </button>
                    </div>
                </form>
            </section>

            {{-- ─── Recent imports audit ───────────────────────────────────── --}}
            <section class="rounded-lg bg-white shadow-sm border border-gray-100 overflow-hidden">
                <header class="px-4 py-3 border-b border-gray-100 bg-gray-50">
                    <h3 class="text-sm font-semibold text-gray-800">Últimas 10 importações</h3>
                </header>

                @if($recent->isEmpty())
                    <div class="px-4 py-6 text-sm text-gray-500 text-center">
                        Ainda não foram feitas importações.
                    </div>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead class="bg-gray-50 text-xs uppercase text-gray-500">
                                <tr>
                                    <th class="px-3 py-2 text-left">Data</th>
                                    <th class="px-3 py-2 text-left">Fonte</th>
                                    <th class="px-3 py-2 text-left">Ficheiro</th>
                                    <th class="px-3 py-2 text-left">Utilizador</th>
                                    <th class="px-3 py-2 text-right">Lidas</th>
                                    <th class="px-3 py-2 text-right">Novas</th>
                                    <th class="px-3 py-2 text-right">Actualiz.</th>
                                    <th class="px-3 py-2 text-right">Saltadas</th>
                                    <th class="px-3 py-2 text-right">Tempo</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 bg-white">
                                @foreach($recent as $imp)
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-3 py-2 text-xs text-gray-600">
                                            {{ $imp->created_at->format('d/m/Y H:i') }}
                                        </td>
                                        <td class="px-3 py-2 text-xs font-semibold uppercase text-gray-700">
                                            {{ $imp->source }}
                                        </td>
                                        <td class="px-3 py-2 text-xs font-mono text-gray-700 max-w-xs truncate"
                                            title="{{ $imp->file_name }}">
                                            {{ $imp->file_name }}
                                        </td>
                                        <td class="px-3 py-2 text-xs text-gray-600">
                                            {{ $imp->user?->name ?? '—' }}
                                        </td>
                                        <td class="px-3 py-2 text-xs text-right">{{ $imp->rows_parsed }}</td>
                                        <td class="px-3 py-2 text-xs text-right text-green-700 font-medium">{{ $imp->rows_created }}</td>
                                        <td class="px-3 py-2 text-xs text-right text-blue-700">{{ $imp->rows_updated }}</td>
                                        <td class="px-3 py-2 text-xs text-right text-gray-500">{{ $imp->rows_skipped }}</td>
                                        <td class="px-3 py-2 text-xs text-right text-gray-500">
                                            @if($imp->duration_ms)
                                                {{ number_format($imp->duration_ms / 1000, 1) }}s
                                            @else
                                                —
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>
        </div>
    </div>
</x-app-layout>
