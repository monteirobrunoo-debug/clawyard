<?php

namespace App\Services;

use App\Models\AgentRun;
use App\Services\AgentTools\AgentToolInterface;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;

/**
 * AutonomousAgentRunner — loop tool-use para agentes autónomos.
 *
 * Pedido directo 2026-05-20: "agentes com mega capacidade de análise
 * e autónomos". Substitui o flow 1-shot do TenderServiceAnalysisService
 * por um loop onde Claude decide quais tools chamar.
 *
 * Lifecycle:
 *   1. run() recebe: agent metadata, system prompt, user message, tools[]
 *   2. POST /v1/messages com tools[] no body
 *   3. Resposta vem com content[] que pode conter tool_use blocks
 *   4. Para cada tool_use: invoke $tool->execute($input, $context),
 *      injecta tool_result no próximo turn como user message
 *   5. Loop até stop_reason=end_turn OU max_iterations OU cost_cap
 *   6. Salva AgentRun row com tool_trace, custo, iterações
 *   7. Devolve {ok, final_text, thinking_text, tool_trace, cost_usd}
 *
 * Caps:
 *   - max_iterations: 12 (default) — protege contra loop infinito
 *   - cost_cap_usd: $1 (default) — corta se custo estimado ultrapassar
 *   - per-call timeout: 90s
 *
 * Extended thinking: opt-in via $config['thinking_budget'] (default
 * desligado para tools-only call; on para analysts pesados).
 */
class AutonomousAgentRunner
{
    private const ANTHROPIC_BASE = 'https://api.anthropic.com';
    private const ANTHROPIC_VERSION = '2023-06-01';

    // Token pricing for claude-sonnet-4-6 (USD per million tokens)
    // Source: Anthropic pricing 2026. Refresh quando mudar.
    private const PRICE_INPUT_PER_M       = 3.00;
    private const PRICE_OUTPUT_PER_M      = 15.00;
    private const PRICE_CACHE_READ_PER_M  = 0.30;
    private const PRICE_CACHE_WRITE_PER_M = 3.75;

    private Client $http;
    private string $apiKey;
    private string $model;

    public function __construct()
    {
        $this->apiKey = (string) config('services.anthropic.api_key', '');
        $this->model  = (string) config('services.anthropic.model', 'claude-sonnet-4-6');
        $this->http   = new Client([
            'base_uri'    => self::ANTHROPIC_BASE,
            'timeout'     => 90,
            'http_errors' => false,
        ]);
    }

    /**
     * @param  array{
     *   agent_key:string, agent_name:string, system_prompt:string,
     *   user_message:string, tools:array<AgentToolInterface>,
     *   context:array{tender_id?:int, user_id?:int},
     *   max_iterations?:int, cost_cap_usd?:float, thinking_budget?:int,
     *   max_output_tokens?:int
     * } $config
     * @return array{
     *   ok:bool, agent_run_id:int, final_text:string, thinking_text:string,
     *   tool_trace:array, iterations:int, cost_usd:float,
     *   input_tokens:int, output_tokens:int, status:string, error?:string
     * }
     */
    public function run(array $config): array
    {
        $agentKey      = (string) $config['agent_key'];
        $agentName     = (string) ($config['agent_name'] ?? $agentKey);
        $systemPrompt  = (string) $config['system_prompt'];
        $userMessage   = (string) $config['user_message'];
        $tools         = (array)  ($config['tools'] ?? []);
        $context       = (array)  ($config['context'] ?? []);
        $maxIters      = (int)    ($config['max_iterations'] ?? 12);
        $costCap       = (float)  ($config['cost_cap_usd'] ?? 1.00);
        $thinkBudget   = (int)    ($config['thinking_budget'] ?? 0);
        $maxOutTokens  = (int)    ($config['max_output_tokens'] ?? 4096);

        // 2026-05-28 BUGFIX defensivo: extended thinking SÓ funciona em Opus.
        // Se o caller passa thinking_budget>0 mas o $this->model default é
        // Sonnet, a API trava silently (sem text_delta events) — o user vê
        // dots+caption infinitos. Auto-swap para Opus aqui evita o bug
        // latente. Caller pode forçar Sonnet explícito via $config['model'].
        $resolvedModel = (string) ($config['model'] ?? $this->model);
        if ($thinkBudget > 0 && str_contains(strtolower($resolvedModel), 'sonnet')) {
            $opusModel = (string) config('services.anthropic.model_opus', 'claude-opus-4-8');
            Log::info('AutonomousAgentRunner: auto-swap Sonnet→Opus (thinking enabled)', [
                'agent_key'    => $agentKey,
                'from'         => $resolvedModel,
                'to'           => $opusModel,
                'budget'       => $thinkBudget,
            ]);
            $resolvedModel = $opusModel;
        }

        // Cria AgentRun em status=running ANTES do loop para o ter sempre
        // como forensics mesmo se algo rebenta.
        $run = AgentRun::create([
            'tender_id'   => $context['tender_id'] ?? null,
            'user_id'     => $context['user_id']   ?? null,
            'agent_key'   => $agentKey,
            'purpose'     => 'analysis',
            'status'      => AgentRun::STATUS_RUNNING,
            'started_at'  => now(),
            'tool_trace'  => [],
        ]);

        $tStart = microtime(true);
        $messages = [['role' => 'user', 'content' => $userMessage]];
        $toolMap  = $this->indexTools($tools);
        $toolDefs = $this->renderToolDefs($tools);
        $trace    = [];
        $thinkingText = '';
        $finalText    = '';
        $inputTokens  = 0;
        $outputTokens = 0;
        $costUsd      = 0.0;

        try {
            if ($this->apiKey === '') {
                throw new \RuntimeException('ANTHROPIC_API_KEY não configurada');
            }

            for ($iter = 1; $iter <= $maxIters; $iter++) {
                if ($costUsd > $costCap) {
                    Log::warning('AutonomousAgentRunner: cost cap exceeded', [
                        'agent_key' => $agentKey,
                        'cost'      => $costUsd,
                        'cap'       => $costCap,
                    ]);
                    $run->status = AgentRun::STATUS_COST_CAPPED;
                    $finalText  .= "\n\n[ANÁLISE INTERROMPIDA — cost cap \${$costCap} atingido após {$iter} iterações.]";
                    break;
                }

                $body = [
                    'model'      => $resolvedModel,  // 2026-05-28: respeita auto-swap Opus quando thinking activo
                    'max_tokens' => $maxOutTokens,
                    'system'     => $systemPrompt,
                    'messages'   => $messages,
                ];
                if (!empty($toolDefs)) {
                    $body['tools'] = $toolDefs;
                }
                if ($thinkBudget > 0) {
                    $body['thinking'] = [
                        'type'         => 'enabled',
                        'budget_tokens' => $thinkBudget,
                    ];
                }

                $res = $this->http->post('/v1/messages', [
                    'headers' => [
                        'x-api-key'         => $this->apiKey,
                        'anthropic-version' => self::ANTHROPIC_VERSION,
                        'content-type'      => 'application/json',
                        'accept'            => 'application/json',
                    ],
                    'json' => $body,
                ]);

                $status = $res->getStatusCode();
                $raw    = (string) $res->getBody();
                if ($status < 200 || $status >= 300) {
                    throw new \RuntimeException('Anthropic API HTTP ' . $status . ': ' . mb_substr($raw, 0, 300));
                }

                $data = json_decode($raw, true);
                $usage = $data['usage'] ?? [];
                $inputTokens  += (int) ($usage['input_tokens']  ?? 0);
                $outputTokens += (int) ($usage['output_tokens'] ?? 0);
                $costUsd = $this->estimateCost($inputTokens, $outputTokens, $usage);

                $contentBlocks = (array) ($data['content'] ?? []);
                $stopReason    = (string) ($data['stop_reason'] ?? '');

                // Acumula texto + thinking + acrescenta a mensagem do assistant
                // para o próximo turn (preserve tool_use blocks para Claude
                // saber quais já chamou).
                $assistantContent = [];
                $toolUseRequests  = [];
                foreach ($contentBlocks as $block) {
                    $type = $block['type'] ?? '';
                    if ($type === 'thinking') {
                        $thinkingText .= ($block['thinking'] ?? '') . "\n\n";
                    } elseif ($type === 'text') {
                        $finalText .= ($block['text'] ?? '');
                    } elseif ($type === 'tool_use') {
                        $toolUseRequests[] = $block;
                    }
                    $assistantContent[] = $block;
                }
                $messages[] = ['role' => 'assistant', 'content' => $assistantContent];

                if ($stopReason === 'end_turn' || empty($toolUseRequests)) {
                    // Sem mais tools pedidas — agente terminou.
                    break;
                }

                // Executa tools requested em sequência (Anthropic permite
                // múltiplos tool_use no mesmo turn). Devolve tool_result
                // em 1 user message com array de blocks.
                $toolResultsBlocks = [];
                foreach ($toolUseRequests as $tu) {
                    $tuId   = (string) ($tu['id'] ?? '');
                    $tuName = (string) ($tu['name'] ?? '');
                    $tuInput = (array)  ($tu['input'] ?? []);
                    $tStartTool = microtime(true);

                    if (!isset($toolMap[$tuName])) {
                        $toolResult = ['ok' => false, 'error' => "Tool desconhecido: {$tuName}"];
                    } else {
                        try {
                            $toolResult = $toolMap[$tuName]->execute($tuInput, $context);
                        } catch (\Throwable $e) {
                            $toolResult = ['ok' => false, 'error' => 'Tool exception: ' . $e->getMessage()];
                        }
                    }
                    if (!empty($toolResult['cost_usd'])) {
                        $costUsd += (float) $toolResult['cost_usd'];
                    }

                    $resultText = $toolResult['ok']
                        ? (string) ($toolResult['result'] ?? '')
                        : '[ERRO: ' . (string) ($toolResult['error'] ?? 'unknown') . ']';

                    $trace[] = [
                        'iter'   => $iter,
                        'tool'   => $tuName,
                        'input'  => $tuInput,
                        'output' => mb_substr($resultText, 0, 400) . (mb_strlen($resultText) > 400 ? '…' : ''),
                        'ok'     => (bool) ($toolResult['ok'] ?? false),
                        'ms'     => (int) ((microtime(true) - $tStartTool) * 1000),
                    ];

                    $toolResultsBlocks[] = [
                        'type'        => 'tool_result',
                        'tool_use_id' => $tuId,
                        'content'     => $resultText,
                        'is_error'    => !($toolResult['ok'] ?? false),
                    ];
                }
                $messages[] = ['role' => 'user', 'content' => $toolResultsBlocks];
            }

            $run->status = $run->status === AgentRun::STATUS_COST_CAPPED
                ? AgentRun::STATUS_COST_CAPPED
                : AgentRun::STATUS_DONE;

        } catch (\Throwable $e) {
            $run->status = AgentRun::STATUS_FAILED;
            $run->error  = mb_substr($e->getMessage(), 0, 500);
            Log::error('AutonomousAgentRunner: failed', [
                'agent_key' => $agentKey,
                'error'     => $e->getMessage(),
            ]);
        }

        $run->iterations      = isset($iter) ? $iter : 0;
        $run->input_tokens    = $inputTokens;
        $run->output_tokens   = $outputTokens;
        $run->cost_usd        = round($costUsd, 4);
        $run->tool_trace      = $trace;
        $run->final_text      = mb_substr($finalText, 0, 65000);
        $run->thinking_text   = mb_substr($thinkingText, 0, 65000);
        $run->finished_at     = now();
        $run->duration_ms     = (int) ((microtime(true) - $tStart) * 1000);
        $run->save();

        return [
            'ok'             => $run->status === AgentRun::STATUS_DONE,
            'agent_run_id'   => $run->id,
            'final_text'     => $finalText,
            'thinking_text'  => $thinkingText,
            'tool_trace'     => $trace,
            'iterations'     => $run->iterations,
            'cost_usd'       => $run->cost_usd,
            'input_tokens'   => $inputTokens,
            'output_tokens'  => $outputTokens,
            'status'         => $run->status,
            'error'          => $run->error,
        ];
    }

    /** @param array<AgentToolInterface> $tools */
    private function indexTools(array $tools): array
    {
        $map = [];
        foreach ($tools as $t) {
            if ($t instanceof AgentToolInterface) {
                $map[$t->name()] = $t;
            }
        }
        return $map;
    }

    /** @param array<AgentToolInterface> $tools */
    private function renderToolDefs(array $tools): array
    {
        $out = [];
        foreach ($tools as $t) {
            if (!$t instanceof AgentToolInterface) continue;
            $out[] = [
                'name'         => $t->name(),
                'description'  => $t->description(),
                'input_schema' => $t->inputSchema(),
            ];
        }
        return $out;
    }

    private function estimateCost(int $inputTokens, int $outputTokens, array $usage): float
    {
        $cacheRead  = (int) ($usage['cache_read_input_tokens']  ?? 0);
        $cacheWrite = (int) ($usage['cache_creation_input_tokens'] ?? 0);
        $regularIn  = max(0, $inputTokens - $cacheRead - $cacheWrite);

        $cost = ($regularIn  / 1_000_000) * self::PRICE_INPUT_PER_M
              + ($cacheRead  / 1_000_000) * self::PRICE_CACHE_READ_PER_M
              + ($cacheWrite / 1_000_000) * self::PRICE_CACHE_WRITE_PER_M
              + ($outputTokens / 1_000_000) * self::PRICE_OUTPUT_PER_M;

        return $cost;
    }
}
