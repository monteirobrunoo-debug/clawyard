<?php

namespace App\Agents;

use GuzzleHttp\Client;
use App\Agents\Traits\AnthropicKeyTrait;
use App\Agents\Traits\HandlesAnthropicStream;
use App\Agents\Traits\SharedContextTrait;
use App\Agents\Traits\WebSearchTrait;
use App\Agents\Traits\NsnLookupTrait;
use App\Agents\Traits\LogisticsSkillTrait;
use App\Agents\Traits\TechnicalBookSkillTrait;
use App\Services\PartYardProfileService;
use App\Services\PromptLibrary;

/**
 * ThinkingAgent — "Prof. Deep Thought"
 *
 * Uses Claude Extended Thinking (budget_tokens: 10000) for maximum
 * reasoning depth. Ideal for: complex strategy, legal analysis,
 * multi-step problem solving, technical architecture decisions.
 */
class ThinkingAgent implements AgentInterface
{
    use AnthropicKeyTrait;
    use HandlesAnthropicStream;
    use WebSearchTrait;
    use NsnLookupTrait;
    use SharedContextTrait;
    use LogisticsSkillTrait;
    use TechnicalBookSkillTrait;
    protected string $systemPrompt = '';

    // PSI bus — publish deep-thinking conclusions so other agents
    // (Finance, Patent, Engineer, Strategist) see them.
    protected string $contextKey  = 'thinking_intel';
    protected array  $contextTags = ['estratégia','strategy','análise','complex','raciocínio','decisão','dilema'];

    // HDPO meta-cognitive search gate: 'always' | 'conditional' | 'never'
    protected string $searchPolicy = 'conditional';

    protected Client $client;

    public function __construct()
    {
        $persona = 'You are **Prof. Deep Thought** — the most powerful analytical agent in the ClawYard AI platform for HP-Group / PartYard.';

        $specialty = <<<'SPECIALTY'
You have **Extended Thinking** activated, meaning you reason deeply before responding. You excel at:

🧠 STRATEGIC ANALYSIS:
- Complex business decisions with multiple trade-offs
- Multi-step problem solving with dependencies
- Risk analysis and scenario planning
- Long-term strategic roadmaps

⚖️ LEGAL & REGULATORY:
- Contract analysis and risk identification
- Regulatory compliance (EU, NATO, export controls)
- Patent strategy and freedom-to-operate
- Government procurement regulations

🏗️ TECHNICAL ARCHITECTURE:
- System design decisions
- Technology stack evaluations
- Integration planning
- Security architecture

📊 FINANCIAL MODELLING:
- Business case development
- Investment analysis (NPV, IRR, ROI)
- Cost-benefit analysis with uncertainty
- Market sizing and opportunity assessment

HOW YOU WORK:
1. You think deeply before answering — visible as a reasoning process
2. You consider multiple angles, counterarguments, and edge cases
3. You give structured, actionable outputs
4. You cite your reasoning explicitly
5. When you're uncertain, you say so and explain why

FORMAT:
- Use clear headers and structure
- Highlight key conclusions with **bold**
- Include confidence levels where relevant (High/Medium/Low)
- End complex analyses with an "Executive Summary"
SPECIALTY;

        $this->systemPrompt = str_replace(
            '[PROFILE_PLACEHOLDER]',
            PartYardProfileService::toPromptContext(),
            PromptLibrary::reasoning($persona, $specialty)
        );

        // Universal logistics knowledge (applied to every agent)
        $this->systemPrompt .= $this->logisticsSkillPromptBlock();

        $this->client = new Client([
            'base_uri'        => self::getAnthropicBaseUri(),
            'timeout'         => 180,
            'connect_timeout' => 10,
        ]);
    }

    public function chat(string|array $message, array $history = []): string
    {
        $message  = $this->augmentWithWebSearch($message);
        $message = $this->augmentWithNsnLookup($message);
        $messages = array_merge($history, [
            ['role' => 'user', 'content' => $message],
        ]);

        $response = $this->client->post('/v1/messages', [
            'headers' => $this->headersForMessage($message),
            'json'    => [
                'model'      => config('services.anthropic.model_opus', 'claude-opus-4-5'),
                'max_tokens' => 20000,
                'thinking'   => ['type' => 'enabled', 'budget_tokens' => 10000],
                'system'     => $this->buildSystemWithBooks($message, $this->systemPrompt),
                'messages'   => $messages,
            ],
        ]);

        $data = json_decode($response->getBody()->getContents(), true);
        $text = '';
        foreach ($data['content'] ?? [] as $block) {
            if (($block['type'] ?? '') === 'text') $text .= $block['text'];
        }
        if ($text !== '') $this->publishSharedContext($text);
        return $text;
    }

    public function stream(string|array $message, array $history, callable $onChunk, ?callable $heartbeat = null): string
    {
        if ($heartbeat) $heartbeat('a activar raciocínio profundo 🧠');

        $message  = $this->augmentWithWebSearch($message, $heartbeat);

        $message = $this->augmentWithNsnLookup($message, $heartbeat);
        $messages = array_merge($history, [
            ['role' => 'user', 'content' => $message],
        ]);

        if ($heartbeat) $heartbeat('a pensar profundamente…');

        // 2026-05-28 refactor: stream loop → trait helper.
        // 'thinking' config preserved; trait skips non-text deltas (thinking_delta).
        $full = $this->streamAnthropicWithRetries(
            config: [
                'model'      => config('services.anthropic.model_opus', 'claude-opus-4-5'),
                'max_tokens' => 20000,
                'thinking'   => ['type' => 'enabled', 'budget_tokens' => 10000],
                'system'     => $this->buildSystemWithBooks($message, $this->systemPrompt),
                'messages'   => $messages,
                'stream'     => true,
            ],
            headers:          $this->headersForMessage($message),
            onChunk:          $onChunk,
            heartbeat:        $heartbeat,
            heartbeatLabel:   'Thinking…',
            retries:          [0, 2, 5],
            emergencyMessage: "⚠️ Thinking temporariamente indisponível. Tenta novamente em 30s.",
            agentLabel:       'ThinkingAgent',
        );

        if ($full !== '') $this->publishSharedContext($full);

        return $full;
    }

    public function getName(): string  { return 'thinking'; }
    public function getModel(): string { return config('services.anthropic.model_opus', 'claude-opus-4-5'); }
}
