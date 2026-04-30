{{--
    /leads/{id} — drill-down on a single lead. The point is full
    transparency: the admin sees EXACTLY what each agent contributed,
    so they trust (or distrust) the synthesised opportunity with
    evidence. Without this drill-down the swarm is an opaque
    "AI said so" black box and nobody acts on the leads.
--}}
<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-3 flex-wrap">
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                <a href="{{ route('leads.index') }}" class="text-indigo-600 hover:underline">← Leads</a>
                <span class="ml-2">{{ \Illuminate\Support\Str::limit($lead->title, 80) }}</span>
            </h2>
            <span class="rounded-full px-3 py-1 text-xs font-bold
                {{ $lead->score >= 70 ? 'bg-emerald-100 text-emerald-800' : ($lead->score >= 30 ? 'bg-blue-100 text-blue-800' : 'bg-gray-100 text-gray-600') }}">
                Score {{ $lead->score }}/100
            </span>
        </div>
    </x-slot>

    <div class="py-6">
        <div class="mx-auto max-w-5xl px-4 sm:px-6 lg:px-8 space-y-4">

            @if(session('status'))
                <div class="rounded-md bg-emerald-50 border border-emerald-200 px-4 py-2 text-sm text-emerald-800">
                    {{ session('status') }}
                </div>
            @endif

            <section class="rounded-lg bg-white shadow-sm border border-gray-200 p-5">
                <h3 class="text-sm font-semibold text-gray-800 mb-3">Resumo</h3>
                <p class="text-gray-700 text-sm leading-relaxed">{{ $lead->summary }}</p>
                <dl class="mt-4 grid grid-cols-2 gap-x-6 gap-y-2 text-xs sm:grid-cols-4">
                    <div>
                        <dt class="text-gray-500">Cliente (hint)</dt>
                        <dd class="text-gray-800 font-medium">{{ $lead->customer_hint ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Equipamento (hint)</dt>
                        <dd class="text-gray-800 font-medium">{{ $lead->equipment_hint ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Origem</dt>
                        <dd class="text-gray-800 font-mono">{{ $lead->source_signal_type }} #{{ $lead->source_signal_id ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Custo do swarm</dt>
                        <dd class="text-gray-800 font-mono">${{ number_format((float) $lead->swarmRun?->cost_usd, 4) }}</dd>
                    </div>
                </dl>
            </section>

            {{-- Triage form — same fields as on /leads index, just isolated. --}}
            <section class="rounded-lg bg-white shadow-sm border border-gray-200 p-5">
                <h3 class="text-sm font-semibold text-gray-800 mb-3">Triagem</h3>
                <form method="POST" action="{{ route('leads.update', $lead) }}" class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                    @csrf @method('PATCH')
                    <label class="text-sm">
                        <span class="text-xs text-gray-600">Status</span>
                        <select name="status" class="mt-1 block w-full rounded-md border-gray-300 text-sm">
                            @foreach(['draft','review','confident','contacted','won','lost','discarded'] as $s)
                                <option value="{{ $s }}" @selected($lead->status === $s)>{{ ucfirst($s) }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="text-sm">
                        <span class="text-xs text-gray-600">Atribuído a</span>
                        <select name="assigned_user_id" class="mt-1 block w-full rounded-md border-gray-300 text-sm">
                            <option value="">— ninguém —</option>
                            @foreach(\App\Models\User::where('is_active', true)->whereIn('role',['user','manager','admin'])->orderBy('name')->get(['id','name']) as $u)
                                <option value="{{ $u->id }}" @selected($lead->assigned_user_id === $u->id)>{{ $u->name }}</option>
                            @endforeach
                        </select>
                    </label>
                    <div class="sm:col-span-3">
                        <span class="text-xs text-gray-600">Notas</span>
                        <textarea name="notes" rows="3" class="mt-1 block w-full rounded-md border-gray-300 text-sm">{{ $lead->notes }}</textarea>
                    </div>
                    <div class="sm:col-span-3 flex justify-end">
                        <button class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white">Guardar</button>
                    </div>
                </form>
            </section>

            {{-- ─── Outreach pipeline ──────────────────────────────────────
                 Cold-email drafter. The cron generates a draft for every
                 confident lead overnight; the manager reviews + edits +
                 approves + sends here. State machine in the migration.
            --}}
            @php
                $os         = $lead->outreach_status ?? 'none';
                $hasDraft   = !empty($lead->outreach_draft_subject) || !empty($lead->outreach_draft_body);
                $isPending  = $os === \App\Models\LeadOpportunity::OUTREACH_DRAFT_PENDING;
                $isApproved = $os === \App\Models\LeadOpportunity::OUTREACH_APPROVED;
                $isSent     = $os === \App\Models\LeadOpportunity::OUTREACH_SENT;
                $isReject   = $os === \App\Models\LeadOpportunity::OUTREACH_REJECTED;

                $statusBadge = [
                    'none'           => ['label' => 'Sem draft',        'class' => 'bg-gray-100 text-gray-600'],
                    'draft_pending'  => ['label' => 'Draft pendente',   'class' => 'bg-amber-100 text-amber-800'],
                    'approved'       => ['label' => 'Aprovado',         'class' => 'bg-blue-100 text-blue-800'],
                    'sent'           => ['label' => 'Enviado',          'class' => 'bg-emerald-100 text-emerald-800'],
                    'rejected'       => ['label' => 'Rejeitado',        'class' => 'bg-red-100 text-red-700'],
                ][$os] ?? ['label' => $os, 'class' => 'bg-gray-100 text-gray-700'];
            @endphp

            <section class="rounded-lg bg-white shadow-sm border border-gray-200 p-5">
                <div class="flex items-center justify-between gap-3 mb-3 flex-wrap">
                    <h3 class="text-sm font-semibold text-gray-800">📧 Outreach</h3>
                    <span class="rounded-full px-2.5 py-0.5 text-[11px] font-semibold {{ $statusBadge['class'] }}">
                        {{ $statusBadge['label'] }}
                    </span>
                </div>

                @error('outreach')
                    <div class="mb-3 rounded-md bg-red-50 border border-red-200 px-3 py-2 text-xs text-red-700">{{ $message }}</div>
                @enderror

                @if(!$hasDraft && $os === 'none')
                    <p class="text-xs text-gray-500 mb-3">
                        @if($lead->status === 'confident')
                            Ainda não foi gerado um draft para este lead. O cron diário (08:30 Lisboa)
                            trata destes automaticamente. Podes também forçar agora:
                        @else
                            Drafts automáticos só são criados para leads com status <code>confident</code>.
                            Promove o status na secção de Triagem para activar.
                        @endif
                    </p>
                    @if($lead->status === 'confident')
                        <form method="POST" action="{{ route('leads.outreach.draft', $lead) }}">
                            @csrf
                            <button class="rounded-md bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white">
                                Gerar draft agora
                            </button>
                        </form>
                    @endif
                @else
                    {{-- Edit form — visible while pending or approved.
                         Submitting changes after approve kicks back to pending. --}}
                    @if($isPending || $isApproved)
                        <form method="POST" action="{{ route('leads.outreach.update', $lead) }}" class="space-y-3">
                            @csrf @method('PATCH')
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <label class="text-sm">
                                    <span class="text-xs text-gray-600">Para (email)</span>
                                    <input type="email" name="outreach_to_email"
                                           value="{{ old('outreach_to_email', $lead->outreach_to_email) }}"
                                           placeholder="ex.: compras@cliente.com"
                                           class="mt-1 block w-full rounded-md border-gray-300 text-sm">
                                </label>
                                <label class="text-sm">
                                    <span class="text-xs text-gray-600">Para (nome, opcional)</span>
                                    <input type="text" name="outreach_to_name"
                                           value="{{ old('outreach_to_name', $lead->outreach_to_name) }}"
                                           class="mt-1 block w-full rounded-md border-gray-300 text-sm">
                                </label>
                            </div>
                            <label class="text-sm block">
                                <span class="text-xs text-gray-600">Assunto</span>
                                <input type="text" name="outreach_draft_subject" required
                                       value="{{ old('outreach_draft_subject', $lead->outreach_draft_subject) }}"
                                       maxlength="255"
                                       class="mt-1 block w-full rounded-md border-gray-300 text-sm">
                            </label>
                            <label class="text-sm block">
                                <span class="text-xs text-gray-600">Corpo</span>
                                <textarea name="outreach_draft_body" rows="10" required maxlength="8000"
                                          class="mt-1 block w-full rounded-md border-gray-300 text-sm font-mono leading-relaxed">{{ old('outreach_draft_body', $lead->outreach_draft_body) }}</textarea>
                            </label>
                            <div class="flex items-center justify-between gap-3 flex-wrap">
                                <div class="text-[11px] text-gray-500">
                                    Drafted {{ $lead->outreach_drafted_at?->diffForHumans() }} ·
                                    Custo do draft: ${{ number_format((float) $lead->outreach_draft_cost_usd, 4) }}
                                    @if($isApproved && $lead->outreach_approved_by_user_id)
                                        · Aprovado por <span class="font-medium">{{ $lead->outreach_approved_by_user_id === auth()->id() ? 'ti' : 'manager#' . $lead->outreach_approved_by_user_id }}</span>
                                        ({{ $lead->outreach_approved_at?->diffForHumans() }})
                                    @endif
                                </div>
                                <button class="rounded-md border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50">
                                    Guardar alterações
                                </button>
                            </div>
                        </form>

                        {{-- Action row — separate forms so each button is its own POST. --}}
                        <div class="mt-4 flex items-center gap-2 flex-wrap border-t border-gray-100 pt-3">
                            @if($isPending)
                                <form method="POST" action="{{ route('leads.outreach.approve', $lead) }}">
                                    @csrf
                                    <button class="rounded-md bg-blue-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-blue-500">
                                        Aprovar
                                    </button>
                                </form>
                            @endif

                            @if($isApproved)
                                <form method="POST" action="{{ route('leads.outreach.send', $lead) }}"
                                      onsubmit="return confirm('Enviar o email para {{ $lead->outreach_to_email }}?');">
                                    @csrf
                                    <button class="rounded-md bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-emerald-500">
                                        Enviar agora ✉
                                    </button>
                                </form>
                            @endif

                            <form method="POST" action="{{ route('leads.outreach.draft', $lead) }}">
                                @csrf
                                <button class="rounded-md border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50">
                                    Regenerar draft
                                </button>
                            </form>

                            <details class="ml-auto">
                                <summary class="cursor-pointer rounded-md border border-red-200 px-3 py-1.5 text-xs font-medium text-red-700 hover:bg-red-50">
                                    Rejeitar…
                                </summary>
                                <form method="POST" action="{{ route('leads.outreach.reject', $lead) }}"
                                      class="mt-2 flex items-center gap-2">
                                    @csrf
                                    <input type="text" name="outreach_reject_reason" maxlength="500"
                                           placeholder="Motivo (opcional, ajuda a melhorar drafts futuros)"
                                           class="rounded-md border-gray-300 text-xs w-72">
                                    <button class="rounded-md bg-red-600 px-3 py-1.5 text-xs font-semibold text-white">
                                        Confirmar rejeição
                                    </button>
                                </form>
                            </details>
                        </div>
                    @endif

                    {{-- Read-only view for sent / rejected drafts. --}}
                    @if($isSent || $isReject)
                        <div class="rounded-md border border-gray-200 bg-gray-50 px-4 py-3 text-xs space-y-2">
                            <div class="grid grid-cols-2 gap-x-6 gap-y-1">
                                <div><span class="text-gray-500">Para:</span> <span class="font-mono">{{ $lead->outreach_to_email ?: '—' }}</span></div>
                                <div><span class="text-gray-500">Assunto:</span> <span class="font-medium">{{ $lead->outreach_draft_subject }}</span></div>
                                @if($isSent)
                                    <div><span class="text-gray-500">Enviado:</span> {{ $lead->outreach_sent_at?->format('d/m/Y H:i') }}</div>
                                    <div><span class="text-gray-500">Por:</span> manager#{{ $lead->outreach_sent_by_user_id }}</div>
                                @endif
                                @if($isReject && $lead->outreach_reject_reason)
                                    <div class="col-span-2"><span class="text-gray-500">Motivo:</span> <span class="text-red-700">{{ $lead->outreach_reject_reason }}</span></div>
                                @endif
                            </div>
                            <pre class="whitespace-pre-wrap font-sans text-gray-800 mt-2">{{ $lead->outreach_draft_body }}</pre>
                        </div>
                    @endif
                @endif
            </section>

            {{-- Chain log — the audit trail. Every step the swarm took
                 is rendered here verbatim so the admin can sanity-check
                 the AI's reasoning. Critical for trust. --}}
            <section class="rounded-lg bg-white shadow-sm border border-gray-200 p-5">
                <h3 class="text-sm font-semibold text-gray-800 mb-3">
                    🔬 Audit do swarm
                    <span class="ml-2 text-xs font-normal text-gray-500">{{ optional($lead->swarmRun)->chain_name }} ·
                        {{ optional($lead->swarmRun)->status }} ·
                        {{ optional($lead->swarmRun)->started_at?->diffForHumans() }}</span>
                </h3>
                @php($steps = (array) optional($lead->swarmRun)->chain_log)
                @if(count($steps) === 0)
                    <p class="text-xs text-gray-500">Sem registos de chain (ainda).</p>
                @else
                    <ol class="space-y-2 text-xs">
                        @foreach($steps as $i => $step)
                            <li class="rounded border border-gray-100 bg-gray-50 px-3 py-2">
                                <div class="flex items-center gap-2 flex-wrap">
                                    <span class="font-mono text-gray-400">#{{ $i+1 }}</span>
                                    <span class="font-semibold text-gray-700">{{ $step['event'] ?? '—' }}</span>
                                    @if(!empty($step['agent']))
                                        <span class="rounded bg-indigo-100 text-indigo-800 px-1.5 py-0.5 font-mono">{{ $step['agent'] }}</span>
                                    @endif
                                    @if(!empty($step['phase']))
                                        <span class="text-gray-500">{{ $step['phase'] }}</span>
                                    @endif
                                    @if(isset($step['cost_usd']))
                                        <span class="ml-auto text-gray-500">${{ number_format((float) $step['cost_usd'], 4) }}</span>
                                    @endif
                                </div>
                                @if(!empty($step['output']))
                                    <pre class="mt-2 whitespace-pre-wrap font-mono text-[11px] text-gray-700">{{ json_encode($step['output'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                                @endif
                                @if(!empty($step['reason']))
                                    <div class="mt-1 text-amber-700">↳ {{ $step['reason'] }}</div>
                                @endif
                            </li>
                        @endforeach
                    </ol>
                @endif
            </section>
        </div>
    </div>
</x-app-layout>
