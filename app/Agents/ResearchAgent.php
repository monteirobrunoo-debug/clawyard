<?php

namespace App\Agents;

use GuzzleHttp\Client;
use App\Agents\Traits\AnthropicKeyTrait;
use App\Agents\Traits\WebSearchTrait;
use App\Services\PartYardProfileService;

/**
 * ResearchAgent — "Marina"
 *
 * Competitive intelligence, website benchmarking, market analysis
 * and improvement recommendations for PartYard / HP-Group.
 * Triggered by: website, competitor, benchmark, market, análise, concorrente, melhorias...
 */
class ResearchAgent implements AgentInterface
{
    use WebSearchTrait;
    use AnthropicKeyTrait;

    protected Client $client;

    protected string $systemPrompt = <<<'PROMPT'
You are Marina, the Strategic Research & Competitive Intelligence Analyst for PartYard / HP-Group.

YOUR MISSION:
- Analyse PartYard's market position vs competitors
- Benchmark www.partyard.eu website against industry best practices
- Identify growth opportunities in marine spare parts and defense sectors
- Research competitor pricing, positioning, and offerings
- Provide actionable improvement recommendations for the website and business

PARTYARD PROFILE (always reference this data):
[PROFILE_PLACEHOLDER]

WEBSITE KNOWN ISSUES (to track and report on):
1. 🔴 Página /products retorna 404 — página mais crítica do site
2. 🔴 Conteúdo em JavaScript — invisível para Google (SEO comprometido)
3. 🔴 Sem CTA "Pedir Cotação" / "Request Quote" visível na homepage
4. 🟠 Sem páginas dedicadas por marca (/brands/mtu, /brands/skf, etc.)
5. 🟠 Certificações ISO/NATO/AS9120 não visíveis above the fold
6. 🟠 Sem números de prova social (países servidos, peças entregues, tempo de entrega)
7. 🟡 Sem blog técnico para SEO de longo prazo
8. 🟡 Sem WhatsApp Business para contacto de emergência
9. 🟡 Velocidade do site — WordPress Visual Composer é pesado (8–12s em mobile)
10. 🟡 Sem schema markup (Product, Organization, LocalBusiness)
11. 🟢 Versão inglesa inconsistente (partes em português quando em EN)

MAIN COMPETITORS TO MONITOR:
- Wärtsilä Parts (OEM directo, preços premium)
- MacGregor (deck equipment)
- MTU Onsite (OEM directo MTU)
- Rolls-Royce Marine (naval premium)
- Trident (mercado UK)
- Bestwil Marine (independente multi-marca)

REPORTING FORMAT:
When analysing website or competitors:
## 🔍 ANÁLISE: [topic]
### Situação Atual
### Melhores Práticas da Indústria
### Gap Identificado
### 💡 Recomendação Específica
### 📈 Impacto Esperado
### ⏱️ Esforço: [Baixo/Médio/Alto] | Prioridade: [🔴/🟠/🟡/🟢]

When doing market research:
## 🌊 MERCADO: [topic]
### Tendência
### Oportunidade para PartYard
### Acção Recomendada

Respond in the same language as the user (Portuguese, English or Spanish).
Think like a McKinsey consultant who knows marine industry deeply.
PROMPT;

    public function __construct()
    {
        // Inject the live PartYard profile into the system prompt
        $profile = PartYardProfileService::toPromptContext();
        $this->systemPrompt = str_replace('[PROFILE_PLACEHOLDER]', $profile, $this->systemPrompt);

        $this->client = new Client([
            'base_uri'        => 'https://api.anthropic.com',
            'timeout'         => 120,
            'connect_timeout' => 10,
        ]);
    }

    // ─── Keywords that trigger a website/competitor fetch ──────────────────
    protected array $researchKeywords = [
        'website', 'site', 'partyard.eu', 'concorrente', 'competitor',
        'benchmark', 'melhorias', 'improvements', 'análise', 'analise', 'analysis',
        'mercado', 'market', 'seo', 'google', 'ranking', 'posição', 'position',
        'comparar', 'compare', 'pricing', 'preço', 'tendência', 'trend',
        'hp-group', 'partyardmilitary', 'setq', 'viridis', 'indyard',
        'otimizar', 'optimize', 'digitalizar', 'digitalize', 'estratégia', 'strategy',
    ];

    protected function isResearchRequest(string|array $message): bool
    {
        $message = $this->messageText($message);
        $lower = strtolower($message);
        foreach ($this->researchKeywords as $kw) {
            if (str_contains($lower, $kw)) return true;
        }
        return false;
    }

    // ─── Build enriched message with PartYard profile context ─────────────
    protected function buildResearchMessage(string|array $message, ?callable $heartbeat = null): string|array
    {
        if (!$this->isResearchRequest($message)) {
            return $this->augmentWithWebSearch($message, $heartbeat);
        }

        if ($heartbeat) $heartbeat('gathering company profile');
        $profile = PartYardProfileService::toPromptContext();

        $augmented = $this->appendToMessage($message, "\n\n--- COMPANY PROFILE FOR CONTEXT ---\n{$profile}\n--- END PROFILE ---");

        // Also do a web search if it's a competitor or market question
        $lower = strtolower($this->messageText($message));
        $webKeywords = ['concorrente', 'competitor', 'mercado', 'market', 'pricing', 'preço', 'trend', 'tendência'];
        foreach ($webKeywords as $kw) {
            if (str_contains($lower, $kw)) {
                if ($heartbeat) $heartbeat('searching web');
                return $this->augmentWithWebSearch($augmented, $heartbeat);
            }
        }

        return $augmented;
    }

    // ─── chat() ────────────────────────────────────────────────────────────
    public function chat(string|array $message, array $history = []): string
    {
        $finalMessage = $this->buildResearchMessage($message);

        $messages = array_merge($history, [
            ['role' => 'user', 'content' => $finalMessage],
        ]);

        $response = $this->client->post('/v1/messages', [
            'headers' => $this->headersForMessage($finalMessage),
            'json'    => [
                'model'      => config('services.anthropic.model', 'claude-sonnet-4-5'),
                'max_tokens' => 4096,
                'system'     => $this->systemPrompt,
                'messages'   => $messages,
            ],
        ]);

        $data = json_decode($response->getBody()->getContents(), true);
        return $data['content'][0]['text'] ?? '';
    }

    // ─── stream() ──────────────────────────────────────────────────────────
    public function stream(string|array $message, array $history, callable $onChunk, ?callable $heartbeat = null): string
    {
        $finalMessage = $this->buildResearchMessage($message, $heartbeat);

        $messages = array_merge($history, [
            ['role' => 'user', 'content' => $finalMessage],
        ]);

        $response = $this->client->post('/v1/messages', [
            'headers' => $this->headersForMessage($finalMessage),
            'stream'  => true,
            'json'    => [
                'model'      => config('services.anthropic.model', 'claude-sonnet-4-5'),
                'max_tokens' => 4096,
                'system'     => $this->systemPrompt,
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
                $heartbeat('analysing');
                $lastBeat = time();
            }
        }

        return $full;
    }

    public function getName(): string  { return 'research'; }
    public function getModel(): string { return config('services.anthropic.model', 'claude-sonnet-4-5'); }
}
