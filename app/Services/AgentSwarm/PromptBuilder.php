<?php

namespace App\Services\AgentSwarm;

use App\Services\AgentCatalog;

/**
 * Compose the system + user prompts that AgentDispatcher sends to
 * Anthropic for each step of a swarm chain.
 *
 * Why a dedicated builder rather than reusing the agents' own
 * systemPrompt strings:
 *   • The full agent personas (SalesAgent, VesselSearchAgent, …) carry
 *     conversational behaviour, tool blocks, augment pipelines and
 *     skills traits that we DON'T want in a swarm. We want a tight,
 *     structured analysis from each agent, not a chatty answer.
 *   • The synthesis pass needs to emit machine-parseable JSON; the
 *     interactive personas don't. Embedding that contract here keeps
 *     the chat path untouched.
 *
 * Outputs:
 *   - systemFor()       per-agent role/persona seed + format rules
 *   - userFor()         signal + prior-agent context + domain question
 *   - parseSynthesis()  turn synthesiser TEXT into a {leads:[…]} array
 *
 * The signal payload + prior outputs are JSON-serialised in the user
 * message so the model sees structured input rather than narrated
 * paragraphs (denser context, better routing).
 */
class PromptBuilder
{
    /**
     * Build the system prompt for a given agent key.
     *
     * @param string $agentKey   one of AgentCatalog::all()['key']
     * @param bool   $isSynthesis when true, the agent must emit
     *                            machine-parseable JSON for persistLeads
     */
    public function systemFor(string $agentKey, bool $isSynthesis = false): string
    {
        $meta = AgentCatalog::find($agentKey) ?? [
            'name' => ucfirst($agentKey) . ' Agent',
            'role' => 'Domain analyst',
        ];
        $name = $meta['name'];
        $role = $meta['role'];

        $base = <<<TXT
You are {$name}, an agent in PartYard's autonomous business-signal
analysis swarm. Your role: {$role}.

You are receiving a signal (tender, email, equipment query) plus the
analyses already produced by other agents in this chain. Produce a
short, decision-grade contribution from YOUR domain — not a chat
answer, not a recap of what others said.

Hard constraints:
  • Keep it under 200 words.
  • Lead with the single most actionable insight (one bullet).
  • Then 2-4 supporting bullets.
  • If you don't have enough signal to judge, say so explicitly with
    'INSUFFICIENT_SIGNAL: <what you'd need>' rather than guessing.
  • Never invent customer names, prices, dates, contacts, or part
    numbers. If they're not in the signal, omit them.
TXT;

        if ($isSynthesis) {
            $base .= "\n\n" . <<<TXT
SYNTHESIS MODE — you are closing the chain. Combine all prior agent
analyses + any hp_history hits into a final lead recommendation.

Output STRICT JSON (no markdown fences, no preamble) matching:

{
  "leads": [
    {
      "title":          "<= 120 chars, action-oriented (e.g. 'CAT 3516 spares retrofit @ NSPA Brunssum, deadline 2026-05-18')",
      "summary":        "<= 400 chars, 2-3 sentences explaining WHY this is a lead worth chasing",
      "score":          0..100 (50 = average, 80+ = high-conviction; weigh signal strength × agent agreement × precedent fit),
      "customer_hint":  "<short string or null>",
      "equipment_hint": "<short string or null>"
    }
  ]
}

Rules:
  • Emit one lead per distinct opportunity surfaced by the chain. Most
    signals produce exactly one lead; only emit multiple when the
    chain genuinely uncovered separate angles.
  • If no agent produced a viable angle, emit {"leads": []} — the run
    is recorded as low-value and the operator sees nothing to chase.
  • Never include text outside the JSON object.
TXT;
        }

        return $base;
    }

    /**
     * Build the user message for a non-synthesis agent.
     *
     * @param array $signal   the signal payload (tender row, email parsed, …)
     * @param array $priorContext  outputs from earlier agents in the chain
     * @param string $agentKey
     */
    public function userFor(string $agentKey, array $signal, array $priorContext = []): string
    {
        $signalJson = $this->jsonClip($signal, 4000);
        $priorJson  = $priorContext === []
            ? '(none — you are the first agent in the chain)'
            : $this->jsonClip($this->trimPriorContext($priorContext), 4000);

        return <<<TXT
SIGNAL:
{$signalJson}

PRIOR_AGENT_OUTPUTS:
{$priorJson}

Your task as {$agentKey}: produce your domain contribution per the
rules in the system prompt. Stay tight, factual, and concrete.
TXT;
    }

    /**
     * Build the synthesis user message — same as userFor() but
     * frames it for the JSON-emitting role.
     */
    public function synthesisUserFor(array $signal, array $priorContext): string
    {
        $signalJson = $this->jsonClip($signal, 4000);
        $priorJson  = $this->jsonClip($this->trimPriorContext($priorContext), 6000);

        return <<<TXT
SIGNAL:
{$signalJson}

CHAIN_OUTPUTS:
{$priorJson}

Now emit the final lead JSON per the synthesis contract. JSON only.
TXT;
    }

    /**
     * Parse the text returned by the synthesiser into a leads array.
     * Robust against:
     *   • leading/trailing whitespace
     *   • markdown ```json fences (some models add them despite instructions)
     *   • trailing commentary the model snuck in after the JSON
     *
     * Returns ['leads' => [...]] on success, or ['leads' => [], 'parse_error' => '...']
     * on failure — callers can record the failure but always get a
     * usable shape.
     *
     * @param string $text the assistant's reply text
     */
    public function parseSynthesis(string $text): array
    {
        $clean = trim($text);

        // Strip ```json fences if present.
        if (preg_match('/^```(?:json)?\s*(.+?)\s*```$/s', $clean, $m)) {
            $clean = trim($m[1]);
        }

        // Find first '{' and the matching last '}' — tolerates trailing prose.
        $start = strpos($clean, '{');
        $end   = strrpos($clean, '}');
        if ($start === false || $end === false || $end <= $start) {
            return ['leads' => [], 'parse_error' => 'no_json_object_found'];
        }
        $json = substr($clean, $start, $end - $start + 1);

        $data = json_decode($json, true);
        if (!is_array($data)) {
            return ['leads' => [], 'parse_error' => 'json_decode_failed'];
        }

        // Normalise: ensure leads is a list, drop bad entries.
        $leads = [];
        foreach (($data['leads'] ?? []) as $lead) {
            if (!is_array($lead)) continue;
            $leads[] = [
                'title'          => (string) ($lead['title']          ?? '(untitled)'),
                'summary'        => (string) ($lead['summary']        ?? ''),
                'score'          => (int)    ($lead['score']          ?? 0),
                'customer_hint'  => $lead['customer_hint']  ?? null,
                'equipment_hint' => $lead['equipment_hint'] ?? null,
            ];
        }

        return ['leads' => $leads];
    }

    /**
     * JSON-encode an array, then truncate to a budget so we don't
     * blow past the model's context window when signals are huge
     * (e.g. tender XML with 200 line items). 4000 chars ≈ ~1000
     * tokens; tune by phase.
     */
    private function jsonClip(array $data, int $maxChars): string
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($json === false) return '{}';
        if (mb_strlen($json) <= $maxChars) return $json;
        return mb_substr($json, 0, $maxChars) . "\n…[truncated]";
    }

    /**
     * Normalise prior context for inclusion in a downstream prompt.
     * Drops noise keys ('signal', '_synthesis') and keeps only the
     * agent outputs each phase produced.
     *
     * @param array $context  full context blob from AgentSwarmRunner
     */
    private function trimPriorContext(array $context): array
    {
        $trimmed = [];
        foreach ($context as $key => $value) {
            if ($key === 'signal' || $key === '_synthesis') continue;
            // hp_history hits are kept as-is — they're already a digest
            $trimmed[$key] = $value;
        }
        return $trimmed;
    }
}
