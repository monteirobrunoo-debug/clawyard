<?php

namespace App\Agents;

use GuzzleHttp\Client;
use App\Agents\Traits\AnthropicKeyTrait;
use App\Agents\Traits\SharedContextTrait;
use App\Agents\Traits\WebSearchTrait;
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
    use WebSearchTrait;
    use SharedContextTrait;

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

        $this->client = new Client([
            'base_uri'        => 'https://api.anthropic.com',
            'timeout'         => 180,
            'connect_timeout' => 10,
        ]);
    }

    public function chat(string|array $message, array $history = []): string
    {
        $message  = $this->augmentWithWebSearch($message);
        $messages = array_merge($history, [
            ['role' => 'user', 'content' => $message],
        ]);

        $response = $this->client->post('/v1/messages', [
            'headers' => $this->headersForMessage($message),
            'json'    => [
                'model'      => config('services.anthropic.model', 'claude-sonnet-4-6'),
                'max_tokens' => 20000,
                'thinking'   => ['type' => 'enabled', 'budget_tokens' => 10000],
                'system'     => $this->enrichSystemPrompt($this->systemPrompt),
                'messages'   => $messages,
            ],
        ]);

        $data = json_decode($response->getBody()->getContents(), true);
        $text = '';
        foreach ($data['content'] ?? [] as $block) {
            if (($block['type'] ?? '') === 'text') $text .= $block['text'];
        }
        return $text;
    }

    public function stream(string|array $message, array $history, callable $onChunk, ?callable $heartbeat = null): string
    {
        if ($heartbeat) $heartbeat('a activar raciocínio profundo 🧠');

        $message  = $this->augmentWithWebSearch($message, $heartbeat);
        $messages = array_merge($history, [
            ['role' => 'user', 'content' => $message],
        ]);

        if ($heartbeat) $heartbeat('a pensar profundamente…');

        $response = $this->client->post('/v1/messages', [
            'headers' => $this->headersForMessage($message),
            'stream'  => true,
            'json'    => [
                'model'      => config('services.anthropic.model', 'claude-sonnet-4-6'),
                'max_tokens' => 20000,
                'thinking'   => ['type' => 'enabled', 'budget_tokens' => 10000],
                'system'     => $this->enrichSystemPrompt($this->systemPrompt),
                'messages'   => $messages,
                'stream'     => true,
            ],
        ]);

        $body     = $response->getBody();
        $full     = '';
        $buf      = '';
        $lastBeat = time();
        $inThinking = false;

        while (!$body->eof()) {
            $buf .= $body->read(1024);
            while (($pos = strpos($buf, "\n")) !== false) {
                $line = substr($buf, 0, $pos);
                $buf  = substr($buf, $pos + 1);
                $line = trim($line);
                if (!str_starts_with($line, 'data: ')) continue;
                $json = substr($line, 6);
                if ($json === '[DONE]') break 2;
                $evt = json_decode($json, true);
                if (!is_array($evt)) continue;

                // Track when we enter/exit thinking blocks
                if (($evt['type'] ?? '') === 'content_block_start') {
                    $inThinking = ($evt['content_block']['type'] ?? '') === 'thinking';
                    if ($inThinking && $heartbeat) $heartbeat('a raciocinar… 🧠');
                }
                if (($evt['type'] ?? '') === 'content_block_stop') {
                    if ($inThinking && $heartbeat) $heartbeat('a formular resposta…');
                    $inThinking = false;
                }

                // Only stream text_delta (not thinking_delta)
                if (($evt['type'] ?? '') === 'content_block_delta'
                    && ($evt['delta']['type'] ?? '') === 'text_delta') {
                    $chunk = $evt['delta']['text'] ?? '';
                    if ($chunk !== '') {
                        $full .= $chunk;
                        $onChunk($chunk);
                    }
                }
            }
            if ($heartbeat && (time() - $lastBeat) >= 5) {
                $heartbeat($inThinking ? 'a raciocinar profundamente… 🧠' : 'a elaborar resposta…');
                $lastBeat = time();
            }
        }

        return $full;
    }

    public function getName(): string  { return 'thinking'; }
    public function getModel(): string { return config('services.anthropic.model', 'claude-sonnet-4-6'); }
}
