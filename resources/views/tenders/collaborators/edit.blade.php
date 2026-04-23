<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                Editar colaborador — {{ $collaborator->name }}
            </h2>
            <a href="{{ route('tenders.collaborators.index') }}"
               class="text-sm text-gray-500 hover:text-gray-700">← Voltar ao roster</a>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-2xl sm:px-6 lg:px-8 space-y-4">

            @if($errors->any())
                <div class="rounded-md bg-red-50 border border-red-200 p-4 text-sm text-red-800">
                    <ul class="list-disc ml-5">
                        @foreach($errors->all() as $e)
                            <li>{{ $e }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <section class="rounded-lg bg-white shadow-sm border border-gray-100 p-6 space-y-4">
                <form method="POST" action="{{ route('tenders.collaborators.update', $collaborator) }}"
                      class="space-y-4">
                    @csrf @method('PATCH')

                    <div>
                        <label class="block text-sm font-medium text-gray-700">Nome</label>
                        <input type="text" name="name" required
                               value="{{ old('name', $collaborator->name) }}"
                               class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <p class="mt-1 text-xs text-gray-400">
                            Normalizado: <code>{{ $collaborator->normalized_name }}</code>
                        </p>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700">Email</label>
                        <input type="email" name="email"
                               value="{{ old('email', $collaborator->email) }}"
                               placeholder="Deixe em branco para herdar do User ligado"
                               class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                        <p class="mt-1 text-xs text-gray-500">
                            Este email é usado para o digest e para lembretes de deadline.
                            Se em branco e houver User ligado, usa-se o email do User.
                        </p>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700">User ligado</label>
                        <select name="user_id"
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                            <option value="">— sem User ligado —</option>
                            @foreach($linkableUsers as $u)
                                <option value="{{ $u->id }}"
                                    @selected((int)old('user_id', $collaborator->user_id) === $u->id)>
                                    {{ $u->name }} ({{ $u->email }})
                                </option>
                            @endforeach
                        </select>

                        <div class="mt-2 rounded-md bg-blue-50 border border-blue-200 p-3 text-xs text-blue-900 space-y-1.5">
                            <div class="font-semibold uppercase tracking-wide text-[10px] text-blue-700">
                                O que faz a ligação a um User?
                            </div>
                            <div>
                                🔸 <strong>{{ $collaborator->name }}</strong> passa a ver os concursos
                                atribuídos a este nome na secção <em>"Os meus concursos"</em> do dashboard
                                — com login próprio.
                            </div>
                            <div>
                                🔸 Recebe o <strong>digest diário</strong> (2× por dia) apenas com os concursos dele(a).
                            </div>
                            <div>
                                🔸 Recebe o <strong>lembrete 24h antes de cada deadline</strong> (uma vez por concurso).
                            </div>
                            <div class="text-blue-800">
                                🔸 Sem User ligado, ninguém além dos managers vê este bucket —
                                só tu (super-user) consegues enviar lembretes manuais pelo
                                <em>/tenders/overview</em>.
                            </div>
                        </div>
                    </div>

                    <div>
                        <label class="flex items-center gap-2 text-sm text-gray-700">
                            <input type="checkbox" name="is_active" value="1"
                                   @checked(old('is_active', $collaborator->is_active))
                                   class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                            Activo
                        </label>
                    </div>

                    <div class="flex justify-between pt-3 border-t border-gray-100">
                        <a href="{{ route('tenders.collaborators.index') }}"
                           class="text-sm text-gray-500 hover:text-gray-700">Cancelar</a>
                        <button type="submit"
                                class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500">
                            Guardar alterações
                        </button>
                    </div>
                </form>
            </section>

        </div>
    </div>
</x-app-layout>
