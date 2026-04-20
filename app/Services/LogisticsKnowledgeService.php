<?php

namespace App\Services;

use App\Data\LogisticsGlossary;

/**
 * LogisticsKnowledgeService — foundational logistics/supply-chain knowledge
 * for every PartYard agent.
 *
 * Based on "Introdução à Logística — Fundamentos, Práticas e Integração"
 * (Editora GEN/Atlas, 2024). Provides:
 *
 *   • A concise skill prompt block that agents inject into their system
 *     prompt so they can discuss logistics fluently (WMS, TMS, ERP,
 *     Kanban, 3PL/4PL, cross-docking, Lei da Balança, Incoterms, etc.)
 *   • A term lookup helper over the full 292-term glossary
 *   • A structured reference for the 5 transport modes and their
 *     typical use cases
 *
 * Companion of ShippingRateService (UPS pricing). Where ShippingRateService
 * answers "quanto custa", LogisticsKnowledgeService answers "o que é / qual
 * o processo / qual o modal / qual a métrica".
 */
class LogisticsKnowledgeService
{
    /**
     * Compact skill block injected into every agent's system prompt.
     * Kept under ~3.5KB so it fits alongside other skills.
     */
    public static function skillPromptBlock(): string
    {
        return <<<'SKILL'

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
📚 SKILL: FUNDAMENTOS DE LOGÍSTICA (Logística/PartYard)
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

Tens conhecimento sólido de logística e supply-chain — vocabulário,
modais, processos, métricas e TI. Usa este conhecimento sempre que a
conversa toque em transporte, armazém, inventário, compras ou fluxo
de materiais.

CANAIS E FLUXO DE MATERIAIS
 · Canal de suprimento: fornecedor → fábrica/centro logístico.
 · Canal de distribuição: fábrica → cliente (directo, 1/2/3 níveis).
 · Grau de atendimento: tempo de ciclo do pedido (order-to-delivery)
   e nível de serviço (OTIF — On-Time In-Full).
 · Trade-off clássico: custo vs. nível de serviço; stock vs. ruptura.

MODAIS DE TRANSPORTE
 · Rodoviário (camião)      — flexível, door-to-door, curta/média distância.
 · Ferroviário              — alto volume, baixo custo/ton.km, granéis.
 · Aéreo                    — rápido, caro, carga de valor/urgência.
 · Aquaviário (marítimo, fluvial) — mais barato, longa distância,
   contêineres (TEU=20', FEU=40'), granéis sólidos/líquidos.
 · Dutoviário (pipeline)    — líquidos, gases, contínuo, baixa manutenção.
 · Intermodal: vários modos, documentos independentes.
 · Multimodal: vários modos, UM único conhecimento emitido pelo OTM.

GESTÃO DE ESTOQUES
 · Classificação ABC (Pareto): A=poucos itens/alto valor, B=intermédio,
   C=muitos itens/baixo valor.
 · FIFO (First-In-First-Out), LIFO/UEPS (Last-In-First-Out),
   PEPS = FIFO em português.
 · Ponto de ressuprimento (reorder point), stock de segurança,
   lote económico de compra (EOQ), Kanban.
 · Curva dente-de-serra; acuracidade do inventário = itens correctos
   / itens verificados × 100%.

ARMAZENAGEM
 · Layout: recepção, quarentena, picking, expedição, cross-docking.
 · Sistemas: estocagem fixa vs. livre; porta-paletes, drive-in,
   flow-rack, cantilever, mezanino.
 · Picking: discreto, por zona, por lote (batch), por onda (wave).
 · WMS controla endereçamento, FEFO/FIFO, inventário rotativo.

GESTÃO DE TRANSPORTE (ROAD)
 · Lei da Balança (PT/BR): limites de peso por eixo, tolerâncias
   (5% no eixo, 7,5% no total), dimensões máximas do veículo.
 · Frete-peso vs. Frete-valor (Ad Valorem, seguro sobre NF).
 · Composição tarifária: custos fixos (CF) + custos variáveis (CV)
   + despesas administrativas e de terminais (DAT) + margem.
 · Peso real vs. peso volumétrico (cubagem) — cobra-se o maior.

TI APLICADA À LOGÍSTICA
 · WMS — Warehouse Management System (armazém)
 · TMS — Transportation Management System (transporte)
 · ERP — Enterprise Resource Planning (integração corporativa)
 · OMS — Order Management System (pedidos)
 · MRP / MRP II — Material/Manufacturing Requirement Planning
 · MES — Manufacturing Execution System (chão-de-fábrica)
 · IMS — Inventory Management System
 · RFID, código de barras, EPC, GPS para rastreio em tempo real.

CONCEITOS-CHAVE
 · 3PL (third-party logistics) — operador logístico contratado.
 · 4PL — integrador que gere todos os 3PLs do cliente.
 · Cross-docking — recebe e re-expede sem armazenar.
 · Milk-run — rota de recolha em múltiplos fornecedores.
 · Just-in-Time (JIT) — stock quase zero, entregas sincronizadas.
 · VMI — Vendor Managed Inventory (fornecedor repõe stock do cliente).
 · Lead time — tempo total entre pedido e entrega.
 · Supply chain — rede completa fornecedor → consumidor final.

INCOTERMS 2020 (11 termos — quem paga o quê)
 · EXW — ex-works, comprador assume tudo.
 · FCA/FAS/FOB — vendedor entrega à transportadora / cais / bordo.
 · CFR/CIF — vendedor paga frete (+ seguro no CIF); risco passa no embarque.
 · CPT/CIP — versões multimodais de CFR/CIF.
 · DAP — entrega no destino, comprador desalfandega.
 · DPU — DAP + descarga.
 · DDP — vendedor entrega desalfandegado e pago (assume TUDO).

FATURAÇÃO & DOCUMENTOS ADUANEIROS
 · Fatura pro-forma vs. comercial | Packing list
 · CMR (rodoviário) | AWB (aéreo) | B/L (marítimo)
 · DAU/SAD — Single Administrative Document (declaração aduaneira UE)
 · EUR.1 / ATR / Form A — certificados de origem preferencial
 · Códigos pautais: HS (6 dígitos global), CN (8, UE), TARIC (10, UE)

FISCALIDADE INTERNACIONAL (UE)
 · IVA intra-UE B2B: zero + reverse charge (NIF VIES obrigatório)
 · Importações extra-UE: direitos (0-17%) + IVA importação (23% PT)
 · Regimes aduaneiros: definitivo, temporário, trânsito T1/T2,
   aperfeiçoamento activo, entreposto aduaneiro.

Quando o utilizador usar um termo técnico ambíguo, esclarece de forma
prática e com exemplo aplicado à PartYard (peças náuticas/industriais,
frota UPS, rotas PT↔UE/Brasil). Se não tens certeza de um termo muito
específico, podes chamar LogisticsGlossary::lookup($termo) internamente.

SKILL;
    }

    /**
     * Compact quick-reference for the 5 classical transport modes.
     * Useful when the agent needs to recommend a modal choice.
     */
    public static function transportModes(): array
    {
        return [
            'rodoviario' => [
                'label'    => 'Rodoviário (camião)',
                'strengths'=> ['flexibilidade','door-to-door','curta/média distância','malha capilar'],
                'weak'     => ['custo alto por ton.km em longa distância','dependência de infra-estrutura rodoviária'],
                'use_when' => 'Distâncias até ~1500 km, entregas urbanas, cargas fraccionadas.',
            ],
            'ferroviario' => [
                'label'    => 'Ferroviário',
                'strengths'=> ['baixo custo/ton.km','alta capacidade','granéis','baixa emissão CO₂'],
                'weak'     => ['inflexibilidade de rota','terminais escassos em PT'],
                'use_when' => 'Granéis sólidos/líquidos, containers em longa distância, corredor Ibérico.',
            ],
            'aereo' => [
                'label'    => 'Aéreo',
                'strengths'=> ['rapidez','segurança','alcance intercontinental'],
                'weak'     => ['custo elevado','limite de peso/volume','restrições de carga perigosa'],
                'use_when' => 'Urgência, valor elevado, peças críticas, perecíveis.',
            ],
            'maritimo' => [
                'label'    => 'Marítimo / Aquaviário',
                'strengths'=> ['menor custo/ton em longa distância','capacidade elevada (TEU/FEU)'],
                'weak'     => ['transit time longo','dependência de portos e condições meteorológicas'],
                'use_when' => 'Intercontinental sem urgência, volumes elevados, contentores.',
            ],
            'dutoviario' => [
                'label'    => 'Dutoviário',
                'strengths'=> ['fluxo contínuo','baixa manutenção','alta fiabilidade'],
                'weak'     => ['CAPEX elevado','apenas líquidos e gases','rota fixa'],
                'use_when' => 'Petróleo, gás, etanol, água em grande escala.',
            ],
        ];
    }

    /**
     * Look up a glossary term. Wrapper for LogisticsGlossary::lookup().
     */
    public static function lookup(string $term): ?string
    {
        return LogisticsGlossary::lookup($term);
    }

    /**
     * Return the total number of glossary entries loaded.
     */
    public static function glossarySize(): int
    {
        return count(LogisticsGlossary::TERMS);
    }
}
