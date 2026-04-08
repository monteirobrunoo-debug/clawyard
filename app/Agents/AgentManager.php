<?php

namespace App\Agents;

class AgentManager
{
    protected array $agents = [];
    protected OrchestratorAgent $orchestrator;

    public function __construct()
    {
        $this->agents = [
            'nvidia'    => new NvidiaAgent(),
            'claude'    => new ClaudeAgent(),
            'sales'     => new SalesAgent(),
            'support'   => new SupportAgent(),
            'email'     => new EmailAgent(),
            'sap'       => new SapAgent(),
            'document'  => new DocumentAgent(),
            'cyber'     => new CyberAgent(),
            'aria'      => new AriaAgent(),
            'quantum'   => new QuantumAgent(),
            'research'  => new ResearchAgent(),
            'finance'   => new FinanceAgent(),
            'capitao'   => new CapitaoAgent(),
            'acingov'   => new AcingovAgent(),
            'engineer'  => new EngineerAgent(),
            'patent'    => new PatentAgent(),
            'energy'    => new EnergyAdvisorAgent(),
            'kyber'     => new KyberAgent(),
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

        // Cyber / ARIA keywords
        $cyberKeywords = ['security', 'hack', 'vulnerability', 'threat', 'attack', 'owasp', 'stride', 'pentest',
                          'seguranca', 'vulnerabilidade', 'ameaca', 'ataque', 'ciberseguranca', 'firewall', 'breach',
                          'aria', 'ssl', 'certificate', 'exploit', 'malware', 'phishing'];
        foreach ($cyberKeywords as $kw) {
            if (str_contains($lower, $kw)) return $this->agents['aria'];
        }

        // Quantum + Patent keywords
        $quantumKeywords = ['quantum', 'qubit', 'arxiv', 'qkd', 'superposition', 'entanglement', 'quantum computing',
                            'quantum cryptography', 'post-quantum', 'professor', 'quantum leap', 'fisica quantica',
                            'patent', 'patente', 'uspto', 'invention', 'invencao', 'intellectual property',
                            'propriedade intelectual', 'innovation', 'inovacao', 'license', 'licenca'];
        foreach ($quantumKeywords as $kw) {
            if (str_contains($lower, $kw)) return $this->agents['quantum'];
        }

        // Finance / accounting keywords
        $financeKeywords = [
            'contabilidade', 'accounting', 'roc', 'toc', 'auditoria', 'audit',
            'iva', 'irc', 'irs', 'fiscal', 'tax', 'imposto', 'fatura', 'factura',
            'balanço', 'balance sheet', 'demonstrações financeiras', 'financial statements',
            'cash flow', 'tesouraria', 'treasury', 'orçamento', 'budget',
            'rentabilidade', 'profitability', 'margem', 'margin', 'ebitda', 'roi',
            'crédito bancário', 'bank credit', 'financiamento', 'financing',
            'luís', 'luis', 'dr. luís', 'dr luis', 'financeiro', 'finance',
            'due diligence', 'consolidação', 'consolidation', 'ifrs', 'snc',
            'preços de transferência', 'transfer pricing', 'beps', 'declaração fiscal',
        ];
        foreach ($financeKeywords as $kw) {
            if (str_contains($lower, $kw)) return $this->agents['finance'];
        }

        // Energy advisory / decarbonisation keywords
        $energyKeywords = [
            'sofia energia', 'eng. sofia', 'energy advisor', 'fuzzy topsis',
            'descarbonização', 'decarbonization', 'combustível marítimo', 'marine fuel',
            'lng', 'biocombustível', 'biofuel', 'hidrogénio', 'hydrogen', 'propulsão eléctrica',
            'cii rating', 'eexi', 'imo 2050', 'imo 2030', 'ghg strategy',
            'bunker', 'bunkeramento', 'fleet energy', 'energia frota',
            'alternativa combustível', 'fuel alternative', 'retrofit propulsão',
            'emissões co2 navio', 'carbon footprint navio', 'eu ets marítimo',
            'shore power', 'scrubber', 'wind propulsion',
        ];
        foreach ($energyKeywords as $kw) {
            if (str_contains($lower, $kw)) return $this->agents['energy'];
        }

        // Patent / IP validation keywords
        $patentKeywords = [
            'sofia', 'dra. sofia', 'patent agent', 'agente de patentes',
            'validar patente', 'validate patent', 'prior art', 'arte anterior',
            'freedom to operate', 'fto', 'patenteabilidade', 'patentability',
            'novidade patente', 'actividade inventiva', 'inventive step',
            'depositar patente', 'file patent', 'pedido de patente', 'patent application',
            'infringement', 'contrafacção', 'design-around', 'licenciar patente',
            'epo search', 'uspto search', 'espacenet', 'wipo search', 'pct',
            'já foi inventado', 'already invented', 'já existe patente',
            'validação ip', 'ip validation', 'propriedade intelectual',
            'intellectual property', 'patent search', 'pesquisa de patentes',
        ];
        foreach ($patentKeywords as $kw) {
            if (str_contains($lower, $kw)) return $this->agents['patent'];
        }

        // Engineer / R&D / product development keywords
        $engineerKeywords = [
            'victor', 'eng. victor', 'engenheiro', 'engineer', 'i&d', 'r&d',
            'fabricar', 'manufacture', 'construir equipamento', 'build equipment',
            'desenvolver produto', 'develop product', 'protótipo', 'prototype',
            'plano de desenvolvimento', 'development plan', 'roadmap técnico',
            'viabilidade técnica', 'technical feasibility', 'mil-spec', 'do-160',
            'armite formulação', 'lubrificante novo', 'new lubricant',
            'simulador', 'simulator', 'training system', 'sistema de treino',
            'novo produto', 'new product', 'novo equipamento', 'new equipment',
            'certificação easa', 'faa certification', 'as9100', 'as9120',
            'patente', 'patent', 'licenciar tecnologia', 'technology license',
            'trl', 'technology readiness', 'capex desenvolvimento', 'desenvolvimento produto',
        ];
        foreach ($engineerKeywords as $kw) {
            if (str_contains($lower, $kw)) return $this->agents['engineer'];
        }

        // Kyber / post-quantum encryption keywords
        $kyberKeywords = [
            'kyber', 'encriptar', 'desencriptar', 'encrypt', 'decrypt',
            'chave', 'secret key', 'public key', 'post-quantum', 'pós-quântico',
            'kyber-1024', 'aes-256-gcm', 'email encriptado', 'encrypted email',
            'gerar chave', 'generate key', 'key pair', 'par de chaves',
            'encriptação', 'encryption', 'decryption', 'desencriptação',
        ];
        foreach ($kyberKeywords as $kw) {
            if (str_contains($lower, $kw)) return $this->agents['kyber'];
        }

        // Research / competitive intelligence keywords
        $researchKeywords = [
            'website', 'partyard.eu', 'concorrente', 'competitor', 'benchmark',
            'melhorias do site', 'site melhorias', 'website improvements', 'website analysis',
            'análise do site', 'analise do site', 'seo', 'google ranking', 'mercado marítimo',
            'market analysis', 'competitive analysis', 'marina', 'research agent',
            'posição no mercado', 'estratégia digital', 'digital strategy',
        ];
        foreach ($researchKeywords as $kw) {
            if (str_contains($lower, $kw)) return $this->agents['research'];
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
