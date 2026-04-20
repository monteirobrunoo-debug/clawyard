<?php

namespace App\Services;

/**
 * Single source of truth for agent display metadata used by the dashboard
 * card grid, the agent profile pages (/agents/{key}), the recent-conversations
 * strip, and anywhere else the UI needs a human-friendly name/emoji/color
 * for an agent key.
 *
 * The canonical list of REGISTERED agents lives in App\Agents\AgentManager —
 * this class only stores presentation metadata on top of those keys.
 */
class AgentCatalog
{
    /**
     * Full list of agents with display metadata.
     * Each entry must include: key, category, name, emoji, role, color.
     * Optional: special (bool), description (long form).
     */
    public static function all(): array
    {
        return [
            ['key' => 'briefing',    'category' => 'strategic',  'name' => 'Strategist Renato',      'emoji' => '📊', 'role' => 'Executive briefing — combines Quantum, ARIA, Sales and all agents into an action plan', 'color' => '#00aaff', 'special' => true],
            ['key' => 'orchestrator','category' => 'strategic',  'name' => 'All Agents',             'emoji' => '🌐', 'role' => 'Orchestrator — activates multiple agents in parallel',                                    'color' => '#76b900'],
            ['key' => 'thinking',    'category' => 'strategic',  'name' => 'Prof. Deep Thought',     'emoji' => '🧠', 'role' => 'Extended thinking — complex multi-step reasoning and deep analysis',                      'color' => '#a855f7'],
            ['key' => 'claude',      'category' => 'strategic',  'name' => 'Bruno AI',               'emoji' => '🧠', 'role' => 'Claude — advanced reasoning and complex analysis',                                        'color' => '#a855f7'],
            ['key' => 'nvidia',      'category' => 'strategic',  'name' => 'Carlos NVIDIA',          'emoji' => '⚡', 'role' => 'NVIDIA NeMo — maximum speed and efficiency',                                              'color' => '#76b900'],

            ['key' => 'sales',       'category' => 'commercial', 'name' => 'Marco Sales',            'emoji' => '💼', 'role' => 'Sales MTU · CAT · MAK · Jenbacher · SKF · Schottel',                                     'color' => '#3b82f6'],
            ['key' => 'support',     'category' => 'commercial', 'name' => 'Marcus Support',         'emoji' => '🔧', 'role' => 'Technical Support — engine and system fault diagnosis',                                   'color' => '#f59e0b'],
            ['key' => 'email',       'category' => 'commercial', 'name' => 'Daniel Email',           'emoji' => '📧', 'role' => 'Maritime email — shipowners, agents and vessels',                                         'color' => '#8b5cf6'],
            ['key' => 'crm',         'category' => 'commercial', 'name' => 'Marta CRM',              'emoji' => '🎯', 'role' => 'SAP B1 CRM — cria oportunidades a partir de emails, pipeline por vendedor',              'color' => '#e11d48'],
            ['key' => 'shipping',    'category' => 'commercial', 'name' => 'Logística/PartYard',     'emoji' => '🚚', 'role' => 'Transporte UPS 2026, catalogação de faturas, alfândega (Incoterms, TARIC, VIES, DAU/SAD, IVA intra-UE)', 'color' => '#8b5cf6'],
            ['key' => 'mildef',      'category' => 'commercial', 'name' => 'Cor. Rodrigues Defesa',  'emoji' => '🎖️', 'role' => 'Military procurement — worldwide defence suppliers excl. China/Russia, NATO/EU/USLI context', 'color' => '#6b3fa0'],

            ['key' => 'sap',         'category' => 'operations', 'name' => 'Richard SAP',            'emoji' => '📊', 'role' => 'SAP B1 — stock, invoices, pipeline CRM and ERP data',                                    'color' => '#06b6d4'],
            ['key' => 'capitao',     'category' => 'operations', 'name' => 'Captain Porto',          'emoji' => '⚓', 'role' => 'Port operations — port calls, documentation and maritime logistics',                      'color' => '#0ea5e9'],
            ['key' => 'document',    'category' => 'operations', 'name' => 'Commander Doc',          'emoji' => '📄', 'role' => 'Documents — analyses PDFs, contracts and technical certificates',                         'color' => '#94a3b8'],
            ['key' => 'qnap',        'category' => 'operations', 'name' => 'PartYard Archive',       'emoji' => '🗄️', 'role' => 'Document archive — search prices, codes, invoices, licences and contracts on QNAP',       'color' => '#f59e0b'],
            ['key' => 'finance',     'category' => 'operations', 'name' => 'Dr. Luís Finance',       'emoji' => '💰', 'role' => 'ROC · TOC · PhD Banking Management — Accounting, Audit and Taxation',                    'color' => '#10b981'],
            ['key' => 'acingov',     'category' => 'operations', 'name' => 'Dr. Ana Contracts',      'emoji' => '🏛️', 'role' => 'Public procurement — Acingov tenders ranked by relevance for PartYard',                  'color' => '#f59e0b'],
            ['key' => 'vessel',      'category' => 'operations', 'name' => 'Capitão Vasco',          'emoji' => '⚓', 'role' => 'Vessel search + naval repair — ship brokers, drydocks, IACS class, inland waterways',     'color' => '#0ea5e9'],

            ['key' => 'engineer',    'category' => 'rd',         'name' => 'Eng. Victor R&D',        'emoji' => '🔩', 'role' => 'R&D and Product Development — TRL plans, CAPEX, roadmap for new PartYard equipment',      'color' => '#f97316'],
            ['key' => 'patent',      'category' => 'rd',         'name' => 'Dr. Sofia IP',           'emoji' => '🏛️', 'role' => 'Intellectual Property — patent validation, prior art EPO/USPTO, patentability and FTO',   'color' => '#8b5cf6'],
            ['key' => 'quantum',     'category' => 'rd',         'name' => 'Prof. Quantum Leap',     'emoji' => '⚛️', 'role' => 'Quantum — arXiv papers + USPTO patents for PartYard',                                      'color' => '#22d3ee'],
            ['key' => 'energy',      'category' => 'rd',         'name' => 'Eng. Sofia Energy',      'emoji' => '⚡', 'role' => 'Maritime decarbonisation — Fuzzy TOPSIS, CII/EEXI, LNG/Biofuel/H2, Fleet Energy Mgmt',   'color' => '#10b981'],
            ['key' => 'research',    'category' => 'rd',         'name' => 'Marina Research',        'emoji' => '🔍', 'role' => 'Competitive intelligence — benchmarking, market analysis and site improvements',          'color' => '#f97316'],

            ['key' => 'aria',        'category' => 'security',   'name' => 'ARIA Security',          'emoji' => '🔐', 'role' => 'Cybersecurity — STRIDE, OWASP, daily site scanning',                                      'color' => '#ef4444'],
            ['key' => 'kyber',       'category' => 'security',   'name' => 'KYBER Encryption',       'emoji' => '🔒', 'role' => 'Post-quantum encryption — Kyber-1024 + AES-256-GCM, key generation and encrypted email',  'color' => '#76b900'],
            ['key' => 'computer',    'category' => 'security',   'name' => 'RoboDesk',               'emoji' => '🖥️', 'role' => 'Web automation — Computer Use API, browser control and desktop tasks',                    'color' => '#22c55e'],
            ['key' => 'batch',       'category' => 'security',   'name' => 'Max Batch',              'emoji' => '📦', 'role' => 'Batch processing — run multiple tasks in parallel with async queues',                     'color' => '#06b6d4'],
        ];
    }

    public static function categories(): array
    {
        return [
            'strategic'  => ['title' => 'Strategic & AI Core',         'subtitle' => 'Executive briefings, orchestration and deep reasoning', 'icon' => '🎯', 'color' => '#00aaff'],
            'commercial' => ['title' => 'Commercial & Logistics',      'subtitle' => 'Sales, support, email, CRM, shipping and defence',       'icon' => '💼', 'color' => '#3b82f6'],
            'operations' => ['title' => 'Operations & Finance',        'subtitle' => 'SAP, port ops, documents, accounting and procurement',   'icon' => '⚙️', 'color' => '#06b6d4'],
            'rd'         => ['title' => 'R&D · Engineering · IP',      'subtitle' => 'Product development, patents, quantum and research',      'icon' => '🔬', 'color' => '#f97316'],
            'security'   => ['title' => 'Security · Automation · Ops', 'subtitle' => 'Cybersecurity, encryption, web automation and batching',  'icon' => '🔐', 'color' => '#ef4444'],
        ];
    }

    public static function find(string $key): ?array
    {
        foreach (self::all() as $agent) {
            if ($agent['key'] === $key) return $agent;
        }
        return null;
    }

    /**
     * Build { key => meta } map for O(1) lookup in views.
     */
    public static function byKey(): array
    {
        $map = [];
        foreach (self::all() as $a) $map[$a['key']] = $a;
        return $map;
    }

    /**
     * Resolve an agent's photo path (/images/agents/{key}.{ext}) if one exists,
     * otherwise null. Views fall back to the emoji.
     */
    public static function photo(string $key): ?string
    {
        foreach (['.png', '.jpg', '.jpeg', '.webp'] as $ext) {
            $path = public_path('images/agents/' . $key . $ext);
            if (file_exists($path)) return '/images/agents/' . $key . $ext;
        }
        return null;
    }

    /**
     * Default starter prompts per agent — shown on profile pages as
     * "Try asking me..." chips. Fallback is a generic trio.
     */
    public static function starters(string $key): array
    {
        $starters = [
            'briefing'  => ['Dá-me o briefing de hoje', 'Resume os KPIs da semana', 'Quais são os riscos operacionais mais urgentes?'],
            'sap'       => ['Qual o stock do item 1290997479873?', 'Faturas em aberto da OceanPact', 'Pipeline CRM por vendedor'],
            'crm'       => ['Cria oportunidade a partir deste email', 'Pipeline por vendedor em estágio 5', 'Que oportunidades estão a escorregar?'],
            'sales'     => ['Preço MTU 396 para OceanPact', 'Cotação CAT 3516 em stock', 'Comparar SKF vs FAG para rolamentos'],
            'support'   => ['Diagnóstico falha injetor MTU 4000', 'Procedimento manutenção MAK M43', 'Troubleshoot Jenbacher J620'],
            'email'     => ['Rascunha email de cotação para shipowner', 'Follow-up profissional para agente em Roterdão', 'Resposta a pedido de spare parts urgente'],
            'shipping'  => ['Cotação UPS de Setúbal para Niterói (BR)', 'Cataloga esta fatura UPS', 'IVA intra-UE para envio França→Portugal'],
            'mildef'    => ['Fornecedores NATO para peças NSN 1290', 'Alternativas NÃO-Russia para equipamento naval', 'Context US LI / ITAR para este item'],
            'capitao'   => ['Checklist port call Setúbal', 'Documentação inspeção Port State Control', 'Custos portuários Lisboa vs Leixões'],
            'document'  => ['Resume este contrato PDF', 'Valida este certificado técnico', 'Extrai cláusulas críticas deste NDA'],
            'qnap'      => ['Procura contrato OceanPact 2024', 'Faturas do fornecedor Raytheon', 'Licenças expiradas este trimestre'],
            'finance'   => ['Análise de tesouraria Q1 2026', 'Impacto fiscal compra intracomunitária', 'Cálculo IRC estimado 2025'],
            'acingov'   => ['Concursos públicos naval esta semana', 'Tenders NATO para peças defesa', 'Oportunidades EU defence procurement'],
            'vessel'    => ['Procura OSV 3000 DWT em venda', 'Drydocks DNV classe no Mediterrâneo', 'Ship brokers ativos para vessels militares'],
            'engineer'  => ['Roadmap TRL para SmartShield UXS', 'CAPEX estimado para nova linha de produção', 'Plano R&D próximo semestre'],
            'patent'    => ['Prior art EPO para "maritime camouflage"', 'Patentabilidade SmartShield USPTO', 'FTO análise para produto naval novo'],
            'quantum'   => ['Papers arXiv recentes sobre post-quantum crypto', 'Patentes USPTO quantum sensing naval', 'Aplicações quântum em defesa marítima'],
            'energy'    => ['CII rating da frota OceanPact', 'Fuzzy TOPSIS LNG vs Biofuel vs H2', 'EEXI compliance para frota 2026'],
            'research'  => ['Benchmark PartYard vs concorrentes europeus', 'Análise mercado spare parts naval 2026', 'Ideias para melhorar site PartYard'],
            'aria'      => ['STRIDE threat model do site PartYard', 'OWASP check esta semana', 'Últimas CVEs relevantes para WordPress'],
            'kyber'     => ['Gera par de chaves Kyber-1024', 'Encripta este texto para OceanPact', 'Envia email encriptado a fornecedor'],
            'computer'  => ['Automatiza scraping de concorrente X', 'Abre site Y e tira screenshot', 'Preenche form deste portal com os meus dados'],
            'batch'     => ['Processa estas 20 faturas em paralelo', 'Classifica estes 50 emails por urgência', 'Extrai NSN de 100 PDFs'],
            'thinking'  => ['Analisa profundamente esta decisão estratégica', 'Raciocínio multi-step para este problema técnico', 'Pros e contras detalhados da opção A vs B'],
            'claude'    => ['Explica este conceito complexo', 'Escreve um texto persuasivo para shipowner', 'Revê este contrato em detalhe'],
            'nvidia'    => ['Resposta rápida a FAQ', 'Classifica este email em 1 segundo', 'Resumo instantâneo deste parágrafo'],
            'orchestrator' => ['Briefing combinado de Sales + SAP + CRM', 'Plano de ação com todos os agentes', 'Orquestra resposta multi-agente a este pedido complexo'],
        ];

        return $starters[$key] ?? [
            'Como posso ajudar-te hoje?',
            'Mostra-me o que sabes fazer',
            'Quais são as tuas capacidades principais?',
        ];
    }
}
