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
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-100 text-sm">
                        <thead class="bg-gray-50 text-xs uppercase text-gray-600">
                            <tr>
                                <th class="px-3 py-2 text-left">Fornecedor</th>
                                <th class="px-3 py-2 text-left">Email</th>
                                <th class="px-3 py-2 text-left">Marcas</th>
                                <th class="px-3 py-2 text-left">Origem</th>
                                <th class="px-3 py-2 text-left">Detectado</th>
                                <th class="px-3 py-2 text-right">Acções</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse($pending as $sup)
                                <tr class="hover:bg-gray-50 align-middle">
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
                                        <form method="POST" action="{{ route('suppliers.promote', $sup) }}" class="inline">
                                            @csrf
                                            <button class="rounded-md bg-emerald-600 px-2 py-1 text-xs font-semibold text-white hover:bg-emerald-500"
                                                    title="Promover para aprovado">✓ Promover</button>
                                        </form>
                                        <form method="POST" action="{{ route('suppliers.reject', $sup) }}" class="inline ml-1"
                                              onsubmit="return confirm('Marcar {{ $sup->name }} como blacklisted? Permanece em DB para forense mas deixa de sugerir.');">
                                            @csrf
                                            <button class="rounded-md bg-red-600 px-2 py-1 text-xs font-semibold text-white hover:bg-red-500"
                                                    title="Rejeitar e blacklist">✗ Rejeitar</button>
                                        </form>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="px-3 py-10 text-center text-sm text-gray-500">
                                        🎉 Fila vazia — nenhum fornecedor à espera de revisão.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if($pending->hasPages())
                    <div class="px-4 py-3 border-t border-gray-100 bg-gray-50">{{ $pending->links() }}</div>
                @endif
            </section>
        </div>
    </div>
</x-app-layout>
