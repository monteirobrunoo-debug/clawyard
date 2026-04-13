<?php

namespace App\Services;

/**
 * AgentChipsService — centralizes starter prompt chips for all agents.
 * Used by welcome.blade.php (internal chat) and agent-shares/chat.blade.php (public shared agents).
 */
class AgentChipsService
{
    public static function all(): array
    {
        return [
            // ── Auto Router ──────────────────────────────────────────────
            'auto' => [
                '🚢 Analisa concorrentes no porto de Sines e lista oportunidades',
                '📧 Escreve cold email para armador grego — peças MTU Série 4000',
                '🔧 Motor CAT 3516B com consumo excessivo de óleo — diagnóstico',
                '📊 Relatório de vendas Q1 2026: MTU vs Caterpillar vs MAK',
                '⚛️ Há patentes novas relevantes para a PartYard esta semana?',
                '🔐 Faz scan OWASP completo ao partyard.eu e lista vulnerabilidades',
            ],
            // ── Orchestrator ─────────────────────────────────────────────
            'orchestrator' => [
                '🌐 Analisa mercado MTU em Roterdão → gera email + cotação SAP + threat model',
                '🌐 Motor MAK M32 avariado → diagnóstico técnico + proposta comercial + email ao armador',
                '🌐 Novo cliente em Algeciras → análise de crédito + cotação + cold outreach multilingue',
                '🌐 Digest completo PartYard: papers arXiv + patentes + concorrência + relatório financeiro',
                '🌐 Auditoria 360° ao partyard.eu: SEO + segurança + UX + proposta de melhoria',
                '🌐 Expansão para mercado grego: pesquisa + contactos + email + plano financeiro',
            ],
            // ── Marco Sales ──────────────────────────────────────────────
            'sales' => [
                '📊 Analisa esta lista de preços Excel — compara lista cliente vs preço justo vs fornecedor',
                '📧 Anexa vários emails de fornecedores — compara preços lado a lado e identifica melhor oferta',
                '💼 Compara preços OEM vs aftermarket para pistões MTU Série 4000 — exporta tabela',
                '💼 Analisa este PDF de fornecedor e extrai referências, preços e lead times',
                '💼 Equivalências de filtros Caterpillar 3516 — OEM vs Fleetguard vs Mann',
                '💼 Compara 3 fornecedores de selos SKF SternTube — qualidade, preço e prazo',
            ],
            // ── Marcus Suporte ────────────────────────────────────────────
            'support' => [
                '🔧 MTU 12V4000M90 — alarme F0203 HT coolant temp alta em carga máxima',
                '🔧 CAT 3516B — consumo excessivo de óleo após revisão aos 8.000h',
                '🔧 MAK M25 — vibração anormal no cilindro 3 a 600 RPM, causa provável?',
                '🔧 Schottel SRP-X — vedante de proa com fuga, procedimento de substituição',
                '🔧 SKF SternTube — folga axial 0.45mm fora de spec, valores admissíveis?',
                '🔧 Jenbacher J320 — falha de ignição intermitente cilindro 6, diagnóstico',
            ],
            // ── Daniel Email ──────────────────────────────────────────────
            'email' => [
                '📧 Cold outreach EN para shipping agent em Hamburgo — motor MTU Série 4000',
                '📧 Proposta comercial PT para armador em Lisboa — peças MAK M32 disponíveis',
                '📧 Follow-up urgente para cliente em Pireu — selos SKF em stock imediato',
                '📧 Apresentação PartYard Defense para NATO procurement em Bruxelas',
                '📧 Email de parceria para agente marítimo em Valência — exclusividade Schottel',
                '📧 Warranty claim ao fabricante MTU — defeito em peças série 2000',
            ],
            // ── Richard SAP ───────────────────────────────────────────────
            'sap' => [
                '📊 Stock actual de peças MTU Série 2000 — listar por referência e quantidade',
                '📊 Estado da encomenda #PY-2025-0847 — última actualização de entrega',
                '📊 Clientes com facturas em atraso >30 dias — valor total e lista de contacto',
                '📊 Vendas por marca Q1 2026: MTU vs Caterpillar vs MAK vs Schottel',
                '📊 Cria cotação SAP B1 para Navios do Tejo Lda — condições NET 30',
                '📊 Kits de pistões CAT 3516 em stock — quantidades + localização warehouse',
            ],
            // ── Comandante Doc ────────────────────────────────────────────
            'document' => [
                '📄 Extrai todos os intervalos de manutenção deste manual MTU (PDF anexo)',
                '📄 Analisa contrato de fornecimento e lista cláusulas de risco (Word anexo)',
                '📄 Verifica validade e conformidade deste certificado ISO/DNV (PDF anexo)',
                '📄 Compara duas propostas técnicas Excel e recomenda a mais vantajosa',
                '📄 Resume este relatório de inspeção de navio em 5 pontos executivos',
                '📄 Traduz manual técnico Caterpillar do inglês para português (PDF anexo)',
            ],
            // ── Bruno AI ──────────────────────────────────────────────────
            'claude' => [
                '🧠 Estratégia de expansão para o mercado grego — análise SWOT completa',
                '🧠 Riscos de entrar no mercado de peças para navios militares — análise detalhada',
                '🧠 Benchmark PartYard vs Wilhelmsen vs Wärtsilä Parts — modelo de negócio',
                '🧠 Plano de negócio para abrir escritório em Roterdão — 3 anos, P&L incluído',
                '🧠 Megatendências da indústria naval até 2030 e oportunidades para a PartYard',
                '🧠 Como posicionar a PartYard Defense face à concorrência NATO e americana?',
            ],
            // ── Carlos NVIDIA ─────────────────────────────────────────────
            'nvidia' => [
                '⚡ Gera 10 subject lines de alta conversão para cold email a armadores gregos',
                '⚡ Cria descrição de produto SEO-optimizada para peças MTU Série 4000',
                '⚡ Optimiza este texto de proposta comercial para maior taxa de fecho',
                '⚡ Gera FAQ técnico completo sobre manutenção preventiva de motores MAK',
                '⚡ Cria post LinkedIn viral sobre inovação de peças Schottel da PartYard',
                '⚡ Traduz e adapta catálogo técnico Caterpillar para mercado espanhol',
            ],
            // ── ARIA Security ─────────────────────────────────────────────
            'aria' => [
                '🔐 Scan STRIDE completo ao partyard.eu — threat model e recomendações',
                '🔐 Análise OWASP Top 10 ao hp-group.org — vulnerabilidades e prioridades',
                '🔐 Testa o ClawYard contra SQL Injection, XSS e CSRF — relatório detalhado',
                '🔐 Verifica certificados SSL/TLS e headers HTTP dos sites do grupo H&P',
                '🔐 Gera threat model completo para a API REST do ClawYard — MITRE ATT&CK',
                '🔐 Plano de cibersegurança para empresa marítima — GDPR + NIS2 compliance',
            ],
            // ── Prof. Quantum Leap ────────────────────────────────────────
            'quantum' => [
                '⚛️ Digest científico de hoje: papers arXiv + patentes USPTO relevantes para PartYard',
                '🏛️ Top 7 patentes novas que a PartYard pode licenciar ou explorar este mês',
                '⚛️ Papers recentes sobre manutenção preditiva com IA para motores marítimos',
                '🏛️ Patentes de propulsão Schottel nos últimos 90 dias — análise de oportunidades',
                '⚛️ Quantum computing aplicado a optimização de rotas marítimas — estado da arte',
                '🏛️ Análise de patentes de turbinas MTU — o que os concorrentes estão a patentear?',
            ],
            // ── Dr. Luís Financeiro ───────────────────────────────────────
            'finance' => [
                '💰 Rentabilidade por linha de produto: marine vs defense vs aftermarket — Q1 2026',
                '💰 Benefícios fiscais RFAI + SIFIDE disponíveis para a PartYard em 2026',
                '💰 Análise de rácios financeiros: liquidez, endividamento e EBITDA da empresa',
                '💰 Estrutura de carta de crédito documentário para importação de peças MTU',
                '💰 Impacto fiscal de abrir subsidiária em Noruega vs Brasil vs Grécia',
                '💰 Análise de cash flow Q2 2026 — riscos de tesouraria e medidas preventivas',
            ],
            // ── Marina Research ───────────────────────────────────────────
            'research' => [
                '🔍 Auditoria completa ao partyard.eu — SEO, velocidade, UX e 10 melhorias urgentes',
                '🔍 Benchmark PartYard vs Wärtsilä Parts vs Wilhelmsen — preços e posicionamento',
                '🔍 Top 5 concorrentes MTU na Europa — análise de forças, fraquezas e market share',
                '🔍 Estratégia de palavras-chave SEO para PartYard em PT/EN/ES/GR',
                '🔍 Análise de presença digital de armadores gregos — oportunidades de contacto',
                '🔍 Estratégia de entrada no mercado escandinavo — canais, parceiros e timing',
            ],
            // ── Dra. Ana Contratos ────────────────────────────────────────
            'acingov' => [
                '🏛️ Relatório completo últimos 5 dias: SAM.gov + base.gov.pt + Vortal + UNIDO + UNGM',
                '🏛️ SAM.gov: contratos US Navy, DoD e Coast Guard abertos para PartYard Military',
                '🏛️ Concursos navais e motores marítimos abertos agora — todos os 5 portais',
                '🏛️ Oportunidades UNIDO e UNGM para PartYard Military e SETQ esta semana',
                '🏛️ Qual o contrato com prazo mais urgente nos 5 portais? Ranking por deadline',
                '🏛️ SAM.gov NAICS 336611 e 334511 — ship building e defense navigation abertos',
            ],
            // ── Eng. Victor I&D ───────────────────────────────────────────
            'engineer' => [
                '🔩 Analisa o briefing do Renato e propõe 3 novos produtos para desenvolver',
                '🛩️ Plano de desenvolvimento: kit de reparação certificado MIL-SPEC para AH-64 Apache',
                '🛢️ ARMITE: plano I&D para novo lubrificante bio-based MIL-PRF-32033 sustentável',
                '🎯 Roadmap completo: simulador de voo part-task trainer para C-130 — TRL, CAPEX, parceiros',
                '🔐 SETQ: plano de produto HSM (Hardware Security Module) para instalações NATO',
                '🚗 Retrofit kit para Leopard 2: sistema de gestão de potência com IA — viabilidade técnica',
            ],
            // ── Eng. Sofia Energia ────────────────────────────────────────
            'energy' => [
                '⚡ Fuzzy TOPSIS: qual o melhor combustível para um ferry de 120m em rotas de cabotagem PT?',
                '🌊 Análise CII/EEXI: navio bulk carrier com motor MAK 9M32C — opções de retrofit para 2026',
                '🔋 Comparação LNG vs Biofuel drop-in para frota de 5 rebocadores MTU — CAPEX e payback',
                '🌿 Plano de descarbonização para armador com 12 navios — metas IMO 2030 e EU ETS marítimo',
                '⚓ Retrofit propulsão eléctrica para ferry Setúbal-Tróia — viabilidade técnica e financiamento',
                '📊 Qual o impacto de mudar para biocombustível B30 nos motores CAT 3516 da frota PartYard?',
            ],
            // ── Dra. Sofia IP ─────────────────────────────────────────────
            'patent' => [
                '🔍 Valida o projecto de lubrificante bio-based ARMITE — prior art EPO e patenteabilidade',
                '🏛️ Pesquisa prior art para simulador de voo com IA adaptativa — conflitos com patentes activas?',
                '✅ O sistema de diagnóstico remoto de motores navais da PartYard já foi patenteado por alguém?',
                '📋 Analisa todos os projectos do Eng. Victor e diz quais são patenteáveis imediatamente',
                '🔐 Freedom to Operate: kit de reparação MIL-SPEC para AH-64 — podemos fabricar sem infringir?',
                '💡 Estratégia IP completa para o HP-Group — onde depositar patentes primeiro (EP vs PCT vs PT)',
            ],
            // ── Capitão Porto ─────────────────────────────────────────────
            'capitao' => [
                '⚓ Navio em Sines com motor MTU avariado — procedimento urgente de entrega de peças a bordo',
                '⚓ Plano de escala completo para cargueiro em Setúbal — documentação APSS e timeline',
                '⚓ Como desalfandegar Ship Spares isentos de IVA num porto português — passo a passo',
                '⚓ Calcular laytime e demurrage para bulk carrier com 48h de atraso no Terminal de Sines',
                '⚓ Documentação completa para exportação de peças via sea freight para Pireu (Grécia)',
                '⚓ Inspeção Port State Control (Paris MOU) amanhã — checklist de preparação para o Chief',
            ],
            // ── KYBER Encryption ──────────────────────────────────────────
            'kyber' => [
                '🔒 Gera um par de chaves Kyber-1024 para mim e explica como guardar o secret key',
                '🔒 Explica como enviar um email encriptado com Kyber-1024 passo a passo',
                '🔒 O que é o CRYSTALS-Kyber? Porque é resistente a computadores quânticos?',
                '🔒 Como funciona o esquema KEM + AES-256-GCM usado nos emails encriptados?',
                '🔒 Como instalar a extensão Kyber no Outlook para Mac e Windows?',
                '🔒 Qual a diferença entre Kyber-512, Kyber-768 e Kyber-1024? Qual usar?',
            ],
            // ── Arquivo PartYard ──────────────────────────────────────────
            'qnap' => [
                '🗄️ Que fornecedores da Collins Aerospace temos no arquivo e quais as condições de licença?',
                '🗄️ Mostra-me todas as invoices de 2023 e os valores totais por fornecedor',
                '🗄️ Pesquisa documentos sobre o programa NP2000 — preços e códigos de peças',
                '🗄️ Que condições de crédito temos com os nossos fornecedores? (net 30, net 60...)',
                '🗄️ Lista todos os contratos e declarações relacionados com o Min. Defesa Nacional',
                '🗄️ Analisa os ficheiros CONCURSOS Excel e resume as oportunidades',
            ],
            // ── Prof. Deep Thought ────────────────────────────────────────
            'thinking' => [
                '🧠 Qual a estratégia óptima para a PartYard dominar o mercado de peças navais na Europa Meridional nos próximos 5 anos?',
                '🧠 Analisa em profundidade os riscos geopolíticos que afectam o supply chain marítimo europeu em 2026',
                '🧠 Faz um raciocínio primeiro-princípios: porque é que os motores MTU dominam o mercado naval militar?',
                '🧠 Modela o impacto de uma recessão europeia de 18 meses no negócio da PartYard — 3 cenários',
                '🧠 Qual o argumento mais forte para a PartYard entrar no mercado de MRO aeronáutico militar?',
                '🧠 Decompõe o problema: como aumentar a margem bruta de 22% para 35% em 24 meses?',
            ],
            // ── Max Batch ─────────────────────────────────────────────────
            'batch' => [
                '📦 Processa esta lista de 50 referências MTU e gera descrições de produto em PT/EN/ES para cada uma',
                '📦 Analisa em paralelo estes 10 PDFs de fornecedores e extrai preços, prazos e condições',
                '📦 Gera 20 cold emails personalizados para armadores gregos — adapta por empresa e frota',
                '📦 Classifica e resume estes 30 concursos BASE.gov por relevância para a PartYard',
                '📦 Cria fichas técnicas para 15 peças CAT 3516 — formato SAP B1 pronto a importar',
                '📦 Traduz 25 documentos técnicos Schottel do alemão para português e inglês em paralelo',
            ],
            // ── RoboDesk ──────────────────────────────────────────────────
            'computer' => [
                '🖥️ Pesquisa os preços actuais de peças MTU 4000 nos 5 maiores distribuidores online europeus',
                '🖥️ Verifica se o site partyard.eu tem erros, links quebrados ou páginas lentas — relatório',
                '🖥️ Pesquisa concursos NATO abertos agora para fornecimento de peças navais e motores',
                '🖥️ Encontra todos os armadores portugueses com frota acima de 5 navios e os seus contactos',
                '🖥️ Compara os preços de frete marítimo Rotterdam→Sines nos principais forwarding agents',
                '🖥️ Pesquisa os últimos press releases dos concorrentes Wärtsilä, Rolls-Royce Marine e Kongsberg',
            ],
            // ── Capitão Vasco ─────────────────────────────────────────────
            'vessel' => [
                '⚓ Procura navio fluvial automotor 2300+ DWT, máx 112m, máx €2M — Rhine/Danube flag',
                '⚓ Lista estaleiros em Portugal e Holanda capazes de colocar navio de 110m em dique seco',
                '⚓ Verifica no mercado actual navios com bow thruster e autopilot disponíveis abaixo de €1.5M',
                '⚓ Quais os contactos dos brokers neerlandeses especializados em motorvrachtschepen 110m?',
                '⚓ Analisa a oferta Mi Vida (ENI 08023148) — especificações, preço e gap de certificação',
                '⚓ Empresas de reparação naval no Reno/Main para overhaul de motor e renovação de casco',
            ],
        ];
    }

    public static function forAgent(string $key): array
    {
        return static::all()[$key] ?? static::all()['auto'];
    }
}
