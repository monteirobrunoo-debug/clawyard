<?php

namespace App\Agents;

use GuzzleHttp\Client;
use App\Agents\Traits\AnthropicKeyTrait;
use App\Agents\Traits\SharedContextTrait;
use App\Agents\Traits\WebSearchTrait;
use App\Agents\Traits\LogisticsSkillTrait;
use App\Services\PartYardProfileService;
use App\Services\PromptLibrary;
use App\Services\SapService;
use Illuminate\Support\Facades\Log;

/**
 * FinanceAgent — "Luís"
 *
 * Especialista financeiro, ROC e TOC, doutorado em Gestão Bancária.
 * Contabilidade empresarial, análise financeira, compliance fiscal,
 * auditoria e consultoria estratégica para HP-Group / PartYard.
 */
class FinanceAgent implements AgentInterface
{
    use WebSearchTrait;
    use AnthropicKeyTrait;
    use SharedContextTrait;
    use LogisticsSkillTrait;
    protected string $systemPrompt = '';

    // HDPO meta-cognitive search gate: 'always' | 'conditional' | 'never'
    protected string $searchPolicy = 'conditional';

    // PSI shared context bus — what this agent publishes
    protected string $contextKey  = 'finance_intel';
    protected array  $contextTags = ['financeiro','finance','ROC','TOC','auditoria','taxa','imposto','IVA','balanço','cash flow'];

    protected Client     $client;
    protected SapService $sap;

    // SAP keywords — always fetch live SAP data for these
    protected array $sapKeywords = [
        'fatura', 'invoice', 'factura', 'saldo', 'balance', 'conta', 'account',
        'receber', 'receivable', 'pagar', 'payable', 'fornecedor', 'supplier',
        'cliente', 'customer', 'encomenda', 'order', 'stock', 'inventário',
        'tesouraria', 'treasury', 'cash', 'caixa', 'compra', 'purchase',
    ];

    // WebSearch only for regulatory/fiscal research
    protected array $webSearchKeywords = [
        'lei', 'law', 'portaria', 'decreto', 'despacho', 'norma', 'ifrs', 'ias',
        'artigo', 'article', 'irc', 'iva', 'at.pt', 'autoridade tributária',
        'ocde', 'beps', 'taxa', 'rate', 'imposto', 'tax', 'regulamento', 'regulation',
        'jurisprudência', 'acórdão', 'ruling', 'news', 'notícia', 'alteração',
    ];

    public function __construct()
    {
        $persona = <<<'PERSONA'
Você é o Dr. Luís, o Director Financeiro e Consultor Estratégico de topo do ClawYard / HP-Group.

CREDENCIAIS E QUALIFICAÇÕES:
- Doutoramento em Gestão Bancária e Finanças Empresariais
- ROC — Revisor Oficial de Contas (certificado pela OROC — Ordem dos Revisores Oficiais de Contas de Portugal)
- TOC — Técnico Oficial de Contas (certificado pela OCC — Ordem dos Contabilistas Certificados de Portugal)
- Economista Sénior com especialização em mercados marítimos e defesa
- Analista Financeiro certificado (CFA equivalente europeu)
- Especialista em compliance fiscal português, europeu e internacional
- Mais de 25 anos de experiência em contabilidade empresarial de grandes grupos multinacionais
PERSONA;

        $specialty = <<<'SPECIALTY'
ÁREAS DE EXPERTISE:

📊 CONTABILIDADE EMPRESARIAL:
- Escrituração contabilística completa (SNC — Sistema de Normalização Contabilística)
- Demonstrações financeiras: Balanço, DRE, DACP, Anexo
- Consolidação de contas de grupos empresariais (IFRS / IAS)
- Reconciliações bancárias e controlo de tesouraria
- Centros de custo e contabilidade analítica por projecto/departamento
- Activos fixos, amortizações e depreciações

🔍 AUDITORIA & ROC:
- Revisão legal de contas (Certificação Legal de Contas — CLC)
- Auditoria financeira e operacional
- Auditoria interna e externa
- Detecção de fraude e irregularidades contabilísticas
- Due diligence financeira para fusões e aquisições
- Relatórios de auditoria conforme ISA (International Standards on Auditing)

💰 ANÁLISE FINANCEIRA:
- Análise de rácios financeiros (liquidez, rendibilidade, solvabilidade, eficiência)
- DCF (Discounted Cash Flow) e avaliação de empresas
- Análise de risco de crédito e rating de clientes
- Projecções financeiras e business plans
- Break-even analysis e análise de margens por linha de produto
- Working capital management e optimização de cash flow

🏦 GESTÃO BANCÁRIA:
- Negociação de crédito bancário e condições de financiamento
- Análise de propostas de crédito e scoring
- Gestão de risco de taxa de juro e câmbio (hedging)
- Factoring, reverse factoring e supply chain finance
- Cartas de crédito documentário (L/C) para comércio internacional marítimo
- Garantias bancárias e seguros de crédito à exportação (COSEC/ECA)

📋 FISCALIDADE & COMPLIANCE:
- IRC (Imposto sobre o Rendimento das Pessoas Colectivas)
- IVA — regimes especiais, isenções, reembolsos
- IRS de sócios e trabalhadores
- Derrama estadual e municipal
- Preços de transferência em grupos multinacionais
- OCDE e BEPS (Base Erosion and Profit Shifting)
- Compliance GDPR financeiro
- Declarações fiscais: Modelo 22, Modelo 22-DA, IES, DMIS, DIVA
- AT (Autoridade Tributária e Aduaneira) — procedimentos e defesa fiscal
- Benefícios fiscais: SIFIDE, RFAI, DLRR, CFEI

📦 FINANÇAS PARA COMÉRCIO MARÍTIMO & DEFESA:
- Incoterms 2020 e implicações financeiras
- Financiamento de contratos de defesa e NATO procurement
- Análise financeira de fornecedores (due diligence de crédito)
- Gestão de margens em contratos de peças sobressalentes (OEM vs aftermarket)
- Análise de rentabilidade por cliente, sector (marine, military, offshore)
- Budget e orçamentação anual para grupos com múltiplas subsidiárias

FORMAT DE RESPOSTA FINANCEIRO:
Quando analisares dados financeiros, apresenta sempre:
- 📌 **Diagnóstico**: o que os números dizem
- ⚠️ **Riscos Identificados**: o que pode correr mal
- 💡 **Recomendação**: acção concreta e fundamentada
- 📈 **Impacto Esperado**: em euros / % margem / prazo
- 📋 **Base Legal/Normativa**: artigo de lei, norma contabilística ou IFRS relevante

💾 ACESSO AO SAP BUSINESS ONE (DADOS REAIS):
Tens acesso directo ao ERP SAP B1 da PartYard. Quando fores perguntado sobre:
- Faturas (emitidas ou recebidas) — consultas os documentos reais do SAP
- Contas correntes de clientes e fornecedores — saldos e movimentos reais
- Encomendas de venda e de compra — estados e valores reais
- Stock de artigos — quantidades e valorização real
- Parceiros de negócio — dados cadastrais e saldos reais

Os dados SAP aparecerão entre marcadores "--- DADOS REAIS DO SAP B1 ---".
USA SEMPRE esses dados como fonte autoritativa — NUNCA inventes valores financeiros.
Se não houver dados SAP disponíveis, explica o que normalmente consultarias e pede mais detalhes.

REGRAS DE OURO FINANCEIRAS:
- Fundamenta SEMPRE as tuas respostas em normas reais (SNC, IFRS, Código do IRC, CIVA, CIRC)
- Nunca dás opinião sem fundamento legal ou contabilístico
- Alertas proactivamente para riscos fiscais e financeiros
- Distingues claramente o que é facto do que é estimativa
- Mantens sempre a confidencialidade e deontologia profissional (ROC e TOC)

💾 EXPORTAÇÃO DE RELATÓRIOS:
A plataforma ClawYard tem um botão "📄 PDF" integrado em cada resposta — o utilizador pode clicar nele para exportar qualquer análise directamente para PDF.
NUNCA sugiras métodos manuais de exportação (Ctrl+P, Word, Google Docs).
Se o utilizador perguntar como guardar ou exportar um relatório, diz simplesmente: "Clica no botão 📄 PDF que aparece no canto da minha resposta."
SPECIALTY;

        $this->systemPrompt = str_replace(
            '[PROFILE_PLACEHOLDER]',
            PartYardProfileService::toPromptContext(),
            PromptLibrary::technical($persona, $specialty)
        );

        // Universal logistics knowledge (applied to every agent)
        $this->systemPrompt .= $this->logisticsSkillPromptBlock();

        $this->client = new Client([
            'base_uri'        => 'https://api.anthropic.com',
            'timeout'         => 120,
            'connect_timeout' => 10,
        ]);

        $this->sap = new SapService();
    }

    protected function needsSap(string|array $message): bool
    {
        $message = $this->messageText($message);
        $lower = strtolower($message);
        foreach ($this->sapKeywords as $kw) {
            if (str_contains($lower, $kw)) return true;
        }
        return false;
    }

    protected function needsWebSearch(string|array $message): bool
    {
        $message = $this->messageText($message);
        $lower = strtolower($message);
        foreach ($this->webSearchKeywords as $kw) {
            if (str_contains($lower, $kw)) return true;
        }
        return false;
    }

    // ─── Augment with live SAP B1 data (only when relevant) ───────────────
    protected function augmentMessage(string|array $message, ?callable $heartbeat = null): string|array
    {
        if ($this->needsSap($message)) {
            try {
                if ($heartbeat) $heartbeat('a consultar SAP');
                $context = $this->sap->buildContext($this->messageText($message));
                if ($context) $message = $this->appendToMessage($message, $context);
            } catch (\Throwable $e) {
                Log::warning('FinanceAgent: SAP context failed — ' . $e->getMessage());
            }
        }
        // Skip web search for multimodal messages (file/image attached) — avoids corrupting array structure
        if (is_string($message) && $this->needsWebSearch($message)) {
            if ($heartbeat) $heartbeat('a pesquisar normativa');
            $message = $this->augmentWithWebSearch($message, $heartbeat);
        }
        return $message;
    }

    // ─── chat() ────────────────────────────────────────────────────────────
    public function chat(string|array $message, array $history = []): string
    {
        $message  = $this->augmentMessage($message);
        $messages = array_merge($history, [
            ['role' => 'user', 'content' => $message],
        ]);

        $response = $this->client->post('/v1/messages', [
            'headers' => $this->headersForMessage($message),
            'json'    => [
                'model'      => config('services.anthropic.model_opus', 'claude-opus-4-5'),
                'max_tokens' => 16000,
                'thinking'   => ['type' => 'enabled', 'budget_tokens' => 5000],
                'system'     => $this->enrichSystemPrompt($this->systemPrompt),
                'messages'   => $messages,
            ],
        ]);

        $data = json_decode($response->getBody()->getContents(), true);
        $text = '';
        foreach ($data['content'] ?? [] as $block) {
            if (($block['type'] ?? '') === 'text') $text .= $block['text'];
        }
        $this->publishSharedContext($text);
        return $text;
    }

    // ─── stream() ──────────────────────────────────────────────────────────
    public function stream(string|array $message, array $history, callable $onChunk, ?callable $heartbeat = null): string
    {
        $message = $this->augmentMessage($message, $heartbeat);
        $messages = array_merge($history, [
            ['role' => 'user', 'content' => $message],
        ]);

        if ($heartbeat) $heartbeat('a activar análise financeira avançada 💰');

        $response = $this->client->post('/v1/messages', [
            'headers' => $this->headersForMessage($message),
            'stream'  => true,
            'json'    => [
                'model'      => config('services.anthropic.model_opus', 'claude-opus-4-5'),
                'max_tokens' => 16000,
                'thinking'   => ['type' => 'enabled', 'budget_tokens' => 5000],
                'system'     => $this->enrichSystemPrompt($this->systemPrompt),
                'messages'   => $messages,
                'stream'     => true,
            ],
        ]);

        $body     = $response->getBody();
        $full     = '';
        $buf      = '';
        $lastBeat = time();

        while (!$body->eof()) {
            $buf .= $body->read(1024);
            while (($pos = strpos($buf, "\n")) !== false) {
                $line = substr($buf, 0, $pos);
                $buf  = substr($buf, $pos + 1);
                $line = trim($line);
                if (!str_starts_with($line, 'data: ')) continue;
                $json = substr($line, 6);
                if ($json === '[DONE]') break 2;
                $evt = json_decode($json, true);
                if (!is_array($evt)) continue;
                if (($evt['type'] ?? '') === 'content_block_delta'
                    && ($evt['delta']['type'] ?? '') === 'text_delta') {
                    $text = $evt['delta']['text'] ?? '';
                    if ($text !== '') {
                        $full .= $text;
                        $onChunk($text);
                    }
                }
            }
            if ($heartbeat && (time() - $lastBeat) >= 10) {
                $heartbeat('a calcular');
                $lastBeat = time();
            }
        }

        $this->publishSharedContext($full);
        return $full;
    }

    public function getName(): string  { return 'finance'; }
    public function getModel(): string { return config('services.anthropic.model_opus', 'claude-opus-4-5'); }
}
