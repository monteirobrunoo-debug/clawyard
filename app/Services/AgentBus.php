<?php

namespace App\Services;

use App\Agents\AgentManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * AgentBus — Direct agent-to-agent delegation
 *
 * Allows an agent to silently query another agent for specific data
 * and inject the result into its own response context.
 *
 * Usage inside any agent:
 *   $sapData = AgentBus::ask('sap', 'Qual o stock do item 1290997479873?');
 */
class AgentBus
{
    /** Max chars returned from a delegate call */
    const MAX_RESPONSE = 1200;

    /** Cache TTL in minutes for identical delegate queries */
    const CACHE_TTL = 5;

    /**
     * Ask another agent a question and return its answer.
     * Results are cached for CACHE_TTL minutes to avoid duplicate API calls.
     *
     * @param  string  $agentKey   e.g. 'sap', 'research', 'patent'
     * @param  string  $question   the question to ask
     * @param  array   $history    optional conversation history
     * @return string  the agent's answer (truncated)
     */
    public static function ask(string $agentKey, string $question, array $history = []): string
    {
        $cacheKey = 'agentbus_' . $agentKey . '_' . md5($question);

        return Cache::remember($cacheKey, now()->addMinutes(self::CACHE_TTL), function () use ($agentKey, $question, $history) {
            try {
                $manager = app(AgentManager::class);
                $agent   = $manager->agent($agentKey);

                $answer = $agent->chat($question, $history);
                return substr(strip_tags($answer), 0, self::MAX_RESPONSE);
            } catch (\Throwable $e) {
                Log::warning("AgentBus: delegate to [{$agentKey}] failed — " . $e->getMessage());
                return "(AgentBus: {$agentKey} não disponível — {$e->getMessage()})";
            }
        });
    }

    /**
     * Ask multiple agents simultaneously and return all answers.
     * Runs sequentially (Guzzle async not feasible across agent types).
     *
     * @param  array  $queries  ['sap' => 'question', 'research' => 'question2']
     * @return array  ['sap' => 'answer', 'research' => 'answer2']
     */
    public static function askMany(array $queries, array $history = []): array
    {
        $results = [];
        foreach ($queries as $agentKey => $question) {
            $results[$agentKey] = self::ask($agentKey, $question, $history);
        }
        return $results;
    }
}
