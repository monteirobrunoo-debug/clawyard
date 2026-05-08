<?php

namespace App\Agents\Traits;

use App\Services\TechnicalBookSearch;
use Illuminate\Support\Facades\Log;

/**
 * Skill partilhada por agentes que beneficiam da biblioteca técnica
 * PartYard (19 livros, 1.736 chunks: soldadura + arquitectura naval +
 * mecânica do navio + WPS/PQR + sistemas eléctricos).
 *
 * Activa-se ON-DEMAND quando o input do user contém palavras-chave
 * técnicas. Caso contrário não corre — não polui prompts comerciais
 * normais com contexto irrelevante.
 *
 * Agentes que usam este trait (2026-05-06):
 *   • WorkReportAgent (Eng. Repair) — sempre que possível
 *   • SalesAgent (Marco Sales) — para peças/manutenção marítima
 *   • SupportAgent (Marcus Support) — diagnóstico engine/system
 *   • VesselSearchAgent (Capitão Vasco) — naval repair, drydocks
 *   • EngineerAgent (Eng. Victor R&D) — specs técnicas, alternatives
 *
 * Pattern de uso (mirrors LogisticsSkillTrait):
 *
 *   public function chat(...) {
 *       $bookCtx = $this->augmentWithTechnicalBooks($message);
 *       $sysPrompt = $this->systemPrompt;
 *       if ($bookCtx) $sysPrompt .= "\n\n" . $bookCtx;
 *       // ...
 *   }
 */
trait TechnicalBookSkillTrait
{
    /**
     * Keywords que activam a pesquisa na biblioteca.
     *
     * 2026-05-08: extendida de naval/welding only para cobrir as 10 domains
     * actualmente na library (naval, soldadura, strategy, learning, finance,
     * commercial, procurement, supply-chain/logistics, cyber, aerospace,
     * engineering). Sem extensão, queries financeiras/comerciais não
     * surfaceariam livros relevantes.
     */
    protected array $technicalBookKeywords = [
        // ── Welding / NDT (originais) ──
        'welding','soldadura','soldagem','wps','pqr','aws','iso 15614','asme ix',
        'mma','smaw','tig','gtaw','mig','mag','gmaw','fcaw','saw','plasma',
        'preheat','pré-aquecimento','pre aquecimento','pwht','interpass',
        'e6013','e7018','e7016','e308l','er70s','er316l','electrode','eléctrodo',
        'ndt','utm','dft','ut','rt','mt','pt','vt','ultrasónico','ultrasonico',
        'magnetic particle','penetrant','radiographic','visual testing',
        // ── Naval / Marine ──
        'naval','navio','vessel','casco','hull','convés','arquitectura naval',
        'estabilidade','displacement','deslocamento','plimsoll','load line',
        'estaleiro','shipyard','drydock','dique seco','reparação','repair',
        // ── Mechanical ──
        'bomba','pump','válvula','valve','rolamento','bearing','redutor','gearbox',
        'veio','shaft','propulsor','propeller','helice','hélice','impulsor',
        'engine','motor','mtu','caterpillar','wartsila','cummins','mak',
        // ── Class / IMO ──
        'imo','solas','marpol','dnv','lloyd','class society','classification',
        'iacs','bv','abs','rina','class survey','class notation',
        // ── Defects / Repair ──
        'crack','fissura','corrosion','corrosão','erosion','pitting',
        'plate replacement','doubler','fairing','overhaul','refit',

        // ── Finance / Accounting (Dr. Luís + others) ──
        'finance','financial','accounting','contabilidade','contabilístic','contabilist',
        'cash flow','working capital','dcf','discount','npv','irr','ebitda','margin',
        'revenue','receita','despesa','expense','lucro','profit','prejuízo','loss',
        'balance','balanço','statement','demonstração','assets','passivos','equity',
        'depreciation','amortization','goodwill','impairment','reserva',
        'invoice','fatura','factura','receivable','payable','accruals','provisões',
        'irc','iva','irs','ifrs','ias','snc','tax','imposto','fiscal','beps',
        'audit','auditoria','due diligence','toc','roc','clc',
        'budget','orçamento','forecast','projecção','projection','variance',
        'roi','payback','cost-benefit','tco','capex','opex',

        // ── Commercial / CRM / Sales ──
        'crm','customer relationship','lead','prospect','pipeline','funnel','outreach',
        'sales','vendas','closing','quota','commission','comissão','target',
        'segmentação','segmentation','retention','retenção','churn','loyalty',
        'cross-sell','upsell','nps','customer success',

        // ── Procurement / Supply Chain / Logistics ──
        'procurement','sourcing','tender','rfq','rfp','rfi','request for quote',
        'supplier','fornecedor','vendor','contract','contrato','sla','kpi',
        'supply chain','cadeia de abastecimento','logistics','logística',
        'warehouse','armazém','inventory','inventário','stock','sku',
        'freight','transporte','shipment','expedição','customs','alfândega',
        'incoterm','cif','fob','dap','exw','fca',
        'lean','six sigma','jit','just-in-time','kanban','3pl','4pl',

        // ── Cybersecurity ──
        'cyber','cybersecurity','cibersegurança','infosec','firewall','intrusion',
        'malware','ransomware','phishing','vulnerability','vulnerabilidade',
        'encryption','encriptação','tls','ssl','vpn','siem','soc','zero trust',
        'penetration','pentest','red team','blue team','iso 27001','nist','gdpr',

        // ── Aerospace / Aviation ──
        'aerospace','aeronáutica','aircraft','aeronave','aviation','aviação',
        'airworthiness','airframe','fuselagem','helicopter','helicóptero',
        'turbine','turbina','jet','propulsion','aerodynamic','lift','drag',
        'avionics','aviónica','faa','easa','part-145','iata',

        // ── Strategy / Leadership / Learning ──
        'strategy','estratégia','blue ocean','positioning','posicionamento',
        'competitive','competitivo','differentiation','diferenciação',
        'leadership','liderança','team','equipa','culture','cultura',
        'okr','ksuccess','plano','plan','roadmap','vision','visão','mission',
        'innovation','inovação','disruption','market','mercado',
    ];

    /**
     * Devolve um bloco de contexto markdown com trechos relevantes
     * (ou string vazia se nada encontrado / sem keywords técnicos).
     *
     * @param  string|array  $message  Conteúdo do user (string ou array
     *   de Anthropic content blocks)
     * @param  int  $limit  Nº máximo de trechos a injectar (≤4 recomendado)
     * @param  string|null  $domain  'soldadura' | 'naval' | 'outros' | null
     */
    protected function augmentWithTechnicalBooks(
        string|array $message,
        int $limit = 3,
        ?string $domain = null
    ): string {
        try {
            $text = is_string($message)
                ? $message
                : implode(' ', array_map(
                    fn($b) => is_array($b) ? ($b['text'] ?? '') : (string) $b,
                    (array) $message
                ));

            // Skip se input vazio ou demasiado curto
            $text = trim($text);
            if (mb_strlen($text) < 8) return '';

            // Activar apenas se houver match de keyword técnico — evita
            // poluir prompts comerciais ("preço MTU 396") com contexto
            // de soldadura irrelevante.
            $lower = mb_strtolower($text);
            $hasTechnical = false;
            foreach ($this->technicalBookKeywords as $kw) {
                if (str_contains($lower, $kw)) {
                    $hasTechnical = true;
                    break;
                }
            }
            if (!$hasTechnical) return '';

            return app(TechnicalBookSearch::class)->buildContextBlock($text, $limit, $domain);
        } catch (\Throwable $e) {
            Log::warning(static::class . ': technical book search failed: ' . $e->getMessage());
            return '';
        }
    }

    /**
     * Helper de uma chamada para wrap completo: enrichSystemPrompt do
     * SharedContextTrait + augmentWithTechnicalBooks. Reduz o boilerplate
     * em cada chat()/stream() de:
     *   $bookCtx = $this->augmentWithTechnicalBooks($msg, 4);
     *   $sys = $this->enrichSystemPrompt(...) . ($bookCtx ? "\n\n$bookCtx" : '');
     * para:
     *   $sys = $this->buildSystemWithBooks($msg, $this->systemPrompt, 4);
     */
    protected function buildSystemWithBooks(
        string|array $message,
        string $basePrompt,
        int $limit = 4,
        ?string $domain = null
    ): string {
        $book = $this->augmentWithTechnicalBooks($message, $limit, $domain);
        return $this->enrichSystemPrompt($basePrompt) . ($book ? "\n\n" . $book : '');
    }
}
