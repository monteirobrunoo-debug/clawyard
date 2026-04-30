@php
    /**
     * Tender detail page.
     *
     *   - Edit form: all fields mutable except deletion (user explicitly asked
     *     "pode editar todos os campos, mas nao pode apagar").
     *   - Observations panel: append-only notes with [timestamp — user] prefix
     *     (serialised into the `notes` column by TenderController@observe).
     *   - Similar opportunities: Jaccard title match with boosts for same type,
     *     purchasing_org and presence of sap_opportunity_number — the "posteriormente
     *     a outra base de dados para sugerir oportunidades iguais" requirement.
     */
    $statusLabels = [
        \App\Models\Tender::STATUS_PENDING       => 'Pendente',
        \App\Models\Tender::STATUS_EM_TRATAMENTO => 'Em Tratamento',
        \App\Models\Tender::STATUS_SUBMETIDO     => 'Submetido',
        \App\Models\Tender::STATUS_AVALIACAO     => 'Em Avaliação',
        \App\Models\Tender::STATUS_CANCELADO     => 'Cancelado',
        \App\Models\Tender::STATUS_NAO_TRATAR    => 'Não Tratar',
        \App\Models\Tender::STATUS_GANHO         => 'Ganho',
        \App\Models\Tender::STATUS_PERDIDO       => 'Perdido',
    ];
    $urgencyClasses = [
        'overdue'  => 'bg-red-100 text-red-800 border-red-300',
        'critical' => 'bg-orange-100 text-orange-800 border-orange-300',
        'urgent'   => 'bg-yellow-100 text-yellow-800 border-yellow-300',
        'soon'     => 'bg-blue-100 text-blue-800 border-blue-300',
        'normal'   => 'bg-gray-100 text-gray-700 border-gray-300',
        'unknown'  => 'bg-gray-50 text-gray-500 border-gray-200',
    ];
@endphp

<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-3 flex-wrap">
            <div class="flex items-center gap-3 min-w-0">
                <a href="{{ route('tenders.index') }}" class="text-sm text-indigo-600 hover:underline shrink-0">← Voltar</a>
                <h2 class="text-xl font-semibold leading-tight text-gray-800 truncate">
                    {{ strtoupper($tender->source) }} · {{ $tender->reference }}
                </h2>
            </div>
            <button type="button"
                    onclick="copyTenderInfo()"
                    title="Copia toda a info deste concurso para a clipboard — pronto para colar num agente ou numa nota externa"
                    class="inline-flex items-center gap-2 rounded-md border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50">
                📋 <span id="copy-btn-label">Copiar info completa</span>
            </button>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-7xl sm:px-6 lg:px-8 space-y-6">

            @if(session('status'))
                <div class="rounded-md bg-green-50 border border-green-200 p-4 text-sm text-green-800">
                    {{ session('status') }}
                </div>
            @endif

            @if($errors->any())
                <div class="rounded-md bg-red-50 border border-red-200 p-4 text-sm text-red-800">
                    <ul class="list-disc list-inside">
                        @foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach
                    </ul>
                </div>
            @endif

            {{-- ─── Header card ───────────────────────────────────────────── --}}
            <section class="rounded-lg bg-white shadow-sm border border-gray-100 p-6">
                <div class="flex items-start justify-between gap-4 flex-wrap">
                    <div class="min-w-0 flex-1">
                        <h1 class="text-2xl font-bold text-gray-900">{{ $tender->title }}</h1>
                        <div class="mt-2 flex flex-wrap items-center gap-2">
                            <span class="inline-flex rounded bg-gray-100 px-2.5 py-1 text-xs font-medium text-gray-700">
                                {{ $statusLabels[$tender->status] ?? $tender->status }}
                            </span>
                            @if($tender->type)
                                <span class="inline-flex rounded border border-gray-200 px-2.5 py-1 text-xs text-gray-600">
                                    {{ $tender->type }}
                                </span>
                            @endif
                            <span class="inline-flex rounded border px-2.5 py-1 text-xs font-medium {{ $urgencyClasses[$tender->urgency_bucket] ?? $urgencyClasses['unknown'] }}">
                                @if($tender->urgency_bucket === 'overdue')
                                    Em atraso {{ abs($tender->days_to_deadline) }}d
                                @elseif($tender->days_to_deadline !== null)
                                    {{ $tender->days_to_deadline }}d até deadline
                                @else
                                    Sem deadline
                                @endif
                            </span>
                        </div>
                    </div>
                    <div class="text-right text-sm">
                        {{-- Single-timezone deadline. The dual PT/LU readout
                             was simplified 2026-04-27 — operators only care
                             about the value as imported from the source. --}}
                        <dl class="space-y-1">
                            <div>
                                <dt class="inline text-gray-500">Deadline:</dt>
                                <dd class="inline font-medium text-gray-800">
                                    {{ $tender->deadline_lisbon?->format('d/m/Y H:i') ?? '—' }}
                                </dd>
                            </div>
                        </dl>
                    </div>
                </div>

                <dl class="mt-6 grid grid-cols-2 gap-x-6 gap-y-3 sm:grid-cols-4 text-sm">
                    <div>
                        <dt class="text-xs uppercase text-gray-500">Colaborador</dt>
                        <dd class="font-medium text-gray-900">{{ $tender->collaborator?->name ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase text-gray-500">Nº Oportunidade SAP</dt>
                        <dd class="font-mono text-gray-900">{{ $tender->sap_opportunity_number ?: '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase text-gray-500">Organização</dt>
                        <dd class="text-gray-900">{{ $tender->purchasing_org ?: '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase text-gray-500">Atribuído por</dt>
                        <dd class="text-gray-900">{{ $tender->assignedBy?->name ?? '—' }}</dd>
                    </div>
                </dl>
            </section>

            {{-- ─── Sugerir fornecedores + drafts ──────────────────────────
                 Click → AJAX → mostra lista de fornecedores aprovados (H&P)
                 que fazem match na categoria do concurso + sugestões web
                 (Tavily). User selecciona com checkbox quais quer contactar
                 e clica "Gerar drafts" → Daniel devolve 1 email por
                 fornecedor (formato SHAPE B), renderizados inline com botão
                 Outlook em cada um.
            --}}
            <section id="supplier-suggester" class="rounded-lg bg-white shadow-sm border border-gray-100 p-6">
                <div class="flex items-center justify-between gap-3 flex-wrap">
                    <div>
                        <h2 class="text-base font-semibold text-gray-800">🤖 Sugerir fornecedores e drafts</h2>
                        <p class="mt-1 text-xs text-gray-500">
                            H&amp;P aprovados (Excel 2026) + sugestões da web — Daniel escreve 1 email tailored por fornecedor.
                        </p>
                    </div>
                    <div class="flex items-center gap-2">
                        <label class="text-xs text-gray-600 inline-flex items-center gap-1">
                            <input type="checkbox" id="ss-include-web" checked class="rounded border-gray-300">
                            incluir web
                        </label>
                        <button type="button" id="ss-search-btn"
                                class="rounded-md bg-indigo-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-indigo-500">
                            🔎 Procurar fornecedores
                        </button>
                    </div>
                </div>

                <div id="ss-results" class="mt-4 hidden"></div>

                <template id="ss-loading-tpl">
                    <div class="text-xs text-gray-500 py-4">A procurar fornecedores…</div>
                </template>
            </section>

            <script>
            (function () {
                const btn      = document.getElementById('ss-search-btn');
                const incWeb   = document.getElementById('ss-include-web');
                const results  = document.getElementById('ss-results');
                const tenderId = {{ $tender->id }};
                const csrf     = document.querySelector('meta[name="csrf-token"]')?.content || '';

                function esc(s) {
                    return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
                }

                btn.addEventListener('click', async () => {
                    btn.disabled = true;
                    btn.textContent = '⏳ A procurar…';
                    results.classList.remove('hidden');
                    results.innerHTML = '<div class="text-xs text-gray-500 py-4">A procurar fornecedores na base de dados H&P + web…</div>';

                    try {
                        const res = await fetch(`/tenders/${tenderId}/suggest-suppliers`, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': csrf,
                                'Accept': 'application/json',
                            },
                            body: JSON.stringify({ include_web: incWeb.checked }),
                            credentials: 'same-origin',
                        });
                        if (!res.ok) throw new Error(`HTTP ${res.status}`);
                        const data = await res.json();
                        renderSuggestions(data);
                    } catch (e) {
                        results.innerHTML = `<div class="text-sm text-red-700 py-3">Erro: ${esc(e.message)}</div>`;
                    } finally {
                        btn.disabled = false;
                        btn.textContent = '🔎 Procurar fornecedores';
                    }
                });

                function renderSuggestions(data) {
                    const cats = (data.categories || []).join(', ');
                    const local = data.local || [];
                    const web   = data.web   || [];

                    let html = `
                        <div class="rounded-md border border-gray-100 bg-gray-50 px-3 py-2 text-xs text-gray-600 mb-3">
                            🏷️ Categorias inferidas: <span class="font-mono text-gray-800">${esc(cats || 'nenhuma')}</span>
                            · ${local.length} fornecedor(es) H&amp;P · ${web.length} sugestão(ões) web
                        </div>
                    `;

                    // Local approved
                    if (local.length === 0) {
                        html += `
                            <div class="rounded-md bg-amber-50 border border-amber-200 px-3 py-2 text-xs text-amber-800 mb-3">
                                Nenhum fornecedor H&amp;P aprovado faz match nas categorias inferidas.
                                Podes adicionar manualmente em <a href="/suppliers/create" class="underline">/suppliers/create</a>.
                            </div>
                        `;
                    } else {
                        html += `
                            <form id="ss-draft-form" class="space-y-2">
                                <h3 class="text-sm font-semibold text-gray-800">Fornecedores H&amp;P (aprovados)</h3>
                                <div class="border border-gray-200 rounded-md divide-y divide-gray-100 bg-white">
                        `;
                        local.forEach((s, idx) => {
                            const iqfBadge = s.iqf_score !== null
                                ? `<span class="inline-block rounded ${s.iqf_score >= 2.75 ? 'bg-emerald-100 text-emerald-800' : 'bg-blue-100 text-blue-800'} px-1.5 py-0.5 text-[10px] font-bold">IQF ${s.iqf_score}</span>`
                                : '';
                            const emailBadge = s.has_email
                                ? `<span class="text-blue-700 font-mono text-[11px]">${esc(s.primary_email)}</span>`
                                : `<span class="text-amber-700 text-[11px] italic">⚠ sem email — preencher antes de enviar</span>`;
                            const cats = (s.categories || []).slice(0,4).map(c =>
                                `<span class="inline-block rounded bg-gray-100 text-gray-700 px-1.5 py-0.5 text-[10px] font-mono">${esc(c)}</span>`
                            ).join(' ');
                            html += `
                                <label class="flex items-start gap-3 px-3 py-2 hover:bg-indigo-50 cursor-pointer">
                                    <input type="checkbox" name="supplier_ids[]" value="${s.id}" ${s.has_email ? 'checked' : ''}
                                           class="mt-0.5 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                                    <div class="flex-1 min-w-0">
                                        <div class="flex items-center gap-2 flex-wrap">
                                            <a href="${s.detail_url}" target="_blank" class="text-sm font-medium text-indigo-700 hover:underline">${esc(s.name)}</a>
                                            ${iqfBadge}
                                            ${cats}
                                        </div>
                                        <div class="mt-0.5 flex items-center gap-3 flex-wrap text-[11px]">
                                            ${emailBadge}
                                            ${s.last_contacted ? `<span class="text-gray-500">último contacto: ${esc(s.last_contacted)}</span>` : ''}
                                            ${s.brands && s.brands.length ? `<span class="text-gray-500">marcas: ${esc(s.brands.slice(0,3).join(', '))}</span>` : ''}
                                        </div>
                                    </div>
                                </label>
                            `;
                        });
                        html += `
                                </div>
                                <div class="flex items-center gap-3 flex-wrap pt-2">
                                    <select id="ss-language" class="rounded-md border-gray-300 text-xs">
                                        <option value="pt" selected>Português (pt)</option>
                                        <option value="en">English (en)</option>
                                        <option value="es">Español (es)</option>
                                    </select>
                                    <input type="text" id="ss-note" placeholder="Notas extra para o Daniel (opcional)…"
                                           class="flex-1 min-w-[200px] rounded-md border-gray-300 text-xs">
                                    <button type="submit" id="ss-draft-btn"
                                            class="rounded-md bg-emerald-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-emerald-500">
                                        ✉ Gerar drafts (Daniel Email)
                                    </button>
                                </div>
                            </form>
                        `;
                    }

                    // Web suggestions (advisory only — not selectable)
                    if (web.length > 0) {
                        html += `
                            <div class="mt-4 pt-3 border-t border-gray-100">
                                <h3 class="text-sm font-semibold text-gray-800 mb-2">💡 Sugestões da web (não aprovados — investigar)</h3>
                                <div class="space-y-2">
                        `;
                        web.forEach(w => {
                            html += `
                                <div class="rounded-md border border-gray-200 bg-amber-50/30 px-3 py-2 text-xs">
                                    <a href="${esc(w.url)}" target="_blank" rel="noopener" class="font-semibold text-amber-800 hover:underline">${esc(w.title)}</a>
                                    <div class="text-gray-600 mt-0.5">${esc(w.snippet || '')}</div>
                                    <div class="font-mono text-[10px] text-gray-400 mt-0.5 truncate">${esc(w.url)}</div>
                                </div>
                            `;
                        });
                        html += `
                                </div>
                                <p class="mt-2 text-[11px] text-gray-500">
                                    Para promover um destes a fornecedor aprovado, abre <a href="/suppliers/create" class="underline">/suppliers/create</a>.
                                </p>
                            </div>
                        `;
                    } else if (data.web_available === false) {
                        html += `
                            <div class="mt-4 text-[11px] text-gray-500">
                                💡 Pesquisa web indisponível (TAVILY_API_KEY não configurado).
                            </div>
                        `;
                    }

                    // Container for the generated drafts
                    html += `<div id="ss-drafts" class="mt-5"></div>`;

                    results.innerHTML = html;

                    const draftForm = document.getElementById('ss-draft-form');
                    if (draftForm) draftForm.addEventListener('submit', onDraftSubmit);
                }

                async function onDraftSubmit(e) {
                    e.preventDefault();
                    const form = e.currentTarget;
                    const ids = Array.from(form.querySelectorAll('input[name="supplier_ids[]"]:checked')).map(i => parseInt(i.value, 10));
                    if (ids.length === 0) {
                        alert('Selecciona pelo menos um fornecedor.');
                        return;
                    }
                    if (ids.length > 12) {
                        alert('Máximo 12 fornecedores por batch — desselecciona alguns.');
                        return;
                    }

                    const draftBtn = document.getElementById('ss-draft-btn');
                    const dropbox  = document.getElementById('ss-drafts');
                    draftBtn.disabled = true;
                    draftBtn.textContent = '⏳ Daniel a escrever…';
                    dropbox.innerHTML = '<div class="text-xs text-gray-500 py-4">Daniel está a escrever os emails. Pode demorar 20-30s…</div>';

                    try {
                        const res = await fetch(`/tenders/${tenderId}/draft-supplier-emails`, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': csrf,
                                'Accept': 'application/json',
                            },
                            body: JSON.stringify({
                                supplier_ids: ids,
                                language: document.getElementById('ss-language').value,
                                note:     document.getElementById('ss-note').value.trim(),
                            }),
                            credentials: 'same-origin',
                        });
                        if (!res.ok) {
                            const err = await res.text();
                            throw new Error(`HTTP ${res.status}: ${err.slice(0,200)}`);
                        }
                        const data = await res.json();
                        renderDrafts(dropbox, data);
                    } catch (e) {
                        dropbox.innerHTML = `<div class="text-sm text-red-700 py-3">Erro a gerar drafts: ${esc(e.message)}</div>`;
                    } finally {
                        draftBtn.disabled = false;
                        draftBtn.textContent = '✉ Gerar drafts (Daniel Email)';
                    }
                }

                function renderDrafts(box, data) {
                    if (data.shape === 'fallback') {
                        box.innerHTML = `
                            <div class="rounded-md bg-amber-50 border border-amber-200 px-3 py-2 text-xs text-amber-800">
                                Daniel devolveu texto livre em vez de drafts estruturados. Conteúdo:
                                <pre class="mt-2 whitespace-pre-wrap font-mono text-[11px] text-gray-700">${esc(data.text || '')}</pre>
                            </div>
                        `;
                        return;
                    }

                    const emails = data.emails || [];
                    if (emails.length === 0) {
                        box.innerHTML = `<div class="text-sm text-amber-700">Daniel não devolveu emails utilizáveis — tenta de novo.</div>`;
                        return;
                    }

                    let html = `
                        <h3 class="text-sm font-semibold text-gray-800 mb-2">📧 ${emails.length} draft(s) gerado(s) — revê e edita antes de enviar</h3>
                        <div class="space-y-3">
                    `;
                    emails.forEach((em, i) => {
                        const id = `ts_em_${tenderId}_${i}_${Date.now()}`;
                        html += `
                            <div class="rounded-md border border-gray-200 bg-white">
                                <div class="px-3 py-2 bg-gray-50 border-b border-gray-100 flex items-center justify-between gap-2 flex-wrap">
                                    <span class="text-xs font-semibold text-gray-700">${i+1}/${emails.length} · ${esc(em.supplier || em.to || 'fornecedor')}</span>
                                    ${em.template ? `<span class="text-[10px] text-gray-500">${esc(em.template)}</span>` : ''}
                                </div>
                                <div class="px-3 py-2 space-y-2" id="${id}_card">
                                    <div class="flex items-center gap-2 text-xs">
                                        <label class="text-gray-500 w-16">Para</label>
                                        <input type="email" id="${id}_to" value="${esc(em.to || '')}"
                                               class="flex-1 rounded-md border-gray-300 text-xs font-mono">
                                    </div>
                                    <div class="flex items-center gap-2 text-xs">
                                        <label class="text-gray-500 w-16">Assunto</label>
                                        <input type="text" id="${id}_subject" value="${esc(em.subject || '')}"
                                               class="flex-1 rounded-md border-gray-300 text-xs">
                                    </div>
                                    <textarea id="${id}_body" rows="8"
                                              class="w-full rounded-md border-gray-300 text-xs font-mono leading-relaxed">${esc(em.body || '')}</textarea>
                                    <input type="hidden" id="${id}_cc" value="${esc(em.cc || '')}">
                                    <div class="flex items-center gap-2 pt-1">
                                        <button type="button" data-card-id="${id}" data-action="outlook"
                                                class="rounded-md bg-blue-600 text-white px-3 py-1 text-xs font-semibold hover:bg-blue-500">
                                            ✉ Abrir no Outlook
                                        </button>
                                        <button type="button" data-card-id="${id}" data-action="copy"
                                                class="rounded-md border border-gray-300 px-3 py-1 text-xs text-gray-700 hover:bg-gray-50">
                                            📋 Copiar
                                        </button>
                                        <span class="text-[11px] text-gray-500" id="${id}_status"></span>
                                    </div>
                                </div>
                            </div>
                        `;
                    });
                    html += `
                        </div>
                        <div class="mt-3 flex items-center gap-3 flex-wrap rounded-md border border-dashed border-blue-300 bg-blue-50/30 px-3 py-2">
                            <button type="button" id="ss-open-all"
                                    class="rounded-md bg-blue-600 text-white px-3 py-1.5 text-xs font-semibold hover:bg-blue-500">
                                ✉ Abrir TODOS no Outlook (sequência)
                            </button>
                            <span class="text-[11px] text-gray-500">⚠️ ${emails.length} mailto: serão disparados — o browser pode pedir confirmação para o segundo em diante.</span>
                        </div>
                    `;

                    box.innerHTML = html;

                    // Hook up the per-card buttons.
                    box.querySelectorAll('[data-card-id]').forEach(b => {
                        b.addEventListener('click', () => {
                            const id = b.dataset.cardId;
                            const action = b.dataset.action;
                            if (action === 'outlook') openInOutlookLocal(id);
                            else if (action === 'copy') copyEmailLocal(id);
                        });
                    });
                    document.getElementById('ss-open-all')?.addEventListener('click', () => {
                        box.querySelectorAll('[data-card-id][data-action="outlook"]').forEach((b, i) => {
                            setTimeout(() => b.click(), i * 700);
                        });
                    });
                }

                function openInOutlookLocal(id) {
                    const to      = document.getElementById(id+'_to')?.value.trim() || '';
                    const cc      = document.getElementById(id+'_cc')?.value.trim() || '';
                    const subject = document.getElementById(id+'_subject')?.value.trim() || '';
                    const body    = document.getElementById(id+'_body')?.value.trim() || '';

                    let mailto = 'mailto:' + encodeURIComponent(to);
                    const parts = [];
                    if (cc)      parts.push('cc='      + encodeURIComponent(cc));
                    if (subject) parts.push('subject=' + encodeURIComponent(subject));
                    if (body)    parts.push('body='    + encodeURIComponent(body));
                    if (parts.length) mailto += '?' + parts.join('&');
                    window.location.href = mailto;

                    const st = document.getElementById(id+'_status');
                    if (st) {
                        st.textContent = '📮 A abrir no cliente de email…';
                        setTimeout(() => { st.textContent = ''; }, 3000);
                    }
                }

                function copyEmailLocal(id) {
                    const subject = document.getElementById(id+'_subject')?.value || '';
                    const body    = document.getElementById(id+'_body')?.value || '';
                    const to      = document.getElementById(id+'_to')?.value || '';
                    const text    = (to ? 'Para: '+to+'\n' : '') + 'Assunto: '+subject+'\n\n'+body;
                    navigator.clipboard.writeText(text).then(() => {
                        const st = document.getElementById(id+'_status');
                        if (st) {
                            st.textContent = '✅ Copiado!';
                            setTimeout(() => { st.textContent = ''; }, 1800);
                        }
                    });
                }
            })();
            </script>

            {{-- ─── SAP B1 Opportunity card ───────────────────────────────
                 Phase 1 of the SAP integration: when the tender has a SAP
                 opportunity number, fetch live data from the Service Layer
                 (via /tenders/{id}/sap-preview JSON endpoint) and show it
                 here. Async so a slow SAP doesn't block the page render.
            --}}
            <section id="sap-opp-card"
                     class="rounded-lg bg-white shadow-sm border border-gray-100 p-5"
                     data-sap-url="{{ route('tenders.sap_preview', $tender) }}">
                <header class="flex items-center justify-between mb-3">
                    <h3 class="text-base font-semibold text-gray-800 flex items-center gap-2">
                        🔗 SAP Opportunity
                        <span id="sap-opp-ref" class="text-xs font-mono font-normal text-gray-500">{{ $tender->sap_opportunity_number ?: '—' }}</span>
                    </h3>
                    <button type="button" id="sap-opp-refresh"
                            class="text-xs text-indigo-600 hover:text-indigo-800"
                            title="Buscar outra vez ao SAP">↻ Actualizar</button>
                </header>

                <div id="sap-opp-body" class="text-sm text-gray-600">
                    <div class="flex items-center gap-2 text-gray-400">
                        <svg class="animate-spin h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                        </svg>
                        A contactar o SAP…
                    </div>
                </div>
            </section>

            <script>
            (function () {
                const root     = document.getElementById('sap-opp-card');
                const body     = document.getElementById('sap-opp-body');
                const refresh  = document.getElementById('sap-opp-refresh');
                if (!root || !body) return;

                const fmtEur = (n) => new Intl.NumberFormat('pt-PT', { style: 'currency', currency: 'EUR', maximumFractionDigits: 0 }).format(Number(n) || 0);
                const fmtDate = (iso) => {
                    if (!iso) return '—';
                    const s = String(iso).slice(0, 10);
                    if (!/^\d{4}-\d{2}-\d{2}$/.test(s)) return s;
                    const [y, m, d] = s.split('-');
                    return `${d}/${m}/${y}`;
                };
                const esc = (s) => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

                // State → human-friendly rendering
                const renderEmpty = (msg, tone = 'gray') => {
                    const colors = {
                        gray:   'bg-gray-50 border-gray-200 text-gray-600',
                        amber:  'bg-amber-50 border-amber-200 text-amber-800',
                        red:    'bg-red-50 border-red-200 text-red-800',
                    };
                    body.innerHTML = `<div class="rounded-md border p-3 text-xs ${colors[tone] || colors.gray}">${esc(msg)}</div>`;
                };

                const renderOk = (d) => {
                    const statusLabel = { O: 'Open', W: 'Won', L: 'Lost' }[d.status] || d.status || '—';
                    const statusColor = { O: 'bg-blue-100 text-blue-800', W: 'bg-emerald-100 text-emerald-800', L: 'bg-red-100 text-red-800' }[d.status] || 'bg-gray-100 text-gray-800';
                    const remarks = d.remarks ? esc(d.remarks) : '<span class="text-gray-400 italic">(sem Remarks no SAP)</span>';

                    const lastStage = d.last_stage ? `
                        <div class="mt-3 rounded-md bg-gray-50 border border-gray-200 p-2 text-xs text-gray-700">
                            <div class="font-semibold text-gray-800">Último estado (Níveis)</div>
                            <div>Stage #${d.last_stage.stage_key} · ${d.last_stage.percentage_rate}% · Vendedor: ${esc(d.last_stage.sales_employee) || '—'}</div>
                            <div class="text-gray-500">Início: ${fmtDate(d.last_stage.start_date)} · Fecho: ${fmtDate(d.last_stage.close_date)}</div>
                        </div>` : '';

                    body.innerHTML = `
                        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                            <div>
                                <div class="text-[10px] uppercase text-gray-500">Estado SAP</div>
                                <span class="inline-flex mt-0.5 rounded px-2 py-0.5 text-xs font-medium ${statusColor}">${esc(statusLabel)}</span>
                            </div>
                            <div>
                                <div class="text-[10px] uppercase text-gray-500">Cliente (BP)</div>
                                <div class="font-medium text-gray-900 truncate" title="${esc(d.bp_code)} · ${esc(d.bp_name)}">${esc(d.bp_name) || '—'}</div>
                                <div class="text-[10px] font-mono text-gray-400">${esc(d.bp_code) || ''}</div>
                            </div>
                            <div>
                                <div class="text-[10px] uppercase text-gray-500">Valor</div>
                                <div class="font-semibold text-gray-900">${fmtEur(d.max_local_total)}</div>
                                <div class="text-[10px] text-gray-400">Ponderado: ${fmtEur(d.weighted_total)}</div>
                            </div>
                            <div>
                                <div class="text-[10px] uppercase text-gray-500">Fecho previsto</div>
                                <div class="text-gray-900">${fmtDate(d.predicted_closing)}</div>
                                <div class="text-[10px] text-gray-400">${d.closing_percentage || 0}% prob.</div>
                            </div>
                        </div>
                        ${lastStage}
                        <div class="mt-3">
                            <div class="text-[10px] uppercase text-gray-500 mb-0.5">Remarks (SAP)</div>
                            <div class="rounded-md bg-indigo-50 border border-indigo-200 p-2 text-xs text-indigo-900 whitespace-pre-wrap">${remarks}</div>
                            <div class="text-[10px] text-gray-400 mt-1">
                                Ao guardar o campo <strong>Notas</strong> abaixo, este campo <em>Remarks</em> no SAP é actualizado automaticamente.
                            </div>
                        </div>
                    `;
                };

                const load = () => {
                    body.innerHTML = `<div class="flex items-center gap-2 text-gray-400">
                        <svg class="animate-spin h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                        </svg>
                        A contactar o SAP…
                    </div>`;

                    fetch(root.dataset.sapUrl, { headers: { 'Accept': 'application/json' } })
                        .then(r => r.json().then(j => ({ status: r.status, body: j })))
                        .then(({ status, body: payload }) => {
                            switch (payload.state) {
                                case 'ok':          return renderOk(payload.data);
                                case 'empty':
                                    // If the controller suggested a number parsed
                                    // from the tender reference, show it as a
                                    // one-click copy hint — saves Monica the
                                    // "porquê não liga" confusion when the SAP
                                    // number is sitting right there in the ref.
                                    if (payload.suggestion) {
                                        const s = esc(String(payload.suggestion));
                                        body.innerHTML = `
                                            <div class="rounded-md border p-3 text-xs bg-gray-50 border-gray-200 text-gray-700">
                                                ${esc(payload.message)}
                                                <div class="mt-2 flex items-center gap-2">
                                                    <span class="text-gray-500">Sugestão (da referência do concurso):</span>
                                                    <code class="font-mono bg-white border border-gray-300 rounded px-1.5 py-0.5">${s}</code>
                                                </div>
                                            </div>`;
                                        return;
                                    }
                                    return renderEmpty(payload.message, 'gray');
                                case 'unparseable': return renderEmpty(payload.message, 'amber');
                                case 'not_found':   return renderEmpty(payload.message, 'amber');
                                case 'auth_failed': return renderEmpty(payload.message, 'red');
                                case 'disabled':    return renderEmpty(payload.message, 'gray');
                                case 'error':       return renderEmpty(payload.message, 'red');
                                default:            return renderEmpty('Resposta inesperada do servidor.', 'red');
                            }
                        })
                        .catch(err => renderEmpty('Erro de rede: ' + err.message, 'red'));
                };

                refresh.addEventListener('click', load);
                load();
            })();
            </script>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

                {{-- ─── Edit form (spans 2 cols) ──────────────────────────── --}}
                <section class="lg:col-span-2 rounded-lg bg-white shadow-sm border border-gray-100 p-6">
                    <h3 class="text-base font-semibold text-gray-800 mb-4">Editar concurso</h3>

                    @if(!$canEdit)
                        <p class="text-sm text-gray-500 italic">Apenas leitura — não tem permissões para editar este concurso.</p>
                    @endif

                    <form method="POST" action="{{ route('tenders.update', $tender) }}" class="space-y-4"
                          @if(!$canEdit) inert @endif>
                        @csrf
                        @method('PATCH')

                        <div>
                            <label class="block text-xs font-medium text-gray-700 mb-1">Título</label>
                            <input type="text" name="title" value="{{ old('title', $tender->title) }}"
                                   maxlength="500"
                                   class="w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs font-medium text-gray-700 mb-1">Estado</label>
                                <select name="status" class="w-full rounded-md border-gray-300 text-sm shadow-sm">
                                    @foreach($statusLabels as $k => $label)
                                        <option value="{{ $k }}" @selected(old('status', $tender->status) === $k)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-700 mb-1">Nº Oportunidade SAP</label>
                                <input type="text" name="sap_opportunity_number"
                                       value="{{ old('sap_opportunity_number', $tender->sap_opportunity_number) }}"
                                       maxlength="64"
                                       placeholder="ex.: SAP-2026-0451"
                                       class="w-full rounded-md border-gray-300 text-sm shadow-sm font-mono">
                            </div>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs font-medium text-gray-700 mb-1">Tipo</label>
                                <input type="text" name="type" value="{{ old('type', $tender->type) }}"
                                       maxlength="64"
                                       class="w-full rounded-md border-gray-300 text-sm shadow-sm">
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-700 mb-1">Organização</label>
                                <input type="text" name="purchasing_org" value="{{ old('purchasing_org', $tender->purchasing_org) }}"
                                       maxlength="255"
                                       class="w-full rounded-md border-gray-300 text-sm shadow-sm">
                            </div>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                            <div>
                                <label class="block text-xs font-medium text-gray-700 mb-1">Deadline (UTC)</label>
                                <input type="datetime-local" name="deadline_at"
                                       value="{{ old('deadline_at', $tender->deadline_at?->format('Y-m-d\TH:i')) }}"
                                       class="w-full rounded-md border-gray-300 text-sm shadow-sm">
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-700 mb-1">Prioridade</label>
                                <input type="text" name="priority" value="{{ old('priority', $tender->priority) }}"
                                       maxlength="16"
                                       class="w-full rounded-md border-gray-300 text-sm shadow-sm">
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-700 mb-1">Resultado</label>
                                <input type="text" name="result" value="{{ old('result', $tender->result) }}"
                                       maxlength="64"
                                       class="w-full rounded-md border-gray-300 text-sm shadow-sm">
                            </div>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                            <div>
                                <label class="block text-xs font-medium text-gray-700 mb-1">Valor da proposta</label>
                                <input type="number" step="0.01" min="0" name="offer_value"
                                       value="{{ old('offer_value', $tender->offer_value) }}"
                                       class="w-full rounded-md border-gray-300 text-sm shadow-sm">
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-700 mb-1">Moeda</label>
                                <input type="text" name="currency" value="{{ old('currency', $tender->currency) }}"
                                       maxlength="3" placeholder="EUR"
                                       class="w-full rounded-md border-gray-300 text-sm shadow-sm uppercase">
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-700 mb-1">Horas dedicadas</label>
                                <input type="number" step="0.1" min="0" name="time_spent_hours"
                                       value="{{ old('time_spent_hours', $tender->time_spent_hours) }}"
                                       class="w-full rounded-md border-gray-300 text-sm shadow-sm">
                            </div>
                        </div>

                        <div>
                            <label class="block text-xs font-medium text-gray-700 mb-1 flex items-center gap-2">
                                <span>Notas</span>
                                @if($tender->sap_opportunity_number)
                                    <span class="inline-flex items-center gap-1 rounded bg-indigo-50 border border-indigo-200 px-1.5 py-0.5 text-[10px] font-normal text-indigo-700"
                                          title="Guardar aqui faz PATCH ao campo Remarks da oportunidade SAP #{{ $tender->getSapSequentialNo() ?: '?' }}.">
                                        🔗 sincroniza com SAP Remarks
                                    </span>
                                @else
                                    <span class="inline-flex items-center gap-1 rounded bg-yellow-50 border border-yellow-200 px-1.5 py-0.5 text-[10px] font-normal text-yellow-800"
                                          title="Preenche o campo Nº Oportunidade SAP acima para activar a sincronização de notas.">
                                        ⚠ sem nº SAP — notas não sincronizam
                                    </span>
                                @endif
                            </label>
                            <textarea name="notes" rows="4"
                                      class="w-full rounded-md border-gray-300 text-sm shadow-sm font-mono">{{ old('notes', $tender->notes) }}</textarea>
                            <p class="mt-1 text-xs text-gray-500">
                                Para histórico inalterável, use o painel de Observações abaixo.
                                @if($tender->sap_opportunity_number)
                                    Ao guardar, o campo <em>Remarks</em> da oportunidade SAP
                                    <strong>#{{ $tender->getSapSequentialNo() ?: '?' }}</strong>
                                    é reescrito com o conteúdo das notas.
                                @endif
                            </p>
                        </div>

                        @if($canEdit)
                            <div class="flex justify-end">
                                <button type="submit"
                                        class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow hover:bg-indigo-500">
                                    Guardar alterações
                                </button>
                            </div>
                        @endif
                    </form>
                </section>

                {{-- ─── Similar opportunities ─────────────────────────────── --}}
                <section class="rounded-lg bg-white shadow-sm border border-gray-100 p-6">
                    <h3 class="text-base font-semibold text-gray-800 mb-4">
                        Oportunidades semelhantes
                        <span class="ml-1 text-xs font-normal text-gray-500">(histórico)</span>
                    </h3>

                    @if($similar->isEmpty())
                        <p class="text-sm text-gray-500 italic">
                            Sem histórico semelhante — este título não tem correspondências &gt;15% de similaridade.
                        </p>
                    @else
                        <ul class="space-y-3">
                            @foreach($similar as $s)
                                <li class="border border-gray-100 rounded-md p-3 hover:bg-gray-50">
                                    <a href="{{ route('tenders.show', $s) }}" class="block">
                                        <div class="flex items-center justify-between gap-2">
                                            <div class="text-xs font-mono text-gray-500">
                                                {{ strtoupper($s->source) }} · {{ $s->reference }}
                                            </div>
                                            <div class="text-xs font-semibold text-indigo-700">
                                                {{ number_format($s->similarity_score * 100, 0) }}%
                                            </div>
                                        </div>
                                        <div class="mt-1 text-sm text-gray-900 font-medium">
                                            {{ \Illuminate\Support\Str::limit($s->title, 110) }}
                                        </div>
                                        @if($s->sap_opportunity_number)
                                            <div class="mt-1 text-xs font-mono text-green-700">
                                                ✓ SAP: {{ $s->sap_opportunity_number }}
                                            </div>
                                        @endif
                                        <div class="mt-0.5 text-xs text-gray-500">
                                            {{ $statusLabels[$s->status] ?? $s->status }}
                                            @if($s->offer_value)
                                                · {{ number_format($s->offer_value, 2) }} {{ $s->currency }}
                                            @endif
                                        </div>
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </section>
            </div>

            {{-- ─── Observations (append-only) ─────────────────────────────── --}}
            <section class="rounded-lg bg-white shadow-sm border border-gray-100 p-6">
                <h3 class="text-base font-semibold text-gray-800 mb-4">
                    Observações
                    <span class="text-xs font-normal text-gray-500">(histórico permanente — não pode ser apagado)</span>
                </h3>

                <form method="POST" action="{{ route('tenders.observe', $tender) }}" class="space-y-3">
                    @csrf
                    <textarea name="body" rows="3" maxlength="5000" required
                              placeholder="Adicionar observação…"
                              class="w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"></textarea>
                    <div class="flex justify-end">
                        <button type="submit"
                                class="rounded-md bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-800">
                            Adicionar observação
                        </button>
                    </div>
                </form>
            </section>

            {{-- ─── Last import provenance ─────────────────────────────────── --}}
            @if($tender->lastImport)
                <section class="rounded-lg bg-gray-50 border border-gray-100 p-4 text-xs text-gray-600">
                    Última importação:
                    <span class="font-mono">{{ $tender->lastImport->file_name }}</span>
                    em {{ $tender->lastImport->created_at->format('d/m/Y H:i') }}
                    ({{ $tender->lastImport->source }})
                </section>
            @endif
        </div>
    </div>

    {{-- ─── "Copy full info" helper ─────────────────────────────────────────
         Builds a server-side plain-text snapshot so agents (Richar, etc.) or
         external notes can receive every relevant field in one paste. The
         payload is JSON-encoded into a data attribute and copied to the
         clipboard on click. Avoids any client-side templating surprises. --}}
    @php
        $payload = collect([
            'CONCURSO'        => strtoupper($tender->source) . ' · ' . $tender->reference,
            'TÍTULO'          => $tender->title,
            'TIPO'            => $tender->type ?: '—',
            'ORGANIZAÇÃO'     => $tender->purchasing_org ?: '—',
            'ESTADO'          => $statusLabels[$tender->status] ?? $tender->status,
            'COLABORADOR'     => $tender->collaborator?->name ?? '—',
            'EMAIL COLAB.'    => $tender->collaborator?->digest_email ?? '—',
            'DEADLINE'        => $tender->deadline_lisbon?->format('d/m/Y H:i') ?: '—',
            'Nº SAP'          => $tender->sap_opportunity_number ?: '—',
            'VALOR'           => $tender->offer_value ? number_format((float) $tender->offer_value, 2, ',', '.') . ' ' . ($tender->currency ?: '') : '—',
            'HORAS GASTAS'    => $tender->time_spent_hours ? (float) $tender->time_spent_hours . 'h' : '—',
            'RESULTADO'       => $tender->result ?: '—',
            'URL'             => rtrim(config('app.url'), '/') . '/tenders/' . $tender->id,
        ])
        ->map(fn($v, $k) => str_pad($k, 17) . ': ' . $v)
        ->implode("\n");

        $payload .= "\n\n=== NOTAS / OBSERVAÇÕES ===\n" . ($tender->notes ?: '(sem notas)');
    @endphp

    <script>
        // Using textarea-based fallback so non-HTTPS localhost also works.
        window.copyTenderInfo = async function () {
            const payload = @json($payload);
            const label   = document.getElementById('copy-btn-label');
            try {
                if (navigator.clipboard && window.isSecureContext) {
                    await navigator.clipboard.writeText(payload);
                } else {
                    // Legacy fallback for older browsers / insecure contexts.
                    const ta = document.createElement('textarea');
                    ta.value = payload;
                    ta.style.position = 'fixed';
                    ta.style.opacity = '0';
                    document.body.appendChild(ta);
                    ta.select();
                    document.execCommand('copy');
                    document.body.removeChild(ta);
                }
                if (label) { label.textContent = '✓ Copiado!'; setTimeout(() => label.textContent = 'Copiar info completa', 2000); }
            } catch (e) {
                alert('Não foi possível copiar: ' + e.message);
            }
        };
    </script>
</x-app-layout>
