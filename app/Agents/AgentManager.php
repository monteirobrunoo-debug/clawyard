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
            'qnap'      => new QnapAgent(),
            'thinking'  => new ThinkingAgent(),
            'batch'     => new BatchAgent(),
            'computer'  => new ComputerUseAgent(),
            'vessel'    => new VesselSearchAgent(),
            'mildef'    => new MilDefAgent(),
            'crm'       => new CrmAgent(),
            // BriefingAgent was referenced by OrchestratorAgent's system prompt
            // but not registered here — requests for "briefing" silently fell
            // back to ClaudeAgent. Registering it fixes the orchestrator and
            // lets the /briefing route resolve a real agent instance.
            'briefing'  => new BriefingAgent(),
            'shipping'  => new ShippingAgent(),
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

        // Deep thinking keywords
        $thinkingKeywords = [
            'deep thought', 'raciocínio profundo', 'análise complexa', 'complex analysis',
            'estratégia', 'strategy', 'dilema', 'dilemma', 'decidir', 'decide',
            'trade-off', 'pros and cons', 'prós e contras', 'melhor opção',
            'thinking agent', 'prof. deep', 'deep thought',
        ];
        foreach ($thinkingKeywords as $kw) {
            if (str_contains($lower, $kw)) return $this->agents['thinking'];
        }

        // Batch processing keywords
        $batchKeywords = [
            'batch', 'lote', 'bulk', 'em massa', 'multiple', 'múltiplos',
            'lista de', 'list of', 'vários emails', 'several emails',
            'processar lista', 'process list', 'max batch',
        ];
        foreach ($batchKeywords as $kw) {
            if (str_contains($lower, $kw)) return $this->agents['batch'];
        }

        // Vessel search / naval procurement keywords
        $vesselKeywords = [
            'vessel', 'navio', 'embarcação', 'ship for sale', 'navio à venda',
            'motorvrachtschip', 'automoteur', 'inland waterway', 'waterway vessel',
            'dwt', 'deadweight', 'hold capacity', 'capacidade porão',
            'bow thruster', 'thruster', 'eni number', 'numero eni',
            'drydock', 'estaleiro', 'shipyard', 'reparação naval', 'naval repair',
            'classificação navio', 'iacs class', 'union certificate', 'cvo',
            'broker navio', 'ship broker', 'vessel broker', 'capitão vasco',
            'bunker consumption', 'moulded depth', 'loa beam draught',
            'grain capacity', 'europaschiff', 'rhine vessel',
        ];
        foreach ($vesselKeywords as $kw) {
            if (str_contains($lower, $kw)) return $this->agents['vessel'];
        }

        // Military defence procurement keywords
        $mildefKeywords = [
            'mildef', 'procurement militar', 'military procurement', 'defesa', 'defense',
            'coronel rodrigues', 'cor. rodrigues', 'míssil', 'missile', 'foguete', 'rocket',
            'radar defesa', 'defesa aérea', 'air defence', 'air defense', 'SAM', 'MANPADS',
            'superficie-ar', 'surface-to-air', 'ar-ar', 'air-to-air', 'artilharia antiaérea',
            'SHORAD', 'HIMARS', 'NASAMS', 'Patriot', 'IRIS-T', 'MBDA', 'Rheinmetall',
            'NATO procurement', 'NSPA', 'OCCAR', 'EDA procurement', 'EDIP', 'EDIRPA',
            'Ukraine Support Loan', 'USLI', 'IDDPORTUGAL', 'DGAPDN', 'S-CIRCABC',
            'bomba guiada', 'precision bomb', 'glide bomb', 'stand-off', 'armamento',
            'munições', 'ammunition', 'fabricante defesa', 'defence supplier',
        ];
        foreach ($mildefKeywords as $kw) {
            if (str_contains($lower, strtolower($kw))) return $this->agents['mildef'];
        }

        // Computer Use / web automation keywords
        $computerKeywords = [
            'robodesk', 'computer use', 'navegar website', 'navigate website',
            'preencher formulário', 'fill form', 'web automation', 'automação web',
            'extrair dados', 'extract data', 'monitorizar site', 'monitor website',
            'base.gov', 'joue', 'tender portal', 'portal concursos',
            'pesquisar fornecedor', 'search supplier', 'web scraping',
        ];
        foreach ($computerKeywords as $kw) {
            if (str_contains($lower, $kw)) return $this->agents['computer'];
        }

        // Shipping / transport / UPS / FedEx keywords
        $shippingKeywords = [
            'ups', 'fedex', 'dhl', 'envio', 'envios', 'transporte', 'transportadora',
            'custo de envio', 'quanto custa enviar', 'shipping', 'courier', 'frete',
            'tarifa', 'tarifas', 'zona ups', 'express saver', 'expedited',
            'entrega internacional', 'international delivery', 'carta de porte',
            'peso volumetrico', 'dimensional weight', 'palete', 'pallet freight',
            'trackng', 'tracking number', 'número de seguimento',
        ];
        foreach ($shippingKeywords as $kw) {
            if (str_contains($lower, $kw)) return $this->agents['shipping'];
        }

        return $this->agents['claude'];
    }

    public function orchestrate(string $message, array $history = []): array
    {
        $agentNames = $this->orchestrator->decideAgents($message);
        $results    = [];

        if (count($agentNames) > 1) {
            $fibers = [];
            foreach ($agentNames as $name) {
                if (!isset($this->agents[$name])) continue;
                $agent = $this->agents[$name];
                $fiber = new \Fiber(function () use ($name, $agent, $message, $history) {
                    try {
                        return [
                            'agent' => $name,
                            'model' => $agent->getModel(),
                            'reply' => $agent->chat($message, $history),
                        ];
                    } catch (\Throwable $e) {
                        return [
                            'agent' => $name,
                            'model' => 'error',
                            'reply' => 'Erro: ' . $e->getMessage(),
                        ];
                    }
                });
                $fibers[$name] = $fiber;
                $fiber->start();
            }
            foreach ($fibers as $name => $fiber) {
                if ($fiber->isTerminated()) {
                    $results[] = $fiber->getReturn();
                }
            }
            // Any fiber not terminated yet — run sequentially as fallback
            foreach ($agentNames as $name) {
                if (!isset($this->agents[$name])) continue;
                $alreadyDone = array_filter($results, fn($r) => $r['agent'] === $name);
                if (!empty($alreadyDone)) continue;
                try {
                    $results[] = [
                        'agent' => $name,
                        'model' => $this->agents[$name]->getModel(),
                        'reply' => $this->agents[$name]->chat($message, $history),
                    ];
                } catch (\Throwable $e) {
                    $results[] = [
                        'agent' => $name,
                        'model' => 'error',
                        'reply' => 'Erro: ' . $e->getMessage(),
                    ];
                }
            }
        } else {
            foreach ($agentNames as $name) {
                if (!isset($this->agents[$name])) continue;
                try {
                    $results[] = [
                        'agent' => $name,
                        'model' => $this->agents[$name]->getModel(),
                        'reply' => $this->agents[$name]->chat($message, $history),
                    ];
                } catch (\Throwable $e) {
                    $results[] = [
                        'agent' => $name,
                        'model' => 'error',
                        'reply' => 'Erro: ' . $e->getMessage(),
                    ];
                }
            }
        }

        return $results;
    }
}
