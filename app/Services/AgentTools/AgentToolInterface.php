<?php

namespace App\Services\AgentTools;

/**
 * Contract for tools usable by autonomous agents via tool-use loops.
 *
 * Pedido directo do operador 2026-05-20 — "agentes com mega capacidade
 * de análise e autónomos". Cada tool é uma capacidade que o agente
 * pode invocar durante o seu raciocínio (search tenders passados,
 * pesquisar web, ler PDFs, etc.).
 *
 * Lifecycle:
 *   1. AutonomousAgentRunner regista os tools via name() / schema()
 *      no system prompt (Anthropic Messages API tool_use format)
 *   2. Claude decide chamar tool X com inputs Y
 *   3. Runner invoca $tool->execute($inputs, $context) e injecta o
 *      result como tool_result no próximo turn
 *   4. Loop continua até stop_reason=end_turn ou cap de iterações
 *
 * Cada tool deve ser pure-ish: idempotente, sem side-effects além de
 * Logs. Tools com side-effects (notes.append, sap.update, etc.)
 * precisam de gate de confirmação humana — deixei isso para mais tarde.
 */
interface AgentToolInterface
{
    /** Nome curto canónico, snake_case (ex: 'tender_search'). */
    public function name(): string;

    /** Descrição one-liner usada pelo LLM para decidir quando chamar. */
    public function description(): string;

    /**
     * JSON schema dos inputs (formato Anthropic tools API).
     *
     * @return array{type:string, properties:array, required?:array}
     */
    public function inputSchema(): array;

    /**
     * Executa o tool. Recebe inputs validados pelo runner + context
     * mínimo (tender id, user id) para audit/scope.
     *
     * @param  array  $input    Decoded JSON from Claude's tool_use block
     * @param  array  $context  ['tender_id' => int, 'user_id' => int, 'agent_key' => string]
     * @return array{ok:bool, result?:string, error?:string, cost_usd?:float}
     */
    public function execute(array $input, array $context): array;
}
