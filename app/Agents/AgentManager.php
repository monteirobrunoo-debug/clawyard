<?php

namespace App\Agents;

class AgentManager
{
    protected array $agents = [];

    public function __construct()
    {
        $this->agents = [
            'nvidia' => new NvidiaAgent(),
            'claude' => new ClaudeAgent(),
        ];
    }

    public function agent(string $name): AgentInterface
    {
        return $this->agents[$name] ?? $this->agents['nvidia'];
    }

    public function available(): array
    {
        return array_keys($this->agents);
    }

    /**
     * Auto-route based on message content.
     * Claude for complex reasoning, NVIDIA for everything else.
     */
    public function route(string $message): AgentInterface
    {
        $complexKeywords = ['analyze', 'explain', 'code', 'debug', 'write', 'create', 'compare', 'analisar', 'explicar', 'codigo', 'escrever', 'criar'];

        foreach ($complexKeywords as $keyword) {
            if (stripos($message, $keyword) !== false) {
                return $this->agents['claude'];
            }
        }

        return $this->agents['nvidia'];
    }
}
