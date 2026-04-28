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
