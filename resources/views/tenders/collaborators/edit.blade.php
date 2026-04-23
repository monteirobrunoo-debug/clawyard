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

                    {{-- Identity is the email — nothing else to pick.
                         If a User already exists with this email we show the
                         current link status as a read-only info chip. If not,
                         we show the "Criar conta" call-to-action. The manager
                         never has to open a dropdown. --}}
                    <div>
                        <div class="rounded-md bg-blue-50 border border-blue-200 p-3 text-xs text-blue-900 space-y-1.5">
                            <div class="font-semibold text-sm text-blue-900">Como funciona a conta?</div>
                            <div>
                                🔹 O <strong>email acima</strong> é a identidade deste colaborador.
                                Se existir uma conta ClawYard com esse email, a pessoa vê
                                automaticamente os concursos dela em <em>"Os meus concursos"</em>
                                no dashboard e recebe o digest diário.
                            </div>
                            <div>
                                🔹 Se ainda não existir conta, usa o botão abaixo — criamos a conta
                                com esse email e enviamos-lhe o link de activação para escolher
                                a password.
                            </div>
                        </div>

                        @if($collaborator->user)
                            <div class="mt-3 rounded-md bg-emerald-50 border border-emerald-200 p-3 text-xs text-emerald-900">
                                <div class="font-semibold mb-0.5">✓ Conta ligada automaticamente</div>
                                <div>
                                    <strong>{{ $collaborator->user->name }}</strong>
                                    ({{ $collaborator->user->email }}) —
                                    role <code>{{ $collaborator->user->role }}</code>.
                                    Vê os concursos dele(a) no próprio dashboard.
                                </div>
                            </div>
                        @elseif($collaborator->email)
                            <div class="mt-3 rounded-md bg-amber-50 border border-amber-200 p-3 text-xs text-amber-900">
                                <div class="font-semibold mb-1">⚠ Ainda não existe conta com este email</div>
                                <div class="mb-2">
                                    Posso criar uma agora — o(a) <strong>{{ $collaborator->name }}</strong>
                                    recebe um email em <code>{{ $collaborator->email }}</code> para
                                    escolher a password.
                                </div>
                                <button type="submit"
                                        form="create-user-form-{{ $collaborator->id }}"
                                        class="inline-flex items-center gap-1 rounded bg-amber-600 px-3 py-1 text-xs font-semibold text-white hover:bg-amber-500">
                                    ➕ Criar conta + enviar link de activação
                                </button>
                            </div>
                        @else
                            <div class="mt-3 rounded-md bg-gray-50 border border-gray-200 p-3 text-xs text-gray-600">
                                💡 Preenche o campo <strong>Email</strong> acima e guarda.
                                Se já houver conta com esse email, ficam ligados; senão,
                                podes criar a conta num clique depois.
                            </div>
                        @endif

                        {{-- Kept as hidden no-op so existing routes/forms don't 404
                             on missing field. Email drives the link now. --}}
                        <input type="hidden" name="user_id" value="">
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

            {{-- Hidden out-of-tree form referenced by the "Criar conta" button
                 inside the main update form. Kept here (outside the <section>
                 wrapping the update form) so clicking it POSTs to create-user
                 instead of the collaborator update endpoint. --}}
            @if(!$collaborator->user_id && $collaborator->email)
                <form id="create-user-form-{{ $collaborator->id }}"
                      method="POST"
                      action="{{ route('tenders.collaborators.create_user', $collaborator) }}"
                      onsubmit="return confirm('Criar User para {{ $collaborator->name }} ({{ $collaborator->email }})? Vai ser enviado um email com link de activação.')">
                    @csrf
                </form>
            @endif

        </div>
    </div>
</x-app-layout>
