<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-3">
            <a href="{{ route('suppliers.index') }}" class="text-sm text-indigo-600 hover:underline">← Fornecedores</a>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">📋 Fila de revisão — fornecedores pending</h2>
        </div>
    </x-slot>

    <div class="py-6">
        <div class="mx-auto max-w-6xl sm:px-6 lg:px-8 space-y-4">
            @if(session('status'))
                <div class="rounded-md bg-emerald-50 border border-emerald-200 px-4 py-2 text-sm text-emerald-800">{{ session('status') }}</div>
            @endif

            <section class="rounded-lg bg-white shadow-sm border border-gray-100 p-4 text-sm text-gray-700">
                <p>
                    Estes fornecedores foram <strong>extraídos automaticamente</strong> pelos agentes
                    (Marco, MilDef, Acingov, …) ao mencionarem emails em respostas. Para evitar poluição
                    do directório, ficam em estado <code>pending</code> até um manager revisar.
                </p>
                <ul class="mt-2 text-xs list-disc list-inside text-gray-600">
                    <li><strong>Promover</strong> → o fornecedor passa a aprovado e aparece na pesquisa de tenders.</li>
                    <li><strong>Rejeitar</strong> → blacklisted; permanece registado para forense (não volta a sugerir).</li>
                </ul>
            </section>

            <section class="rounded-lg bg-white shadow-sm border border-gray-100 overflow-hidden">
                {{-- Bulk-action form: a single <form> wraps the table so all
                     checkboxes are submitted together. Buttons set the
                     `action` hidden input via JS before submitting. --}}
                <form id="bulk-form" method="POST" action="{{ route('suppliers.bulk') }}">
                    @csrf
                    <input type="hidden" name="action" id="bulk-action" value="">

                    <div class="px-4 py-2 border-b border-gray-100 bg-gray-50 flex items-center justify-between gap-2 flex-wrap text-xs sticky top-0">
                        <div class="flex items-center gap-2">
                            <input type="checkbox" id="bulk-select-all" class="rounded border-gray-300">
                            <span class="text-gray-700">
                                <span id="bulk-count">0</span> selecionado(s) · {{ $pending->total() }} pending nesta fila
                            </span>
                        </div>
                        <div class="flex items-center gap-2">
                            <button type="submit" data-bulk="promote" disabled
                                    class="bulk-btn rounded-md bg-emerald-600 px-3 py-1 text-xs font-semibold text-white hover:bg-emerald-500 disabled:opacity-40 disabled:cursor-not-allowed">
                                ✓ Promover seleccionados
                            </button>
                            <button type="submit" data-bulk="reject" disabled
                                    class="bulk-btn rounded-md bg-red-600 px-3 py-1 text-xs font-semibold text-white hover:bg-red-500 disabled:opacity-40 disabled:cursor-not-allowed">
                                ✗ Rejeitar seleccionados
                            </button>
                        </div>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-100 text-sm">
                            <thead class="bg-gray-50 text-xs uppercase text-gray-600">
                                <tr>
                                    <th class="px-3 py-2 text-left w-10"></th>
                                    <th class="px-3 py-2 text-left">Fornecedor</th>
                                    <th class="px-3 py-2 text-left">Email</th>
                                    <th class="px-3 py-2 text-left">Marcas</th>
                                    <th class="px-3 py-2 text-left">Origem</th>
                                    <th class="px-3 py-2 text-left">Detectado</th>
                                    <th class="px-3 py-2 text-right">Acções individuais</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @forelse($pending as $sup)
                                    <tr class="hover:bg-gray-50 align-middle">
                                        <td class="px-3 py-2 align-top">
                                            <input type="checkbox" name="supplier_ids[]" value="{{ $sup->id }}"
                                                   class="bulk-row rounded border-gray-300">
                                        </td>
                                        <td class="px-3 py-2 max-w-md">
                                            <a href="{{ route('suppliers.show', $sup) }}" class="font-medium text-indigo-700 hover:underline">{{ $sup->name }}</a>
                                            <div class="text-[11px] text-gray-500 font-mono">{{ $sup->slug }}</div>
                                        </td>
                                        <td class="px-3 py-2 text-xs font-mono">
                                            {{ $sup->primary_email ?: '—' }}
                                            @if(!empty($sup->additional_emails))
                                                <div class="text-[10px] text-gray-500">+{{ count($sup->additional_emails) }} extra</div>
                                            @endif
                                        </td>
                                        <td class="px-3 py-2 text-xs">
                                            @foreach((array) $sup->brands ?? [] as $b)
                                                <span class="inline-block rounded bg-blue-50 text-blue-700 border border-blue-100 px-1.5 py-0.5 text-[10px]">{{ $b }}</span>
                                            @endforeach
                                        </td>
                                        <td class="px-3 py-2 text-xs">
                                            @php $src = match($sup->source) { 'agent_extraction' => '🤖 Agente', 'excel_2026' => '📋 Excel', 'manual' => '✍️ Manual', default => $sup->source }; @endphp
                                            {{ $src }}
                                        </td>
                                        <td class="px-3 py-2 text-xs text-gray-500">{{ $sup->created_at->diffForHumans() }}</td>
                                        <td class="px-3 py-2 text-right whitespace-nowrap">
                                            <button type="button" class="row-btn rounded-md bg-emerald-600 px-2 py-1 text-xs font-semibold text-white hover:bg-emerald-500"
                                                    data-action="{{ route('suppliers.promote', $sup) }}"
                                                    title="Promover só este">✓</button>
                                            <button type="button" class="row-btn rounded-md bg-red-600 px-2 py-1 text-xs font-semibold text-white hover:bg-red-500 ml-1"
                                                    data-action="{{ route('suppliers.reject', $sup) }}"
                                                    data-confirm="Rejeitar {{ $sup->name }}?"
                                                    title="Rejeitar só este">✗</button>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="7" class="px-3 py-10 text-center text-sm text-gray-500">
                                            🎉 Fila vazia — nenhum fornecedor à espera de revisão.
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </form>

                @if($pending->hasPages())
                    <div class="px-4 py-3 border-t border-gray-100 bg-gray-50">{{ $pending->links() }}</div>
                @endif
            </section>

            <script>
            (function () {
                const form    = document.getElementById('bulk-form');
                if (!form) return;
                const action  = document.getElementById('bulk-action');
                const all     = document.getElementById('bulk-select-all');
                const count   = document.getElementById('bulk-count');
                const rows    = form.querySelectorAll('input.bulk-row');
                const btns    = form.querySelectorAll('button.bulk-btn');
                const csrf    = document.querySelector('meta[name="csrf-token"]')?.content || '';

                function refresh() {
                    const sel = form.querySelectorAll('input.bulk-row:checked').length;
                    count.textContent = sel;
                    btns.forEach(b => b.disabled = sel === 0);
                }

                all?.addEventListener('change', () => {
                    rows.forEach(r => r.checked = all.checked);
                    refresh();
                });
                rows.forEach(r => r.addEventListener('change', refresh));

                btns.forEach(b => {
                    b.addEventListener('click', (e) => {
                        const sel = form.querySelectorAll('input.bulk-row:checked').length;
                        if (sel === 0) { e.preventDefault(); return; }
                        action.value = b.dataset.bulk;
                        if (b.dataset.bulk === 'reject') {
                            if (!confirm('Marcar ' + sel + ' fornecedor(es) como blacklisted?')) {
                                e.preventDefault();
                            }
                        }
                    });
                });

                // Per-row action buttons — POST to the single-row endpoint
                // via a temporary hidden form so they bypass the bulk form's
                // action/handler.
                form.querySelectorAll('button.row-btn').forEach(b => {
                    b.addEventListener('click', () => {
                        if (b.dataset.confirm && !confirm(b.dataset.confirm)) return;
                        const f = document.createElement('form');
                        f.method = 'POST';
                        f.action = b.dataset.action;
                        f.innerHTML = '<input type="hidden" name="_token" value="' + csrf + '">';
                        document.body.appendChild(f);
                        f.submit();
                    });
                });

                refresh();
            })();
            </script>
        </div>
    </div>
</x-app-layout>
