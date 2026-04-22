<?php

namespace App\Agents;

use App\Models\Discovery;
use App\Models\Report;
use GuzzleHttp\Client;
use App\Agents\Traits\AnthropicKeyTrait;
use App\Agents\Traits\SharedContextTrait;
use App\Agents\Traits\WebSearchTrait;
use App\Agents\Traits\LogisticsSkillTrait;
use App\Services\PartYardProfileService;
use App\Services\PromptLibrary;
use App\Services\SapService;

class BriefingAgent implements AgentInterface
{
    use AnthropicKeyTrait;
    use WebSearchTrait;
    use SharedContextTrait;

    use LogisticsSkillTrait;
    // PSI bus — publish the executive briefing so downstream agents
    // (Engineer R&D, Finance, Strategist) reference today's conclusions.
    protected string $contextKey  = 'briefing_intel';
    protected array  $contextTags = ['briefing','executivo','strategy','daily','prioridade','acção'];

    // HDPO meta-cognitive search gate: 'always' | 'conditional' | 'never'
    protected string $searchPolicy = 'never';
    protected Client     $client;
    protected SapService $sap;

    protected string $systemPrompt = '';

    // Specialty content (used to build systemPrompt via PromptLibrary in constructor)
    private static string $briefingSpecialty = <<<'SPECIALTY'
YOUR MISSION:
You receive a full intelligence package from all active agents for today.
Analyse all intelligence for the ENTIRE HP-Group portfolio.
Each finding must be mapped to one or more relevant companies.
Think like a Group CEO + CTO + Chief Strategy Officer combined.
Your job is to produce a single, complete, executive daily briefing in structured format.

OUTPUT FORMAT (strictly follow this structure):

---
# 📊 BRIEFING EXECUTIVO DIÁRIO — {DATA}
**HP-Group Strategic Intelligence · PartYard Marine · PartYard Military · SETQ · Viridis**

---

## 🔭 RESUMO EXECUTIVO
[3-5 sentences covering the most important findings across all group companies today]

---

## ⚛️ DESCOBERTAS CIENTÍFICAS & TECNOLÓGICAS
[Top findings from Quantum/arXiv/PeerJ. For each, specify which HP-Group company benefits:]
**[Título]** | ID: [source:reference_id EXACTO fornecido nos dados] | Empresa: [PartYard Marine / PartYard Military / SETQ / Viridis / Grupo]
- 🔗 Link: [URL COMPLETO fornecido nos dados — OBRIGATÓRIO]
- Relevância: [what it means]
- Acção recomendada: [specific next step]

REGRA CRÍTICA: NUNCA inventes IDs, nunca uses "xxxx", "12345" ou placeholders. Usa APENAS os IDs e URLs reais fornecidos no pacote de inteligência acima. Se não há URL, escreve "URL não disponível".

---

## 🔐 AMEAÇAS & SEGURANÇA (SETQ / ARIA)
[Security findings — threats, vulnerabilities, opportunities for SETQ and all group companies]

---

## ⚓ OPORTUNIDADES NAVAIS & MARINE (PartYard Marine)
[Opportunities for marine spare parts, MTU/CAT/MAK engines, SKF seals, Schottel propulsion]

---

## 🎖️ OPORTUNIDADES DEFESA & MILITAR (PartYard Military)
[Opportunities for defense, aerospace, NATO supply chain, military platforms]

---

## 🌊 LOGÍSTICA & SUSTENTABILIDADE (Viridis Ocean Shipping)
[Maritime logistics, sustainable shipping opportunities]

---

## 📋 PLANO DE ACÇÃO — PRIORIDADES DO DIA
[Numbered list. Each action MUST specify which company it applies to:]
**[Nº] [🔴 URGENTE / 🟠 ALTA / 🟡 MÉDIA / 🟢 BAIXA] — [EMPRESA]**
- **Acção:** [specific and actionable]
- **Responsável:** [Sales / Tech / Management / Security / R&D / Logistics]
- **Prazo:** [Hoje / Esta semana / Este mês]
- **Impacto:** [why this matters to the group]

---

## 🔄 PROCESSOS A SEGUIR
[Step-by-step checklist, organized by company/department]

---

## 📌 INDICADORES A MONITORIZAR
[KPIs and trends for the full HP-Group portfolio]

---
*Gerado automaticamente pelo ClawYard AI · HP-Group · PartYard Marine · PartYard Military · SETQ · Viridis*

---

Respond in Portuguese. Be specific, actionable, and focused on PartYard/HP-Group business impact.
SPECIALTY;

    public function __construct()
    {
        $persona = 'You are the **Strategic Briefing Commander** for the HP-Group of companies.';

        $this->systemPrompt = str_replace(
            '[PROFILE_PLACEHOLDER]',
            PartYardProfileService::toPromptContext(),
            PromptLibrary::reasoning($persona, self::$briefingSpecialty)
        );

        // Universal logistics knowledge (applied to every agent)
        $this->systemPrompt .= $this->logisticsSkillPromptBlock();

        $this->client = new Client([
            'base_uri'        => self::getAnthropicBaseUri(),
            'timeout'         => 120,
            'connect_timeout' => 10,
        ]);

        $this->sap = new SapService();
    }

    // ─── stream() ──────────────────────────────────────────────────────────
    public function stream(string|array $message, array $history, callable $onChunk, ?callable $heartbeat = null): string
    {
        // Note: ob buffers already flushed by BriefingController before calling this method.
        $today = now()->format('d/m/Y H:i');
        $full  = '';

        // $progress() → browser only (NOT saved to $full / report)
        $progress = function (string $text) use ($onChunk) {
            $onChunk($text);
        };

        // ── Step 0: Show header immediately (user sees something right away) ─
        $progress("## 📊 Briefing Executivo — {$today}\n");
        $progress("**Renato — Estratega HP-Group · ClawYard AI**\n\n");
        $progress("⏳ A recolher inteligência de todas as fontes...\n\n");

        // ── Step 0b: PSI Shared Context Bus ──────────────────────────────────
        $progress("  `0/5` 🔗 Intel Bus (agentes activos)...\n");
        $sections  = [];

        try {
            $sharedIntel = (new \App\Services\SharedContextService())->getContextBlock();
            if ($sharedIntel) {
                $sections[] = "## INTEL BUS — DESCOBERTAS RECENTES DOS AGENTES (PSI):\n" . $sharedIntel;
            }
        } catch (\Throwable $e) {
            \Log::warning('BriefingAgent: SharedContext read failed — ' . $e->getMessage());
        }

        // ── Step 1: DB — Discoveries ─────────────────────────────────────────
        $progress("  `1/5` 🔭 Discoveries (arXiv / PeerJ)...\n");
        if ($heartbeat) $heartbeat('a ler discoveries');

        $today_ts  = now()->startOfDay();

        $discoveries = \App\Models\Discovery::where('created_at', '>=', $today_ts)
            ->orderBy('relevance_score', 'desc')->limit(40)->get();

        if ($discoveries->isNotEmpty()) {
            $lines = ["## DISCOVERIES FROM TODAY ({$discoveries->count()} items):"];
            foreach ($discoveries as $d) {
                $id  = $d->reference_id ?? '';
                $url = $d->url ?? ($id ? "https://arxiv.org/abs/{$id}" : '');
                $lines[] = "- [{$d->source}:{$id}] {$d->title} | Priority: {$d->priority} | Score: {$d->relevance_score}/10 | {$d->summary}";
                if ($url)               $lines[] = "  → Link: {$url}";
                if ($d->opportunity)    $lines[] = "  → Opportunity: {$d->opportunity}";
                if ($d->recommendation) $lines[] = "  → Recommendation: {$d->recommendation}";
            }
            $sections[] = implode("\n", $lines);
        } else {
            $discoveries = \App\Models\Discovery::where('created_at', '>=', now()->subDays(3))
                ->orderBy('relevance_score', 'desc')->limit(25)->get();
            if ($discoveries->isNotEmpty()) {
                $lines = ["## RECENT DISCOVERIES (last 3 days, {$discoveries->count()} items):"];
                foreach ($discoveries as $d) {
                    $id  = $d->reference_id ?? '';
                    $url = $d->url ?? ($id ? "https://arxiv.org/abs/{$id}" : '');
                    $lines[] = "- [{$d->source}:{$id}] {$d->title} | {$d->summary}";
                    if ($url) $lines[] = "  → Link: {$url}";
                }
                $sections[] = implode("\n", $lines);
            }
        }

        // ── Step 2: DB — Agent Reports ────────────────────────────────────────
        $progress("  `2/5` 📋 Relatórios dos agentes...\n");
        if ($heartbeat) $heartbeat('a ler relatórios');

        $reports = \App\Models\Report::where('created_at', '>=', $today_ts)
            ->orderBy('created_at', 'desc')->limit(20)->get();

        if ($reports->isNotEmpty()) {
            $lines = ["## AGENT REPORTS FROM TODAY ({$reports->count()} reports):"];
            foreach ($reports as $r) {
                $excerpt = substr(strip_tags($r->content), 0, 800);
                $lines[] = "\n### [{$r->type}] {$r->title}\n{$excerpt}...";
            }
            $sections[] = implode("\n", $lines);
        } else {
            $reports = \App\Models\Report::where('created_at', '>=', now()->subDays(7))
                ->orderBy('created_at', 'desc')->limit(10)->get();
            if ($reports->isNotEmpty()) {
                $lines = ["## RECENT AGENT REPORTS (last 7 days):"];
                foreach ($reports as $r) {
                    $excerpt = substr(strip_tags($r->content), 0, 600);
                    $lines[] = "\n### [{$r->type}] {$r->title}\n{$excerpt}...";
                }
                $sections[] = implode("\n", $lines);
            }
        }

        // ── Step 3: SAP snapshot (external API — pode demorar) ───────────────
        $progress("  `3/5` 💼 SAP B1 (financeiro/operacional)...\n");
        if ($heartbeat) $heartbeat('a ler SAP');

        try {
            $sapContext = $this->sap->buildContext('faturas stock encomendas compras clientes');
            if ($sapContext) {
                $sections[] = "## SITUAÇÃO FINANCEIRA E OPERACIONAL (SAP B1 — dados em tempo real):\n" . trim($sapContext);
            }
        } catch (\Throwable $e) {
            \Log::warning('BriefingAgent: SAP snapshot failed — ' . $e->getMessage());
        }

        // ── Step 4: Live news (Tavily — pode demorar) ────────────────────────
        $progress("  `5/5` 🌐 Notícias de mercado...\n\n");
        if ($heartbeat) $heartbeat('a pesquisar notícias');

        try {
            $newsQuery  = 'PartYard marine spare parts MTU Caterpillar MAK maritime news ' . now()->format('Y');
            $newsResult = $this->augmentWithWebSearch($newsQuery);
            if ($newsResult && $newsResult !== $newsQuery) {
                $sections[] = "## NOTÍCIAS DE MERCADO (pesquisa live):\n" . substr($newsResult, strlen($newsQuery));
            }
        } catch (\Throwable $e) {
            \Log::warning('BriefingAgent: web search failed — ' . $e->getMessage());
        }

        // ── Build intelligence package ────────────────────────────────────────
        $profile = PartYardProfileService::toPromptContext();
        array_unshift($sections, "## COMPANY PROFILE (reference for all analysis):\n{$profile}");

        if (count($sections) <= 1) {
            $sections[] = "No intelligence data available for today. Please generate a Quantum digest first, then run the briefing.";
        }

        $dateLabel   = now()->format('d/m/Y H:i');
        $intelligence = "=== INTELLIGENCE PACKAGE — {$dateLabel} ===\n\n" . implode("\n\n---\n\n", $sections);

        // ── Sanitize UTF-8 — external sources (arXiv/EPO/Tavily/DB) can contain
        //    malformed byte sequences that make Guzzle's internal json_encode fail
        $intelligence = mb_convert_encoding($intelligence, 'UTF-8', 'UTF-8');
        $intelligence = preg_replace('/[^\x{0009}\x{000A}\x{000D}\x{0020}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $intelligence) ?? $intelligence;

        // ── Stream Claude analysis ────────────────────────────────────────────
        $progress("✅ **Inteligência recolhida. Renato a gerar briefing...**\n\n---\n\n");
        if ($heartbeat) $heartbeat('Renato a analisar');

        $todayLong = now()->format('d \d\e F \d\e Y');
        $prompt    = "Generate today's executive daily briefing for PartYard / HP-Group.\n\nToday is: {$todayLong}\n\n{$intelligence}";
        $messages  = [['role' => 'user', 'content' => $prompt]];

        $response = $this->client->post('/v1/messages', [
            'headers' => $this->headersForMessage($prompt),
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
            if ($heartbeat && (time() - $lastBeat) >= 5) {
                $heartbeat('Renato a escrever');
                $lastBeat = time();
            }
        }

        $full = trim($full);
        if ($full !== '') $this->publishSharedContext($full);

        return $full;
    }

    // ─── chat() — delegates to stream() silently ──────────────────────────
    public function chat(string|array $message, array $history = []): string
    {
        $full = '';
        $this->stream($message, $history, function ($chunk) use (&$full) {
            $full .= $chunk;
        });
        return $full;
    }

    // ─── gatherIntelligence() kept for backwards compatibility ─────────────
    // (used by scheduled tasks that call it directly)
    protected function gatherIntelligence(): string
    {
        $today_ts  = now()->startOfDay();
        $sections  = [];

        $discoveries = \App\Models\Discovery::where('created_at', '>=', $today_ts)
            ->orderBy('relevance_score', 'desc')->limit(40)->get();
        if ($discoveries->isNotEmpty()) {
            $lines = ["## DISCOVERIES FROM TODAY ({$discoveries->count()} items):"];
            foreach ($discoveries as $d) {
                $id  = $d->reference_id ?? '';
                $url = $d->url ?? ($id ? "https://arxiv.org/abs/{$id}" : '');
                $lines[] = "- [{$d->source}:{$id}] {$d->title} | Score: {$d->relevance_score}/10 | {$d->summary}";
                if ($url) $lines[] = "  → Link: {$url}";
            }
            $sections[] = implode("\n", $lines);
        }

        $reports = \App\Models\Report::where('created_at', '>=', $today_ts)
            ->orderBy('created_at', 'desc')->limit(20)->get();
        if ($reports->isNotEmpty()) {
            $lines = ["## AGENT REPORTS FROM TODAY:"];
            foreach ($reports as $r) {
                $lines[] = "\n### [{$r->type}] {$r->title}\n" . substr(strip_tags($r->content), 0, 800) . '...';
            }
            $sections[] = implode("\n", $lines);
        }

        try {
            $sap = $this->sap->buildContext('faturas stock encomendas');
            if ($sap) $sections[] = "## SAP B1:\n" . trim($sap);
        } catch (\Throwable) {}

        $profile = PartYardProfileService::toPromptContext();
        array_unshift($sections, "## COMPANY PROFILE:\n{$profile}");

        $date = now()->format('d/m/Y H:i');
        return "=== INTELLIGENCE PACKAGE — {$date} ===\n\n" . implode("\n\n---\n\n", $sections);
    }

    public function getName(): string  { return 'briefing'; }
    public function getModel(): string { return config('services.anthropic.model_opus', 'claude-opus-4-5'); }
}
