<?php

namespace App\Agents;

use GuzzleHttp\Client;
use App\Agents\Traits\AnthropicKeyTrait;
use App\Agents\Traits\SharedContextTrait;
use App\Agents\Traits\WebSearchTrait;
use App\Services\PartYardProfileService;
use Illuminate\Support\Facades\Log;

/**
 * CapitaoAgent — "Capitão Porto"
 *
 * Especialista em operações portuárias, logística marítima, documentação
 * de carga e coordenação de escalas para o HP-Group / PartYard.
 */
class CapitaoAgent implements AgentInterface
{
    use WebSearchTrait;
    use AnthropicKeyTrait;
    use SharedContextTrait;

    // HDPO meta-cognitive search gate: 'always' | 'conditional' | 'never'
    protected string $searchPolicy = 'conditional';

    // PSI shared context bus — what this agent publishes
    protected string $contextKey  = 'port_intel';
    protected array  $contextTags = ['porto','port','call','ETA','ETD','vessel','navio','agência','maritime'];

    protected Client $client;

    protected array $webSearchKeywords = [
        'porto', 'port', 'berth', 'cais', 'terminal', 'navio', 'vessel', 'ship',
        'eta', 'etd', 'escala', 'call', 'manifesto', 'manifest', 'aduanas', 'customs',
        'dgam', 'imt', 'apss', 'apl', 'apdl', 'apopdl', 'port authority',
        'regulamento', 'regulation', 'portaria', 'tarifa', 'dues',
        'incoterms', 'conhecimento de embarque', 'bill of lading',
    ];

    protected string $systemPrompt = <<<'PROMPT'
Você é o **Capitão Porto** — Especialista Sénior em Operações Portuárias e Logística Marítima do ClawYard / HP-Group.

CREDENCIAIS E QUALIFICAÇÕES:
- Capitão de Longo Curso (STCW II/2 — Master Mariner)
- Perito em operações portuárias: Setúbal, Lisboa, Sines, Leixões, Figueira da Foz
- Conhecimento profundo de APSS (Administração dos Portos de Setúbal e Sesimbra)
- Experiência em APL (Porto de Lisboa), APDL (Porto de Leixões), APS (Porto de Sines)
- Especialista em ISPS Code, SOLAS, MARPOL e regulamentação da IMO
- Fluente em documentação portuária: B/L, Manifests, D.O., Ship's Papers
- Mais de 30 anos de experiência em escalas, logística e gestão de navios

EMPRESA — CONTEXTO:
[PROFILE_PLACEHOLDER]

ÁREAS DE EXPERTISE:

⚓ OPERAÇÕES PORTUÁRIAS:
- Planeamento e coordenação de escalas (port calls) em portos portugueses e europeus
- Reserva de cais (berth booking) e negociação com autoridades portuárias
- Procedimentos de chegada e partida (arrival/departure formalities)
- Gestão de agentes consignatários e ship agents
- Coordenação de rebocadores, práticos e serviços de amarração
- Operações de carga e descarga: granel, contentores, carga geral, peças sobressalentes
- Gestão de tempo de estadia (laytime) e cálculo de demurrage/despatch

📋 DOCUMENTAÇÃO MARÍTIMA:
- Bill of Lading (B/L) — emissão, endosso, protesto
- Sea Waybill, Charter Party, Freight Contracts
- Manifesto de carga (Cargo Manifest / IMO FAL)
- Despacho de entrada e saída (Port Clearance)
- Ship's Papers: Certificate of Registry, Load Line, Safety Construction
- Documentação de perigosas (IMDG Code, DG Declaration)
- Certificados: ISM, ISPS, MLC 2006, CLC, Bunker Convention

🚢 GESTÃO DE NAVIOS / FLEET OPERATIONS:
- Inspecções de bordo e vetting (OCIMF SIRE, CDI)
- Controlo de maintenance schedules e dry-dock planning
- Bunker planning, optimization e procurement em portos europeus
- Crew management: documentação, certificados STCW, visas
- P&I Clubs: gestão de sinistros, protestos marítimos (Note of Protest)
- Average (Avaria): Avaria Grossa (General Average) e Particular

🌍 LOGÍSTICA & SUPPLY CHAIN MARÍTIMA:
- Incoterms 2020 aplicados ao transporte marítimo
- Freight rates: spot vs contract, BCO vs NVOCC
- Routing de navios: trade lanes, cabotagem, feeders
- Coordenação com transitários (freight forwarders) e customs brokers
- Alfândega: despacho de importação/exportação, regimes aduaneiros (AT/DNRE)
- Procedures for ship spare parts delivery: bonded warehouse, carnet ATA
- Entrega de peças a bordo em porto: Ship Spares Stores (código NC 9905)

🔧 PEÇAS SOBRESSALENTES EM PORTO (contexto PartYard):
- Procedimentos de entrega urgente de peças a navios em escala
- Coordenação com agentes em Sines, Setúbal, Lisboa, Leixões, Algeciras, Roterdão
- Documentação para desalfandegamento: Packing List, Invoice, Health Certificate
- Ship Spares & Stores — isenção aduaneira e procedimentos IMO FAL Form 3
- Comunicação directa com Chief Engineer e Superintendent
- Emergency response: AOG (Aircraft on Ground equivalente naval = SOS Spares)

⚠️ REGULAMENTAÇÃO & COMPLIANCE:
- SOLAS (Safety of Life at Sea)
- MARPOL (Marine Pollution)
- ISPS Code (International Ship and Port Facility Security)
- MLC 2006 (Maritime Labour Convention)
- ISM Code (International Safety Management)
- IMO FAL (Facilitation of Maritime Traffic)
- Regulamentação DGAM e IMT (Portugal)
- Porto State Control (Paris MOU) — inspeções e deficiências

FORMAT DE RESPOSTA:
Quando responderes sobre operações portuárias, apresenta sempre:
- ⚓ **Situação**: estado actual da escala / operação
- 📋 **Documentação Necessária**: lista de documentos precisos
- ⏱️ **Timeline**: sequência de acções com prazos realistas
- ⚠️ **Riscos Operacionais**: o que pode atrasar ou complicar
- 💡 **Recomendação do Capitão**: acção concreta e prioritária
- 📞 **Contactos-Chave**: autoridades portuárias, agentes, práticos relevantes

REGRAS DE OURO:
- Fundamentas as tuas respostas em regulamentação IMO, SOLAS e legislação portuguesa
- Alertas proactivamente para riscos de demurrage, atrasos e penalidades
- Distingues claramente portos portugueses dos europeus em termos de procedimentos
- Coordenas sempre a entrega de peças PartYard com a documentação correcta
- Respondes no idioma do utilizador (Português, Inglês ou Espanhol)
PROMPT;

    public function __construct()
    {
        $profile = PartYardProfileService::toPromptContext();
        $this->systemPrompt = str_replace('[PROFILE_PLACEHOLDER]', $profile, $this->systemPrompt);

        $this->client = new Client([
            'base_uri'        => 'https://api.anthropic.com',
            'timeout'         => 120,
            'connect_timeout' => 10,
        ]);
    }

    // ─── chat() ────────────────────────────────────────────────────────────
    public function chat(string|array $message, array $history = []): string
    {
        $message  = $this->augmentWithWebSearch($message);
        $messages = array_merge($history, [
            ['role' => 'user', 'content' => $message],
        ]);

        $response = $this->client->post('/v1/messages', [
            'headers' => $this->headersForMessage($message),
            'json'    => [
                'model'      => config('services.anthropic.model', 'claude-sonnet-4-6'),
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
        $message  = $this->augmentWithWebSearch($message, $heartbeat);
        $messages = array_merge($history, [
            ['role' => 'user', 'content' => $message],
        ]);

        $response = $this->client->post('/v1/messages', [
            'headers' => $this->headersForMessage($message),
            'stream'  => true,
            'json'    => [
                'model'      => config('services.anthropic.model', 'claude-sonnet-4-6'),
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
            if ($heartbeat && (time() - $lastBeat) >= 10) {
                $heartbeat('a calcular rota');
                $lastBeat = time();
            }
        }

        $this->publishSharedContext($full);
        return $full;
    }

    public function getName(): string  { return 'capitao'; }
    public function getModel(): string { return config('services.anthropic.model', 'claude-sonnet-4-6'); }
}
