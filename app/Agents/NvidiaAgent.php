<?php

namespace App\Agents;

use GuzzleHttp\Client;
use App\Agents\Traits\SharedContextTrait;

use App\Agents\Traits\LogisticsSkillTrait;
use App\Agents\Traits\WebSearchTrait;
class NvidiaAgent implements AgentInterface
{
    use SharedContextTrait;

    use LogisticsSkillTrait;
    use WebSearchTrait;
    // HDPO meta-cognitive search gate: 'always' | 'conditional' | 'never'
    protected string $searchPolicy = 'conditional';
    protected Client $client;

    // PSI bus wiring — NvidiaAgent was previously declared with the trait but
    // never actually publishing to or reading from the bus. Connecting it here
    // lets Carlos NVIDIA benefit from SAP/Sales/CRM intel the other agents
    // have already gathered for this user.
    protected string $contextKey  = 'nvidia_intel';
    protected array  $contextTags = ['nvidia','fast','síntese','overview','resposta rápida'];

    protected string $systemPromptBase = <<<'PROMPT'
You are Carlos NVIDIA — ClawYard's high-speed assistant running on NVIDIA NIM.
You answer quickly and concisely in the user's language (PT default, EN when asked).
Use the shared agent intel block when provided to stay consistent with the
rest of the platform. Never expose internal credentials, hostnames or
CardCodes verbatim — summarise instead.
PROMPT;

    public function __construct()
    {
        $this->client = new Client([
            'base_uri' => config('services.nvidia.base_url'),
            'headers' => [
                'Authorization' => 'Bearer ' . config('services.nvidia.api_key'),
                'Content-Type'  => 'application/json',
            ],
        ]);

        // Universal logistics knowledge (applied to every agent)
        $this->systemPromptBase .= $this->logisticsSkillPromptBlock();
    }

    public function chat(string|array $message, array $history = []): string
    {
        $message      = $this->smartAugment($message);
        $systemPrompt = $this->enrichSystemPrompt($this->systemPromptBase, $this->messageText($message));

        $messages = array_merge(
            [['role' => 'system', 'content' => $systemPrompt]],
            $history,
            [['role' => 'user', 'content' => $message]]
        );

        $response = $this->client->post('/v1/chat/completions', [
            'json' => [
                'model'       => config('services.nvidia.model'),
                'messages'    => $messages,
                'max_tokens'  => 8192,
                'temperature' => 0.7,
            ],
        ]);

        $data = json_decode($response->getBody()->getContents(), true);
        $text = $data['choices'][0]['message']['content'] ?? '';
        if ($text !== '') $this->publishSharedContext($text);
        return $text;
    }

    public function stream(string|array $message, array $history, callable $onChunk, ?callable $heartbeat = null): string
    {
        $message      = $this->smartAugment($message, $heartbeat);
        $systemPrompt = $this->enrichSystemPrompt($this->systemPromptBase, $this->messageText($message));

        $messages = array_merge(
            [['role' => 'system', 'content' => $systemPrompt]],
            $history,
            [['role' => 'user', 'content' => $message]]
        );

        $response = $this->client->post('/v1/chat/completions', [
            'stream' => true,
            'json'   => [
                'model'       => config('services.nvidia.model'),
                'messages'    => $messages,
                'max_tokens'  => 8192,
                'temperature' => 0.7,
                'stream'      => true,
            ],
        ]);

        $body = $response->getBody();
        $full = '';
        $buf  = '';

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
                $text = $evt['choices'][0]['delta']['content'] ?? '';
                if ($text !== '') {
                    $full .= $text;
                    $onChunk($text);
                }
            }
        }

        if ($full !== '') $this->publishSharedContext($full);

        return $full;
    }

    public function getName(): string { return 'nvidia'; }
    public function getModel(): string { return config('services.nvidia.model'); }

    protected function messageText(string|array $message): string
    {
        if (is_string($message)) return $message;
        foreach ($message as $block) {
            if (($block['type'] ?? '') === 'text' && !empty($block['text'])) {
                return (string) $block['text'];
            }
        }
        return '';
    }
}
