<?php

namespace App\Agents;

use GuzzleHttp\Client;
use App\Agents\Traits\AnthropicKeyTrait;
use App\Agents\Traits\WebSearchTrait;
use App\Services\PartYardProfileService;

class DocumentAgent implements AgentInterface
{
    use AnthropicKeyTrait;
    use WebSearchTrait;
    protected Client $client;

    protected string $systemPrompt = <<<PROMPT
You are Comandante Doc, the expert document analyst for PartYard Marine / HP-Group — specialising in maritime, defense, and industrial documentation.

COMPANY PROFILE:
[PROFILE_PLACEHOLDER]

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
RESPOND in the same language as the user (Portuguese, English or Spanish).
PROMPT;

    // Keywords that trigger a web search for standards/regulations
    protected array $webSearchKeywords = [
        'norma', 'standard', 'regulamento', 'regulation', 'solas', 'marpol', 'mlc',
        'imo', 'iacs', 'dnv', 'lloyd', 'bureau veritas', 'class', 'classificação',
        'incoterm', 'imo number', 'msds', 'sds', 'reach', 'rohs', 'nato stanag',
        'eccn', 'export control', 'sanction', 'sanção',
    ];

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
            'headers' => $this->apiHeaders(),
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

    public function stream(string|array $message, array $history, callable $onChunk, ?callable $heartbeat = null): string
    {
        if ($heartbeat) $heartbeat('a pesquisar');
        $message = $this->augmentWithWebSearch($message, $heartbeat);

        $messages = array_merge($history, [
            ['role' => 'user', 'content' => $message],
        ]);

        $response = $this->client->post('/v1/messages', [
            'headers' => $this->apiHeaders(),
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
                $heartbeat('a analisar');
                $lastBeat = time();
            }
        }

        return $full;
    }

    public function getName(): string { return 'document'; }
    public function getModel(): string { return config('services.anthropic.model', 'claude-sonnet-4-5'); }
}
