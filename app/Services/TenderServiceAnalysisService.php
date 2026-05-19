<?php

namespace App\Services;

use App\Models\Tender;
use App\Models\TenderServiceAnalysis;
use App\Services\AgentSwarm\AgentDispatcher;
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
    ) {}

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

    /** Consulta um agente e parseia a resposta JSON. */
    private function consultAgent(string $agentKey, array $meta, Tender $tender, string $context): array
    {
        $name     = $meta['name'] ?? $agentKey;
        $role     = $meta['role'] ?? '';
        $domain   = $this->domainFraming($agentKey);

        $system = <<<PROMPT
És o {$name} ({$role}) do PartYard / HP-Group. {$domain}

Outro agente (Marta CRM) está a preparar uma proposta para um concurso
e quer a tua análise técnica do SERVIÇO a executar — não fornecedores,
não emails. Foco: o que é preciso fazer, riscos, lead time, compliance.

DEVOLVE APENAS este JSON (sem markdown, sem comentário antes ou depois):

{
  "summary": "≤200 chars · uma frase com a tua leitura estratégica",
  "key_points": ["ponto-chave do teu domínio · ≤120 chars cada", ...max 5...],
  "risks": ["risco a sinalizar · ≤120 chars cada", ...max 4...],
  "recommendations": ["próximo passo concreto · ≤120 chars cada", ...max 5...],
  "lead_time_estimate": "ex: 6-8 semanas, ou 'depende' · ≤60 chars",
  "compliance_flags": ["NATO", "ITAR", "CE", "EUC", ...]
}

REGRAS:
  • Foca-te apenas no teu domínio (não atravesses para o domínio de outros agentes).
  • NÃO inventes dados (NCAGE, números, prazos) — se o concurso não diz, omite.
  • NUNCA escrevas fornecedores chineses/russos como recomendação.
  • Recomenda Setúbal/Lisboa hub se logística passar por PT.
  • Português europeu, max ~600 tokens output total.
PROMPT;

        $user = "Concurso a analisar:\n{$context}\n\nDevolve o JSON.";

        $res = $this->dispatcher->dispatch(
            systemPrompt: $system,
            userMessage:  $user,
            maxTokens:    1200,
        );

        if (!($res['ok'] ?? false)) {
            return ['ok' => false, 'error' => $res['error'] ?? 'dispatch_error'];
        }

        $parsed = $this->parseJson((string) ($res['text'] ?? ''));
        return [
            'ok'       => true,
            'parsed'   => $parsed,
            'cost_usd' => $res['cost_usd'] ?? 0,
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
