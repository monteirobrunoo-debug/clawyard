<?php

namespace App\Agents;

class AgentManager
{
    protected array $agents = [];
    protected OrchestratorAgent $orchestrator;

    public function __construct()
    {
        $this->agents = [
            'nvidia'   => new NvidiaAgent(),
            'claude'   => new ClaudeAgent(),
            'sales'    => new SalesAgent(),
            'support'  => new SupportAgent(),
            'email'    => new EmailAgent(),
            'sap'      => new SapAgent(),
            'document' => new DocumentAgent(),
            'cyber'    => new CyberAgent(),
        ];

        $this->orchestrator = new OrchestratorAgent($this->agents);
    }

    public function agent(string $name): AgentInterface
    {
        if ($name === 'orchestrator') {
            return $this->orchestrator;
        }
        return $this->agents[$name] ?? $this->agents['claude'];
    }

    public function available(): array
    {
        return array_merge(['orchestrator'], array_keys($this->agents));
    }

    /**
     * Auto-route based on message content.
     */
    public function route(string $message): AgentInterface
    {
        $lower = strtolower($message);

        // Sales keywords
        $salesKeywords = ['price', 'quote', 'buy', 'purchase', 'order', 'cost', 'preco', 'cotacao', 'comprar', 'encomenda'];
        foreach ($salesKeywords as $kw) {
            if (str_contains($lower, $kw)) return $this->agents['sales'];
        }

        // Support keywords
        $supportKeywords = ['broken', 'error', 'problem', 'issue', 'fix', 'repair', 'not working', 'avaria', 'problema', 'reparar'];
        foreach ($supportKeywords as $kw) {
            if (str_contains($lower, $kw)) return $this->agents['support'];
        }

        // Email keywords
        $emailKeywords = ['email', 'write email', 'draft', 'send', 'redigir', 'escrever email'];
        foreach ($emailKeywords as $kw) {
            if (str_contains($lower, $kw)) return $this->agents['email'];
        }

        // SAP keywords
        $sapKeywords = ['sap', 'stock', 'inventory', 'invoice', 'order status', 'fatura', 'inventario'];
        foreach ($sapKeywords as $kw) {
            if (str_contains($lower, $kw)) return $this->agents['sap'];
        }

        // Document keywords
        $docKeywords = ['document', 'pdf', 'contract', 'analyze', 'review', 'documento', 'contrato', 'analisar'];
        foreach ($docKeywords as $kw) {
            if (str_contains($lower, $kw)) return $this->agents['document'];
        }

        // Cyber keywords
        $cyberKeywords = ['security', 'hack', 'vulnerability', 'threat', 'attack', 'owasp', 'stride', 'pentest',
                          'seguranca', 'vulnerabilidade', 'ameaca', 'ataque', 'ciberseguranca', 'firewall', 'breach'];
        foreach ($cyberKeywords as $kw) {
            if (str_contains($lower, $kw)) return $this->agents['cyber'];
        }

        return $this->agents['claude'];
    }

    public function orchestrate(string $message, array $history = []): array
    {
        $agentNames = $this->orchestrator->decideAgents($message);
        $results    = [];

        foreach ($agentNames as $name) {
            if (isset($this->agents[$name])) {
                try {
                    $reply     = $this->agents[$name]->chat($message, $history);
                    $results[] = [
                        'agent' => $name,
                        'model' => $this->agents[$name]->getModel(),
                        'reply' => $reply,
                    ];
                } catch (\Exception $e) {
                    $results[] = [
                        'agent' => $name,
                        'model' => 'error',
                        'reply' => 'Error: ' . $e->getMessage(),
                    ];
                }
            }
        }

        return $results;
    }
}
