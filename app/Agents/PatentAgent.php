<?php

namespace App\Agents;

use GuzzleHttp\Client;
use App\Agents\Traits\AnthropicKeyTrait;
use App\Agents\Traits\SharedContextTrait;
use App\Agents\Traits\WebSearchTrait;
use App\Agents\Traits\LogisticsSkillTrait;
use App\Services\PartYardProfileService;
use App\Services\PromptLibrary;
use App\Models\Discovery;
use App\Models\Report;
use App\Services\PatentPdfService;
use Illuminate\Support\Facades\Log;

/**
 * PatentAgent — "Dra. Sofia IP"
 *
 * Especialista em Propriedade Intelectual e validação de patentes do HP-Group.
 * Analisa projectos submetidos, verifica prior art (EPO/USPTO/WIPO),
 * confirma se já foram desenvolvidos e avalia a patentabilidade.
 */
class PatentAgent implements AgentInterface
{
    use WebSearchTrait;
    use AnthropicKeyTrait;
    use SharedContextTrait;
    use LogisticsSkillTrait;
    protected string $systemPrompt = '';

    // HDPO meta-cognitive search gate: 'always' | 'conditional' | 'never'
    protected string $searchPolicy = 'conditional';

    // PSI shared context bus — what this agent publishes
    protected string $contextKey  = 'patent_intel';
    protected array  $contextTags = ['patente','patent','EPO','USPTO','IP','FTO','prior art','invenção','SmartShield','UXS'];

    protected Client $client;
    protected Client $httpClient;

    public function __construct()
    {
        $persona = <<<'PERSONA'
Você é a **Dra. Sofia IP** — Directora de Propriedade Intelectual e Inovação do HP-Group / PartYard.

CREDENCIAIS E QUALIFICAÇÕES:
- Doutoramento em Direito da Propriedade Industrial (Faculdade de Direito de Lisboa + EPO Academy)
- European Patent Attorney (EPA) — habilitada perante o EPO (Escritório Europeu de Patentes)
- USPTO Registered Patent Agent
- WIPO (World Intellectual Property Organization) — especialista em PCT (Patent Cooperation Treaty)
- Engenheira de formação base (Engenharia Mecânica — IST Lisboa), reconvertida para IP
- +18 anos de experiência em patentes industriais, defesa, aeronáutica e tecnologia marítima
- Especialista em Freedom to Operate (FTO), prior art searches, licenciamento e design-around
- Experiência em invalidação de patentes concorrentes (Inter Partes Review — USPTO)
PERSONA;

        $specialty = <<<'SPECIALTY'
MISSÃO PRINCIPAL:
Analisar projectos de inovação/invenção do HP-Group e responder a 3 perguntas críticas:
1. 🔍 **JÁ EXISTE?** — Alguém já patenteou algo igual ou muito semelhante? (Prior Art)
2. ✅ **É PATENTEÁVEL?** — O projecto tem novidade, actividade inventiva e aplicação industrial?
3. 🚀 **JÁ FOI DESENVOLVIDO?** — Existe produto comercial que já implementa esta ideia?

PORTFÓLIO DE INOVAÇÃO HP-GROUP (áreas onde temos ou podemos ter patentes):

🛩️ AEROSPACE MRO — PartYard Defense & Aerospace:
- Ferramentas especiais de MRO para aeronaves militares (AH-64, CH-47, C-130, F-16, UH-60)
- Bancos de teste aeronáuticos, kits de reparação certificados, NDT equipment
- Sistemas de diagnóstico embarcado para aeronaves militares
- Classes CPV/IPC relevantes: B64F, B64C, G01M, F02C

🛢️ LUBRIFICANTES ARMITE:
- Formulações MIL-SPEC: MIL-PRF-23827, MIL-PRF-32033, MIL-PRF-81322, AMS 2518
- Bio-lubricants para defesa (sustentabilidade NATO)
- Nanocompósitos lubrificantes para aplicações de alta temperatura
- Anti-corrosão extremo para ambientes marítimos/árticos
- Classes IPC relevantes: C10M, C10N, F16N

⚓ SISTEMAS NAVAIS — PartYard Marine:
- Componentes propulsão naval (alternativa OEM para MTU/CAT/MAK)
- Sistemas de vedação (SternTube seals — alternativa SKF)
- Diagnóstico remoto de motores navais (IoT + sensores)
- Classes IPC relevantes: B63H, F02B, F16J

🎯 SIMULAÇÃO E TREINO:
- Simuladores de missão com IA adaptativa (avaliação de performance em tempo real)
- Cenários sintéticos de combate baseados em ML
- Interfaces homem-máquina para treino táctico
- Classes IPC relevantes: G09B, G06F, A63F

💻 SETQ — CIBERSEGURANÇA:
- Soluções de rede segura para infraestruturas críticas militares
- Quantum-resistant encryption para comunicações NATO
- C4ISR — sistemas de comando e controlo seguros
- Classes IPC relevantes: H04L, G06F, H04K

🏭 INDYARD — SERVIÇOS INDUSTRIAIS:
- Processos de manutenção preditiva (vibração, termografia, análise de óleo)
- Sistemas de gestão de supply chain para peças OEM/aftermarket de defesa
- Classes IPC relevantes: G05B, G06Q

═══════════════════════════════════════════
PROCESSO DE VALIDAÇÃO DE PATENTE
═══════════════════════════════════════════

Para cada projecto/invenção analisado, produz um relatório estruturado:

---
### 🔬 [TÍTULO DO PROJECTO / INVENÇÃO]

**📋 Descrição Técnica:** O que o projecto faz / qual o problema que resolve

**🏷️ Classificação IPC/CPC:** Códigos de classificação mais relevantes

**🔍 PESQUISA DE PRIOR ART:**
| Base | Resultado | Patentes Encontradas |
|------|-----------|---------------------|
| EPO Espacenet | ✅/⚠️/❌ | EP... / WO... |
| USPTO | ✅/⚠️/❌ | US... |
| WIPO/Google Patents | ✅/⚠️/❌ | WO... |

**⚠️ PATENTES CONFLITUANTES** (se existirem):
- 🏛️ [Número] — [Título] — Titular: [empresa] — Estado: [activa/expirada/pendente]
- 📌 Overlap: [o que sobrepõe com o nosso projecto]
- 💡 Estratégia: [design-around / licenciar / aguardar expiração / desafiar]

**🚀 JÁ DESENVOLVIDO? Produtos Comerciais Existentes:**
- ✅ SIM — [produto/empresa que já faz isto] → implicação: entrar como fornecedor alternativo ou licenciar
- ❌ NÃO — oportunidade de mercado confirmada
- ⚠️ PARCIALMENTE — [o que existe e o que falta]

**✅ AVALIAÇÃO DE PATENTEABILIDADE:**
- 🆕 Novidade: ✅ Alta / ⚠️ Média / ❌ Baixa
- 💡 Actividade Inventiva: ✅ Alta / ⚠️ Média / ❌ Baixa
- 🏭 Aplicação Industrial: ✅ Sim / ❌ Não
- 🎯 **Veredicto:** 🟢 PATENTEÁVEL / 🟡 PATENTEÁVEL COM AJUSTES / 🔴 NÃO PATENTEÁVEL

**📑 RECOMENDAÇÃO IP:**
- Acção: [Depositar PCT / Depositar EP / Depositar PT + extensão / Não depositar / Licenciar de X]
- Prazo sugerido: [urgente < 3 meses / normal 6-12 meses / aguardar]
- Custo estimado: [pedido EP ~€3-5k / PCT ~€8-12k / full prosecution ~€30-80k]
- Risco concorrência: [Alto / Médio / Baixo]
---

NO FINAL DO RELATÓRIO:
### 📊 Sumário Executivo
- Total projectos analisados: N
- 🟢 Patenteáveis imediatamente: N
- 🟡 Patenteáveis com ajustes: N
- 🔴 Não patenteáveis (já existe): N
- 💼 Já desenvolvidos comercialmente: N
- 🏆 Top 3 para depositar primeiro (por valor estratégico + probabilidade de concessão)
- ⚡ Próximos passos com prazo

REGRAS ESPECÍFICAS IP:
- Usa dados reais dos portais de patentes quando disponíveis (EPO, USPTO, WIPO, Google Patents)
- Distingue sempre patente activa vs expirada (expirada = tecnologia de domínio público)
- Quando a tecnologia já existe mas a patente expirou: ✅ LIVRE para usar, mencionar
- Alerta para riscos de contrafacção (infringement) se o projecto se sobrepõe a patente activa
- Responde sempre em Português
SPECIALTY;

        $this->systemPrompt = str_replace(
            '[PROFILE_PLACEHOLDER]',
            PartYardProfileService::toPromptContext(),
            PromptLibrary::research($persona, $specialty)
        );

        // Universal logistics knowledge (applied to every agent)
        $this->systemPrompt .= $this->logisticsSkillPromptBlock();

        $this->client = new Client([
            'base_uri'        => 'https://api.anthropic.com',
            'timeout'         => 120,
            'connect_timeout' => 10,
        ]);

        // SECURITY: keep TLS verification on — EPO/USPTO/Espacenet/WIPO all
        // have valid certs. verify=false would let an on-path attacker feed
        // fake prior-art into the FTO analysis.
        $this->httpClient = new Client([
            'timeout'         => 15,
            'connect_timeout' => 8,
            'verify'          => true,
            'headers'         => ['User-Agent' => 'ClawYard/1.0 (research@hp-group.org)'],
        ]);
    }

    // ─── Fetch context from ALL agents in the system ──────────────────────
    protected function buildPatentContext(): string
    {
        $sections = [];
        $today    = now()->startOfDay();
        $week     = now()->subDays(7);

        // ── 1. Briefing do dia (Renato) ────────────────────────────────────
        $briefing = Report::where('type', 'briefing')
            ->orderBy('created_at', 'desc')
            ->first();

        if ($briefing) {
            $excerpt = substr(strip_tags($briefing->content), 0, 1200);
            $sections[] = "## BRIEFING EXECUTIVO — RENATO (último, " . $briefing->created_at->format('d/m/Y') . "):\n{$excerpt}...";
        }

        // ── 2. Planos de I&D do Eng. Victor ───────────────────────────────
        $engineerReports = Report::where('type', 'engineer')
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get();

        if ($engineerReports->isNotEmpty()) {
            $lines = ["## PLANOS DE DESENVOLVIMENTO — ENG. VICTOR (últimos {$engineerReports->count()}):"];
            foreach ($engineerReports as $r) {
                $excerpt = substr(strip_tags($r->content), 0, 800);
                $lines[] = "\n### [{$r->created_at->format('d/m/Y')}] {$r->title}\n{$excerpt}...";
            }
            $sections[] = implode("\n", $lines);
        }

        // ── 3. Relatório do Prof. Quantum (papers + patentes EPO) ─────────
        $quantumReport = Report::where('type', 'quantum')
            ->orderBy('created_at', 'desc')
            ->first();

        if ($quantumReport) {
            $excerpt = substr(strip_tags($quantumReport->content), 0, 1000);
            $sections[] = "## DIGEST CIENTÍFICO — PROF. QUANTUM (último, " . $quantumReport->created_at->format('d/m/Y') . "):\n{$excerpt}...";
        }

        // ── 4. Patentes EPO/USPTO guardadas nas Discoveries ───────────────
        $patents = Discovery::where(function ($q) {
                $q->where('source', 'like', '%EPO%')
                  ->orWhere('source', 'like', '%USPTO%')
                  ->orWhere('source', 'like', '%patent%')
                  ->orWhere('category', 'like', '%patent%');
            })
            ->orderBy('created_at', 'desc')
            ->limit(30)
            ->get();

        if ($patents->isNotEmpty()) {
            $lines = ["## PATENTES RECENTES NO SISTEMA (EPO/USPTO — " . $patents->count() . " entradas):"];
            foreach ($patents as $p) {
                $line = "- [{$p->source}] {$p->title}";
                if ($p->reference_id) $line .= " | REF: {$p->reference_id}";
                if ($p->summary)      $line .= " | {$p->summary}";
                if ($p->url)          $line .= " | URL: {$p->url}";
                $lines[] = $line;
            }
            $sections[] = implode("\n", $lines);
        }

        // ── 5. Todas as Discoveries recentes (arXiv + PeerJ + EPO) ────────
        $allDiscoveries = Discovery::where('created_at', '>=', $week)
            ->orderBy('relevance_score', 'desc')
            ->orderBy('created_at', 'desc')
            ->limit(40)
            ->get();

        if ($allDiscoveries->isNotEmpty()) {
            $lines = ["## DESCOBERTAS CIENTÍFICAS RECENTES (últimos 7 dias — " . $allDiscoveries->count() . " papers/patentes):"];
            foreach ($allDiscoveries as $d) {
                $line = "- [{$d->source}] [{$d->category}] {$d->title}";
                if ($d->summary)          $line .= " — {$d->summary}";
                if ($d->opportunity)      $line .= " | Oportunidade: {$d->opportunity}";
                if ($d->recommendation)   $line .= " | Recomendação: {$d->recommendation}";
                if ($d->url)              $line .= " | {$d->url}";
                $lines[] = $line;
            }
            $sections[] = implode("\n", $lines);
        }

        // ── 6. Todos os relatórios recentes de todos os agentes ────────────
        $allReports = Report::where('created_at', '>=', $week)
            ->where('type', '!=', 'briefing') // briefing já incluído acima
            ->where('type', '!=', 'quantum')  // quantum já incluído acima
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get();

        if ($allReports->isNotEmpty()) {
            $lines = ["## RELATÓRIOS RECENTES — TODOS OS AGENTES (últimos 7 dias):"];
            foreach ($allReports as $r) {
                $excerpt = substr(strip_tags($r->content), 0, 400);
                $lines[] = "\n### [{$r->type}] {$r->title} ({$r->created_at->format('d/m/Y')})\n{$excerpt}...";
            }
            $sections[] = implode("\n", $lines);
        }

        if (empty($sections)) {
            return "(Sem dados de agentes no sistema ainda — executa o Renato e o Prof. Quantum primeiro)\n\n";
        }

        return "=== CONTEXTO COMPLETO HP-GROUP — TODOS OS AGENTES ===\n\n"
            . implode("\n\n---\n\n", $sections)
            . "\n\n=== FIM CONTEXTO ===\n\n";
    }

    // ─── [LEGACY — mantido para compatibilidade] ──────────────────────────
    protected function buildPatentContextLegacy(): string
    {
        $sections = [];

        // Quantum/research discoveries (papers that may relate to the project)
        $discoveries = Discovery::whereIn('category', ['quantum', 'defense', 'propulsion', 'materials', 'ai_ml', 'digital'])
            ->where('created_at', '>=', now()->subDays(30))
            ->orderBy('relevance_score', 'desc')
            ->limit(15)
            ->get();

        if ($discoveries->isNotEmpty()) {
            $lines = ["## DESCOBERTAS CIENTÍFICAS RECENTES (contexto de prior art):"];
            foreach ($discoveries as $d) {
                $line = "- [{$d->source}] {$d->title}";
                if ($d->summary) $line .= " — {$d->summary}";
                if ($d->url)     $line .= " | {$d->url}";
                $lines[] = $line;
            }
            $sections[] = implode("\n", $lines);
        }

        if (empty($sections)) return '';

        return "=== CONTEXTO HP-GROUP — PATENTES E PROJECTOS ===\n\n"
            . implode("\n\n---\n\n", $sections)
            . "\n\n=== FIM CONTEXTO ===\n\n";
    }

    // ─── EPO Espacenet prior art search ───────────────────────────────────
    protected function searchEPO(string $query): string
    {
        // Use Tavily to search Espacenet (EPO public search)
        if (!method_exists($this, 'augmentWithWebSearch')) return '';

        try {
            $result = $this->augmentWithWebSearch(
                "site:worldwide.espacenet.com OR site:patents.google.com {$query} patent",
            );
            return $result ?? '';
        } catch (\Throwable $e) {
            Log::info("PatentAgent EPO search [{$query}]: " . $e->getMessage());
            return '';
        }
    }

    // ─── Build full patent validation message ─────────────────────────────
    protected function buildPatentMessage(string|array $message, ?callable $heartbeat = null): string
    {
        $userText = is_array($message)
            ? implode(' ', array_map(fn($c) => $c['text'] ?? '', $message))
            : $message;

        if ($heartbeat) $heartbeat('a carregar base de patentes');
        $context = $this->buildPatentContext();

        if ($heartbeat) $heartbeat('a pesquisar prior art');
        $priorArt = '';
        try {
            // Extract key terms from the user's query for prior art search
            $searchQuery = "patent " . substr($userText, 0, 200) . " prior art EPO USPTO";
            $priorArt = $this->augmentWithWebSearch($searchQuery, $heartbeat);
        } catch (\Throwable $e) {
            Log::info('PatentAgent prior art search: ' . $e->getMessage());
        }

        $today = now()->format('d/m/Y');

        return <<<MSG
{$userText}

--- CONTEXTO INTERNO HP-GROUP ({$today}) ---
{$context}
--- PRIOR ART SEARCH (EPO/USPTO/Google Patents) ---
{$priorArt}
--- FIM ---

Com base nos dados acima:
1. Verifica se algum dos projectos/invenções já tem prior art (patentes existentes)
2. Confirma quais já foram desenvolvidos como produto comercial
3. Avalia a patenteabilidade de cada um
4. Identifica conflitos com patentes activas de concorrentes
5. Recomenda estratégia IP para cada projecto

Usa o formato estruturado do teu sistema de relatórios.
MSG;
    }

    // ─── chat() ────────────────────────────────────────────────────────────
    public function chat(string|array $message, array $history = []): string
    {
        $finalMessage = $this->buildPatentMessage($message);
        $messages = array_merge($history, [
            ['role' => 'user', 'content' => $finalMessage],
        ]);

        $response = $this->client->post('/v1/messages', [
            'headers' => $this->headersForMessage($finalMessage),
            'json'    => [
                'model'      => config('services.anthropic.model_opus', 'claude-opus-4-5'),
                'max_tokens' => 8192,
                'system'     => $this->enrichSystemPrompt($this->systemPrompt),
                'messages'   => $messages,
            ],
        ]);

        $data = json_decode($response->getBody()->getContents(), true);
        $result = $data['content'][0]['text'] ?? '';
        $this->publishSharedContext($result);
        return $result;
    }

    // ─── stream() ──────────────────────────────────────────────────────────
    public function stream(string|array $message, array $history, callable $onChunk, ?callable $heartbeat = null): string
    {
        $today = now()->format('d/m/Y H:i');

        if ($heartbeat) $heartbeat('a carregar base de patentes');

        $finalMessage = $this->buildPatentMessage($message, $heartbeat);

        $messages = array_merge($history, [
            ['role' => 'user', 'content' => $finalMessage],
        ]);

        if ($heartbeat) $heartbeat('Dra. Sofia a validar patentes');

        $response = $this->client->post('/v1/messages', [
            'headers' => $this->headersForMessage($finalMessage),
            'stream'  => true,
            'json'    => [
                'model'      => config('services.anthropic.model_opus', 'claude-opus-4-5'),
                'max_tokens' => 8192,
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
            if ($heartbeat && (time() - $lastBeat) >= 3) {
                $heartbeat('Dra. Sofia a analisar');
                $lastBeat = time();
            }
        }

        // ── Auto-download PDFs found in the response ───────────────────────
        try {
            $pdfService = new PatentPdfService();
            $patents    = $pdfService->extractPatentNumbers($full);
            if ($patents) {
                if ($heartbeat) $heartbeat('a fazer download de ' . count($patents) . ' patente(s) em PDF');
                $results  = $pdfService->downloadMultiple($patents);
                $ok       = array_filter($results);
                $failed   = array_diff_key($results, $ok);

                $summary = "\n\n---\n📥 **PDFs descarregados automaticamente:** " . count($ok) . "/" . count($patents);
                foreach ($ok as $pn => $path) {
                    $summary .= "\n- ✅ [{$pn}](/patents/download/{$pn})";
                }
                foreach (array_keys($failed) as $pn) {
                    $summary .= "\n- ⚠️ {$pn} — não disponível online";
                }

                $onChunk($summary);
                $full .= $summary;
            }
        } catch (\Throwable $e) {
            Log::warning('PatentAgent PDF download failed: ' . $e->getMessage());
        }

        $this->publishSharedContext($full);
        return $full;
    }

    public function getName(): string  { return 'patent'; }
    public function getModel(): string { return config('services.anthropic.model_opus', 'claude-opus-4-5'); }
}
