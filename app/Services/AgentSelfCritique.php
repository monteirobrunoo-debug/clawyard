<?php

namespace App\Services;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * AgentSelfCritique — Reflexion-style second-pass validation.
 *
 * 2026-05-18 — pedido directo do operador:
 *   "O resultado dos agentes tem de ser sempre verdadeiro, e tentar validar
 *    sempre a melhor opção, por isso critica internamente e cria mecanismos
 *    de critica e auto prompts para ter os melhores resultados"
 *
 * Como funciona:
 *   • Após o agente principal produzir o draft, este service faz UMA chamada
 *     extra ao Claude com persona "Revisor sénior crítico"
 *   • O revisor lê: (a) o pedido original, (b) o draft, (c) opcional contexto
 *   • Devolve JSON estruturado: verdict, issues[], refined?, confidence
 *
 * Modelo usado: configurável via services.agent_critique.model — default
 * claude-sonnet-4-5 (mesmo que o draft) para consistência de capacidade,
 * mas pode-se forçar claude-opus-4-5 para validação ainda mais rigorosa.
 *
 * Custo: ~+1 chamada por turn = +30-50% custo. Por isso é OPT-IN via
 * config flag `services.agent_critique.enabled` (default false), e
 * cada agente pode activar individualmente em runtime.
 *
 * SKIP automatic se:
 *   • Draft tem menos de 200 chars (conversational, baixo risco)
 *   • Draft contém token __TABLE__ / __CHART__ / __EMAIL__ (estruturado,
 *     já validado pelo schema)
 *   • Cache hit (mesmo (prompt, draft) já validado nos últimos 5 min)
 */
class AgentSelfCritique
{
    /**
     * Threshold: drafts mais curtos que isto saltam crítica.
     * Conversação curta ("ok", "obrigado", etc) não precisa de validar.
     */
    private const MIN_DRAFT_LEN_TO_CRITIQUE = 200;

    /**
     * Padrões que indicam draft estruturado (já validado pelo seu próprio
     * schema) — saltar crítica por defeito.
     */
    private const STRUCTURED_TOKENS = ['__TABLE__', '__CHART__', '__EMAIL__', '__PPT__'];

    private Client $client;

    public function __construct()
    {
        $baseUri = config('services.anthropic.base_uri', 'https://api.anthropic.com');
        $this->client = new Client([
            'base_uri' => rtrim($baseUri, '/'),
            'timeout'  => 45,
        ]);
    }

    /**
     * Avalia se o draft passa o critique. Retorna array estruturado:
     *
     *   [
     *     'verdict'    => 'ok' | 'minor' | 'major' | 'block',
     *     'confidence' => 0.0 - 1.0,
     *     'issues'     => [
     *       ['severity' => 'high|med|low', 'category' => 'fact|hedge|alt|cite', 'text' => '...'],
     *       ...
     *     ],
     *     'refined'    => string|null,     // versão melhorada, se aplicável
     *     'meta'       => ['model' => ..., 'tokens_in' => ..., 'tokens_out' => ..., 'skipped' => bool],
     *   ]
     *
     * @param string $userPrompt   O pedido original do user
     * @param string $draft        O output do agente (a validar)
     * @param array  $opts         {
     *     @var string  $agent_context  Persona/role do agente original
     *     @var bool    $refine         Pedir versão refinada (default false)
     *     @var bool    $force          Ignorar skip heuristics
     *     @var string  $model          Override do modelo de crítica
     * }
     */
    public function critique(string $userPrompt, string $draft, array $opts = []): array
    {
        $opts = array_merge([
            'agent_context' => '',
            'refine'        => false,
            'force'         => false,
            'model'         => config('services.anthropic.critique_model',
                              config('services.anthropic.model', 'claude-sonnet-4-5')),
        ], $opts);

        // Skip checks
        $skipReason = $opts['force'] ? null : $this->shouldSkip($draft);
        if ($skipReason) {
            return $this->okEnvelope("skipped: {$skipReason}", $opts);
        }

        // Cache: evita re-validar o mesmo draft repetidamente (pode acontecer
        // em retries / re-rendering). TTL 5 min — para outputs idênticos.
        $cacheKey = 'critique:' . sha1($userPrompt . '|' . $draft . '|' . $opts['model']);
        $cached   = Cache::get($cacheKey);
        if ($cached && !$opts['force']) {
            return $cached;
        }

        try {
            $result = $this->callCritiqueLLM($userPrompt, $draft, $opts);
            Cache::put($cacheKey, $result, now()->addMinutes(5));
            return $result;
        } catch (\Throwable $e) {
            Log::warning('AgentSelfCritique: failed — ' . $e->getMessage(), [
                'draft_len' => mb_strlen($draft),
                'prompt_excerpt' => mb_substr($userPrompt, 0, 100),
            ]);
            // Falha de crítica não deve bloquear UX — devolve "ok" com flag
            return $this->okEnvelope('critique-failed: ' . $e->getMessage(), $opts);
        }
    }

    /**
     * Versão batch para validar múltiplos drafts em paralelo (não usa
     * concorrência real — chama sequencialmente mas em loop).
     *
     * @param array<int, array{user_prompt: string, draft: string, opts?: array}> $items
     * @return array<int, array>
     */
    public function critiqueBatch(array $items): array
    {
        $out = [];
        foreach ($items as $i => $item) {
            $out[$i] = $this->critique(
                (string) ($item['user_prompt'] ?? ''),
                (string) ($item['draft'] ?? ''),
                (array) ($item['opts'] ?? [])
            );
        }
        return $out;
    }

    // ── Internals ────────────────────────────────────────────────────────────

    private function shouldSkip(string $draft): ?string
    {
        if (mb_strlen($draft) < self::MIN_DRAFT_LEN_TO_CRITIQUE) {
            return 'too_short';
        }
        foreach (self::STRUCTURED_TOKENS as $tok) {
            if (str_contains($draft, $tok)) {
                return 'structured_output';
            }
        }
        return null;
    }

    private function okEnvelope(string $reason, array $opts): array
    {
        return [
            'verdict'    => 'ok',
            'confidence' => 1.0,
            'issues'     => [],
            'refined'    => null,
            'meta'       => [
                'model'      => $opts['model'],
                'skipped'    => true,
                'skip_reason' => $reason,
            ],
        ];
    }

    private function callCritiqueLLM(string $userPrompt, string $draft, array $opts): array
    {
        $criticPrompt = $this->buildCriticSystemPrompt($opts['agent_context'], $opts['refine']);

        $userTurn = "PEDIDO ORIGINAL DO UTILIZADOR:\n```\n" . mb_substr($userPrompt, 0, 4000) . "\n```\n\n"
                  . "DRAFT GERADO PELO AGENTE (A VALIDAR):\n```\n" . mb_substr($draft, 0, 12000) . "\n```\n\n"
                  . "Avalia o draft contra o pedido e devolve APENAS o JSON especificado, sem texto antes ou depois.";

        $apiKey   = config('services.anthropic.api_key', env('ANTHROPIC_API_KEY'));
        $response = $this->client->post('/v1/messages', [
            'headers' => [
                'Content-Type'      => 'application/json',
                'x-api-key'         => $apiKey,
                'anthropic-version' => '2023-06-01',
            ],
            'json' => [
                'model'      => $opts['model'],
                'max_tokens' => $opts['refine'] ? 8000 : 1500,
                'system'     => $criticPrompt,
                'messages'   => [
                    ['role' => 'user', 'content' => $userTurn],
                ],
            ],
        ]);

        $data = json_decode($response->getBody()->getContents(), true);
        $text = '';
        foreach ($data['content'] ?? [] as $block) {
            if (($block['type'] ?? '') === 'text') $text .= $block['text'];
        }

        // O modelo devolve JSON — tenta extrair o objecto principal mesmo se
        // veio com texto à volta (defensivo).
        $parsed = $this->extractJson($text);
        if (!is_array($parsed)) {
            return $this->okEnvelope('invalid_json_from_critic', $opts);
        }

        return [
            'verdict'    => $this->validVerdict($parsed['verdict'] ?? 'ok'),
            'confidence' => max(0.0, min(1.0, (float) ($parsed['confidence'] ?? 0.8))),
            'issues'     => $this->normaliseIssues($parsed['issues'] ?? []),
            'refined'    => isset($parsed['refined']) && is_string($parsed['refined'])
                                ? $parsed['refined'] : null,
            'meta'       => [
                'model'      => $opts['model'],
                'tokens_in'  => $data['usage']['input_tokens']  ?? null,
                'tokens_out' => $data['usage']['output_tokens'] ?? null,
                'skipped'    => false,
            ],
        ];
    }

    private function buildCriticSystemPrompt(string $agentContext, bool $askRefine): string
    {
        $refineRule = $askRefine
            ? "  • SE \"verdict\" for \"minor\" OU \"major\", inclui um campo \"refined\" com a versão melhorada do draft (texto completo, pronto a usar como substituto).\n"
            : "  • NÃO inclui campo \"refined\" — só queremos a avaliação.\n";

        $ctx = $agentContext !== ''
            ? "\nO agente original opera neste contexto:\n---\n{$agentContext}\n---\n"
            : '';

        return <<<CRIT
És um REVISOR sénior de output de agentes IA, com pendor obsessivo pela
verdade, grounding e qualidade de raciocínio. A tua função é APENAS
avaliar — nunca conversar.{$ctx}

CRITÉRIOS DE AVALIAÇÃO (em ordem de prioridade):

1. FACTUALIDADE (HIGH severity):
   • Nomes próprios inventados (empresas, pessoas, modelos de produto)
   • Números específicos sem fonte (preços, %, KPIs, datas)
   • Contactos inventados (emails, telefones, sites)
   • Referências técnicas inventadas (NCAGE, NSN, P/N, NIF, CNPJ)
   • Afirmações sobre eventos passados/futuros sem base

2. HEDGING (MEDIUM):
   • Linguagem assertiva ("É", "Sempre", "Todos") onde devia hedge
   • Afirmações categóricas sobre matérias controversas

3. ALTERNATIVAS (MEDIUM):
   • User pediu "melhor"/"recomendação" mas só recebe UMA opção
   • Faltam trade-offs explícitos

4. CITAÇÕES (LOW-MEDIUM):
   • Dados que parecem de web search/RAG sem cite
   • Memória da conversa sem indicação clara

5. CONSISTÊNCIA INTERNA (HIGH se contraditório):
   • Draft contém contradições internas
   • Conclusão não decorre do raciocínio

VERDICTS POSSÍVEIS:
  • "ok"     — draft é aceitável, máximo 1-2 issues de severidade low
  • "minor"  — há issues de medium mas o draft é utilizável; sugerir refinements
  • "major"  — há issues high (factualidade, contradição) — refinar OBRIGATÓRIO
  • "block"  — draft contém invenções perigosas ou dados sensíveis vazados —
               NÃO entregar ao user sem revisão humana

DEVOLVE UM ÚNICO OBJECTO JSON, com este schema EXACTO:

{
  "verdict": "ok|minor|major|block",
  "confidence": 0.0,
  "summary": "1 frase descrevendo o estado geral",
  "issues": [
    {
      "severity": "high|med|low",
      "category": "fact|hedge|alt|cite|consistency",
      "text": "descrição do problema concreto, com excerpt do draft entre aspas"
    }
  ]{$refineRule}}

REGRAS DURAS:
  • NUNCA escreves nada fora do JSON
  • Se o draft é IMPECÁVEL → verdict "ok", issues [] vazio
  • Confidence reflecte a TUA certeza na avaliação (não no draft)
  • Sê severo em factualidade, generoso em estilo
CRIT;
    }

    private function validVerdict(string $v): string
    {
        return in_array($v, ['ok', 'minor', 'major', 'block'], true) ? $v : 'ok';
    }

    private function normaliseIssues(mixed $raw): array
    {
        if (!is_array($raw)) return [];
        $out = [];
        foreach (array_slice($raw, 0, 10) as $item) {
            if (!is_array($item)) continue;
            $out[] = [
                'severity' => in_array($item['severity'] ?? '', ['high','med','low'], true)
                              ? $item['severity'] : 'low',
                'category' => in_array($item['category'] ?? '',
                              ['fact','hedge','alt','cite','consistency'], true)
                              ? $item['category'] : 'consistency',
                'text'     => mb_substr((string) ($item['text'] ?? ''), 0, 600),
            ];
        }
        return $out;
    }

    private function extractJson(string $text): mixed
    {
        $text = trim($text);
        // Cleanup: remove ```json fences se vierem
        $text = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $text);
        $decoded = json_decode($text, true);
        if (is_array($decoded)) return $decoded;

        // Fallback: extrai o primeiro objecto { ... } balanceado
        if (preg_match('/\{(?:[^{}]|(?R))*\}/s', $text, $m)) {
            $decoded = json_decode($m[0], true);
            if (is_array($decoded)) return $decoded;
        }
        return null;
    }
}
