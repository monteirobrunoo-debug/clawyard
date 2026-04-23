<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                Colaboradores de concursos
            </h2>
            <a href="{{ route('tenders.index') }}"
               class="text-sm text-gray-500 hover:text-gray-700">← Voltar aos concursos</a>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-5xl sm:px-6 lg:px-8 space-y-6">

            @if(session('status'))
                <div class="rounded-md bg-green-50 border border-green-200 p-4 text-sm text-green-800">
                    {{ session('status') }}
                </div>
            @endif

            @if($errors->any())
                <div class="rounded-md bg-red-50 border border-red-200 p-4 text-sm text-red-800">
                    <ul class="list-disc ml-5">
                        @foreach($errors->all() as $e)
                            <li>{{ $e }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            {{-- ─── Add new ───────────────────────────────────────────────── --}}
            <section class="rounded-lg bg-white shadow-sm border border-gray-100 p-4">
                <h3 class="text-sm font-semibold text-gray-800 mb-3">Adicionar colaborador</h3>

                <form method="POST" action="{{ route('tenders.collaborators.store') }}"
                      class="grid grid-cols-1 gap-3 sm:grid-cols-5">
                    @csrf
                    <input type="text" name="name" required placeholder="Nome"
                           value="{{ old('name') }}"
                           class="sm:col-span-2 rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">

                    <input type="email" name="email" placeholder="Email (opcional)"
                           value="{{ old('email') }}"
                           class="sm:col-span-2 rounded-md border-gray-300 text-sm shadow-sm">

                    <select name="user_id" class="rounded-md border-gray-300 text-sm shadow-sm">
                        <option value="">— sem User ligado —</option>
                        @foreach($linkableUsers as $u)
                            <option value="{{ $u->id }}" @selected((int)old('user_id') === $u->id)>
                                {{ $u->name }} ({{ $u->email }})
                            </option>
                        @endforeach
                    </select>

                    <label class="flex items-center gap-2 text-sm text-gray-600 sm:col-span-4">
                        <input type="checkbox" name="is_active" value="1" checked
                               class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                        Activo (aparece no dropdown de atribuição e recebe lembretes)
                    </label>

                    <button type="submit"
                            class="sm:col-span-1 rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white hover:bg-indigo-500">
                        + Adicionar
                    </button>
                </form>
            </section>

            {{-- ─── Roster ────────────────────────────────────────────────── --}}
            <section class="rounded-lg bg-white shadow-sm border border-gray-100 overflow-hidden">
                <header class="px-4 py-3 border-b border-gray-100 bg-gray-50">
                    <h3 class="text-sm font-semibold text-gray-800">
                        Roster — {{ $collaborators->count() }} colaboradores
                    </h3>
                </header>

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr class="text-xs font-medium text-gray-500 uppercase tracking-wider">
                                <th class="px-3 py-2 text-left">Nome</th>
                                <th class="px-3 py-2 text-left">Email</th>
                                <th class="px-3 py-2 text-left">User ligado</th>
                                <th class="px-3 py-2 text-left">Concursos</th>
                                <th class="px-3 py-2 text-left">Estado</th>
                                <th class="px-3 py-2 text-right">Acções</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 bg-white">
                            @forelse($collaborators as $c)
                                <tr class="text-sm {{ $c->is_active ? '' : 'bg-gray-50 text-gray-500' }}">
                                    <td class="px-3 py-2">
                                        <div class="font-medium text-gray-900">{{ $c->name }}</div>
                                        <div class="text-xs text-gray-400 font-mono">{{ $c->normalized_name }}</div>
                                    </td>
                                    <td class="px-3 py-2 text-gray-700">
                                        @if($c->email)
                                            <a href="mailto:{{ $c->email }}" class="text-indigo-700 hover:underline">{{ $c->email }}</a>
                                        @elseif($c->user?->email)
                                            <span class="text-gray-500" title="Herdado do User ligado">
                                                {{ $c->user->email }} <span class="text-xs">(via User)</span>
                                            </span>
                                        @else
                                            <span class="text-red-600">— sem email, não pode receber lembretes</span>
                                        @endif
                                    </td>
                                    <td class="px-3 py-2 text-gray-700">
                                        @if($c->user)
                                            {{ $c->user->name }}
                                            <span class="text-xs text-gray-400">({{ $c->user->role }})</span>
                                        @else
                                            <span class="text-gray-400">—</span>
                                        @endif
                                    </td>
                                    <td class="px-3 py-2 font-mono text-xs text-gray-600">{{ $c->tenders_count }}</td>
                                    <td class="px-3 py-2">
                                        @if($c->is_active)
                                            <span class="inline-flex rounded bg-green-50 px-2 py-0.5 text-xs text-green-700">Activo</span>
                                        @else
                                            <span class="inline-flex rounded bg-gray-100 px-2 py-0.5 text-xs text-gray-500">Inactivo</span>
                                        @endif
                                    </td>
                                    <td class="px-3 py-2 text-right">
                                        <a href="{{ route('tenders.collaborators.edit', $c) }}"
                                           class="text-xs text-indigo-700 hover:underline">Editar</a>
                                        @if($c->is_active)
                                            <form method="POST" action="{{ route('tenders.collaborators.destroy', $c) }}"
                                                  class="inline-block ml-3"
                                                  onsubmit="return confirm('Desactivar {{ $c->name }}? O histórico é mantido.')">
                                                @csrf @method('DELETE')
                                                <button type="submit" class="text-xs text-red-700 hover:underline">Desactivar</button>
                                            </form>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="px-3 py-8 text-center text-sm text-gray-500">
                                        Nenhum colaborador ainda. Adiciona um acima ou importa um Excel.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>

        </div>
    </div>
</x-app-layout>
