<?php

namespace App\Services;

use App\Models\Tender;
use App\Models\TenderServiceAnalysis;
use App\Services\AgentSwarm\AgentDispatcher;
use App\Services\AgentTools\AgentToolInterface;
use App\Services\AgentTools\BookSearchTool;
use App\Services\AgentTools\TenderAttachmentsTool;
use App\Services\AgentTools\TenderSearchTool;
use App\Services\AgentTools\WebSearchTool;
use Illuminate\Support\Facades\Log;

/**
 * Orchestra uma "análise completa do serviço" para um concurso,
 * combinando o output de 4-6 agentes especialistas em diferentes
 * domínios (defesa, comercial, logística, engenharia, contratos).
 *
 * Cada agente recebe o mesmo contexto (tender body + PDF text +
 * categorias inferidas) e devolve um JSON estruturado com:
 *   - summary (≤200 chars)
 *   - key_points (lista de pontos-chave da análise dele)
 *   - risks (riscos a sinalizar)
 *   - recommendations (próximos passos concretos)
 *   - lead_time_estimate (string)
 *   - compliance_flags (NATO/ITAR/CE/etc se aplicável)
 *
 * O agregador junta tudo num documento renderizável + cria um
 * "executive summary" sintetizado a partir das 4-6 análises.
 *
 * Custo típico: ~$0.04 por análise (5 agentes × ~$0.008 cada).
 * Cached em DB (TenderServiceAnalysis) — re-running sobrepõe.
 */
class TenderServiceAnalysisService
{
    /**
     * Mapa de agentes a consultar consoante as categorias do concurso.
     * Os 'core' agents correm sempre; os 'conditional' só quando
     * a categoria está nas inferidas; os 'source' só quando a source
     * do tender corresponde (ex.: marine adiciona Captain Porto + Capitao
     * Vasco + Eng. Repair).
     *
     * 2026-05-19 — pedido directo do operador:
     *   "analise com cor. rodrigues e marco sales e todos os agentes
     *    para analise"
     * Promove Cor. Rodrigues (mildef) a core porque ~todos os tenders
     * HP-Group/PartYard são dual-use militar/civil — vale a pena ter a
     * leitura defence sempre, mesmo quando a categoria não está
     * explicitamente classificada como 13/14. Também adiciona o painel
     * marítimo (capitao + vessel + workreport) automaticamente quando
     * o tender vem do Marine Department.
     */
    private const AGENT_PLAYBOOK = [
        // Sempre — domínios universais
        'core' => [
            'mildef',    // Cor. Rodrigues — defesa & procurement militar (sempre)
            'sales',     // Marco Sales — strategy, OEM ecosystem (sempre)
            'engineer',  // Eng. Victor R&D — technical interpretation
            'shipping',  // Logística — Incoterms, freight, customs
            'acingov',   // Dr. Ana Contracts — procurement PT/EU
        ],
        // Por categoria — só corre se a categoria está nas inferidas
        'conditional' => [
            '11' => 'capitao',   // Portos → Captain Porto
            '12' => 'capitao',   // Maritime services PT → Captain Porto
            // 13/14 (militar) já cobertos por mildef no core
            // 15 (industrial PT) já coberto por acingov no core
        ],
        // Por source — só corre se a source do tender corresponde
        'source' => [
            'marine' => ['capitao', 'vessel', 'workreport'],  // operações navais
            'nspa'   => [],         // mildef já no core, basta
            'ncia'   => [],
        ],
    ];

    /** Tecto de agentes a consultar por análise (controlo de custo). */
    private const MAX_AGENTS = 8;

    public function __construct(
        private AgentDispatcher $dispatcher,
        private TenderSupplierSuggesterService $suggester,
        private AutonomousAgentRunner $runner,
        private TenderSearchTool $tenderSearch,
        private TenderAttachmentsTool $tenderAttachments,
        private BookSearchTool $bookSearch,
        private WebSearchTool $webSearch,
        private \App\Services\AnthropicBatchService $batch,
        private \App\Services\AgentTools\NsnLookupTool $nsnLookup,
        private \App\Services\AgentTools\KnowledgeSearchTool $knowledgeSearch,
    ) {}

    /**
     * Tools allow-list por agent_key. Define que ferramentas cada agente
     * pode invocar durante o seu loop autónomo.
     *
     * Política: tender_search + tender_attachments_read + book_search são
     * grátis (Postgres/embedding interno), liberalmente disponíveis. O
     * web_search (Tavily, ~$0.005/call) só para agentes onde info actual
     * importa: defesa, comercial, marketing, research.
     *
     * @return list<AgentToolInterface>
     */
    private function toolsForAgent(string $agentKey): array
    {
        // Base universal — todos têm.
        // 2026-05-25: knowledge_search adicionado à base. Custo €0 por
        // call (DB query) e os 31 factos auto-extraídos da memória
        // organizacional PartYard são úteis para qualquer agente —
        // saber que ORE26003 = NSPA, ou que HP-Group tem NCAGE P3527
        // melhora a precisão de TODAS as recomendações.
        $tools = [
            $this->tenderSearch,
            $this->tenderAttachments,
            $this->bookSearch,
            $this->knowledgeSearch,
        ];

        // Web search só para agents que precisam de info actual externa
        $webAllowed = ['mildef', 'sales', 'marketing', 'research', 'engineer'];
        if (in_array($agentKey, $webAllowed, true)) {
            $tools[] = $this->webSearch;
        }

        // 2026-05-21: nsn_lookup para agentes que mais usam NSN refs.
        // Defesa (Cor. Rodrigues), Sales (Marco), R&D (Eng. Victor) e
        // Marítimo (Captain Porto) frequentemente precisam de identificar
        // OEM + distribuidores autorizados a partir de NSN num tender.
        $nsnAllowed = ['mildef', 'sales', 'engineer', 'capitao'];
        if (in_array($agentKey, $nsnAllowed, true)) {
            $tools[] = $this->nsnLookup;
        }

        return $tools;
    }

    /**
     * Run the full analysis. Idempotente: cria/sobrepõe a row de
     * TenderServiceAnalysis para o tender. Retorna o model.
     */
    public function analyse(Tender $tender, ?int $userId = null): TenderServiceAnalysis
    {
        $analysis = TenderServiceAnalysis::firstOrNew(['tender_id' => $tender->id]);
        $analysis->status               = 'running';
        $analysis->generated_by_user_id = $userId;
        $analysis->save();

        try {
            $categories = $this->suggester->inferCategories($tender);
            $agentKeys  = $this->pickAgents($categories, $tender->source);

            $sections   = [];
            $totalCost  = 0.0;
            $context    = $this->buildContext($tender, $categories);

            foreach ($agentKeys as $agentKey) {
                $meta = AgentCatalog::find($agentKey);
                if (!$meta) continue;

                $res = $this->consultAgent($agentKey, $meta, $tender, $context);
                if ($res['ok']) {
                    $sections[$agentKey] = [
                        'agent_name'  => $meta['name']  ?? $agentKey,
                        'agent_emoji' => $meta['emoji'] ?? '🤖',
                        'agent_color' => $meta['color'] ?? '#76b900',
                        'summary'     => $res['parsed']['summary']            ?? '',
                        'key_points'  => $res['parsed']['key_points']         ?? [],
                        'risks'       => $res['parsed']['risks']              ?? [],
                        'recommendations' => $res['parsed']['recommendations'] ?? [],
                        'lead_time'   => $res['parsed']['lead_time_estimate'] ?? '',
                        'compliance'  => $res['parsed']['compliance_flags']   ?? [],
                        'cost_usd'    => $res['cost_usd']                     ?? 0,
                        // 2026-05-20 (#65): metadata da execução autónoma para
                        // a UI mostrar transparência do raciocínio.
                        'iterations'   => $res['iterations']   ?? 0,
                        'tool_trace'   => $res['tool_trace']   ?? [],
                        'agent_run_id' => $res['agent_run_id'] ?? null,
                    ];
                    $totalCost += (float) ($res['cost_usd'] ?? 0);
                }
            }

            $execSummary = $this->synthesizeExecutiveSummary($tender, $sections);

            // 2026-05-18 fix: sanitização UTF-8 antes de gravar.
            // Os LLMs por vezes devolvem bytes inválidos em strings com
            // acentos PT — depois o response()->json() do controller
            // rebenta porque json_encode falha com "Malformed UTF-8".
            // Substituímos bytes inválidos por U+FFFD agora, na origem,
            // para que o DB também não acumule lixo.
            $sections    = $this->sanitizeUtf8($sections);
            $execSummary = $this->sanitizeUtf8($execSummary);

            $analysis->status             = 'done';
            $analysis->agents_consulted   = array_keys($sections);
            $analysis->sections           = $sections;
            $analysis->executive_summary  = $execSummary;
            $analysis->total_cost_usd     = round($totalCost, 4);
            $analysis->generated_at       = now();
            $analysis->save();

            Log::info('TenderServiceAnalysis: done', [
                'tender_id' => $tender->id,
                'agents'    => $analysis->agents_consulted,
                'cost'      => $analysis->total_cost_usd,
            ]);
        } catch (\Throwable $e) {
            $analysis->status = 'failed';
            $analysis->save();
            Log::error('TenderServiceAnalysis: failed', [
                'tender_id' => $tender->id,
                'error'     => $e->getMessage(),
            ]);
            throw $e;
        }

        return $analysis;
    }

    /**
     * 2026-05-21: variante BATCH da analyse() — em vez de correr N agentes
     * sincronamente por tender (que demora 60-180s e cobra Messages API
     * full price), agrupa todos os (tender × agent) prompts em UM único
     * batch Anthropic e devolve-o em ~1h por **50% off**.
     *
     * Pedido directo: "Anthropic Batch API nightly multi-agent (50% off)
     * quero este vale a pena".
     *
     * Trade-off vs sync path:
     *   • Sem tool-use (web_search / tender_search) — Batch API é single-turn
     *   • Resposta em ~1h (vs ~3min sync) — bom para nocturno
     *   • 50% off no input+output token pricing
     *
     * Caller responsabilidade:
     *   • Filtrar tenders sem confidential, com PDFs OK ou texto útil
     *   • Não submeter o mesmo tender 2× (verificar TenderServiceAnalysis
     *     row status='running'|'queued_batch')
     *
     * @param iterable<Tender> $tenders
     * @return \App\Models\AnthropicBatch|null  null se nada submetido
     */
    public function submitBatch(iterable $tenders, ?int $userId = null): ?\App\Models\AnthropicBatch
    {
        $requests = [];
        $custom_id_map = [];   // custom_id → ['tender_id'=>X, 'agent_key'=>Y]

        foreach ($tenders as $tender) {
            if ($tender->is_confidential) continue;   // never expose to external

            $categories = $this->suggester->inferCategories($tender);
            $agentKeys  = $this->pickAgents($categories, $tender->source);
            $context    = $this->buildContext($tender, $categories);

            foreach ($agentKeys as $agentKey) {
                $meta = AgentCatalog::find($agentKey);
                if (!$meta) continue;
                $name   = $meta['name'] ?? $agentKey;
                $role   = $meta['role'] ?? '';
                $domain = $this->domainFraming($agentKey);

                // Prompt SIMPLIFICADO (single-turn, sem tools) — Batch API
                // não suporta tool-use multi-step. O agent recebe contexto +
                // domain framing e devolve directamente o JSON estruturado.
                $system = <<<PROMPT
És o {$name} ({$role}) do PartYard / HP-Group. {$domain}

Outro agente está a preparar uma análise para este concurso. Foca-te
no SERVIÇO a executar: o que é preciso fazer, riscos, lead time,
compliance — não fornecedores nem emails.

DEVOLVE APENAS este JSON (sem markdown, sem comentário antes ou depois):

{
  "summary": "≤200 chars · uma frase com a tua leitura estratégica",
  "key_points": ["ponto-chave do teu domínio · ≤120 chars", ...max 5...],
  "risks": ["risco · ≤120 chars", ...max 4...],
  "recommendations": ["próximo passo · ≤120 chars", ...max 5...],
  "lead_time_estimate": "ex: 6-8 semanas, ou 'depende' · ≤60 chars",
  "compliance_flags": ["NATO", "ITAR", "CE", "EUC", ...]
}

REGRAS:
  • Foca-te apenas no teu domínio.
  • NÃO inventes dados — se não tens certeza, omite.
  • NUNCA escrevas fornecedores chineses/russos.
  • Português europeu.
PROMPT;

                $userMsg = "Concurso a analisar:\n{$context}\n\nDevolve APENAS o JSON.";

                $customId = "tender-{$tender->id}-{$agentKey}";   // sanitizado pelo service

                $requests[] = [
                    'custom_id'  => $customId,
                    'system'     => $system,
                    'messages'   => [['role' => 'user', 'content' => $userMsg]],
                    'max_tokens' => 2000,
                ];
                $custom_id_map[$customId] = ['tender_id' => $tender->id, 'agent_key' => $agentKey];

                // Marca a row da análise como queued
                TenderServiceAnalysis::updateOrCreate(
                    ['tender_id' => $tender->id],
                    ['status' => 'queued_batch', 'generated_by_user_id' => $userId]
                );
            }
        }

        if (empty($requests)) return null;

        $model = (string) config('services.anthropic.model', 'claude-sonnet-4-6');
        $batch = $this->batch->submit($model, 'tender-analysis', $requests, $userId);
        if (!$batch || !$batch->batch_id) return $batch;

        // Persistir o mapping no metadata para collectBatch saber assemblar
        $batch->update([
            'metadata' => array_merge((array) $batch->metadata, [
                'custom_id_map' => $custom_id_map,
            ]),
        ]);

        return $batch;
    }

    /**
     * Quando o batch acaba (status=ended), descarrega results e assembla
     * por tender. Retorna número de tenders processados.
     */
    public function collectBatch(\App\Models\AnthropicBatch $batch): int
    {
        if (!$batch->isReady()) return 0;

        $results = $this->batch->collectResults($batch);
        if (empty($results)) return 0;

        $map = (array) (($batch->metadata['custom_id_map'] ?? []));
        if (empty($map)) return 0;

        // Agrupa results por tender_id → [agent_key => parsed_json|null]
        $byTender = [];
        $costTotal = 0.0;
        foreach ($results as $customId => $row) {
            $info = $map[$customId] ?? null;
            if (!$info) continue;
            $tid = (int) $info['tender_id'];
            $ak  = (string) $info['agent_key'];
            if (!($row['ok'] ?? false)) {
                $byTender[$tid][$ak] = null;
                continue;
            }
            $parsed = $this->parseJson((string) ($row['text'] ?? ''));
            $byTender[$tid][$ak] = $parsed;

            // Estimar custo do request (Batch API é 50% off)
            $usage = (array) ($row['usage'] ?? []);
            $inTok  = (int) ($usage['input_tokens']  ?? 0);
            $outTok = (int) ($usage['output_tokens'] ?? 0);
            // Sonnet 4.6: $3 in / $15 out per MTok → ÷2 para Batch
            $costTotal += ($inTok * 3 + $outTok * 15) / 1_000_000 * 0.5;
        }

        $processed = 0;
        foreach ($byTender as $tid => $agentResults) {
            $tender = Tender::find($tid);
            if (!$tender) continue;
            $sections = [];
            foreach ($agentResults as $agentKey => $parsed) {
                if (!is_array($parsed) || empty($parsed['summary'])) continue;
                $meta = AgentCatalog::find($agentKey);
                $sections[$agentKey] = [
                    'agent_name'  => $meta['name']  ?? $agentKey,
                    'agent_emoji' => $meta['emoji'] ?? '🤖',
                    'agent_color' => $meta['color'] ?? '#76b900',
                    'summary'     => $parsed['summary']             ?? '',
                    'key_points'  => $parsed['key_points']          ?? [],
                    'risks'       => $parsed['risks']               ?? [],
                    'recommendations' => $parsed['recommendations'] ?? [],
                    'lead_time'   => $parsed['lead_time_estimate']  ?? '',
                    'compliance'  => $parsed['compliance_flags']    ?? [],
                    'cost_usd'    => 0,    // costs are tracked at batch level
                    'iterations'  => 0,
                    'tool_trace'  => [],
                    'agent_run_id'=> null,
                    'via_batch'   => true,
                ];
            }
            if (empty($sections)) continue;

            $execSummary = $this->synthesizeExecutiveSummary($tender, $sections);
            $sections    = $this->sanitizeUtf8($sections);
            $execSummary = $this->sanitizeUtf8($execSummary);

            TenderServiceAnalysis::updateOrCreate(
                ['tender_id' => $tid],
                [
                    'status'            => 'done',
                    'agents_consulted'  => array_keys($sections),
                    'sections'          => $sections,
                    'executive_summary' => $execSummary,
                    'total_cost_usd'    => round($costTotal / max(1, count($byTender)), 4),
                    'generated_at'      => now(),
                ],
            );

            \Log::info('TenderServiceAnalysis: done (batch)', [
                'tender_id' => $tid,
                'agents'    => array_keys($sections),
                'via_batch' => $batch->id,
            ]);
            $processed++;
        }

        $batch->update([
            'cost_usd_actual' => round($costTotal, 4),
        ]);

        return $processed;
    }

    /**
     * Decide o agent panel consoante:
     *   1. core fixo (mildef, sales, engineer, shipping, acingov)
     *   2. categorias inferidas (conditional)
     *   3. source do tender (source-aware: marine → +capitao/vessel/workreport)
     *
     * O resultado é ordered de forma estável (core primeiro, depois
     * conditional, depois source) para que o operador veja sempre os
     * mesmos agentes nas mesmas posições — útil ao re-correr análise.
     */
    private function pickAgents(array $categories, ?string $source = null): array
    {
        $picked = self::AGENT_PLAYBOOK['core'];

        foreach ($categories as $cat) {
            $key = self::AGENT_PLAYBOOK['conditional'][(string) $cat] ?? null;
            if ($key && !in_array($key, $picked, true)) $picked[] = $key;
        }

        $sourceKey = mb_strtolower((string) $source);
        $extraBySource = self::AGENT_PLAYBOOK['source'][$sourceKey] ?? [];
        foreach ($extraBySource as $key) {
            if (!in_array($key, $picked, true)) $picked[] = $key;
        }

        return array_slice($picked, 0, self::MAX_AGENTS);
    }

    /** Junta tender + categorias + PDFs num blob de contexto. */
    private function buildContext(Tender $tender, array $categories): string
    {
        $deadline = $tender->deadline_lisbon?->format('d/m/Y') ?? '—';
        $ref      = $tender->reference ?: '—';
        $org      = $tender->purchasing_org ?: '—';

        $ctx = "Concurso #{$tender->id}\n"
             . "  Título: {$tender->title}\n"
             . "  Referência: {$ref}\n"
             . "  Fonte: {$tender->source}\n"
             . "  Organização: {$org}\n"
             . "  Deadline: {$deadline}\n"
             . "  Categorias H&P inferidas: " . implode(', ', $categories) . "\n";

        // Adicionar texto dos PDFs anexos (até 3, máx 5KB cada para conter custo)
        try {
            $atts = $tender->attachments()->where('extraction_status', 'ok')->limit(3)->get();
            if ($atts->isNotEmpty()) {
                $ctx .= "\nDocumentos anexos:\n---\n";
                foreach ($atts as $att) {
                    $ctx .= "[{$att->original_name}]\n" . $att->promptSnippet(5000) . "\n\n---\n";
                }
            }
        } catch (\Throwable $e) { /* anexos opcionais */ }

        return $ctx;
    }

    /**
     * Consulta um agente autónomo (tool-use loop) e parseia o JSON final.
     *
     * 2026-05-20 (#65): substitui o flow 1-shot pela versão autónoma —
     * cada agente recebe tools (tender_search / book_search / web_search /
     * tender_attachments_read) e decide quando chamar durante o seu loop.
     * Cap: 8 iterações, $0.25/agent (≈$1.25 num panel de 5-8 agentes).
     */
    private function consultAgent(string $agentKey, array $meta, Tender $tender, string $context): array
    {
        $name     = $meta['name'] ?? $agentKey;
        $role     = $meta['role'] ?? '';
        $domain   = $this->domainFraming($agentKey);
        $tools    = $this->toolsForAgent($agentKey);
        $toolList = implode(', ', array_map(fn($t) => $t->name(), $tools));

        $system = <<<PROMPT
És o {$name} ({$role}) do PartYard / HP-Group. {$domain}

Outro agente (Marta CRM) está a preparar uma proposta para um concurso
e quer a tua análise técnica do SERVIÇO a executar — não fornecedores,
não emails. Foco: o que é preciso fazer, riscos, lead time, compliance.

TENS FERRAMENTAS DISPONÍVEIS ({$toolList}):
  • tender_search: procura nos 779+ tenders ClawYard para precedentes
    (ex: ver tenders anteriores do mesmo cliente, lead times reais
    praticados, valores históricos).
  • tender_attachments_read: lê o texto completo de um anexo PDF do tender
    actual quando precisas de specs específicas que não estão no contexto.
  • book_search: cita normas/técnicas da biblioteca PartYard.
  • web_search (se disponível): info actual da web (preços OEM, fornecedores
    certificados, regulamentação 2026). Custo ~\$0.005/call — usa quando
    a info que precisas é externa e actual.
  • nsn_lookup (defesa/sales/engineer/maritimo): procura NSN (NATO Stock
    Number) e devolve descrição + OEM + NCAGE codes + distribuidores +
    emails de contacto. Usa quando o tender menciona NSN específico
    (formato XXXX-XX-XXX-XXXX). Custo ~\$0.013/call. Cache 7d.

ESTRATÉGIA:
  1. Pensa: que info crítica te falta para uma resposta forte?
  2. Chama 1-3 tools (não mais — cada call custa tempo e dinheiro)
  3. Sintetiza o que descobriste com o teu conhecimento de domínio
  4. Devolve o JSON FINAL — sem mais tool calls

DEVOLVE APENAS este JSON (sem markdown, sem comentário antes ou depois):

{
  "summary": "≤200 chars · uma frase com a tua leitura estratégica (cita dados específicos das tools quando relevante)",
  "key_points": ["ponto-chave do teu domínio · ≤120 chars cada", ...max 5...],
  "risks": ["risco a sinalizar · ≤120 chars cada", ...max 4...],
  "recommendations": ["próximo passo concreto · ≤120 chars cada", ...max 5...],
  "lead_time_estimate": "ex: 6-8 semanas, ou 'depende' · ≤60 chars",
  "compliance_flags": ["NATO", "ITAR", "CE", "EUC", ...]
}

REGRAS:
  • Foca-te apenas no teu domínio (não atravesses para o domínio de outros agentes).
  • NÃO inventes dados — se uma tool não confirmar, omite.
  • Cita o tender_id/livro/URL quando uses dados de uma tool (transparência).
  • NUNCA escrevas fornecedores chineses/russos como recomendação.
  • Recomenda Setúbal/Lisboa hub se logística passar por PT.
  • Português europeu.
PROMPT;

        $userMsg = "Concurso a analisar:\n{$context}\n\nUsa tools se precisares de dados específicos, depois devolve APENAS o JSON.";

        $res = $this->runner->run([
            'agent_key'         => $agentKey,
            'agent_name'        => $name,
            'system_prompt'     => $system,
            'user_message'      => $userMsg,
            'tools'             => $tools,
            'context'           => [
                'tender_id' => $tender->id,
                'user_id'   => optional(auth()->user())->id,
            ],
            'max_iterations'    => 8,
            'cost_cap_usd'      => 0.25,   // por agent. Panel 5-8 agentes ≈ $1-2 total
            'max_output_tokens' => 2000,
        ]);

        if (!($res['ok'] ?? false)) {
            return [
                'ok' => false,
                'error' => $res['error'] ?? ('agent_runner status=' . ($res['status'] ?? 'unknown')),
            ];
        }

        $parsed = $this->parseJson((string) ($res['final_text'] ?? ''));
        return [
            'ok'         => true,
            'parsed'     => $parsed,
            'cost_usd'   => $res['cost_usd']   ?? 0,
            'iterations' => $res['iterations'] ?? 0,
            'tool_trace' => $res['tool_trace'] ?? [],
            'agent_run_id' => $res['agent_run_id'] ?? null,
        ];
    }

    /** Domain framing per agent — acima e além da role generica do catálogo. */
    private function domainFraming(string $agentKey): string
    {
        return match ($agentKey) {
            'mildef'   => 'Domínio: defesa e procurement militar. Pensa em NSN/NCAGE, ITAR/EAR, EUC, classificações, fornecedores NATO/EU/USLI. NUNCA China/Rússia.',
            'sales'    => 'Domínio: estratégia comercial. Pensa em ecossistema OEM (MTU, CAT, MAK, MAN, Wartsila), distribuidores oficiais, OEM vs aftermarket, lead times.',
            'engineer' => 'Domínio: engenharia e validação técnica. Pensa em datasheets, alternativas técnicas, testes de aceitação, certificados de conformidade.',
            'capitao'  => 'Domínio: operações marítimas e portos. Pensa em port calls, agentes portuários, bunkering, IMO/SOLAS, classificadoras.',
            'shipping' => 'Domínio: logística internacional. Pensa em Incoterms, TARIC, VIES, alfândega, modos (FCL/LCL/aéreo), seguros, hub PT vs direto.',
            'acingov'  => 'Domínio: contratação pública PT/EU. Pensa em NIPC, CAE, RECAP, qualificação prévia, garantias, prazos legais.',
            default    => "Domínio: {$agentKey}.",
        };
    }

    /** Loose JSON parsing, mesma tolerância da CrmAgent / outros. */
    private function parseJson(string $raw): array
    {
        $clean = trim(preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $raw) ?? $raw);
        if (!preg_match('/\{[\s\S]*\}/', $clean, $m)) {
            return ['summary' => mb_substr($raw, 0, 200)];
        }
        $decoded = json_decode($m[0], true);
        if (!is_array($decoded)) {
            return ['summary' => mb_substr($raw, 0, 200)];
        }
        return $decoded;
    }

    /**
     * Sintetiza um "executive summary" de ≤500 chars combinando os
     * summaries de cada agente. Usa string concatenation simples
     * (zero LLM calls extras — economia).
     */
    private function synthesizeExecutiveSummary(Tender $tender, array $sections): string
    {
        if (empty($sections)) return '';

        $bits = ["**{$tender->title}** — análise multi-agente:"];
        foreach ($sections as $key => $sec) {
            $emoji = $sec['agent_emoji'] ?? '·';
            $name  = $sec['agent_name']  ?? $key;
            $sum   = trim((string) ($sec['summary'] ?? ''));
            if ($sum !== '') {
                $bits[] = "{$emoji} **{$name}**: {$sum}";
            }
        }
        return mb_substr(implode("\n\n", $bits), 0, 1500);
    }

    /**
     * Sanitização recursiva de bytes UTF-8 inválidos em strings de qualquer
     * profundidade dentro de uma estrutura (array | string | scalar).
     * Substitui bytes inválidos por '?' usando iconv com //IGNORE//TRANSLIT.
     *
     * Necessário porque os LLMs ocasionalmente emitem sequências UTF-8
     * partidas em texto português com acentos — depois Laravel json_encode
     * rebenta com fatal "Malformed UTF-8 characters" e o controller
     * devolve HTML 500 em vez de JSON. Sanitizar antes do save evita
     * problemas downstream também na renderização da view (Blade).
     */
    private function sanitizeUtf8(mixed $data): mixed
    {
        if (is_array($data)) {
            $out = [];
            foreach ($data as $k => $v) {
                $out[$k] = $this->sanitizeUtf8($v);
            }
            return $out;
        }
        if (is_string($data)) {
            // Tenta primeiro validar — se já é UTF-8 válido, devolve as-is.
            if (mb_check_encoding($data, 'UTF-8')) return $data;
            // Fallback: força UTF-8 substituindo bytes inválidos.
            $clean = @iconv('UTF-8', 'UTF-8//IGNORE', $data);
            return $clean !== false ? $clean : mb_convert_encoding($data, 'UTF-8', 'UTF-8');
        }
        return $data;
    }
}
