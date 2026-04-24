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

            {{-- ─── What is this page? ───────────────────────────────────── --}}
            <section class="rounded-md bg-blue-50 border border-blue-200 p-4 text-xs text-blue-900 space-y-1.5">
                <div class="font-semibold text-sm text-blue-900">Como funciona?</div>
                <div>
                    🔹 A coluna <code>Colaborador</code> dos Excel criam entradas aqui automaticamente.
                    Podes também adicionar manualmente (ex: novo contratado antes do primeiro import).
                </div>
                <div>
                    🔹 <strong>O email é a identidade.</strong> Se a pessoa já tem conta ClawYard com
                    esse email, fica ligada automaticamente — vê os concursos dela no dashboard
                    (<em>"Os meus concursos"</em>) e recebe o digest diário. Sem conta, ninguém além
                    dos managers vê o bucket.
                </div>
                <div class="text-blue-800">
                    🔹 Para criar conta a uma pessoa que ainda não tem login, preenche o email e
                    clica <strong>"Criar conta"</strong> — criamos o User e enviamos-lhe um email
                    para escolher a password.
                </div>
            </section>

            {{-- ─── Batch provisioning ──────────────────────────────────── --}}
            @php
                $pendingUserCreate = $collaborators
                    ->filter(fn($c) => !$c->user_id && !empty($c->email) && $c->is_active)
                    ->count();
            @endphp
            @if($pendingUserCreate > 0)
                <section class="rounded-md bg-amber-50 border border-amber-200 p-4 text-sm text-amber-900 flex items-center justify-between gap-4">
                    <div>
                        <div class="font-semibold">
                            Há {{ $pendingUserCreate }} colaborador(es) com email mas sem conta criada.
                        </div>
                        <div class="text-xs text-amber-800 mt-0.5">
                            Criar todos de uma vez: o sistema gera o User, liga ao colaborador e envia
                            o link de activação da password para cada email. Acção idempotente (podes
                            correr várias vezes sem risco).
                        </div>
                    </div>
                    <form method="POST"
                          action="{{ route('tenders.collaborators.create_users_batch') }}"
                          onsubmit="return confirm('Criar Users para {{ $pendingUserCreate }} colaborador(es) com email? Vai enviar {{ $pendingUserCreate }} email(s) de activação.')">
                        @csrf
                        <button type="submit"
                                class="whitespace-nowrap rounded-md bg-amber-600 px-3 py-2 text-xs font-semibold text-white hover:bg-amber-500">
                            ➕ Criar Users em falta ({{ $pendingUserCreate }})
                        </button>
                    </form>
                </section>
            @endif

            {{-- ─── Add new ───────────────────────────────────────────────── --}}
            <section class="rounded-lg bg-white shadow-sm border border-gray-100 p-4">
                <h3 class="text-sm font-semibold text-gray-800 mb-3">Adicionar colaborador</h3>

                <form method="POST" action="{{ route('tenders.collaborators.store') }}"
                      class="grid grid-cols-1 gap-3 sm:grid-cols-6">
                    @csrf
                    <input type="text" name="name" required placeholder="Nome"
                           value="{{ old('name') }}"
                           class="sm:col-span-2 rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">

                    <input type="email" name="email" placeholder="Email (liga automaticamente à conta)"
                           value="{{ old('email') }}"
                           class="sm:col-span-3 rounded-md border-gray-300 text-sm shadow-sm">

                    <label class="flex items-center gap-2 text-sm text-gray-600 sm:col-span-5">
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
                                <th class="px-3 py-2 text-left">Conta</th>
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
                                            <span class="inline-flex items-center gap-1 rounded bg-emerald-50 border border-emerald-200 px-2 py-0.5 text-xs text-emerald-800"
                                                  title="Conta detectada via email — a pessoa vê os concursos dela">
                                                ✓ conta activa
                                                <span class="text-[10px] text-emerald-600">({{ $c->user->role }})</span>
                                            </span>
                                            <div class="text-[11px] text-gray-500 mt-0.5">vê no dashboard + digest</div>
                                        @elseif($c->email)
                                            <span class="inline-flex items-center gap-1 rounded bg-amber-50 border border-amber-200 px-2 py-0.5 text-xs text-amber-800"
                                                  title="Email preenchido mas ainda não há conta com esse email">
                                                ⚠ sem conta
                                            </span>
                                            <div class="text-[11px] text-amber-700 mt-0.5">criar conta →</div>
                                        @else
                                            <span class="inline-flex items-center gap-1 rounded bg-gray-100 border border-gray-200 px-2 py-0.5 text-xs text-gray-600"
                                                  title="Preenche o email para ligar ou criar conta">
                                                — sem email
                                            </span>
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
                                    <td class="px-3 py-2 text-right whitespace-nowrap">
                                        <a href="{{ route('tenders.collaborators.edit', $c) }}"
                                           class="text-xs text-indigo-700 hover:underline">Editar</a>

                                        {{-- Per-row quick-create: only shown when the collaborator
                                             has an email but no User yet. Same action as the edit
                                             page button, so the super-user can onboard directly
                                             from the roster without a round-trip. --}}
                                        @if(!$c->user_id && $c->email && $c->is_active)
                                            <form method="POST"
                                                  action="{{ route('tenders.collaborators.create_user', $c) }}"
                                                  class="inline-block ml-3"
                                                  onsubmit="return confirm('Criar User para {{ $c->name }} ({{ $c->email }}) e enviar email de activação?')">
                                                @csrf
                                                <button type="submit" class="text-xs text-amber-700 hover:underline font-medium">
                                                    ➕ Criar conta
                                                </button>
                                            </form>
                                        @endif

                                        @if($c->is_active)
                                            {{-- ACTIVE row: can desactivate (soft). --}}
                                            <form method="POST" action="{{ route('tenders.collaborators.destroy', $c) }}"
                                                  class="inline-block ml-3"
                                                  onsubmit="return confirm('Desactivar {{ $c->name }}? Sai do dashboard e do dropdown de atribuição, mas o histórico é mantido.')">
                                                @csrf @method('DELETE')
                                                <button type="submit" class="text-xs text-red-700 hover:underline"
                                                        title="Desactiva — esconde do dashboard mas preserva histórico">
                                                    Desactivar
                                                </button>
                                            </form>
                                        @else
                                            {{-- INACTIVE row: two actions —
                                                 1) Reactivar  — bring back into assign dropdown + digest
                                                 2) Excluir    — hard-delete, only possible when the row
                                                                 has never been assigned to a tender. The
                                                                 button is still rendered for rows with
                                                                 history but disabled, with a tooltip
                                                                 explaining why (avoids silent nullify). --}}
                                            <form method="POST" action="{{ route('tenders.collaborators.reactivate', $c) }}"
                                                  class="inline-block ml-3"
                                                  onsubmit="return confirm('Reactivar {{ $c->name }}? Volta a aparecer no dropdown de atribuição e no digest.')">
                                                @csrf
                                                <button type="submit" class="text-xs text-emerald-700 hover:underline font-medium"
                                                        title="Reactiva — volta ao dashboard e recebe lembretes">
                                                    ↻ Reactivar
                                                </button>
                                            </form>

                                            @if($c->tenders_count === 0)
                                                <form method="POST" action="{{ route('tenders.collaborators.force_destroy', $c) }}"
                                                      class="inline-block ml-3"
                                                      onsubmit="return confirm('⚠ EXCLUIR PERMANENTEMENTE {{ $c->name }}? Esta acção é irreversível. Nenhum concurso lhe está atribuído, pelo que não há histórico para preservar.')">
                                                    @csrf @method('DELETE')
                                                    <button type="submit" class="text-xs text-red-800 hover:underline font-semibold"
                                                            title="Excluir permanentemente — sem histórico a preservar">
                                                        ✗ Excluir
                                                    </button>
                                                </form>
                                            @else
                                                <span class="inline-block ml-3 text-xs text-gray-400 cursor-not-allowed"
                                                      title="Não se pode excluir — tem {{ $c->tenders_count }} concurso(s) atribuído(s). Histórico seria desligado.">
                                                    ✗ Excluir
                                                </span>
                                            @endif
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
