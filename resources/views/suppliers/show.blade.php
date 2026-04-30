<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-3 flex-wrap">
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                <a href="{{ route('suppliers.index') }}" class="text-indigo-600 hover:underline">← Fornecedores</a>
                <span class="ml-2">{{ $supplier->name }}</span>
            </h2>
            @php
                $statusBadge = match($supplier->status) {
                    'approved'    => ['Aprovado',    'bg-emerald-100 text-emerald-800'],
                    'pending'     => ['Pendente',    'bg-amber-100 text-amber-800'],
                    'blacklisted' => ['Blacklisted', 'bg-red-100 text-red-700'],
                    default       => [$supplier->status, 'bg-gray-100 text-gray-700'],
                };
            @endphp
            <span class="rounded-full px-3 py-1 text-xs font-bold {{ $statusBadge[1] }}">{{ $statusBadge[0] }}</span>
        </div>
    </x-slot>

    <div class="py-6">
        <div class="mx-auto max-w-4xl px-4 sm:px-6 lg:px-8 space-y-4">

            @if(session('status'))
                <div class="rounded-md bg-emerald-50 border border-emerald-200 px-4 py-2 text-sm text-emerald-800">{{ session('status') }}</div>
            @endif
            @error('name')<div class="rounded-md bg-red-50 border border-red-200 px-4 py-2 text-sm text-red-700">{{ $message }}</div>@enderror

            <section class="rounded-lg bg-white shadow-sm border border-gray-200 p-5 space-y-4">
                <form method="POST" action="{{ route('suppliers.update', $supplier) }}" class="space-y-4">
                    @csrf @method('PATCH')

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <label class="text-sm">
                            <span class="text-xs text-gray-600">Nome <span class="text-red-500">*</span></span>
                            <input type="text" name="name" required @disabled(!$canEdit)
                                   value="{{ old('name', $supplier->name) }}"
                                   class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm">
                        </label>
                        <label class="text-sm">
                            <span class="text-xs text-gray-600">Razão social</span>
                            <input type="text" name="legal_name" @disabled(!$canEdit)
                                   value="{{ old('legal_name', $supplier->legal_name) }}"
                                   class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm">
                        </label>

                        <label class="text-sm">
                            <span class="text-xs text-gray-600">País (ISO 2 letras)</span>
                            <input type="text" name="country_code" @disabled(!$canEdit)
                                   value="{{ old('country_code', $supplier->country_code) }}" maxlength="2"
                                   class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm uppercase">
                        </label>
                        <label class="text-sm">
                            <span class="text-xs text-gray-600">Website</span>
                            <input type="url" name="website" @disabled(!$canEdit)
                                   value="{{ old('website', $supplier->website) }}" placeholder="https://…"
                                   class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm">
                        </label>

                        <label class="text-sm">
                            <span class="text-xs text-gray-600">Email primário</span>
                            <input type="email" name="primary_email" @disabled(!$canEdit)
                                   value="{{ old('primary_email', $supplier->primary_email) }}"
                                   class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm font-mono">
                        </label>
                        <label class="text-sm">
                            <span class="text-xs text-gray-600">IQF (0–5)</span>
                            <input type="number" name="iqf_score" step="0.05" min="0" max="5" @disabled(!$canEdit)
                                   value="{{ old('iqf_score', $supplier->iqf_score) }}"
                                   class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm">
                        </label>

                        <label class="text-sm sm:col-span-2">
                            <span class="text-xs text-gray-600">Emails adicionais (separados por vírgula ou quebra de linha)</span>
                            <textarea name="additional_emails" rows="2" @disabled(!$canEdit)
                                      class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm font-mono">{{ old('additional_emails', implode(', ', (array) $supplier->additional_emails ?? [])) }}</textarea>
                        </label>

                        <label class="text-sm sm:col-span-2">
                            <span class="text-xs text-gray-600">Telefones (separados por vírgula)</span>
                            <input type="text" name="phones" @disabled(!$canEdit)
                                   value="{{ old('phones', implode(', ', (array) $supplier->phones ?? [])) }}"
                                   class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm">
                        </label>

                        <label class="text-sm">
                            <span class="text-xs text-gray-600">Categorias (top-level, ex: 13, 14)</span>
                            <input type="text" name="categories" @disabled(!$canEdit)
                                   value="{{ old('categories', implode(', ', (array) $supplier->categories ?? [])) }}"
                                   class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm">
                            @if($supplier->categories)
                                <div class="mt-1 flex gap-1 flex-wrap">
                                    @foreach((array) $supplier->categories as $c)
                                        <span class="inline-block rounded bg-gray-100 text-gray-700 px-1.5 py-0.5 text-[10px]" title="{{ \App\Services\SupplierCategories::labelFor($c) }}">
                                            {{ $c }} · {{ \App\Services\SupplierCategories::labelFor($c) }}
                                        </span>
                                    @endforeach
                                </div>
                            @endif
                        </label>
                        <label class="text-sm">
                            <span class="text-xs text-gray-600">Sub-categorias (ex: 13.15, 16.34)</span>
                            <input type="text" name="subcategories" @disabled(!$canEdit)
                                   value="{{ old('subcategories', implode(', ', (array) $supplier->subcategories ?? [])) }}"
                                   class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm">
                        </label>

                        <label class="text-sm sm:col-span-2">
                            <span class="text-xs text-gray-600">Marcas representadas</span>
                            <input type="text" name="brands" @disabled(!$canEdit)
                                   value="{{ old('brands', implode(', ', (array) $supplier->brands ?? [])) }}"
                                   class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm">
                        </label>

                        <label class="text-sm">
                            <span class="text-xs text-gray-600">Estado</span>
                            <select name="status" @disabled(!$canEdit) class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm">
                                <option value="approved"    @selected(old('status', $supplier->status) === 'approved')>Aprovado</option>
                                <option value="pending"     @selected(old('status', $supplier->status) === 'pending')>Pendente revisão</option>
                                <option value="blacklisted" @selected(old('status', $supplier->status) === 'blacklisted')>Blacklisted</option>
                            </select>
                        </label>
                        <div class="text-sm">
                            <span class="text-xs text-gray-600">Origem</span>
                            <div class="mt-1 text-sm text-gray-700 font-mono">{{ $supplier->source }}</div>
                        </div>
                    </div>

                    <label class="text-sm block">
                        <span class="text-xs text-gray-600">Notas internas</span>
                        <textarea name="notes" rows="3" @disabled(!$canEdit)
                                  class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm">{{ old('notes', $supplier->notes) }}</textarea>
                    </label>

                    @if($canEdit)
                        <div class="flex justify-end">
                            <button class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500">
                                Guardar alterações
                            </button>
                        </div>
                    @else
                        <p class="text-xs text-gray-500">Somente leitura — pede a um manager para editar.</p>
                    @endif
                </form>
            </section>

            {{-- Outreach stats --}}
            <section class="rounded-lg bg-white shadow-sm border border-gray-200 p-5">
                <h3 class="text-sm font-semibold text-gray-800 mb-3">📊 Histórico de outreach</h3>
                <dl class="grid grid-cols-2 sm:grid-cols-4 gap-3 text-xs">
                    <div>
                        <dt class="text-gray-500">Envios</dt>
                        <dd class="text-2xl font-bold text-gray-800">{{ number_format($supplier->total_outreach) }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Respostas</dt>
                        <dd class="text-2xl font-bold {{ $supplier->total_replies > 0 ? 'text-emerald-700' : 'text-gray-400' }}">
                            {{ number_format($supplier->total_replies) }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Tempo médio resposta</dt>
                        <dd class="text-2xl font-bold text-gray-800">{{ $supplier->avg_reply_hours ? $supplier->avg_reply_hours . 'h' : '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Último contacto</dt>
                        <dd class="text-sm text-gray-700">{{ $supplier->last_contacted_at?->format('d/m/Y') ?? '—' }}</dd>
                    </div>
                </dl>
                <p class="mt-3 text-[11px] text-gray-500">
                    As métricas crescem automaticamente quando o sistema envia emails de outreach (ver pipeline de leads + tender suggester).
                </p>
            </section>
        </div>
    </div>
</x-app-layout>
