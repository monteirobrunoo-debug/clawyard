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
        // PT-specific tax codes (Códigos fiscais portugueses — 2026-05-12)
        'circ','civa','cirs','cimi','cimt','cfi','cis','cppt','cpc',
        'código do irc','código do iva','código do irs','código do imposto',
        'tributação autónoma','tributações autónomas','beneficios fiscais',
        'benefícios fiscais','consolidação de contas','equivalência patrimonial',
        'oe2025','orçamento do estado','ies','modelo 22','modelo 3','modelo 30',
        'segurança social','subsídio','subsidio','férias','natal','remunerações',
        'remuneracoes','prestações compensatórias','amortização','confirming',
        'novo estatuto','contabilidade financeira','demonstração de resultados',
        'demonstrações financeiras','snc-ap','gestão fiscal','planeamento fiscal',
        'optimização fiscal','derrama','retenção na fonte','liquidação',
        'autoridade tributária','spend analysis','category management',
        'negotiation','negociação','negociacao',

        // ── Negotiation / Persuasion / Objection handling (Voss · 2026-05-19) ──
        // Activadores genéricos — qualquer query sobre persuadir, negociar,
        // gerir objecções ou fechar deal traz contexto do livro Voss
        // (ou outros de negociação que sejam ingeridos no futuro).
        'persuasion','persuasão','persuasao','persuade','persuadir',
        'objection','objeção','objecção','objecções','objeções','objection handling',
        'gestão de objecções','gestao de objeccoes',
        'closing','fechar deal','fechar venda','close the deal',
        'tactical empathy','empatia táctica','empatia tática',
        'mirroring','espelhar','espelhamento','label','labeling','rotular',
        'calibrated question','calibrated questions','perguntas calibradas',
        'how am i supposed','no-oriented','accusation audit','black swan',
        'anchoring','ancoragem','bargain','barganha','barganhar','regatear',
        'counteroffer','contra-proposta','contraproposta','contraoferta',
        'walkaway','batna','zopa','reserve price','preço de reserva',
        'price negotiation','desconto','discount','rebate','concessão','concessao',
        'win-win','ganha-ganha','hostage','hostage negotiator','difficult conversation',
        'conversa difícil','conversa dificil','dealmaker','deal','fechar acordo',
        'rapport','establishing rapport','listening','escuta activa','escuta ativa',

        // ── Commercial / CRM / Sales ──
        'crm','customer relationship','lead','prospect','pipeline','funnel','outreach',
        'sales','vendas','closing','quota','commission','comissão','target',
        'segmentação','segmentation','retention','retenção','churn','loyalty',
        'cross-sell','upsell','nps','customer success',

        // ── Marketing / Branding / Performance (Ana Monteiro 2026-05-14) ──
        'marketing','marca','brand','branding','positioning','posicionamento',
        'archetype','arquétipo','arquetipo','brand voice','tom de marca',
        'value proposition','proposta de valor','brand book','guidelines',
        'campaign','campanha','content calendar','calendário editorial',
        'content marketing','editorial','copywriting','copy','headline','cta',
        'call to action','social media','redes sociais','instagram','tiktok',
        'pinterest','youtube','linkedin marketing','facebook',
        'meta ads','facebook ads','instagram ads','google ads','tiktok ads',
        'performance max','pmax','ppc','display ads','video ads',
        'seo','search engine optimization','sem','keyword research',
        'on-page seo','technical seo','backlink','link building','schema',
        'core web vitals','sitemap','meta description','title tag',
        'influencer','influenciador','influenciadora','creator','ugc',
        'user generated content','micro influencer','nano influencer',
        'macro influencer','spark ad','spark ads','affiliate',
        'email marketing','newsletter','klaviyo','mailchimp','activecampaign',
        'brevo','welcome flow','abandoned cart','browse abandonment',
        'win-back','post-purchase','rfm','automation','workflow',
        'cac','ltv','ltv:cac','roas','aov','ticket médio','ticket medio',
        'cpm','cpc','ctr','conversion rate','taxa de conversão','tofu','mofu','bofu',
        'funnel','funil','awareness','consideration','decision','retention',
        'attribution','atribuição','atribuicao','utm','first-touch','last-touch',
        'lookalike','retargeting','remarketing','custom audience','pixel',
        'ga4','google analytics','hotjar','mixpanel','looker studio','posthog',
        'd2c','direct-to-consumer','b2c','b2b','ecommerce','e-commerce',
        'shopify','woocommerce','magento','prestashop',
        'press release','comunicado de imprensa','media outreach','media kit',
        'pr','relações públicas','relacoes publicas','crisis comms',
        'event activation','lançamento','lancamento','launch','pop-up','trade show',
        'thought leadership','case study','white paper','webinar',
        'pricing strategy','estratégia de preço','estrategia de preco',
        'segmentation','targeting','persona','buyer persona','jtbd',
        'jobs-to-be-done','retention','retenção','churn','loyalty','lealdade',

        // ── HR / People / Performance / Leadership (Dr.ª Ana Sobral) ──
        'rh','recursos humanos','hr','human resources','people ops','people operations',
        'recrutamento','recruitment','selecção','selecao','headhunting',
        'onboarding','integração','integracao','offboarding','exit interview',
        'avaliação de desempenho','avaliacao de desempenho','performance review',
        'avaliar desempenho','avaliações','avaliacoes','feedback','1:1','one-on-one',
        'okr','okrs','kpi','kpis','objectivos','objetivos','key results',
        'balanced scorecard','bsc','smart goals','metas smart',
        'talent','talento','talent mapping','9-box','nine-box','succession',
        'sucessão','sucessao','plano de carreira','career path',
        'liderança','lideranca','leadership','team lead','people manager',
        'gestão de equipas','team management','team building',
        'equipa','equipas','team','teams','colaborador','colaboradora','colaboradores',
        'employee','staff','workforce','headcount','effectivo','efectivo',
        'engagement','employee engagement','satisfação','satisfacao',
        'motivação','motivacao','retention','turnover','attrition','absentismo',
        'time-to-hire','time-to-fill','cost-per-hire','quality of hire',
        'salário','salario','wage','vencimento','remuneração','remuneracao',
        'benefícios','beneficios','compensation','total rewards','c&b',
        'subsídio de refeição','subsidio de refeicao','cartão refeição','cartao refeicao',
        'subsídio de férias','subsidio de ferias','subsídio de natal','subsidio de natal',
        'férias','feriados','faltas','baixa médica','baixa medica',
        'maternidade','paternidade','parentalidade','licença parental',
        'rescisão','rescisao','denúncia contrato','aviso prévio','aviso previo',
        'despedimento','dismissal','demissão','demissao','justa causa',
        'código do trabalho','codigo do trabalho','cct','contrato colectivo',
        'convenção colectiva','convencao colectiva','contrato sem termo',
        'contrato a termo','contrato a termo certo','contrato a termo incerto',
        'tempo parcial','part-time','full-time','tempo inteiro',
        'horário','horario','banco de horas','horas suplementares','horas extra',
        'trabalho suplementar','trabalho nocturno','trabalho noturno',
        'act','autoridade para as condições do trabalho',
        'segurança social','seguranca social','iss','iss seg social',
        'dmr','declaração mensal de remunerações','declaracao mensal de remuneracoes',
        'modelo 1 ss','modelo 1 seg social','admissão colaborador',
        'relatório único','relatorio unico','gep','inquérito empregos vagos',
        'ies recursos humanos','dgert','iefp','formação certificada','formacao certificada',
        'ccp','ccp-dgert','plano de formação','plano de formacao',
        'avaliação da formação','avaliacao da formacao','eficácia da formação',
        'shst','sst','segurança e saúde no trabalho','medicina do trabalho',
        'higiene e segurança','rgpd colaboradores','assédio','assedio',
        'mobbing','bullying','canal de denúncia','canal de denuncia','whistleblowing',
        'diversidade','diversity','inclusão','inclusao','gender pay gap',
        'organigrama','organograma','org chart','estrutura organizacional',
        'categoria profissional','categorias profissionais','job grade','job description',
        'descrição de funções','descricao de funcoes','perfil de função',
        'mapa de férias','mapa de ferias','plano de férias','plano de ferias',

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
        $system = $this->enrichSystemPrompt($basePrompt) . ($book ? "\n\n" . $book : '');

        // #138 (2026-06-01): chunks de livros extraídos de PDF podem conter bytes
        // UTF-8 inválidos. Vão para o 'system' do request Anthropic, cujo Guzzle
        // 'json' faz json_encode → rebenta com "Malformed UTF-8 characters" e o
        // agente falha (era o caso da Dra. Sofia/PatentAgent). Sanitizar aqui, na
        // origem, protege TODOS os agentes (chat + stream).
        return $this->ensureValidUtf8($system);
    }

    /**
     * Garante UTF-8 válido. Caminho rápido: se a string já é válida (a esmagadora
     * maioria dos casos), devolve tal e qual — custo ~0. Só quando há bytes
     * inválidos é que re-codifica, substituindo as sequências partidas por U+FFFD (�).
     */
    protected function ensureValidUtf8(string $s): string
    {
        if ($s === '' || mb_check_encoding($s, 'UTF-8')) {
            return $s;
        }
        $prev = mb_substitute_character();
        mb_substitute_character(0xFFFD);
        $clean = mb_convert_encoding($s, 'UTF-8', 'UTF-8');
        mb_substitute_character($prev);
        return $clean;
    }
}
