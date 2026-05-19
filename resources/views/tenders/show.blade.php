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
                {{-- Voltar: tenders marinhos voltam a /marine, restantes a /tenders.
                     Mantém o user dentro da secção certa em vez de o jogar fora. --}}
                <a href="{{ $tender->source === 'marine' ? route('marine.index') : route('tenders.index') }}"
                   class="text-sm text-indigo-600 hover:underline shrink-0">← Voltar</a>
                <h2 class="text-xl font-semibold leading-tight text-gray-800 truncate">
                    @if($tender->source === 'marine') ⚓ @endif{{ strtoupper($tender->source) }} · {{ $tender->reference }}
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
                            @if($tender->is_confidential)
                                <span class="inline-flex items-center gap-1 rounded-full bg-red-100 px-2.5 py-1 text-xs font-bold text-red-700 border border-red-200"
                                      title="LLM e pesquisa web bloqueados para este concurso">
                                    🔒 Confidencial
                                </span>
                            @endif
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

            {{-- ─── PDFs do concurso (drag-drop) + Marta CRM trigger ────────
                 Permite ao operador arrastar os PDFs do RFP/RFQ directamente
                 para a página, sem ter de ir ao /hp-history/upload separado.
                 Após upload o texto é extraído via smalot/pdfparser e fica
                 disponível para:
                   • Marta CRM — botão à direita pré-popula o /chat com
                     contexto completo do concurso + texto dos PDFs.
                   • Suggester — categorias inferidas do conteúdo.
                   • Daniel  — drafts ancorados nas specs reais.
                 Oculto se o concurso for confidencial (consistência com a
                 secção do suggester). --}}
            @if(!$tender->is_confidential)
                <section id="tender-attachments" class="rounded-lg bg-white shadow-sm border border-gray-100 p-5">
                    <div class="flex items-center justify-between gap-3 flex-wrap mb-3">
                        <div>
                            <h2 class="text-base font-semibold text-gray-800">📎 PDFs do concurso</h2>
                            <p class="text-xs text-gray-500 mt-0.5">
                                Arrasta os ficheiros (RFP, RFQ, anexos técnicos) ou clica para escolher.
                                O texto é extraído automaticamente e usado pelo Marta + Suggester + Daniel.
                            </p>
                        </div>
                        @php
                            // 2026-05-18: prompt da Marta agora inclui TODOS os anexos extraídos
                            // (não só o primeiro), porque o operador pediu "abre logo com os
                            // ficheiros em anexo". Cap 6000 chars por anexo para o link URL não
                            // explodir; até 4 anexos enviados via query string.
                            $deadline = $tender->deadline_lisbon?->format('d/m/Y H:i') ?? '—';
                            $martaPrompt = "Cria oportunidade SAP B1 para este concurso:\n"
                                . "• Título: {$tender->title}\n"
                                . "• Referência: " . ($tender->reference ?? '—') . "\n"
                                . "• Fonte: " . strtoupper((string) $tender->source) . "\n"
                                . "• Organização: " . ($tender->purchasing_org ?: '—') . "\n"
                                . "• Deadline: {$deadline}\n";
                            $okAttachments = $tender->attachments->where('extraction_status', 'ok');
                            if ($okAttachments->isNotEmpty()) {
                                $martaPrompt .= "\nDocumentos anexados ({$okAttachments->count()}/" . $tender->attachments->count() . " com texto extraído):\n";
                                foreach ($tender->attachments as $a) {
                                    $martaPrompt .= "  - {$a->original_name} (" . ($a->extracted_chars ?? 0) . " chars)\n";
                                }
                                // Inclui os primeiros 4 anexos com 6000 chars cada — chega
                                // para Marta ter contexto material sem rebentar a URL.
                                foreach ($okAttachments->take(4) as $i => $a) {
                                    $snippet = mb_substr((string) $a->extracted_text, 0, 6000);
                                    $martaPrompt .= "\n--- Anexo {$i}: {$a->original_name} ---\n{$snippet}\n";
                                }
                                if ($okAttachments->count() > 4) {
                                    $martaPrompt .= "\n(+" . ($okAttachments->count() - 4) . " anexos não incluídos no chat — usa o botão 'Auto-resumo' para Marta processar TODOS server-side)\n";
                                }
                            }
                            $martaPrompt .= "\nQuando criares a oportunidade, devolve o ID SAP para eu actualizar este concurso.";
                        @endphp
                        {{-- 2026-05-18: dois caminhos para criar SAP Opp:
                             (a) Botão directo → POST /create-sap-opp (manager+).
                                 Cria a oportunidade IMEDIATAMENTE com base nos
                                 dados do tender + 1ª linha de anexos no Remarks.
                                 Auto-liga ao tender.sap_opportunity_number.
                             (b) Link para /chat para o flow interactivo (toda
                                 a gente, mais flexível mas requer Marta a
                                 interpretar + utilizador a confirmar). --}}
                        @if(empty($tender->sap_opportunity_number))
                            @if(auth()->user()?->isManager() || auth()->user()?->can('tenders.create-sap-opp'))
                            <form method="POST" action="{{ route('tenders.create-sap-opp', $tender) }}"
                                  class="inline"
                                  onsubmit="this.querySelector('button').disabled=true;this.querySelector('button').textContent='⏳ A criar SAP Opp…';">
                                @csrf
                                <button type="submit"
                                        class="rounded-md bg-blue-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-blue-500"
                                        title="Cria DIRECTAMENTE a oportunidade SAP B1 com os dados do concurso + 1ª linha de anexos no Remarks. Auto-liga ao concurso.">
                                    🤖 Abrir SAP Opp (directo)
                                </button>
                            </form>
                            @endif
                            <a href="/chat?agent=crm&prompt={{ urlencode($martaPrompt) }}"
                               class="rounded-md border border-blue-300 bg-white text-blue-700 hover:bg-blue-50 px-3 py-1.5 text-sm font-semibold"
                               title="Caminho alternativo: abre /chat com Marta CRM para conversar antes de criar (útil se queres editar campos antes).">
                                💬 ou via chat com Marta
                            </a>
                        @else
                            <span class="rounded-md border border-emerald-300 bg-emerald-50 text-emerald-800 px-3 py-1.5 text-sm font-semibold inline-flex items-center gap-1.5"
                                  title="Concurso já ligado a SAP Opp #{{ $tender->sap_opportunity_number }}. Para criar uma nova, primeiro limpa o campo Nº Oportunidade SAP nas notas.">
                                ✓ SAP Opp #{{ $tender->sap_opportunity_number }}
                            </span>
                        @endif
                        {{-- 2026-05-18: botão dedicado para resumo automático server-side.
                             Mais rápido que o flow de chat — processa TODOS os anexos sem
                             cap de URL, gera resumo ≤200 chars, mete em notas + SAP. --}}
                        @if($tender->attachments->where('extraction_status', 'ok')->isNotEmpty())
                        <form method="POST" action="{{ route('tenders.marta-summarize', $tender) }}"
                              class="inline" id="marta-summarize-form"
                              onsubmit="this.querySelector('button').disabled=true;this.querySelector('button').textContent='✨ Marta a ler {{ $tender->attachments->where('extraction_status', 'ok')->count() }} anexos…';">
                            @csrf
                            <button type="submit"
                                    class="rounded-md bg-emerald-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-emerald-500"
                                    title="Marta processa TODOS os anexos server-side, gera resumo ≤200 chars, mete em Notas e sincroniza com SAP Remarks. Demora ~10-20s.">
                                ✨ Auto-resumo → Notas+SAP
                            </button>
                        </form>
                        {{-- 2026-05-18: Inquiry PartYard Defense — gera PDF
                             conforme modelo MOD_072_V3 (Sistema_Gestão_Qualidade
                             /QUALIDADE/MODELOS/PY Military). Inclui items do
                             SoR + termos NATO/NSPA + bloco assinatura. Anexa
                             automaticamente ao concurso. --}}
                        <a href="{{ route('tenders.inquiry-pdf', $tender) }}"
                           target="_blank"
                           class="rounded-md bg-indigo-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-indigo-500"
                           title="Gera PDF Inquiry PartYard Defense (modelo MOD_072_V3) com items do SoR, termos NATO/NSPA e bloco assinatura. Anexa automaticamente.">
                            📋 Inquiry PDF
                        </a>
                        <a href="{{ route('tenders.inquiry-word', $tender) }}"
                           class="rounded-md bg-sky-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-sky-500"
                           title="Gera Inquiry PartYard como Word editável (.docx) — para revisar/ajustar antes de enviar ao fornecedor. Anexa automaticamente.">
                            📝 Inquiry Word
                        </a>
                        @endif
                        @unless($tender->is_confidential)
                        <button type="button" id="ts-service-analysis-btn"
                                class="rounded-md bg-violet-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-violet-500"
                                title="Painel multi-agente — sempre: 🎖️ Cor. Rodrigues · 💼 Marco Sales · 🔩 Eng. Victor · 🚚 Logística · 🏛️ Dr. Ana Contracts. Marítimo: + ⚓ Capt. Porto + ⚓ Capt. Vasco + 🛠️ Eng. Repair. Gera relatório imprimível e plano de acção para SAP.">
                            🎯 Análise do serviço (multi-agente)
                        </button>
                        @endunless
                    </div>

                    {{-- Status box for service-analysis button --}}
                    <div id="ts-service-analysis-status" class="mt-3 hidden text-xs"></div>

                    {{-- 2026-05-18: se já existe análise concluída, mostra
                         o plano de acção consolidado + atalhos para PDF e
                         sync SAP. Isto vive aqui no dashboard do concurso
                         para o operador não ter de abrir outra página. --}}
                    @php
                        $analysisRow = \App\Models\TenderServiceAnalysis::where('tender_id', $tender->id)
                            ->where('status', 'done')->first();
                        $actionItems = $analysisRow?->extractActionItems() ?? [];
                    @endphp
                    @if($analysisRow && !empty($actionItems))
                        <div class="mt-4 rounded-md border-l-4 border-emerald-500 bg-emerald-50/50 p-3">
                            <div class="flex items-center justify-between gap-2 flex-wrap mb-2">
                                <div class="text-xs font-semibold text-emerald-800">
                                    📋 Plano de acção · {{ count($actionItems) }} passos
                                    <span class="font-normal text-emerald-600 ml-1">
                                        ({{ $analysisRow->generated_at?->diffForHumans() }})
                                    </span>
                                </div>
                                <div class="flex gap-2">
                                    {{-- 2026-05-18: gera 1 email POR FORNECEDOR mencionado
                                         na análise (Karl Storz, Medtronic, etc.) com a
                                         tabela das linhas/items que cada um cobre. --}}
                                    <button type="button" id="ts-emails-from-analysis-btn"
                                            class="inline-flex items-center gap-1 rounded bg-indigo-600 hover:bg-indigo-500 text-white px-2.5 py-1 text-[11px] font-semibold"
                                            title="Daniel gera 1 email por fornecedor mencionado na análise, com a tabela das linhas que cada um cobre. ~20-30s.">
                                        ✉ Emails p/ Fornecedores
                                    </button>
                                    <a href="{{ route('tenders.service-analysis.pdf', $tender) }}"
                                       class="inline-flex items-center gap-1 rounded bg-violet-600 hover:bg-violet-500 text-white px-2.5 py-1 text-[11px] font-semibold"
                                       title="Gera PDF + anexa automaticamente ao concurso">
                                        📄 PDF
                                    </a>
                                    <form method="POST" action="{{ route('tenders.service-analysis.sync-todo', $tender) }}" class="inline">
                                        @csrf
                                        <button type="submit"
                                                class="inline-flex items-center gap-1 rounded bg-emerald-600 hover:bg-emerald-500 text-white px-2.5 py-1 text-[11px] font-semibold"
                                                title="Mete o plano em Notas → sincroniza com SAP Opportunity Remarks">
                                            🔄 SAP
                                        </button>
                                    </form>
                                    <a href="{{ route('tenders.service-analysis.show', $tender) }}"
                                       target="_blank"
                                       class="inline-flex items-center gap-1 rounded border border-emerald-300 bg-white hover:bg-emerald-50 text-emerald-800 px-2.5 py-1 text-[11px] font-semibold"
                                       title="Abrir vista completa da análise em nova tab">
                                        Vista completa ↗
                                    </a>
                                    <button type="button"
                                            onclick="document.getElementById('ts-service-analysis-btn')?.click()"
                                            class="inline-flex items-center gap-1 rounded bg-violet-600 hover:bg-violet-500 text-white px-2.5 py-1 text-[11px] font-semibold"
                                            title="Re-correr análise + render inline no dashboard">
                                        Ver inline
                                    </button>
                                </div>
                            </div>
                            <ol class="ml-5 list-decimal text-xs text-gray-800 space-y-0.5">
                                @foreach(array_slice($actionItems, 0, 6) as $it)
                                    <li>
                                        {{ $it['text'] }}
                                        <span class="ml-1 text-[10px] text-violet-600 font-semibold">· {{ $it['agent_name'] }}</span>
                                    </li>
                                @endforeach
                                @if(count($actionItems) > 6)
                                    <li class="list-none text-[11px] text-gray-500 italic">
                                        … +{{ count($actionItems) - 6 }} mais — abre a análise para ver todos
                                    </li>
                                @endif
                            </ol>
                            <div id="ts-emails-from-analysis-dropbox" class="mt-3"></div>
                        </div>
                    @endif

                    {{-- 2026-05-18: handler do novo botão "Emails p/ Fornecedores".
                         Chama draft-emails-from-analysis e renderiza inline com
                         o mesmo formato dos drafts normais. --}}
                    <script>
                    (function () {
                        const btn = document.getElementById('ts-emails-from-analysis-btn');
                        if (!btn) return;
                        const csrf = document.querySelector('meta[name=csrf-token]')?.content || '';
                        const url  = "{{ route('tenders.draft-emails-from-analysis', $tender) }}";
                        const box  = document.getElementById('ts-emails-from-analysis-dropbox');
                        const esc  = (s) => String(s ?? '').replace(/[&<>"]/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'})[m]);

                        btn.addEventListener('click', async () => {
                            btn.disabled = true;
                            const orig = btn.innerHTML;
                            btn.innerHTML = '⏳ Daniel a extrair fornecedores…';
                            box.innerHTML = '<div class="text-xs text-gray-500 mt-2">Daniel está a ler a análise e a escrever 1 email por fornecedor mencionado. ~20-30s…</div>';
                            try {
                                const res = await fetch(url, {
                                    method: 'POST',
                                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                                    body: JSON.stringify({ language: 'pt' }),
                                    credentials: 'same-origin',
                                });
                                if (res.status === 401 && window.maybeRedirectOnOtp && await window.maybeRedirectOnOtp(res)) return;
                                const data = await res.json();
                                if (!res.ok) throw new Error(data.detail || ('HTTP ' + res.status));

                                const emails = data.emails || [];
                                if (!emails.length) {
                                    box.innerHTML = `<div class="text-xs text-amber-700 mt-2">Daniel não conseguiu extrair fornecedores da análise. Verifica se a análise tem fornecedores candidatos mencionados.</div>`;
                                    return;
                                }

                                let html = `<div class="text-xs font-semibold text-indigo-800 mb-2 mt-2">
                                    📧 ${emails.length} email(s) gerado(s) a partir da análise — 1 por fornecedor com tabela de linhas
                                </div><div class="space-y-3">`;
                                emails.forEach((em, i) => {
                                    const id = 'efa_' + i + '_' + Date.now();
                                    html += `
                                    <div class="rounded-md border border-indigo-200 bg-white">
                                        <div class="px-3 py-2 bg-indigo-50 border-b border-indigo-100 text-xs font-semibold text-indigo-900">
                                            ${i+1}/${emails.length} · ${esc(em.supplier || em.to || 'fornecedor')}
                                        </div>
                                        <div class="px-3 py-2 space-y-2" id="${id}_card">
                                            <div class="flex items-center gap-2 text-xs">
                                                <label class="text-gray-500 w-16">Para</label>
                                                <input type="email" id="${id}_to" value="${esc(em.to || '')}" placeholder="email do fornecedor (preencher se em branco)"
                                                       class="flex-1 rounded-md border-gray-300 text-xs font-mono">
                                            </div>
                                            <div class="flex items-center gap-2 text-xs">
                                                <label class="text-gray-500 w-16">Assunto</label>
                                                <input type="text" id="${id}_subject" value="${esc(em.subject || '')}"
                                                       class="flex-1 rounded-md border-gray-300 text-xs">
                                            </div>
                                            <textarea id="${id}_body" rows="14"
                                                      class="w-full rounded-md border-gray-300 text-xs font-mono leading-relaxed">${esc(em.body || '')}</textarea>
                                            <input type="hidden" id="${id}_cc" value="${esc(em.cc || '')}">
                                            <div class="flex items-center gap-2 pt-1 flex-wrap">
                                                <button type="button" data-efa-id="${id}" data-efa-action="send"
                                                        class="rounded-md bg-emerald-600 text-white px-3 py-1 text-xs font-semibold hover:bg-emerald-500">
                                                    📤 Enviar via ClawYard
                                                </button>
                                                <button type="button" data-efa-id="${id}" data-efa-action="outlook"
                                                        class="rounded-md bg-blue-600 text-white px-3 py-1 text-xs font-semibold hover:bg-blue-500">
                                                    ✉ Abrir no Outlook
                                                </button>
                                                <button type="button" data-efa-id="${id}" data-efa-action="copy"
                                                        class="rounded-md border border-gray-300 px-3 py-1 text-xs text-gray-700 hover:bg-gray-50">
                                                    📋 Copiar
                                                </button>
                                                <span class="text-[11px] text-gray-500" id="${id}_status"></span>
                                            </div>
                                        </div>
                                    </div>`;
                                });
                                html += '</div>';
                                box.innerHTML = html;

                                // Re-use copy / outlook / send com os mesmos handlers
                                box.querySelectorAll('[data-efa-id]').forEach(b => {
                                    b.addEventListener('click', () => handleEfaAction(b));
                                });
                            } catch (e) {
                                box.innerHTML = `<div class="text-sm text-red-700 mt-2">Erro: ${esc(e.message)}</div>`;
                            } finally {
                                btn.disabled = false;
                                btn.innerHTML = orig;
                            }
                        });

                        function handleEfaAction(btn) {
                            const id     = btn.dataset.efaId;
                            const action = btn.dataset.efaAction;
                            const to     = document.getElementById(id+'_to')?.value.trim() || '';
                            const cc     = document.getElementById(id+'_cc')?.value.trim() || '';
                            const subject= document.getElementById(id+'_subject')?.value.trim() || '';
                            const body   = document.getElementById(id+'_body')?.value.trim() || '';
                            const status = document.getElementById(id+'_status');

                            if (action === 'copy') {
                                const text = (to ? 'Para: ' + to + '\n' : '') + 'Assunto: ' + subject + '\n\n' + body;
                                navigator.clipboard.writeText(text).then(() => {
                                    if (status) { status.textContent = '✅ Copiado'; setTimeout(() => status.textContent = '', 1800); }
                                });
                                return;
                            }
                            if (action === 'outlook') {
                                let mailto = 'mailto:' + encodeURIComponent(to);
                                const parts = [];
                                if (cc) parts.push('cc=' + encodeURIComponent(cc));
                                if (subject) parts.push('subject=' + encodeURIComponent(subject));
                                if (body) parts.push('body=' + encodeURIComponent(body));
                                if (parts.length) mailto += '?' + parts.join('&');
                                window.location.href = mailto;
                                return;
                            }
                            if (action === 'send') {
                                if (!to || !subject || !body) {
                                    if (status) { status.textContent = '⚠ Preenche Para + Assunto + Corpo'; status.style.color = '#b91c1c'; }
                                    return;
                                }
                                if (!confirm(`Enviar email para ${to}?`)) return;
                                btn.disabled = true;
                                btn.textContent = '⏳';
                                const fd = new FormData();
                                fd.append('to', to);
                                if (cc) fd.append('cc', cc);
                                fd.append('subject', subject);
                                fd.append('body', body);
                                fetch('/api/email/send', {
                                    method: 'POST',
                                    headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                                    body: fd,
                                    credentials: 'same-origin',
                                })
                                .then(r => r.json().then(d => ({ ok: r.ok, d })))
                                .then(({ ok, d }) => {
                                    if (!ok || d.error) throw new Error(d.error || d.detail || 'send_failed');
                                    btn.style.background = '#16a34a';
                                    btn.textContent = '✅ enviado';
                                    if (status) { status.textContent = '✅ ' + to; status.style.color = '#15803d'; }
                                    document.getElementById(id+'_card')?.style.setProperty('opacity', '0.55');
                                })
                                .catch(e => {
                                    btn.disabled = false;
                                    btn.textContent = '📤 Enviar via ClawYard';
                                    if (status) { status.textContent = '❌ ' + (e.message || 'erro'); status.style.color = '#b91c1c'; }
                                });
                            }
                        }
                    })();
                    </script>

                    <script>
                    (function () {
                        const btn = document.getElementById('ts-service-analysis-btn');
                        const status = document.getElementById('ts-service-analysis-status');
                        if (!btn) return;
                        const csrf = document.querySelector('meta[name=csrf-token]')?.content || '';
                        const url  = "{{ route('tenders.service-analysis.generate', $tender) }}";

                        btn.addEventListener('click', async () => {
                            btn.disabled = true;
                            const orig = btn.textContent;
                            btn.textContent = '🔄 A consultar agentes (~30-60s)...';
                            status.classList.remove('hidden');
                            status.className = 'mt-3 text-xs text-gray-600';
                            status.textContent = 'Cor. Rodrigues, Marco Sales, Eng. Victor e outros estão a analisar...';
                            try {
                                const res = await fetch(url, {
                                    method: 'POST',
                                    headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                                    credentials: 'same-origin',
                                });
                                if (res.status === 401 && await window.maybeRedirectOnOtp(res)) return;
                                const data = await res.json();
                                if (!res.ok) throw new Error(data.detail || 'HTTP ' + res.status);
                                status.className = 'mt-3 text-xs text-emerald-700';
                                status.textContent = (data.cached ? '✓ Análise pronta (cached). ' : '✓ Análise gerada. ') + 'A renderizar inline…';

                                // 2026-05-18: render INLINE em vez de nova tab.
                                // Pedido directo: "quando estou no dashboard do
                                // concurso queria não sair, analisar os emails
                                // para enviar logo, pedidos e analise dos
                                // agentes logo ali". Faz fetch da página da
                                // análise e injecta o conteúdo num panel logo
                                // por baixo do botão.
                                renderAnalysisInline(data.view_url);
                            } catch (e) {
                                status.className = 'mt-3 text-xs text-red-700';
                                status.textContent = 'Erro: ' + e.message;
                            } finally {
                                btn.disabled = false;
                                btn.textContent = orig;
                            }
                        });

                        // 2026-05-18: helper para render inline da análise multi-agente.
                        // Faz fetch ao GET /tenders/{id}/service-analysis e injecta
                        // o html dentro de #ts-service-analysis-inline.
                        async function renderAnalysisInline(viewUrl) {
                            let panel = document.getElementById('ts-service-analysis-inline');
                            if (!panel) {
                                panel = document.createElement('div');
                                panel.id = 'ts-service-analysis-inline';
                                panel.className = 'mt-4 rounded-md border border-violet-200 bg-violet-50/30 overflow-hidden';
                                status.insertAdjacentElement('afterend', panel);
                            }
                            panel.innerHTML = '<div class="p-4 text-xs text-gray-500">⏳ A carregar análise…</div>';

                            try {
                                const r = await fetch(viewUrl, { credentials: 'same-origin', headers: { 'Accept': 'text/html' } });
                                if (!r.ok) throw new Error('HTTP ' + r.status);
                                const html = await r.text();
                                // Extrai o <div class="doc-wrap"> com o conteúdo principal
                                const parser = new DOMParser();
                                const doc = parser.parseFromString(html, 'text/html');
                                const docWrap = doc.querySelector('.doc-wrap');
                                if (!docWrap) {
                                    // Fallback: link para abrir em nova tab
                                    panel.innerHTML = `<div class="p-4 text-xs">
                                        Análise pronta. <a href="${viewUrl}" target="_blank" class="text-violet-700 underline font-semibold">Abrir vista completa</a>.
                                    </div>`;
                                    return;
                                }
                                // Cabeçalho com link para a vista completa + botão de fechar
                                const header = `<div class="px-4 py-2 bg-violet-100 border-b border-violet-200 flex items-center justify-between">
                                    <span class="text-xs font-semibold text-violet-900">🎯 Análise multi-agente (inline)</span>
                                    <div class="flex gap-2">
                                        <a href="${viewUrl}" target="_blank" class="text-[11px] text-violet-700 hover:underline">Abrir vista completa ↗</a>
                                        <button type="button" onclick="document.getElementById('ts-service-analysis-inline').remove()"
                                                class="text-[11px] text-violet-700 hover:underline">Fechar ✕</button>
                                    </div>
                                </div>`;
                                panel.innerHTML = header + '<div class="bg-white p-4">' + docWrap.innerHTML + '</div>';
                                // Scroll suave até ao painel
                                panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
                            } catch (err) {
                                panel.innerHTML = `<div class="p-4 text-xs text-red-700">
                                    Erro a carregar análise: ${err.message}.
                                    <a href="${viewUrl}" target="_blank" class="text-violet-700 underline">Abrir em nova tab</a>.
                                </div>`;
                            }
                        }
                    })();
                    </script>

                    @php
                        $extractedOk = $tender->attachments->where('extraction_status', 'ok')->count();
                        $extractedFail = $tender->attachments->where('extraction_status', 'failed')->count();
                    @endphp
                    @if($tender->attachments->isNotEmpty())
                        <div class="mb-3 rounded-md border border-gray-100 bg-gray-50 px-3 py-2 text-xs text-gray-700 flex items-center gap-3 flex-wrap">
                            <strong>{{ $tender->attachments->count() }}</strong> PDFs anexados ·
                            <span class="text-emerald-700">{{ $extractedOk }} parsed</span>
                            @if($extractedFail > 0)
                                · <span class="text-red-700">{{ $extractedFail }} falharam</span>
                            @endif
                        </div>
                        <ul class="divide-y divide-gray-100 mb-3 border border-gray-100 rounded-md">
                            @foreach($tender->attachments as $a)
                                <li class="flex items-center justify-between gap-3 px-3 py-2 text-xs">
                                    <a href="{{ route('tenders.attachments.download', [$tender, $a]) }}"
                                       class="font-mono text-indigo-700 hover:underline truncate flex-1"
                                       title="{{ $a->original_name }}">
                                        📄 {{ \Illuminate\Support\Str::limit($a->original_name, 60) }}
                                    </a>
                                    <span class="text-gray-500 shrink-0">{{ number_format($a->size_bytes / 1024, 0) }} KB</span>
                                    @if($a->extraction_status === 'ok')
                                        <span class="rounded bg-emerald-50 text-emerald-800 px-1.5 py-0.5 text-[10px] shrink-0" title="{{ $a->extracted_chars }} chars extraídos">
                                            ✓ texto OK
                                        </span>
                                    @elseif($a->extraction_status === 'failed')
                                        <span class="rounded bg-red-50 text-red-800 px-1.5 py-0.5 text-[10px] shrink-0" title="{{ $a->extraction_error }}">
                                            ✗ falha
                                        </span>
                                    @else
                                        <span class="rounded bg-gray-50 text-gray-600 px-1.5 py-0.5 text-[10px] shrink-0">·</span>
                                    @endif
                                    <span class="text-gray-400 shrink-0">{{ $a->created_at?->diffForHumans(['short' => true]) }}</span>
                                    @if($canEdit)
                                        <form method="POST" action="{{ route('tenders.attachments.destroy', [$tender, $a]) }}"
                                              onsubmit="return confirm('Remover {{ addslashes($a->original_name) }}?')"
                                              class="shrink-0">
                                            @csrf @method('DELETE')
                                            <button class="text-red-500 hover:text-red-700" title="Remover">✗</button>
                                        </form>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @endif

                    <div id="ta-dropzone"
                         class="rounded-lg border-2 border-dashed border-gray-300 bg-gray-50 px-6 py-8 text-center cursor-pointer hover:bg-gray-100 hover:border-indigo-400 transition">
                        <svg class="mx-auto h-9 w-9 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M7.5 7.5h-.75A2.25 2.25 0 0 0 4.5 9.75v7.5a2.25 2.25 0 0 0 2.25 2.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-7.5a2.25 2.25 0 0 0-2.25-2.25h-.75m-9 4.5 3.75-3.75m0 0 3.75 3.75M12 7.5v9" />
                        </svg>
                        <p class="mt-2 text-sm text-gray-700">
                            <span class="font-semibold text-indigo-600">Arrasta para aqui</span> ou clica para escolher
                        </p>
                        <p class="mt-1 text-[11px] text-gray-500">
                            PDF, imagens (JPG/PNG) e outros · máx. 10 ficheiros · 50 MB cada
                        </p>
                        <input id="ta-file" type="file"
                               accept=".pdf,application/pdf,image/*,.docx,.xlsx,.eml"
                               multiple class="hidden">
                    </div>

                    {{-- 📷 Capturar com câmara do telemóvel — em mobile abre
                         directamente a câmara traseira (capture="environment").
                         No desktop chrome também abre como upload. Mesma
                         pipeline: vai para /attachments e o backend chama
                         Claude Vision para OCR se for imagem. 2026-05-19. --}}
                    <div class="mt-3 flex items-center gap-2 sm:hidden">
                        <button type="button" id="ta-camera-btn"
                                class="inline-flex items-center gap-1.5 rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white hover:bg-indigo-500">
                            📷 Capturar com câmara
                        </button>
                        <span class="text-[11px] text-gray-500">OCR automático via Claude Vision</span>
                    </div>
                    <input id="ta-camera-file" type="file" accept="image/*" capture="environment" class="hidden">

                    <div id="ta-status" class="mt-3 hidden text-xs"></div>
                </section>

                <script>
                (function () {
                    const drop  = document.getElementById('ta-dropzone');
                    const input = document.getElementById('ta-file');
                    const status= document.getElementById('ta-status');
                    if (!drop || !input || !status) return;
                    const csrf  = document.querySelector('meta[name=csrf-token]')?.content || '';
                    const url   = "{{ route('tenders.attachments.store', $tender) }}";

                    function show(msg, tone) {
                        status.classList.remove('hidden');
                        status.className = 'mt-3 text-xs ' + (tone === 'err' ? 'text-red-700' : tone === 'ok' ? 'text-emerald-700' : 'text-gray-600');
                        status.textContent = msg;
                    }

                    async function uploadFiles(fileList) {
                        const fd = new FormData();
                        Array.from(fileList).forEach(f => fd.append('files[]', f));
                        if (!fd.has('files[]')) return;
                        show('A enviar e a extrair texto…');
                        try {
                            const r = await fetch(url, {
                                method: 'POST',
                                body: fd,
                                headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                                credentials: 'same-origin',
                            });
                            if (r.status === 401) {
                                if (await maybeRedirectOnOtp(r)) return;
                            }
                            if (!r.ok) {
                                const txt = await r.text();
                                throw new Error('HTTP ' + r.status + ': ' + txt.slice(0, 160));
                            }
                            const data = await r.json();
                            const parts = [];
                            if (data.created)    parts.push(data.created + ' novo(s)');
                            if (data.duplicates) parts.push(data.duplicates + ' duplicados');
                            if (data.failed?.length) parts.push(data.failed.length + ' falharam');
                            show('✓ ' + parts.join(' · ') + ' — a recarregar…', 'ok');
                            if (window.cyToast) window.cyToast({ title: '✓ PDFs anexados', body: parts.join(' · '), tone: 'success', duration: 2400 });
                            setTimeout(() => location.reload(), 900);
                        } catch (e) {
                            show('Erro: ' + e.message, 'err');
                        }
                    }

                    drop.addEventListener('click', () => input.click());
                    input.addEventListener('change', (e) => uploadFiles(e.target.files));

                    // ── Camera capture (mobile) → mesma uploadFiles ─────
                    const camBtn = document.getElementById('ta-camera-btn');
                    const camIn  = document.getElementById('ta-camera-file');
                    if (camBtn && camIn) {
                        camBtn.addEventListener('click', () => camIn.click());
                        camIn.addEventListener('change', (e) => uploadFiles(e.target.files));
                    }

                    // ── Local zone highlight while dragging ─────────────
                    ['dragenter','dragover'].forEach(ev =>
                        drop.addEventListener(ev, (e) => { e.preventDefault(); drop.classList.add('bg-indigo-50','border-indigo-400'); }));
                    ['dragleave','drop'].forEach(ev =>
                        drop.addEventListener(ev, (e) => { e.preventDefault(); drop.classList.remove('bg-indigo-50','border-indigo-400'); }));
                    drop.addEventListener('drop', (e) => {
                        if (e.dataTransfer?.files) uploadFiles(e.dataTransfer.files);
                    });

                    // ── Page-level capture so dropping ANYWHERE on the
                    // tender page uploads to THIS tender (sem navegar para
                    // o ficheiro nem ir parar à hp-history). Without this,
                    // dropping outside the dashed dropzone makes the browser
                    // open the PDF in-place — perdendo a página do concurso.
                    let pageDragCounter = 0;
                    document.addEventListener('dragenter', (e) => {
                        if (!e.dataTransfer?.types?.includes('Files')) return;
                        e.preventDefault();
                        pageDragCounter++;
                        drop.classList.add('bg-indigo-50','border-indigo-400');
                        // Optional: scroll dropzone into view so user sees the target
                        if (pageDragCounter === 1) drop.scrollIntoView({ block:'center', behavior:'smooth' });
                    });
                    document.addEventListener('dragleave', (e) => {
                        if (!e.dataTransfer?.types?.includes('Files')) return;
                        pageDragCounter--;
                        if (pageDragCounter <= 0) {
                            pageDragCounter = 0;
                            drop.classList.remove('bg-indigo-50','border-indigo-400');
                        }
                    });
                    document.addEventListener('dragover', (e) => {
                        if (!e.dataTransfer?.types?.includes('Files')) return;
                        e.preventDefault();
                    });
                    document.addEventListener('drop', (e) => {
                        if (!e.dataTransfer?.types?.includes('Files')) return;
                        // Browser default would navigate to file URL → kill it.
                        e.preventDefault();
                        pageDragCounter = 0;
                        drop.classList.remove('bg-indigo-50','border-indigo-400');
                        // If drop was already handled by the dropzone, e.defaultPrevented
                        // will be true on this bubbled event — but uploadFiles is
                        // idempotent enough; check files anyway.
                        if (e.target?.id === 'ta-dropzone' || drop.contains(e.target)) return;
                        const files = e.dataTransfer?.files;
                        if (files?.length) uploadFiles(files);
                    });
                })();
                </script>
            @endif

            {{-- ─── Sugerir fornecedores + drafts ──────────────────────────
                 Click → AJAX → mostra lista de fornecedores aprovados (H&P)
                 que fazem match na categoria do concurso + sugestões web
                 (Tavily). User selecciona com checkbox quais quer contactar
                 e clica "Gerar drafts" → Daniel devolve 1 email por
                 fornecedor (formato SHAPE B), renderizados inline com botão
                 Outlook em cada um.

                 Confidential mode: o painel inteiro fica oculto e mostra
                 apenas um aviso explicando porquê. Os endpoints AJAX
                 também rejeitam (defesa em profundidade — cliente JS
                 manipulado não consegue contornar).
            --}}
            @if($tender->is_confidential)
                <section class="rounded-lg bg-red-50 border border-red-200 p-4">
                    <div class="flex items-start gap-3">
                        <span class="text-2xl">🔒</span>
                        <div>
                            <h3 class="text-sm font-semibold text-red-800">Concurso confidencial — AI bloqueada</h3>
                            <p class="text-xs text-red-700 mt-1">
                                Este concurso está marcado como confidencial. O painel "Sugerir fornecedores e drafts"
                                (Claude + Tavily) está desligado para evitar que o título / descrição saiam para serviços
                                externos. Continua a poder consultar a tabela local de fornecedores aprovados em
                                <a href="{{ route('suppliers.index') }}" class="underline">/suppliers</a> e usar o SAP normalmente.
                            </p>
                            <p class="text-xs text-red-600 mt-1">
                                Para reactivar: desmarca a flag "Concurso confidencial" no formulário em baixo.
                            </p>
                        </div>
                    </div>
                </section>
            @else
            {{-- Pre-warmed analysis (AnalyseTenderJob fired on import) — quando
                 existe, a "Procurar fornecedores" do painel em baixo torna-se
                 instantânea (lê do prelim_analysis em vez de fazer Tavily de novo).
                 Mostramos aqui um sumário compacto + botão para usar directamente. --}}
            @if($tender->prelim_analysed_at && !empty($tender->prelim_analysis))
                @php
                    $pa = $tender->prelim_analysis;
                    $topIds = (array) ($pa['top_supplier_ids'] ?? []);
                    $cats = (array) ($pa['categories'] ?? []);
                    $webHits = count((array) ($pa['web_results'] ?? []));
                    $difficulty = (string) ($pa['difficulty'] ?? '');
                    $diffReasons = (array) ($pa['difficulty_reasons'] ?? []);
                    $diffPill = match ($difficulty) {
                        'easy'   => ['🟢 Fácil',  'bg-emerald-100 text-emerald-800'],
                        'hard'   => ['🔴 Difícil', 'bg-red-100 text-red-800'],
                        'medium' => ['🟡 Médio',  'bg-amber-100 text-amber-800'],
                        default  => null,
                    };
                @endphp
                <section class="rounded-lg bg-emerald-50/40 border border-emerald-200 p-4 text-sm">
                    <div class="flex items-start gap-3">
                        <span class="text-2xl">⚡</span>
                        <div class="flex-1">
                            <div class="flex items-center gap-2 flex-wrap">
                                <span class="font-semibold text-emerald-800">Pré-análise pronta</span>
                                @if($diffPill)
                                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-bold {{ $diffPill[1] }}"
                                          title="{{ implode(' · ', $diffReasons) ?: 'classificação por defeito' }}">
                                        {{ $diffPill[0] }}
                                    </span>
                                @endif
                            </div>
                            <div class="text-xs text-emerald-700 mt-0.5">
                                Computada {{ $tender->prelim_analysed_at->diffForHumans() }} ·
                                <strong>{{ count($topIds) }}</strong> fornecedores H&amp;P sugeridos ·
                                <strong>{{ $webHits }}</strong> resultados web ·
                                Categorias: <code class="bg-white border border-emerald-200 rounded px-1">{{ implode(', ', $cats) ?: 'nenhuma' }}</code>
                            </div>
                            @if(!empty($diffReasons))
                                <div class="text-[11px] text-emerald-600 mt-1">
                                    <strong>Razões:</strong> {{ implode(' · ', $diffReasons) }}
                                </div>
                            @endif
                            <p class="text-[11px] text-emerald-600 mt-1">
                                Clica "🔎 Procurar fornecedores" abaixo para ver o detalhe e gerar drafts.
                            </p>
                        </div>
                    </div>
                </section>
            @endif

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
                        if (res.status === 401 && await maybeRedirectOnOtp(res)) return;
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
                    const cats       = (data.categories || []).join(', ');
                    const local      = data.local           || [];
                    const web        = data.web             || [];
                    const opinions   = data.expert_opinions || [];
                    const outOfScope = !!data.out_of_scope;
                    const oemDirect  = data.oem_direct     || [];

                    let html = `
                        <div class="rounded-md border border-gray-100 bg-gray-50 px-3 py-2 text-xs text-gray-600 mb-3">
                            🏷️ Categorias inferidas: <span class="font-mono text-gray-800">${esc(cats || 'nenhuma')}</span>
                            · ${local.length} fornecedor(es) H&amp;P · ${web.length} web
                            ${opinions.length ? `· <span class="text-purple-700 font-medium">${opinions.length} especialista(s) consultado(s)</span>` : ''}
                            ${outOfScope ? `· <span class="text-amber-700 font-bold">⚠ fora do domínio H&amp;P</span>` : ''}
                        </div>
                    `;

                    // 2026-05-18: banner de "fora do domínio" + lista de OEMs directos.
                    // Aparece em primeiro lugar quando os especialistas H&P unanimamente
                    // não viram encaixe (ex.: concurso ENT médico para PartYard naval).
                    if (outOfScope) {
                        html += `
                            <div class="rounded-md bg-amber-50 border-l-4 border-amber-500 px-4 py-3 mb-4">
                                <div class="text-sm font-semibold text-amber-900 mb-2">⚠ Especialistas H&amp;P não vêem encaixe neste concurso</div>
                                <div class="text-xs text-amber-800 mb-3">
                                    Os pareceres acima indicam que o produto/serviço está fora do ecossistema H&amp;P actual.
                                    Em vez de contactar fornecedores internos genéricos, considera contactar os <strong>OEM directos</strong>:
                                </div>
                                ${oemDirect.length ? `
                                    <div class="bg-white rounded border border-amber-200 px-3 py-2">
                                        <ul class="space-y-1.5 text-xs">
                                            ${oemDirect.map(o => `
                                                <li class="flex items-start gap-2">
                                                    <span class="text-amber-700 font-bold">▸</span>
                                                    <span>
                                                        <strong class="text-gray-900">${esc(o.name)}</strong>
                                                        ${(o.items || o.focus) ? `<span class="text-gray-600 text-[11px]"> — <em>${esc(o.items || o.focus)}</em></span>` : ''}
                                                    </span>
                                                </li>
                                            `).join('')}
                                        </ul>
                                        <div class="text-[10px] text-amber-700 mt-2 italic">
                                            💡 Sugestões geradas por LLM com base no RFP. Confirma cada OEM antes de contactar.
                                            Procura o canal de procurement directo (vendas / distribuidores oficiais EU/NATO).
                                        </div>
                                    </div>
                                ` : `
                                    <div class="text-xs text-amber-700 italic">
                                        Sem sugestões de OEM disponíveis (LLM falhou ou não reconheceu o domínio).
                                        Procura manualmente os fabricantes pelos termos-chave do RFP.
                                    </div>
                                `}
                            </div>
                        `;
                    }

                    // ── Expert opinions panel — appears ABOVE the local list
                    //    so the operator reads the human-style rationale
                    //    BEFORE picking suppliers from the directory. The
                    //    opinions are advisory; the checkboxes still drive
                    //    Daniel email generation from the local table.
                    if (opinions.length > 0) {
                        html += `<div class="space-y-2 mb-4">`;
                        opinions.forEach(op => {
                            const meta = op.agent_meta || {};
                            const color = meta.color || '#76b900';
                            html += `
                                <div class="rounded-md border-l-4 bg-gradient-to-r from-white to-gray-50 px-4 py-3 shadow-sm" style="border-left-color:${esc(color)}">
                                    <div class="flex items-center gap-2 mb-1">
                                        <span class="text-base">${esc(meta.emoji || '🤖')}</span>
                                        <span class="font-semibold text-sm" style="color:${esc(color)}">${esc(meta.name || op.agent)}</span>
                                        <span class="text-[10px] text-gray-400 ml-auto">consultado · ~$${(op.cost_usd || 0).toFixed(4)}</span>
                                    </div>
                                    ${op.response ? `<div class="text-xs text-gray-700 italic mb-2">"${esc(op.response)}"</div>` : ''}
                                    ${(op.suppliers && op.suppliers.length) ? `
                                        <div class="text-[11px] text-gray-500 mb-1 font-semibold uppercase tracking-wider">Sugestões do ${esc(meta.name || op.agent)}:</div>
                                        <ul class="space-y-1">
                                            ${op.suppliers.map(s => `
                                                <li class="text-xs">
                                                    <span class="font-semibold text-gray-800">${esc(s.name)}</span>
                                                    ${s.why ? `<span class="text-gray-600"> — ${esc(s.why)}</span>` : ''}
                                                </li>
                                            `).join('')}
                                        </ul>
                                        <div class="text-[10px] text-gray-400 mt-2">
                                            💡 Estes são pareceres do agente — não estão no directório H&amp;P.
                                            Para os promover a aprovados, abre <a href="/suppliers/create" class="underline">/suppliers/create</a>.
                                        </div>
                                    ` : `<div class="text-[11px] text-gray-500 italic">Sem fornecedores específicos a sugerir.</div>`}
                                </div>
                            `;
                        });
                        html += `</div>`;
                    }

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
                        if (res.status === 401 && await maybeRedirectOnOtp(res)) return;
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
                                    <div class="flex items-center gap-2 pt-1 flex-wrap">
                                        {{-- 2026-05-18: botão Send via ClawYard (SMTP do servidor)
                                             para o operador não precisar de sair para o Outlook.
                                             Pedido directo: "analisar os emails para enviar logo,
                                             pedidos e analise dos agentes logo ali". --}}
                                        <button type="button" data-card-id="${id}" data-action="send"
                                                class="rounded-md bg-emerald-600 text-white px-3 py-1 text-xs font-semibold hover:bg-emerald-500">
                                            📤 Enviar via ClawYard
                                        </button>
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
                            if (action === 'outlook')      openInOutlookLocal(id);
                            else if (action === 'copy')    copyEmailLocal(id);
                            else if (action === 'send')    sendViaClawYardLocal(id, b);
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

                // 2026-05-18: enviar email DIRECTAMENTE via SMTP do servidor
                // ClawYard, sem precisar de Outlook desktop. Pedido directo:
                // "analisar os emails para enviar logo... logo ali".
                async function sendViaClawYardLocal(id, btn) {
                    const to      = document.getElementById(id+'_to')?.value.trim()      || '';
                    const cc      = document.getElementById(id+'_cc')?.value.trim()      || '';
                    const subject = document.getElementById(id+'_subject')?.value.trim() || '';
                    const body    = document.getElementById(id+'_body')?.value.trim()    || '';
                    const st      = document.getElementById(id+'_status');

                    if (!to || !subject || !body) {
                        if (st) { st.textContent = '⚠ Preenche Para + Assunto + Corpo'; st.style.color = '#b91c1c'; }
                        return;
                    }
                    if (!confirm(`Enviar email para ${to}?\nAssunto: ${subject.slice(0,60)}...`)) return;

                    const orig = btn.textContent;
                    btn.disabled = true;
                    btn.textContent = '⏳ a enviar…';
                    if (st) { st.textContent = ''; st.style.color = ''; }

                    try {
                        const fd = new FormData();
                        fd.append('to', to);
                        if (cc) fd.append('cc', cc);
                        fd.append('subject', subject);
                        fd.append('body', body);

                        const res = await fetch('/api/email/send', {
                            method:  'POST',
                            headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                            body:    fd,
                            credentials: 'same-origin',
                        });
                        if (res.status === 401 && await maybeRedirectOnOtp(res)) return;
                        const data = await res.json().catch(() => ({}));
                        if (!res.ok || data.error) throw new Error(data.error || data.message || ('HTTP ' + res.status));

                        btn.style.background = '#16a34a';
                        btn.textContent = '✅ enviado';
                        if (st) { st.textContent = '✅ enviado para ' + to; st.style.color = '#15803d'; }
                        // Desactiva o card para evitar reenvio acidental.
                        document.getElementById(id+'_card')?.style.setProperty('opacity', '0.55');
                    } catch (e) {
                        btn.disabled = false;
                        btn.textContent = orig;
                        if (st) { st.textContent = '❌ ' + (e.message || 'erro'); st.style.color = '#b91c1c'; }
                    }
                }
            })();
            </script>
            @endif {{-- /is_confidential --}}

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
                                <label class="block text-xs font-medium text-gray-700 mb-1">Estado <span class="text-gray-400 font-normal">(derivado do SAP)</span></label>
                                <div class="w-full rounded-md border border-gray-200 bg-gray-50 px-3 py-2">
                                    <span class="inline-flex items-center rounded-md border px-2 py-1 text-xs font-semibold {{ $tender->sapStageBadgeClasses() }}">
                                        {{ $tender->sapStageLabel() }}
                                    </span>
                                    @if($tender->sap_stage_updated_at)
                                        <span class="ml-2 text-xs text-gray-500">
                                            sincronizado {{ $tender->sap_stage_updated_at->diffForHumans() }}
                                        </span>
                                    @endif
                                </div>
                                <p class="mt-1 text-xs text-gray-500">
                                    O estado vem sempre do SAP (single source of truth). Para mudar, actualiza a fase da Oportunidade no SAP B1 ou pede ao Richard SAP/Marta CRM via chat.
                                </p>
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

                        {{-- Modo confidencial — bloqueia LLM + web search
                             para este concurso. Ver migração
                             2026_04_30_000004 para detalhes. --}}
                        <div class="rounded-md border {{ $tender->is_confidential ? 'border-red-300 bg-red-50' : 'border-gray-200 bg-gray-50' }} px-3 py-2">
                            <label class="flex items-start gap-3 cursor-pointer">
                                <input type="checkbox" name="is_confidential" value="1"
                                       @checked(old('is_confidential', $tender->is_confidential))
                                       class="mt-0.5 rounded border-gray-300 text-red-600 focus:ring-red-500">
                                <div>
                                    <span class="text-sm font-semibold text-gray-800">🔒 Concurso confidencial</span>
                                    <p class="text-xs text-gray-600 mt-0.5">
                                        Quando marcado: <strong>nenhum agente LLM</strong> (Claude / NVIDIA) é chamado para este concurso,
                                        <strong>pesquisa web (Tavily) desligada</strong>, e o painel "🤖 Sugerir fornecedores e drafts" fica oculto.
                                        Apenas a tabela local de fornecedores aprovados (H&P) e o SAP continuam acessíveis.
                                        Usar para RFQs NATO / classificados onde o conteúdo não pode sair para serviços externos.
                                    </p>
                                </div>
                            </label>
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
                                      data-autogrow
                                      data-voice
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
                              data-autogrow
                              data-voice
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
