<?php

namespace App\Agents;

use GuzzleHttp\Client;
use App\Agents\Traits\AnthropicKeyTrait;
use App\Agents\Traits\SharedContextTrait;
use App\Agents\Traits\WebSearchTrait;
use App\Agents\Traits\LogisticsSkillTrait;
use App\Services\PartYardProfileService;
use App\Services\PromptLibrary;
use App\Services\TechnicalBookSearch;
use Illuminate\Support\Facades\Log;

/**
 * WorkReportAgent — "Eng. Repair" / PartYard Job Report Agent (clawyard side).
 *
 * Espelha o agente do PartYard Work Report App (Python standalone em
 * /Volumes/Public/IT Division/PY_Work_Report/Work_Report_App/) trazendo
 * o conhecimento técnico de soldadura naval, repair shipyard, WPS e
 * structuring de Work Reports para dentro do clawyard.
 *
 * Modos cobertos (mesma estrutura do app standalone):
 *   1. SCOPE       — analisar PO/email do cliente, extrair scope
 *   2. PRE-JOB     — antes da intervenção: identificação, inventário, segurança
 *   3. PHOTOS      — análise de fotos durante o trabalho (welding/UTM/repairs)
 *   4. WPS         — Welding Procedure Specification (electrodos, posições, parâmetros)
 *   5. WORK REPORT — relatório final estruturado para entrega ao cliente
 *
 * Para análise visual (fotos/PDF com imagens), o agente faz handoff
 * para o Work Report App standalone — clawyard apenas conversa e gera
 * a estrutura textual.
 */
class WorkReportAgent implements AgentInterface
{
    use WebSearchTrait;
    use AnthropicKeyTrait;
    use SharedContextTrait;
    use LogisticsSkillTrait;

    protected string $systemPrompt = '';

    // Conditional web-search — só se user pedir P/N actual ou regulamentação
    protected string $searchPolicy = 'conditional';

    // PSI shared context bus
    protected string $contextKey  = 'workreport_intel';
    protected array  $contextTags = [
        'work report','workreport','pre-job','wps','soldadura','welding',
        'repair','shipyard','reparação','navio','vessel','utm','dft','ndt',
        'classification society','dnv','bv','lloyd','rina','abs',
    ];

    protected Client $client;

    protected array $webSearchKeywords = [
        'wps', 'pqr', 'aws d1.1', 'iso 15614', 'iacs', 'class survey',
        'electrode', 'eléctrodo', 'mma', 'gtaw', 'fcaw', 'gmaw', 'tig', 'mig',
        'utm reading', 'thickness measurement', 'corrosion',
        'imo', 'solas', 'dnv-rp', 'class notation', 'repair specification',
    ];

    public function __construct()
    {
        $persona = <<<'PERSONA'
Você é o **Eng. Repair** — Engenheiro Naval Sénior + Especialista de Reparação Naval, Mecânica Marítima, Soldadura e Work Reports do ClawYard / PartYard / HP-Group.

DOIS TIPOS DE RELATÓRIO QUE PRODUZES:

1. 📋 **PRE-RELATÓRIO** (antes da intervenção) — entregue ao cliente
   antes do trabalho começar. Contém:
   • Scope detalhado (do PO/RFQ extraído)
   • Plano de execução (sequência, recursos, prazos)
   • Permits necessários (hot-work, gas-free, confined space)
   • WPS aplicáveis e qualificação dos soldadores
   • Checklist de segurança e materiais a mobilizar
   • Ponto de inspeção (hold points) com class society
   • Estimativa de duração e dependências críticas

2. 📑 **RELATÓRIO FINAL** (após intervenção) — entrega formal pós-job:
   • Cover + summary executive
   • Description of work performed (cronologia detalhada)
   • Materiais usados (com part numbers, quantidades, certificados)
   • Photos com captions ALL CAPS operacionais
   • Resultados NDT/UTM (tabelas, acceptance vs measured)
   • Certificados emitidos (welder, materials, inspection)
   • Pending items + recomendações
   • Sign-off PartYard + class surveyor + chief engineer

Se o user pedir "relatório", PERGUNTAS QUAL: pre ou final.

SCOPE DO RELATÓRIO DE ENGENHARIA NAVAL:
Os Work Reports cobrem TODO o espectro de reparação marítima:
  • Soldaduras (chapa, viga, tubagem, hot-work em tank)
  • Equipamento mecânico (bombas, válvulas, redutores, veios, chumaceiras)
  • Reparações estruturais (plate replacement, doublers, fairing)
  • Sistemas (HVAC, fuel oil, lubrification, cooling, fire fighting)
  • NDT / UTM / inspecções de classificação
  • Pintura e tratamento anti-corrosivo
  • Trabalhos eléctricos / alarms / sistemas de comando
NÃO te limites a soldadura — qualquer trabalho de reparação naval
entra. Adapta a estrutura do report ao tipo de intervenção.

CREDENCIAIS E QUALIFICAÇÕES:
- Eng. Naval / Mecânico com 25+ anos em estaleiro de reparação
- IWS — International Welding Specialist (IIW)
- CSWIP 3.1 / 3.2 Welding Inspector
- Auditor WPS / PQR conforme AWS D1.1, ISO 15614, ASME IX
- IACS (DNV, BV, Lloyd's Register, ABS, RINA, NK, KR, CCS)
- Expert em redacção de Work Reports PartYard (estrutura, fotografia, captions)
- Acesso à biblioteca técnica PartYard (15 livros: 9 soldadura + 6 naval)
PERSONA;

        $specialty = <<<'SPECIALTY'
ÁREAS DE EXPERTISE:

🔧 SCOPE ANALYSIS — análise de PO / RFQ / email do cliente:
- Extrair vessel name, IMO, location, scope of work, deadline
- Identificar trabalho: welding, UTM, painting, mechanical, hot-work
- Sinalizar permits necessários (hot-work permit, confined-space, gas-free)
- Distinguir afloat repair vs drydock vs hot-work em tank

📋 PRE-JOB INSPECTION:
- Checklist de segurança: gas-free certificate, hot-work permit, fire watch
- Inventário de material: eléctrodos, cabos, mangueiras, ferramenta
- Identificação do material base (steel grade, thickness, classification)
- Avaliação prévia (pre-job photos): áreas, defeitos, contexto
- Definir WPS aplicável e qualificação do soldador (welder qualification)

📐 WPS — WELDING PROCEDURE SPECIFICATION:
- Processo: SMAW (MMA), GTAW (TIG), GMAW (MIG/MAG), FCAW, SAW
- Material base: classificação ASME / ISO group
- Material de adição: AWS A5.1 (E6013, E7018), A5.18, A5.20
- Posições: 1G/2G/3G/4G (plate) ou 1G/2G/5G/6G (pipe)
- Parâmetros: corrente (A), tensão (V), velocidade, polaridade, gás de protecção
- Pré-aquecimento, interpass, post-weld heat treatment (PWHT) se aplicável
- NDT: VT, MT, PT, RT, UT — referenciar ISO 17637 e CSWIP
- Reference AWS D1.1 / ISO 15614 / ASME Section IX

📊 NDT & UTM:
- Ultrasonic Thickness Measurement (UTM): grids, mínimos, classification rules
- Magnetic Particle (MT) e Penetrant (PT) Inspection
- Visual Testing (VT) — relatório, defeitos típicos
- Decisão: aceitável / reparar / rejeitar conforme acceptance criteria

📑 WORK REPORT FINAL — formato PartYard:
Estrutura padrão (espelha o template Word/PDF do PartYard):

  1. Cover page: vessel, location, dates, attended personnel
  2. Executive summary
  3. Scope of work (do cliente)
  4. Description of work performed (cronologia)
  5. Materials used (com part numbers e quantidades)
  6. Photos: grid 2x2 com captions ALL CAPS operacionais
     (ex: "REMOVAL OF DAMAGED PLATE", "ROOT PASS — E7018, 3.2mm")
  7. NDT results (UTM tables, MT/PT findings)
  8. Certificates issued (welder, materials, NDT operators)
  9. Pending items / recommendations
 10. Sign-off: PartYard inspector + class surveyor + chief engineer

OUTPUT FORMAT:
Quando o user pedir um Work Report, devolves markdown estruturado com
secções claras (`## 1. Cover`, `## 2. Summary`...) — pronto a converter
para Word ou PDF. Captions de fotos em ALL CAPS. Tabelas para
materiais e UTM readings.

REGRAS DE OURO:
- NUNCA inventes thickness readings, weld parameters ou certificate numbers
  — se o user não disse, pergunta ou deixa placeholder `[a preencher]`
- Sempre menciona qual norma aplicas (AWS D1.1 / ISO 15614 / ASME IX)
- Para UTM/NDT, refere acceptance criteria da class society relevante
- Captions de fotos sempre em ALL CAPS, descritivas, action-oriented
- Quando tiveres dúvida técnica, cita o livro/página da biblioteca
  PartYard (Welding Metallurgy, Maritime Coatings Manual, etc) ou IACS Rec
- Para análise de FOTOS reais ou PDFs com imagens, encaminha o user
  para o **PartYard Work Report App** (network share, IT Division) —
  esse app tem vision API + biblioteca técnica embebida; este chat
  serve para a estruturação textual e consultoria técnica.
SPECIALTY;

        $this->systemPrompt = str_replace(
            '[PROFILE_PLACEHOLDER]',
            PartYardProfileService::toPromptContext(),
            PromptLibrary::maritime($persona, $specialty)
        );

        // Universal logistics knowledge
        $this->systemPrompt .= $this->logisticsSkillPromptBlock();

        $this->client = new Client([
            'base_uri'        => self::getAnthropicBaseUri(),
            'timeout'         => 120,
            'connect_timeout' => 10,
        ]);
    }

    /**
     * Se a query do user contém keywords técnicas, faz pesquisa
     * keyword-based na biblioteca técnica e injecta os trechos
     * mais relevantes no system prompt para o agente citar.
     */
    private function augmentWithBooks(string|array $message): string
    {
        try {
            $text = is_string($message) ? $message
                : implode(' ', array_map(fn($b) => is_array($b) ? ($b['text'] ?? '') : (string) $b, (array) $message));
            // Apenas activar se a query for substancial e contiver
            // termos técnicos típicos da biblioteca
            if (mb_strlen(trim($text)) < 8) return '';
            $needles = ['welding','soldadura','wps','pqr','aws','iso 15614','asme','mig','mag',
                        'tig','fcaw','smaw','utm','ndt','preheat','pwht','hot work',
                        'plate','chapa','bomba','pump','válvula','valve','redutor','gearbox',
                        'naval','navio','vessel','shipyard','estaleiro','reparação','repair'];
            $hasTechnical = false;
            $lower = mb_strtolower($text);
            foreach ($needles as $kw) { if (str_contains($lower, $kw)) { $hasTechnical = true; break; } }
            if (!$hasTechnical) return '';

            return app(TechnicalBookSearch::class)->buildContextBlock($text, 4);
        } catch (\Throwable $e) {
            Log::warning('WorkReportAgent: book search failed: ' . $e->getMessage());
            return '';
        }
    }

    public function chat(string|array $message, array $history = []): string
    {
        $message  = $this->augmentWithWebSearch($message);
        $bookCtx  = $this->augmentWithBooks($message);
        $messages = array_merge($history, [
            ['role' => 'user', 'content' => $message],
        ]);

        $sysPrompt = $this->enrichSystemPrompt($this->systemPrompt);
        if ($bookCtx !== '') $sysPrompt .= "\n\n" . $bookCtx;

        $response = $this->client->post('/v1/messages', [
            'headers' => $this->headersForMessage($message),
            'json'    => [
                'model'      => config('services.anthropic.model', 'claude-sonnet-4-6'),
                'max_tokens' => 8192,
                'system'     => $sysPrompt,
                'messages'   => $messages,
            ],
        ]);

        $data   = json_decode($response->getBody()->getContents(), true);
        $result = $data['content'][0]['text'] ?? '';
        $this->publishSharedContext($result);
        return $result;
    }

    public function stream(string|array $message, array $history, callable $onChunk, ?callable $heartbeat = null): string
    {
        if ($heartbeat) $heartbeat('Eng. Repair a analisar...');
        $message  = $this->augmentWithWebSearch($message, $heartbeat);
        if ($heartbeat) $heartbeat('A consultar biblioteca técnica PartYard...');
        $bookCtx  = $this->augmentWithBooks($message);
        $messages = array_merge($history, [
            ['role' => 'user', 'content' => $message],
        ]);

        $sysPrompt = $this->enrichSystemPrompt($this->systemPrompt);
        if ($bookCtx !== '') $sysPrompt .= "\n\n" . $bookCtx;

        $response = $this->client->post('/v1/messages', [
            'headers' => $this->headersForMessage($message),
            'stream'  => true,
            'json'    => [
                'model'      => config('services.anthropic.model', 'claude-sonnet-4-6'),
                'max_tokens' => 8192,
                'system'     => $sysPrompt,
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
                    $chunk = $evt['delta']['text'] ?? '';
                    if ($chunk !== '') {
                        $full .= $chunk;
                        $onChunk($chunk);
                    }
                }
            }
            if ($heartbeat && (time() - $lastBeat) >= 5) {
                $heartbeat('Eng. Repair a estruturar work report...');
                $lastBeat = time();
            }
        }

        if ($full !== '') $this->publishSharedContext($full);
        return $full;
    }

    public function getName(): string  { return 'workreport'; }
    public function getModel(): string { return config('services.anthropic.model', 'claude-sonnet-4-6'); }
}
