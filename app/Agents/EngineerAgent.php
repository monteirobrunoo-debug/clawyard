<?php

namespace App\Agents;

use GuzzleHttp\Client;
use App\Agents\Traits\AnthropicKeyTrait;
use App\Agents\Traits\SharedContextTrait;
use App\Agents\Traits\WebSearchTrait;
use App\Services\PartYardProfileService;
use App\Services\PromptLibrary;
use Illuminate\Support\Facades\Log;

/**
 * EngineerAgent — "Eng. Victor"
 *
 * Director de I&D e Desenvolvimento de Produto do HP-Group / PartYard.
 * Analisa o briefing do Renato + descobertas científicas/patentes e produz
 * planos de desenvolvimento de novos equipamentos e produtos para a PartYard.
 */
class EngineerAgent implements AgentInterface
{
    use WebSearchTrait;
    use AnthropicKeyTrait;
    use SharedContextTrait;
    protected string $systemPrompt = '';

    // HDPO meta-cognitive search gate: 'always' | 'conditional' | 'never'
    protected string $searchPolicy = 'conditional';

    // PSI shared context bus — what this agent publishes
    protected string $contextKey  = 'rd_intel';
    protected array  $contextTags = ['I&D','R&D','inovação','TRL','CAPEX','patente','equipamento','PartYard','protótipo'];

    protected Client $client;

    protected array $webSearchKeywords = [
        'fabricar', 'manufacture', 'construir', 'build', 'desenvolver', 'develop',
        'protótipo', 'prototype', 'R&D', 'I&D', 'pesquisa', 'research',
        'equipamento', 'equipment', 'produto', 'product', 'componente', 'component',
        'patente', 'patent', 'licença', 'license', 'tecnologia', 'technology',
        'fornecedor', 'supplier', 'OEM', 'aftermarket', 'engenharia', 'engineering',
        'certificação', 'certification', 'norma', 'standard', 'ISO', 'MIL-SPEC', 'DO-160',
        'lubricant', 'lubrificante', 'ARMITE', 'MIL-PRF', 'AMS', 'formulation',
        'simulation', 'simulação', 'training system', 'sistema de treino',
        'cybersecurity', 'cibersegurança', 'SETQ', 'software', 'hardware',
        'aviation MRO', 'MRO', 'overhaul', 'repair', 'NDT', 'testing',
        'naval', 'maritime', 'marítimo', 'offshore', 'defesa', 'defense',
    ];

    public function __construct()
    {
        $persona = <<<'PERSONA'
Você é o **Eng. Victor Matos** — Director de Investigação & Desenvolvimento (I&D) e Desenvolvimento de Produto do HP-Group / PartYard.

CREDENCIAIS E QUALIFICAÇÕES:
- Doutoramento em Engenharia Mecânica e Aeroespacial (IST Lisboa + MIT)
- Especialista em MRO (Maintenance, Repair & Overhaul) de aeronaves militares e civis
- Certificado FAA A&P (Airframe & Powerplant), EASA Part-66 Cat. B2 (Avionics)
- Engenheiro de Produto sénior com +20 anos em defesa, aeronáutica e marinha
- Especialista em certificações: MIL-SPEC, DO-160G, MIL-STD-461, AS9100, AS9120, ISO 9001
- Expert em transferência de tecnologia e licenciamento OEM (NATO Supply Chain)
- Especialista em desenvolvimento de lubrificantes MIL-SPEC (ARMITE)
- Conhecimento profundo em sistemas de simulação de treino militar e civil
- Experiência em cibersegurança aplicada a sistemas embarcados (SETQ)
PERSONA;

        $specialty = <<<'SPECIALTY'
PORTFÓLIO DE DESENVOLVIMENTO PartYard — CAPACIDADES ACTUAIS E ALVOS:

🛩️ AEROSPACE MRO (PartYard Military / PartYard Defense):
- Plataformas servidas: AH-64 Apache, CH-47 Chinook, C-130 Hercules, F-16 Fighting Falcon, UH-60 Black Hawk
- Componentes actuais: rotor blades, transmissions, landing gear, avionics, hydraulics, engine accessories
- Certificações: NCAGE P3527 (NATO), AS:9120
- Alvos de desenvolvimento: kits de reparação certificados, ferramentas especiais de MRO, bancos de teste

🚗 SISTEMAS TERRESTRES (Land Systems):
- Viaturas militares: Pandur, Leopard 2, M113, Land Rover Defender modificado
- Componentes: power packs, suspensão, sistemas de comunicação, transmissões
- Alvos: sub-sistemas de mobilidade, kits de actualização (retrofit/upgrade kits)

🎯 SIMULAÇÃO E TREINO (Training Simulation):
- Simuladores de voo para aeronaves militares (full-mission simulator, part-task trainer)
- Simuladores de condução de veículos militares
- Sistemas de treino em armas pequenas e artilharia
- Software de missão táctica e debriefing
- Alvos: módulos de IA para avaliação de performance, cenários de combate sintéticos

🛢️ LUBRIFICANTES ARMITE (ARMITE Lubricants):
- Lubrificantes aeroespaciais e militares: MIL-PRF-23827, MIL-PRF-32033, MIL-PRF-81322, AMS 2518
- Linha de lubrificantes navais e industriais
- Alvos de I&D: formulações bio-based (verde), alta temperatura, nanocomposites, anti-corrosão extrema

🔐 CIBERSEGURANÇA (SETQ):
- Sistemas de cibersegurança para infraestruturas críticas
- SCADA/ICS security para instalações navais e militares
- Gestão de identidade e acesso (IAM) para sistemas NATO
- Alvos: produtos de hardware de segurança (HSM), sensores IoT seguros para plataformas militares

⚙️ INDÚSTRIA (IndYard):
- Soluções para a indústria de peças e manutenção
- Sistemas de gestão de manutenção (CMMS/ERP integrado)
- Alvos: plataforma digital de supply chain para MRO aeronáutico e naval

METODOLOGIA DE ANÁLISE DE DESENVOLVIMENTO:

Quando pedido para planear o desenvolvimento de novo equipamento ou produto:

1. **🔍 ANÁLISE DA OPORTUNIDADE**:
   - Que necessidade de mercado resolve?
   - Que concorrentes existem? Qual a diferenciação PartYard?
   - Qual o TAM (Total Addressable Market)?
   - Existe demand signal (contratos NATO, programas de aquisição, concursos públicos)?

2. **🔬 VIABILIDADE TÉCNICA**:
   - Tecnologia base disponível internamente ou a adquirir?
   - Parceiros técnicos necessários (universidades, OEMs, laboratórios)?
   - Requisitos de certificação (MIL-SPEC, FAA, EASA, NATO STANAG)?
   - Propriedade intelectual: desenvolver, licenciar ou design-around?

3. **💰 ANÁLISE FINANCEIRA**:
   - CAPEX estimado por fase (concept → prototype → certification → production)
   - OPEX de manutenção e suporte pós-venda
   - Break-even e ROI esperado
   - Financiamento: COMPETE 2030, Portugal 2030, EDA (European Defence Agency), NATO NSPA?

4. **⏱️ ROADMAP DE DESENVOLVIMENTO**:
   - Fase 0: Concept Study (1-3 meses)
   - Fase 1: Feasibility & Preliminary Design (3-6 meses)
   - Fase 2: Detailed Design & Prototype (6-18 meses)
   - Fase 3: Testing & Certification (12-24 meses)
   - Fase 4: Production & Commercialisation (ongoing)

5. **🤝 ECOSSISTEMA DE PARCEIROS**:
   - OEMs para licenciamento (Boeing Defense, Sikorsky, Lockheed, Airbus DS)
   - Institutos de I&D (IST, INEGI, INESC, DLR, NLR, ONERA)
   - Clientes âncora para co-desenvolvimento (FAP, Marinha, Exército Português, NATO NSPA)
   - Fornecedores de matéria-prima certificada (AMS, MIL-SPEC approved)

6. **⚠️ GESTÃO DE RISCOS TÉCNICOS**:
   - TRL (Technology Readiness Level) actual e alvo
   - Riscos de certificação e timeline
   - Dependência de fornecedores únicos (single-source risk)
   - Risco de obsolescência e DMSMS (Diminishing Manufacturing Sources)

FORMAT DE RESPOSTA PADRÃO:
Quando apresentares um plano de desenvolvimento, usa SEMPRE esta estrutura:

---
## 🚀 PLANO DE DESENVOLVIMENTO — [Nome do Produto/Equipamento]
**Subsidiária Responsável**: [PartYard Military / ARMITE / SETQ / etc.]
**TRL Actual**: X/9 | **TRL Alvo**: X/9

### 🔍 Oportunidade de Mercado
### 🔬 Conceito Técnico & Diferenciação
### 💰 Estimativa de Investimento
| Fase | Duração | CAPEX | Fonte de Financiamento |
### ⏱️ Roadmap
### 🤝 Parceiros Estratégicos
### ⚠️ Riscos Principais
### 📋 Próximos 90 Dias — Quick Wins
---

REGRAS DE ENGENHARIA:
- Baseia SEMPRE as especificações técnicas em normas reais (MIL-SPEC, STANAG, DO-160, EASA CS)
- Nunca subestimas os custos de certificação — são SEMPRE o maior bottleneck
- Identifica SEMPRE se existe financiamento europeu ou nacional disponível (PT2030, H2020, EDF)
- Referencia SEMPRE descobertas científicas e patentes relevantes fornecidas no contexto
SPECIALTY;

        $this->systemPrompt = str_replace(
            '[PROFILE_PLACEHOLDER]',
            PartYardProfileService::toPromptContext(),
            PromptLibrary::technical($persona, $specialty)
        );

        $this->client = new Client([
            'base_uri'        => 'https://api.anthropic.com',
            'timeout'         => 180,
            'connect_timeout' => 10,
        ]);
    }

    // ─── Pull strategic context from DB ────────────────────────────────────
    protected function buildStrategicContext(): string
    {
        $sections = [];

        // Latest briefing from Renato
        try {
            $briefing = \App\Models\Report::where('type', 'briefing')
                ->orderBy('created_at', 'desc')
                ->first();

            if ($briefing) {
                $age     = $briefing->created_at->diffForHumans();
                $excerpt = substr(strip_tags($briefing->content ?? ''), 0, 3000);
                $sections[] = "## ÚLTIMO BRIEFING DO RENATO (Estratega) — {$age}:\n{$excerpt}";
            }
        } catch (\Throwable $e) {
            Log::warning('EngineerAgent: failed to load briefing — ' . $e->getMessage());
        }

        // Recent discoveries (papers + patents)
        try {
            $discoveries = \App\Models\Discovery::where('created_at', '>=', now()->subDays(7))
                ->orderBy('relevance_score', 'desc')
                ->limit(15)
                ->get();

            if ($discoveries->isNotEmpty()) {
                $lines = ["## DESCOBERTAS RECENTES (papers/patentes — últimos 7 dias):"];
                foreach ($discoveries as $d) {
                    $lines[] = "- [{$d->source}] {$d->title} | Score: {$d->relevance_score}/10";
                    if ($d->summary)        $lines[] = "  → {$d->summary}";
                    if ($d->opportunity)    $lines[] = "  → Oportunidade: {$d->opportunity}";
                    if ($d->recommendation) $lines[] = "  → Recomendação: {$d->recommendation}";
                }
                $sections[] = implode("\n", $lines);
            }
        } catch (\Throwable $e) {
            Log::warning('EngineerAgent: failed to load discoveries — ' . $e->getMessage());
        }

        // Recent engineer reports
        try {
            $reports = \App\Models\Report::where('type', 'engineer')
                ->where('created_at', '>=', now()->subDays(30))
                ->orderBy('created_at', 'desc')
                ->limit(3)
                ->get();

            if ($reports->isNotEmpty()) {
                $lines = ["## PLANOS DE DESENVOLVIMENTO ANTERIORES (último mês):"];
                foreach ($reports as $r) {
                    $excerpt = substr(strip_tags($r->content ?? ''), 0, 500);
                    $lines[] = "\n### {$r->title}\n{$excerpt}...";
                }
                $sections[] = implode("\n", $lines);
            }
        } catch (\Throwable $e) {
            Log::warning('EngineerAgent: failed to load engineer reports — ' . $e->getMessage());
        }

        if (empty($sections)) {
            return '';
        }

        return "\n\n=== CONTEXTO ESTRATÉGICO ===\n\n" . implode("\n\n---\n\n", $sections) . "\n\n=== FIM DO CONTEXTO ===\n\n";
    }

    // ─── chat() ────────────────────────────────────────────────────────────
    public function chat(string|array $message, array $history = []): string
    {
        $context = $this->buildStrategicContext();
        $augmented = is_array($message)
            ? $message
            : $context . $message;

        $augmented = $this->augmentWithWebSearch($augmented);
        $augmented = $this->sanitizeForApi($augmented);
        $history   = array_map(fn($m) => $this->sanitizeForApi($m), $history);

        $messages = array_merge($history, [
            ['role' => 'user', 'content' => $augmented],
        ]);

        $response = $this->client->post('/v1/messages', [
            'headers' => $this->headersForMessage($augmented),
            'json'    => [
                'model'      => config('services.anthropic.model_opus', 'claude-opus-4-7'),
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
        if ($heartbeat) $heartbeat('a carregar contexto estratégico');

        // 1. Pull strategic context (briefing + discoveries)
        $context = $this->buildStrategicContext();

        // 2. Prepend context to message (text only — keep array messages intact)
        $augmented = is_string($message) && $context
            ? $context . $message
            : $message;

        // 3. Web search for relevant technology/suppliers (text only)
        if (is_string($augmented)) {
            if ($heartbeat) $heartbeat('a pesquisar tecnologia');
            $augmented = $this->augmentWithWebSearch($augmented, $heartbeat);
        }

        // 4. SECURITY/STABILITY: sanitise every string that Guzzle will
        //    json_encode before it reaches api.anthropic.com. Malformed
        //    UTF-8 slipping in from DB-sourced briefings/discoveries (old
        //    Windows-1252 copy-pastes, truncated multibyte sequences)
        //    used to crash the whole request at `guzzle/src/Utils.php:298`.
        $augmented = $this->sanitizeForApi($augmented);
        $history   = array_map(fn($m) => $this->sanitizeForApi($m), $history);

        $messages = array_merge($history, [
            ['role' => 'user', 'content' => $augmented],
        ]);

        if ($heartbeat) $heartbeat('a activar raciocínio técnico avançado 🔩');

        $response = $this->client->post('/v1/messages', [
            'headers' => $this->headersForMessage($augmented),
            'stream'  => true,
            'json'    => [
                'model'      => config('services.anthropic.model_opus', 'claude-opus-4-7'),
                'max_tokens' => 16000,
                'thinking'   => ['type' => 'enabled', 'budget_tokens' => 5000],
                'system'     => $this->sanitizeForApi($this->enrichSystemPrompt($this->systemPrompt)),
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
                $heartbeat('Eng. Victor a planear');
                $lastBeat = time();
            }
        }

        $this->publishSharedContext($full);
        return $full;
    }

    public function getName(): string  { return 'engineer'; }
    public function getModel(): string { return config('services.anthropic.model_opus', 'claude-opus-4-7'); }

    /**
     * Re-encode every string inside the message tree as valid UTF-8 so
     * Guzzle's internal json_encode never sees a malformed byte. Without
     * this, a single bad byte in an auto-loaded briefing rejects the
     * entire /v1/messages request and the user sees an empty stream.
     */
    private function safeUtf8(string $s): string
    {
        $out = @mb_convert_encoding($s, 'UTF-8', 'UTF-8');
        $out = @iconv('UTF-8', 'UTF-8//IGNORE', $out ?? $s);
        return $out !== false ? $out : '';
    }

    private function sanitizeForApi(mixed $value): mixed
    {
        if (is_string($value)) return $this->safeUtf8($value);
        if (is_array($value))  return array_map(fn($v) => $this->sanitizeForApi($v), $value);
        return $value;
    }
}
