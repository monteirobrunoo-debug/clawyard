<?php

namespace App\Agents;

use GuzzleHttp\Client;
use App\Agents\Traits\AnthropicKeyTrait;
use App\Agents\Traits\SharedContextTrait;
use App\Agents\Traits\WebSearchTrait;
use App\Services\PartYardProfileService;
use App\Services\PromptLibrary;
use Illuminate\Support\Facades\Log;

/**
 * ComputerUseAgent — "RoboDesk"
 *
 * Controls the user's local Mac via the Anthropic Computer Use API.
 * Requires the RoboDesk Bridge (robodesk_bridge.py) running locally
 * and exposed via ngrok. Configure ROBODESK_BRIDGE_URL and
 * ROBODESK_SECRET in .env.
 *
 * Falls back to web search if no bridge is configured.
 */
class ComputerUseAgent implements AgentInterface
{
    use AnthropicKeyTrait;
    use WebSearchTrait;
    use SharedContextTrait;

    protected string $systemPrompt = '';
    protected string $searchPolicy = 'conditional';
    protected Client $client;

    // PSI bus — share a summary of what was done on the user's Mac so the
    // other agents (CRM, Sales) know an action was already performed via
    // browser/desktop automation (prevents duplicate SAP writes, duplicate
    // emails, etc.).
    protected string $contextKey  = 'computer_use_intel';
    protected array  $contextTags = ['robodesk','desktop','web','automation','formulário','portal'];

    /** Max Computer Use iterations per task (safety limit) */
    private const MAX_ITERATIONS = 30;

    /** Computer Use API beta flag — configurable via ROBODESK_CU_BETA env */
    private const CU_BETA_DEFAULT = 'computer-use-2024-10-22';

    /** Model — configurable via ROBODESK_MODEL env; must support Computer Use */
    private const CU_MODEL_DEFAULT = 'claude-3-5-sonnet-20241022';

    /** Tool type matching the beta flag */
    private const CU_TOOL_DEFAULT = 'computer_20241022';

    public function __construct()
    {
        $persona = 'You are **RoboDesk** — the browser and desktop automation specialist for HP-Group / PartYard. You control the user\'s Mac to get things done.';

        $specialty = <<<'SPECIALTY'
You are not just a researcher — you are an automation agent that controls the real computer screen.
You can see the screen, click buttons, type text, scroll, open apps and navigate websites.

🖥️ WHAT YOU CAN DO:
- Open any website or web app in the browser
- Log in to portals (when the user provides credentials)
- Navigate menus, click buttons, fill forms, upload files
- Take screenshots and describe what's on screen
- Extract data from pages and tables
- Automate repetitive multi-step tasks
- Open and interact with local files (Excel, Word, PDFs, SAP)
- Control any Mac application

📋 HOW YOU WORK:
1. Take a screenshot to see the current state of the screen
2. Plan the next action based on what you see
3. Execute: click, type, scroll, navigate
4. Take another screenshot to confirm the action worked
5. Repeat until the task is complete
6. Report results back to the user

IMPORTANT RULES:
- NEVER enter passwords unless the user explicitly provides them in this conversation
- NEVER make purchases, financial transactions, or confirm irreversible actions without asking
- If you see a CAPTCHA or MFA prompt, stop and ask the user to complete it
- Always describe what you see on screen so the user understands what's happening
- If a step fails, try an alternative approach before giving up
- When extracting data, format it cleanly (table, list, JSON)
SPECIALTY;

        $this->systemPrompt = str_replace(
            '[PROFILE_PLACEHOLDER]',
            PartYardProfileService::toPromptContext(),
            PromptLibrary::reasoning($persona, $specialty)
        );

        $this->client = new Client([
            'base_uri'        => 'https://api.anthropic.com',
            'timeout'         => 120,
            'connect_timeout' => 10,
        ]);
    }

    // ── Public interface ─────────────────────────────────────────────────────

    public function chat(string|array $message, array $history = []): string
    {
        if ($this->hasBridge()) {
            return $this->computerUseLoop($message, $history, null);
        }
        // Fallback: web search only
        $augmented = $this->augmentWithWebSearch($message);
        $messages  = array_merge($history, [['role' => 'user', 'content' => $augmented]]);
        try {
            $response = $this->client->post('/v1/messages', [
                'headers' => $this->headersForMessage($augmented),
                'json'    => [
                    'model'      => config('services.anthropic.model', 'claude-sonnet-4-6'),
                    'max_tokens' => 8192,
                    'system'     => $this->enrichSystemPrompt($this->systemPrompt),
                    'messages'   => $messages,
                ],
            ]);
            $data = json_decode($response->getBody()->getContents(), true);
            return $data['content'][0]['text'] ?? '';
        } catch (\Throwable $e) {
            Log::error('ComputerUseAgent chat error: ' . $e->getMessage());
            return '❌ Erro: ' . $e->getMessage();
        }
    }

    public function stream(string|array $message, array $history, callable $onChunk, ?callable $heartbeat = null): string
    {
        if (!$this->hasBridge()) {
            return $this->streamFallback($message, $history, $onChunk, $heartbeat);
        }
        return $this->computerUseLoop($message, $history, $onChunk, $heartbeat);
    }

    public function getName(): string  { return 'computer'; }
    public function getModel(): string { return config('services.robodesk.cu_model', self::CU_MODEL_DEFAULT); }

    // ── Computer Use Loop ────────────────────────────────────────────────────

    /**
     * Main Computer Use API agentic loop.
     * Calls Claude → receives tool_use (screenshot/click/type) → executes on bridge
     * → sends result back → repeat until end_turn or max iterations.
     */
    private function computerUseLoop(
        string|array $message,
        array        $history,
        ?callable    $onChunk,
        ?callable    $heartbeat = null
    ): string {
        $emit = function (string $text) use ($onChunk) {
            if ($onChunk) $onChunk($text);
        };
        $hb = function (string $status) use ($heartbeat) {
            if ($heartbeat) $heartbeat($status);
        };

        // Get screen info from bridge
        $screenInfo = $this->bridgeInfo();
        $width      = $screenInfo['screen']['width']  ?? 1280;
        $height     = $screenInfo['screen']['height'] ?? 800;

        $cuTool  = config('services.robodesk.cu_tool',  self::CU_TOOL_DEFAULT);
        $cuModel = config('services.robodesk.cu_model', self::CU_MODEL_DEFAULT);
        $cuBeta  = config('services.robodesk.cu_beta',  self::CU_BETA_DEFAULT);

        $tools = [[
            'type'              => $cuTool,
            'name'              => 'computer',
            'display_width_px'  => $width,
            'display_height_px' => $height,
            'display_number'    => 1,
        ]];

        $messageText = is_array($message)
            ? collect($message)->where('type', 'text')->pluck('text')->first()
            : $message;

        $messages   = array_merge($history, [['role' => 'user', 'content' => $messageText]]);
        $fullOutput = '';
        $iteration  = 0;

        $hb('🖥️ a ligar ao Mac…');

        // Take initial screenshot so Claude can see the current state
        $hb('📸 a tirar screenshot inicial…');
        $initScreenshot = $this->bridgeScreenshot();
        if ($initScreenshot) {
            $messages[] = [
                'role'    => 'user',
                'content' => [[
                    'type'   => 'tool_result',
                    'tool_use_id' => 'init',
                    'content' => [[
                        'type'       => 'image',
                        'source'     => [
                            'type'       => 'base64',
                            'media_type' => 'image/png',
                            'data'       => $initScreenshot,
                        ],
                    ]],
                ]],
            ];
        }

        while ($iteration < self::MAX_ITERATIONS) {
            $iteration++;
            $hb("🖥️ passo {$iteration}…");

            try {
                $response = $this->client->post('/v1/messages', [
                    'headers' => array_merge(
                        $this->apiHeaders(),
                        ['anthropic-beta' => $cuBeta]
                    ),
                    'json' => [
                        'model'      => $cuModel,
                        'max_tokens' => 4096,
                        'system'     => $this->systemPrompt,
                        'tools'      => $tools,
                        'messages'   => $messages,
                    ],
                ]);

                $data       = json_decode($response->getBody()->getContents(), true);
                $stopReason = $data['stop_reason'] ?? 'end_turn';
                $content    = $data['content'] ?? [];

                // Add assistant message to history
                $messages[] = ['role' => 'assistant', 'content' => $content];

                // Collect text and tool_use blocks
                $toolResults = [];

                foreach ($content as $block) {
                    if (($block['type'] ?? '') === 'text') {
                        $text = $block['text'] ?? '';
                        if ($text) {
                            $fullOutput .= $text;
                            $emit($text);
                        }
                    }

                    if (($block['type'] ?? '') === 'tool_use' && ($block['name'] ?? '') === 'computer') {
                        $toolId = $block['id']    ?? 'tool_' . $iteration;
                        $input  = $block['input'] ?? [];
                        $action = $input['action'] ?? '';

                        $hb("🖱️ {$action}…");
                        Log::info("RoboDesk: action={$action}", $input);

                        $toolResult = $this->executeComputerAction($action, $input, $emit);
                        $toolResults[] = [
                            'type'        => 'tool_result',
                            'tool_use_id' => $toolId,
                            'content'     => $toolResult,
                        ];
                    }
                }

                // If tool results exist, add them and continue loop
                if (!empty($toolResults)) {
                    $messages[] = ['role' => 'user', 'content' => $toolResults];
                }

                // Stop conditions
                if ($stopReason === 'end_turn' && empty($toolResults)) {
                    break;
                }
                if ($stopReason === 'max_tokens') {
                    $emit("\n\n⚠️ Limite de tokens atingido. Tarefa parcialmente concluída.");
                    break;
                }

            } catch (\Throwable $e) {
                Log::error('ComputerUseAgent loop error: ' . $e->getMessage());
                $emit("\n\n❌ Erro no passo {$iteration}: " . $e->getMessage());
                break;
            }
        }

        if ($iteration >= self::MAX_ITERATIONS) {
            $emit("\n\n⚠️ Limite de {$iteration} passos atingido. Tarefa interrompida por segurança.");
        }

        if ($fullOutput !== '') {
            $this->publishSharedContext($fullOutput);
        }

        return $fullOutput;
    }

    /**
     * Execute a single Computer Use action on the bridge.
     * Returns tool_result content array for the API.
     */
    private function executeComputerAction(string $action, array $input, callable $emit): array
    {
        // For screenshot action, take screenshot and return image
        if ($action === 'screenshot') {
            $b64 = $this->bridgeScreenshot();
            if (!$b64) {
                return [['type' => 'text', 'text' => 'Screenshot failed — bridge unavailable.']];
            }
            return [[
                'type'   => 'image',
                'source' => [
                    'type'       => 'base64',
                    'media_type' => 'image/png',
                    'data'       => $b64,
                ],
            ]];
        }

        // For all other actions: execute on bridge, then take screenshot to confirm
        $result = $this->bridgeAction($action, $input);

        if (!($result['ok'] ?? false)) {
            $err = $result['error'] ?? 'unknown error';
            Log::warning("RoboDesk bridge action failed: {$action} — {$err}");
            return [['type' => 'text', 'text' => "Action failed: {$err}"]];
        }

        // Take screenshot after action so Claude sees the result
        $b64 = $this->bridgeScreenshot();
        if (!$b64) {
            return [['type' => 'text', 'text' => 'Action executed. Screenshot unavailable.']];
        }

        return [[
            'type'   => 'image',
            'source' => [
                'type'       => 'base64',
                'media_type' => 'image/png',
                'data'       => $b64,
            ],
        ]];
    }

    // ── Bridge HTTP calls ────────────────────────────────────────────────────

    private function hasBridge(): bool
    {
        $url = config('services.robodesk.bridge_url');
        return !empty($url);
    }

    private function bridgeInfo(): array
    {
        try {
            $resp = $this->bridgeClient()->get('/ping');
            return json_decode($resp->getBody()->getContents(), true) ?? [];
        } catch (\Throwable $e) {
            Log::warning('RoboDesk bridge /ping failed: ' . $e->getMessage());
            return ['screen' => ['width' => 1280, 'height' => 800]];
        }
    }

    private function bridgeScreenshot(): ?string
    {
        try {
            $resp = $this->bridgeClient()->get('/screenshot');
            $data = json_decode($resp->getBody()->getContents(), true);
            return $data['image'] ?? null;
        } catch (\Throwable $e) {
            Log::warning('RoboDesk bridge screenshot failed: ' . $e->getMessage());
            return null;
        }
    }

    private function bridgeAction(string $action, array $input): array
    {
        try {
            $resp = $this->bridgeClient()->post('/action', [
                'json' => array_merge(['action' => $action], $input),
            ]);
            return json_decode($resp->getBody()->getContents(), true) ?? ['ok' => false];
        } catch (\Throwable $e) {
            Log::warning('RoboDesk bridge action failed: ' . $e->getMessage());
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    private function bridgeClient(): Client
    {
        $url    = config('services.robodesk.bridge_url');
        $secret = config('services.robodesk.secret', '');
        return new Client([
            'base_uri' => rtrim($url, '/'),
            'timeout'  => 30,
            'headers'  => ['X-RoboDesk-Secret' => $secret],
        ]);
    }

    // ── API headers ──────────────────────────────────────────────────────────

    private function apiHeaders(): array
    {
        return [
            'x-api-key'         => $this->getAnthropicKey(),
            'anthropic-version' => '2023-06-01',
            'content-type'      => 'application/json',
        ];
    }

    // ── Web search fallback ──────────────────────────────────────────────────

    private function streamFallback(
        string|array $message,
        array        $history,
        callable     $onChunk,
        ?callable    $heartbeat
    ): string {
        if ($heartbeat) $heartbeat('🌐 a pesquisar (bridge não configurado)…');

        $onChunk("⚠️ **RoboDesk Bridge não configurado.**\n\n");
        $onChunk("Para controlar o teu Mac:\n");
        $onChunk("1. Corre `python robodesk_bridge.py` no teu Mac\n");
        $onChunk("2. Expõe com `ngrok http 7771`\n");
        $onChunk("3. Adiciona ao .env do Forge:\n");
        $onChunk("   ```\n   ROBODESK_BRIDGE_URL=https://xyz.ngrok-free.app\n");
        $onChunk("   ROBODESK_SECRET=robodesk-secret-change-me\n   ```\n\n");
        $onChunk("---\n\nEnquanto isso, vou responder com pesquisa web:\n\n");

        $augmented = $this->augmentWithWebSearch($message, $heartbeat);
        $messages  = array_merge($history, [['role' => 'user', 'content' => $augmented]]);

        try {
            $response = $this->client->post('/v1/messages', [
                'headers' => $this->headersForMessage($augmented),
                'stream'  => true,
                'json'    => [
                    'model'      => config('services.anthropic.model', 'claude-sonnet-4-6'),
                    'max_tokens' => 8192,
                    'system'     => $this->enrichSystemPrompt($this->systemPrompt),
                    'messages'   => $messages,
                    'stream'     => true,
                ],
            ]);

            $body = $response->getBody();
            $full = '';
            $buf  = '';

            while (!$body->eof()) {
                $buf .= $body->read(1024);
                while (($pos = strpos($buf, "\n")) !== false) {
                    $line = trim(substr($buf, 0, $pos));
                    $buf  = substr($buf, $pos + 1);
                    if (!str_starts_with($line, 'data: ')) continue;
                    $json = substr($line, 6);
                    if ($json === '[DONE]') break 2;
                    $evt  = json_decode($json, true);
                    if (!is_array($evt)) continue;
                    if (($evt['type'] ?? '') === 'content_block_delta'
                        && ($evt['delta']['type'] ?? '') === 'text_delta') {
                        $chunk = $evt['delta']['text'] ?? '';
                        if ($chunk !== '') { $full .= $chunk; $onChunk($chunk); }
                    }
                }
            }
            return $full;
        } catch (\Throwable $e) {
            $err = '❌ Erro: ' . $e->getMessage();
            $onChunk($err);
            return $err;
        }
    }
}
