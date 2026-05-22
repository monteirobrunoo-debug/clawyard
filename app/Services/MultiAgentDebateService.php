<?php

namespace App\Services;

use App\Agents\AgentManager;
use App\Models\MultiAgentDebate;
use App\Models\Tender;
use Illuminate\Support\Facades\Log;

/**
 * MultiAgentDebateService — implementa debate 3-round entre agentes
 * para tenders críticos (>€100k ou mil-def).
 *
 * Base teórica: Bornet 2025 Cap 6 — "Cognitive Diversity":
 *   • Montreal research: agentes diversos a debater +13% accuracy
 *     vs single agent (78% → 91% em complex math)
 *   • MIT/Google Brain: debate reduz error rate em 22%
 *   • Teacher-student effect: modelo forte pareado com fraco eleva
 *     o fraco rapidamente
 *
 * Fluxo:
 *   ROUND 1 — independent opinions (paralelo)
 *     Cada agente recebe a topic + contexto do tender, produz
 *     recommendation sem ver os outros. Usa ->chat() existente.
 *
 *   ROUND 2 — critique
 *     Cada agente recebe as opinions dos outros + a sua própria,
 *     identifica disagreements + pontos fortes/fracos. Foca em
 *     facts, não em estilo.
 *
 *   ROUND 3 — synthesis (Haiku, cheap)
 *     Haiku consolida tudo num único output estruturado:
 *       - Consensus points (todos concordam)
 *       - Disagreements (com posição de cada agente)
 *       - Recommended action com confidence (0-100)
 *
 * Custo: ~3-5× single agent. Para uso só quando vale a pena (alto
 * stakes). O caller decide quando dispara.
 *
 * Persistência: cada debate fica em multi_agent_debates table com
 * todos os rounds — auditável post-hoc.
 */
class MultiAgentDebateService
{
    /** Agentes default para tenders mil-def. */
    private const DEFAULT_AGENTS_MILDEF = ['mildef', 'sales', 'engineer'];

    /** Agentes default para tenders marine. */
    private const DEFAULT_AGENTS_MARINE = ['capitao', 'sales', 'engineer'];

    public function __construct(
        private AgentManager $manager,
        private AgentDispatcher $dispatcher,
    ) {}

    /**
     * Inicia um debate. Devolve o registo MultiAgentDebate persistido.
     *
     * @param  string|null  $topic    Pergunta concreta; default = sumário do tender
     * @param  array|null   $agents   Override dos agentes a debater
     */
    public function debate(
        Tender $tender,
        ?string $topic = null,
        ?array $agents = null,
        ?int $userId = null,
    ): MultiAgentDebate {
        $agents ??= $this->pickAgentsFor($tender);
        $topic  ??= $this->defaultTopicFor($tender);

        $debate = MultiAgentDebate::create([
            'tender_id'             => $tender->id,
            'initiated_by_user_id'  => $userId ?? auth()->id(),
            'topic'                 => $topic,
            'agent_keys'            => $agents,
            'status'                => MultiAgentDebate::STATUS_RUNNING,
            'started_at'            => now(),
        ]);

        try {
            $context = $this->buildContextFor($tender);
            $rounds  = [];
            $costTotal = 0.0;

            // ─── ROUND 1: opinions independentes (sequencial, podia ser parallel) ──
            Log::info('Debate: round 1 (independent)', ['debate' => $debate->id]);
            $round1 = $this->runRoundIndependent($agents, $topic, $context);
            $rounds[] = ['round' => 1, 'kind' => 'independent', 'opinions' => $round1['opinions']];
            $costTotal += $round1['cost_usd'];

            // ─── ROUND 2: critique mútua ─────────────────────────────────────────
            Log::info('Debate: round 2 (critique)', ['debate' => $debate->id]);
            $round2 = $this->runRoundCritique($agents, $topic, $context, $round1['opinions']);
            $rounds[] = ['round' => 2, 'kind' => 'critique', 'opinions' => $round2['opinions']];
            $costTotal += $round2['cost_usd'];

            // ─── ROUND 3: synthesis com Haiku ────────────────────────────────────
            Log::info('Debate: round 3 (synthesis)', ['debate' => $debate->id]);
            $synth = $this->runSynthesis($topic, $round1['opinions'], $round2['opinions']);
            $costTotal += $synth['cost_usd'];

            $debate->update([
                'status'         => MultiAgentDebate::STATUS_DONE,
                'rounds'         => $rounds,
                'synthesis'      => $synth['synthesis'],
                'disagreements'  => $synth['disagreements'],
                'confidence_pct' => $synth['confidence_pct'],
                'cost_usd'       => round($costTotal, 4),
                'finished_at'    => now(),
            ]);

            Log::info('Debate: done', [
                'debate' => $debate->id,
                'cost' => $costTotal,
                'confidence' => $synth['confidence_pct'],
            ]);

            return $debate->fresh();
        } catch (\Throwable $e) {
            $debate->update([
                'status'      => MultiAgentDebate::STATUS_FAILED,
                'synthesis'   => 'Debate failed: ' . $e->getMessage(),
                'finished_at' => now(),
            ]);
            Log::error('Debate: failed — ' . $e->getMessage(), ['debate' => $debate->id]);
            throw $e;
        }
    }

    private function pickAgentsFor(Tender $tender): array
    {
        if (($tender->source ?? '') === 'marine') {
            return self::DEFAULT_AGENTS_MARINE;
        }
        return self::DEFAULT_AGENTS_MILDEF;
    }

    private function defaultTopicFor(Tender $tender): string
    {
        $title = mb_substr((string) $tender->title, 0, 200);
        return "Tender #{$tender->id} ({$title}) — devemos avançar com esta oportunidade? Identifica riscos, oportunidades, e fornecedores prováveis.";
    }

    /** Sumário compacto do tender para meter como contexto inicial. */
    private function buildContextFor(Tender $tender): string
    {
        $parts = ["Tender ID: #{$tender->id}"];
        if ($tender->title)              $parts[] = "Título: {$tender->title}";
        if ($tender->source)             $parts[] = "Fonte: {$tender->source}";
        if ($tender->organization_name)  $parts[] = "Organização: {$tender->organization_name}";
        if ($tender->deadline)           $parts[] = "Deadline: {$tender->deadline->format('Y-m-d')}";
        if ($tender->status)             $parts[] = "Status: {$tender->status}";
        if ($tender->notes)              $parts[] = "Notas:\n" . mb_substr($tender->notes, 0, 1500);
        return implode("\n", $parts);
    }

    /** Round 1 — cada agente isolado dá opinião. */
    private function runRoundIndependent(array $agents, string $topic, string $context): array
    {
        $opinions = [];
        $cost = 0.0;
        $prompt = "TOPIC: {$topic}\n\n=== CONTEXTO ===\n{$context}\n\n"
                . "Dá a TUA opinião independente (≤300 palavras). NÃO vais ver as opiniões "
                . "dos outros agentes nesta ronda. Foca-te no que sabes melhor do que ninguém.";

        foreach ($agents as $key) {
            $agent = $this->safeGetAgent($key);
            if (!$agent) {
                $opinions[$key] = '[agente não disponível]';
                continue;
            }
            try {
                $text = $agent->chat($prompt);
                $opinions[$key] = $text;
                $cost += 0.04;  // heurística — single agent typical cost
            } catch (\Throwable $e) {
                Log::warning("Debate R1: {$key} failed — " . $e->getMessage());
                $opinions[$key] = '[falha: ' . mb_substr($e->getMessage(), 0, 100) . ']';
            }
        }
        return ['opinions' => $opinions, 'cost_usd' => $cost];
    }

    /** Round 2 — cada agente critica as opiniões dos outros. */
    private function runRoundCritique(array $agents, string $topic, string $context, array $round1): array
    {
        $opinions = [];
        $cost = 0.0;

        foreach ($agents as $key) {
            $others = array_diff_key($round1, [$key => null]);
            $othersBlock = "";
            foreach ($others as $otherKey => $otherText) {
                $othersBlock .= "\n\n### Opinião de {$otherKey}:\n" . mb_substr($otherText, 0, 800);
            }

            $prompt = "TOPIC: {$topic}\n\n=== CONTEXTO ===\n{$context}\n\n"
                    . "Tu já deste a tua opinião. Estas são as opiniões dos OUTROS agentes:"
                    . $othersBlock
                    . "\n\nAgora identifica em ≤200 palavras:\n"
                    . "  1. Onde concordas com os outros (factos comuns)\n"
                    . "  2. Onde DISCORDAS — e PORQUÊ (com base no teu domínio)\n"
                    . "  3. Que pontos eles falharam que tu apanhas\n"
                    . "Foca em factos verificáveis, não em estilo.";

            $agent = $this->safeGetAgent($key);
            if (!$agent) {
                $opinions[$key] = '[agente não disponível]';
                continue;
            }
            try {
                $text = $agent->chat($prompt);
                $opinions[$key] = $text;
                $cost += 0.04;
            } catch (\Throwable $e) {
                Log::warning("Debate R2: {$key} failed — " . $e->getMessage());
                $opinions[$key] = '[falha: ' . mb_substr($e->getMessage(), 0, 100) . ']';
            }
        }
        return ['opinions' => $opinions, 'cost_usd' => $cost];
    }

    /** Round 3 — Haiku (cheap) consolida em síntese estruturada. */
    private function runSynthesis(string $topic, array $round1, array $round2): array
    {
        $allOpinions = "";
        foreach ($round1 as $k => $v) {
            $allOpinions .= "\n\n### {$k} (opinião inicial):\n" . mb_substr($v, 0, 600);
        }
        foreach ($round2 as $k => $v) {
            $allOpinions .= "\n\n### {$k} (crítica):\n" . mb_substr($v, 0, 400);
        }

        $system = <<<PROMPT
És um syntheziser neutro que consolida debate multi-agente.
Recebes opiniões iniciais + críticas mútuas. Devolves APENAS este JSON:

{
  "synthesis": "recomendação final em 3-5 parágrafos, factual e estruturada",
  "disagreements": [
    {"topic": "ponto X", "positions": {"agent_key": "posição resumida em ≤50 chars"}}
  ],
  "confidence_pct": 0-100
}

confidence_pct:
  - 90+ = todos concordam, sem ambiguidades
  - 70-89 = consenso geral mas com nuances
  - 50-69 = disagreements importantes não resolvidos
  - <50 = debate inconclusivo, recomenda humano

Sê NEUTRO — não inventes posições. Se uma opinião disse "X", não digas que ela disse "Y".
PROMPT;

        $userMsg = "TOPIC: {$topic}\n\n=== DEBATE ===" . $allOpinions . "\n\nSintetiza.";

        $haikuModel = (string) config('services.anthropic.model_haiku', 'claude-haiku-4-5-20251001');

        try {
            $res = $this->dispatcher->dispatch(
                systemPrompt: $system,
                userMessage:  $userMsg,
                maxTokens:    1800,
                model:        $haikuModel,
            );

            if (!($res['ok'] ?? false)) {
                throw new \RuntimeException('Synthesis dispatch failed: ' . ($res['error'] ?? '?'));
            }

            $raw = trim((string) ($res['text'] ?? ''));
            $clean = preg_replace('/^```(?:json)?\s*|\s*```\s*$/m', '', $raw) ?? $raw;
            if (!preg_match('/\{[\s\S]*\}/', $clean, $m)) {
                throw new \RuntimeException('Synthesis returned no JSON');
            }
            $decoded = json_decode($m[0], true);
            if (!is_array($decoded)) throw new \RuntimeException('Synthesis JSON decode failed');

            return [
                'synthesis'      => (string) ($decoded['synthesis'] ?? ''),
                'disagreements'  => (array) ($decoded['disagreements'] ?? []),
                'confidence_pct' => max(0, min(100, (int) ($decoded['confidence_pct'] ?? 50))),
                'cost_usd'       => 0.005,  // Haiku roughly
            ];
        } catch (\Throwable $e) {
            Log::warning('Debate synth: ' . $e->getMessage());
            return [
                'synthesis'      => 'Synthesis falhou — vê os rounds para detalhes.',
                'disagreements'  => [],
                'confidence_pct' => 0,
                'cost_usd'       => 0.005,
            ];
        }
    }

    /** Helper: obtém um agente do AgentManager, robusto a missing. */
    private function safeGetAgent(string $key): ?object
    {
        try {
            return $this->manager->agent($key);
        } catch (\Throwable) {
            return null;
        }
    }
}
