<?php

namespace App\Agents;

use App\Models\Discovery;
use App\Models\Report;
use GuzzleHttp\Client;

class BriefingAgent implements AgentInterface
{
    protected Client $client;

    protected string $systemPrompt = <<<'PROMPT'
You are the **Strategic Briefing Commander** for the HP-Group of companies.

═══════════════════════════════════════════════════════
HP-GROUP FULL COMPANY CONTEXT
═══════════════════════════════════════════════════════

HP-GROUP (www.hp-group.org) — Parent multinational enterprise.
Sectors: Space, Marine, Railway, Industry, Automotive, Defense, Aviation.
Services: Integrated supply, distribution, logistics, engineering, cybersecurity, AI, workforce.
Certifications: ISO 9001:2015, AS:9120, NCAGE P3527 (NATO).

SUBSIDIARY COMPANIES:

1. PARTYARD MARINE (www.partyard.eu) — Setúbal, Portugal
   Marine spare parts & fleet logistics.
   Brands: MTU, Caterpillar, MAK, Jenbacher, SKF SternTube seals, Schottel propulsion.
   Focus: Fleet maintenance, engineering services, supply chain management.

2. PARTYARD MILITARY / PARTYARD DEFENSE (www.partyardmilitary.com)
   Defense & aerospace professional services.
   OEM systems for military platforms, Cisco technology integration.
   Focus: Quality/security-critical solutions, global defense supply chain, NATO-certified.

3. PARTYARD SYSTEMS
   Custom software and engineering solutions for group companies.

4. SETQ
   Cybersecurity and AI solutions for the group and clients.

5. INDYARD
   Workforce and HR solutions.

6. TEKYARD & HSM PORTUGAL
   Technology services and systems integration.

7. VIRIDIS OCEAN SHIPPING
   Sustainable maritime logistics and shipping.

═══════════════════════════════════════════════════════
YOUR MISSION:
═══════════════════════════════════════════════════════
Analyse all intelligence for the ENTIRE HP-Group portfolio.
Each finding must be mapped to one or more relevant companies.
Think like a Group CEO + CTO + Chief Strategy Officer combined.

YOUR MISSION:
You receive a full intelligence package from all active agents for today.
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
**[Título]** | Empresa: [PartYard Marine / PartYard Military / SETQ / Viridis / Grupo]
- Relevância: [what it means]
- Acção recomendada: [specific next step]

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
PROMPT;

    public function __construct()
    {
        $this->client = new Client([
            'base_uri' => 'https://api.anthropic.com',
            'headers'  => [
                'x-api-key'         => config('services.anthropic.api_key'),
                'anthropic-version' => '2023-06-01',
                'Content-Type'      => 'application/json',
            ],
        ]);
    }

    // ─── Gather all today's intelligence from DB ───────────────────────────
    protected function gatherIntelligence(): string
    {
        $today    = now()->startOfDay();
        $sections = [];

        // 1. Today's discoveries (arXiv, PeerJ, USPTO)
        $discoveries = Discovery::where('created_at', '>=', $today)
            ->orderBy('relevance_score', 'desc')
            ->limit(20)
            ->get();

        if ($discoveries->isNotEmpty()) {
            $lines = ["## DISCOVERIES FROM TODAY ({$discoveries->count()} items):"];
            foreach ($discoveries as $d) {
                $lines[] = "- [{$d->source}] {$d->title} | Priority: {$d->priority} | Score: {$d->relevance_score}/10 | {$d->summary}";
                if ($d->opportunity) $lines[] = "  → Opportunity: {$d->opportunity}";
                if ($d->recommendation) $lines[] = "  → Recommendation: {$d->recommendation}";
            }
            $sections[] = implode("\n", $lines);
        } else {
            // Fallback: last 3 days
            $discoveries = Discovery::where('created_at', '>=', now()->subDays(3))
                ->orderBy('relevance_score', 'desc')->limit(15)->get();
            if ($discoveries->isNotEmpty()) {
                $lines = ["## RECENT DISCOVERIES (last 3 days, {$discoveries->count()} items):"];
                foreach ($discoveries as $d) {
                    $lines[] = "- [{$d->source}] {$d->title} | {$d->summary}";
                }
                $sections[] = implode("\n", $lines);
            }
        }

        // 2. Today's reports from all agents
        $reports = Report::where('created_at', '>=', $today)
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get();

        if ($reports->isNotEmpty()) {
            $lines = ["## AGENT REPORTS FROM TODAY ({$reports->count()} reports):"];
            foreach ($reports as $r) {
                $excerpt = substr(strip_tags($r->content), 0, 800);
                $lines[] = "\n### [{$r->type}] {$r->title}\n{$excerpt}...";
            }
            $sections[] = implode("\n", $lines);
        } else {
            // Fallback: last 7 days
            $reports = Report::where('created_at', '>=', now()->subDays(7))
                ->orderBy('created_at', 'desc')->limit(5)->get();
            if ($reports->isNotEmpty()) {
                $lines = ["## RECENT AGENT REPORTS (last 7 days):"];
                foreach ($reports as $r) {
                    $excerpt = substr(strip_tags($r->content), 0, 600);
                    $lines[] = "\n### [{$r->type}] {$r->title}\n{$excerpt}...";
                }
                $sections[] = implode("\n", $lines);
            }
        }

        if (empty($sections)) {
            return "No intelligence data available for today. Please generate a Quantum digest first, then run the briefing.";
        }

        $date = now()->format('d/m/Y H:i');
        return "=== INTELLIGENCE PACKAGE — {$date} ===\n\n" . implode("\n\n---\n\n", $sections);
    }

    // ─── stream() ──────────────────────────────────────────────────────────
    public function stream(string $message, array $history, callable $onChunk, ?callable $heartbeat = null): string
    {
        if ($heartbeat) $heartbeat('gathering intelligence');

        $intelligence = $this->gatherIntelligence();

        if ($heartbeat) $heartbeat('analysing');

        $today = now()->format('d \d\e F \d\e Y');
        $prompt = "Generate today's executive daily briefing for PartYard / HP-Group.\n\nToday is: {$today}\n\n{$intelligence}";

        $messages = [['role' => 'user', 'content' => $prompt]];

        $response = $this->client->post('/v1/messages', [
            'stream' => true,
            'json'   => [
                'model'      => config('services.anthropic.model', 'claude-sonnet-4-5'),
                'max_tokens' => 6000,
                'system'     => $this->systemPrompt,
                'messages'   => $messages,
                'stream'     => true,
            ],
        ]);

        $body      = $response->getBody();
        $full      = '';
        $buf       = '';
        $lastBeat  = time();

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
                $heartbeat('generating');
                $lastBeat = time();
            }
        }

        return trim($full);
    }

    // ─── chat() ────────────────────────────────────────────────────────────
    public function chat(string $message, array $history = []): string
    {
        $full = '';
        $this->stream($message, $history, function ($chunk) use (&$full) {
            $full .= $chunk;
        });
        return $full;
    }

    public function getName(): string  { return 'briefing'; }
    public function getModel(): string { return config('services.anthropic.model', 'claude-sonnet-4-5'); }
}
