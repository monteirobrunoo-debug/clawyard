<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-3">
            <a href="{{ route('suppliers.index') }}" class="text-sm text-indigo-600 hover:underline">← Fornecedores</a>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">Novo fornecedor</h2>
        </div>
    </x-slot>

    <div class="py-6">
        <div class="mx-auto max-w-3xl px-4 sm:px-6 lg:px-8 space-y-4">
            @if($errors->any())
                <div class="rounded-md bg-red-50 border border-red-200 px-4 py-2 text-sm text-red-700">
                    <ul class="list-disc list-inside">
                        @foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach
                    </ul>
                </div>
            @endif

            <section class="rounded-lg bg-white shadow-sm border border-gray-200 p-5">
                <form method="POST" action="{{ route('suppliers.store') }}" class="space-y-4">
                    @csrf
                    <p class="text-xs text-gray-500">
                        O slug é gerado automaticamente a partir do nome (sem acentos, sem
                        sufixos legais). Se já existir um fornecedor com o mesmo slug, és
                        redireccionado para essa ficha.
                    </p>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <label class="text-sm sm:col-span-2">
                            <span class="text-xs text-gray-600">Nome <span class="text-red-500">*</span></span>
                            <input type="text" name="name" required value="{{ old('name') }}"
                                   class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm">
                        </label>
                        <label class="text-sm">
                            <span class="text-xs text-gray-600">Email primário</span>
                            <input type="email" name="primary_email" value="{{ old('primary_email') }}"
                                   class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm font-mono">
                        </label>
                        <label class="text-sm">
                            <span class="text-xs text-gray-600">IQF (0–5)</span>
                            <input type="number" name="iqf_score" step="0.05" min="0" max="5"
                                   value="{{ old('iqf_score') }}" placeholder="ex.: 3"
                                   class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm">
                        </label>
                        <label class="text-sm">
                            <span class="text-xs text-gray-600">Estado</span>
                            <select name="status" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm">
                                <option value="approved"    @selected(old('status') === 'approved')>Aprovado</option>
                                <option value="pending"     @selected(old('status', 'pending') === 'pending')>Pendente revisão</option>
                                <option value="blacklisted" @selected(old('status') === 'blacklisted')>Blacklisted</option>
                            </select>
                        </label>
                        <label class="text-sm">
                            <span class="text-xs text-gray-600">País (ISO 2 letras)</span>
                            <input type="text" name="country_code" value="{{ old('country_code') }}" maxlength="2"
                                   class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm uppercase">
                        </label>
                        <label class="text-sm sm:col-span-2">
                            <span class="text-xs text-gray-600">Categorias (separadas por vírgula, ex: 13, 14)</span>
                            <input type="text" name="categories" value="{{ old('categories') }}"
                                   class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm">
                            <p class="mt-1 text-[11px] text-gray-500">
                                @foreach($categories as $code => $label)<span class="mr-1">{{ $code }}={{ $label }};</span>@endforeach
                            </p>
                        </label>
                    </div>

                    <div class="flex justify-end gap-2">
                        <a href="{{ route('suppliers.index') }}" class="rounded-md border border-gray-300 px-3 py-2 text-sm text-gray-700 hover:bg-gray-50">Cancelar</a>
                        <button class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500">Criar</button>
                    </div>
                </form>
            </section>
        </div>
    </div>
</x-app-layout>
