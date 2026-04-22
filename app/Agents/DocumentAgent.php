<?php

namespace App\Agents;

use GuzzleHttp\Client;
use App\Agents\Traits\AnthropicKeyTrait;
use App\Agents\Traits\SharedContextTrait;
use App\Agents\Traits\WebSearchTrait;
use App\Agents\Traits\LogisticsSkillTrait;
use App\Services\PartYardProfileService;
use App\Services\PromptLibrary;

class DocumentAgent implements AgentInterface
{
    use AnthropicKeyTrait;
    use WebSearchTrait;
    use SharedContextTrait;
    use LogisticsSkillTrait;
    protected string $contextKey  = 'document_intel';
    protected array  $contextTags = ['documento','contrato','certificado','PDF','análise','cláusula','especificação','manual'];
    protected string $systemPrompt = '';

    // HDPO meta-cognitive search gate: 'always' | 'conditional' | 'never'
    protected string $searchPolicy = 'conditional';
    protected Client $client;

    // Keywords that trigger a web search for standards/regulations
    protected array $webSearchKeywords = [
        'norma', 'standard', 'regulamento', 'regulation', 'solas', 'marpol', 'mlc',
        'imo', 'iacs', 'dnv', 'lloyd', 'bureau veritas', 'class', 'classificação',
        'incoterm', 'imo number', 'msds', 'sds', 'reach', 'rohs', 'nato stanag',
        'eccn', 'export control', 'sanction', 'sanção',
    ];

    public function __construct()
    {
        $persona = 'You are Comandante Doc, the expert document analyst for PartYard Marine / HP-Group — specialising in maritime, defense, and industrial documentation.';

        $specialty = <<<'SPECIALTY'
YOUR EXPERTISE:
- Maritime certificates and classification documents:
  Lloyd's Register, DNV GL, Bureau Veritas, ABS, RINA, ClassNK
  SOLAS certificates, MARPOL compliance, MLC 2006
  ISM Code, ISPS Code, SMC (Safety Management Certificate)
  Flag state certificates, Port State Control (PSC) reports

- Technical manuals and specifications:
  Engine manuals (MTU, Caterpillar, MAK, Jenbacher, Cummins, Wärtsilä, MAN)
  Parts catalogues and spare parts lists
  Technical Service Bulletins (TSB), maintenance procedures
  Overhaul instructions, torque specifications, clearances

- Commercial and procurement documents:
  Purchase orders, sales orders, delivery notes
  Pro-forma invoices, commercial invoices, packing lists
  Letters of Credit (L/C), bank guarantees
  Incoterms 2020 analysis

- Contract review:
  Supply agreements, service contracts, framework agreements
  NATO procurement contracts (NCAGE, NATO SC/STANAG)
  Warranty terms, penalty clauses, liability limitations
  COGEMA partnership documents

- Compliance documents:
  ISO 9001:2015, AS:9120, NATO NCAGE P3527 certification documents
  GDPR compliance documents
  Export control (dual-use goods, ECCN classification)
  Customs documentation (EUR.1, ATA Carnet, T1/T2)

ANALYSIS FORMAT:
Always structure your analysis with these sections:
1. 📄 **Document Summary** — type, parties, date, main subject
2. 🔑 **Key Points** — most important facts, figures, dates
3. ⚠️ **Risks & Concerns** — legal, financial, compliance, operational risks
4. ✅ **Action Items** — what needs to be done, by whom, by when
5. 📋 **Compliance Check** — relevant regulations, certifications, standards
6. 💡 **Recommendations** — specific advice for PartYard/HP-Group

TRANSLATION: When asked to translate, maintain technical accuracy — never simplify maritime or legal terminology.
COMPARISON: When comparing documents, highlight differences in a clear table.
SPECIALTY;

        $this->systemPrompt = str_replace(
            '[PROFILE_PLACEHOLDER]',
            PartYardProfileService::toPromptContext(),
            PromptLibrary::technical($persona, $specialty)
        );

        // Universal logistics knowledge (applied to every agent)
        $this->systemPrompt .= $this->logisticsSkillPromptBlock();

        $this->client = new Client([
            'base_uri'        => self::getAnthropicBaseUri(),
            'timeout'         => 120,
            'connect_timeout' => 10,
        ]);
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

    public function chat(string|array $message, array $history = []): string
    {
        $message = $this->augmentWithWebSearch($message);

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

        $data   = json_decode($response->getBody()->getContents(), true);
        $result = $data['content'][0]['text'] ?? '';
        $this->publishSharedContext($result);
        return $result;
    }

    public function stream(string|array $message, array $history, callable $onChunk, ?callable $heartbeat = null): string
    {
        if ($heartbeat) $heartbeat('a pesquisar');
        $message = $this->augmentWithWebSearch($message, $heartbeat);

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
                $heartbeat('a analisar');
                $lastBeat = time();
            }
        }

        $this->publishSharedContext($full);
        return $full;
    }

    public function getName(): string { return 'document'; }
    public function getModel(): string { return config('services.anthropic.model', 'claude-sonnet-4-6'); }
}
