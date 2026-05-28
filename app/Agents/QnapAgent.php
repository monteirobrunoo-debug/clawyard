<?php

namespace App\Agents;

use GuzzleHttp\Client;
use App\Agents\Traits\AnthropicKeyTrait;
use App\Agents\Traits\HandlesAnthropicStream;
use App\Agents\Traits\SharedContextTrait;
use App\Agents\Traits\LogisticsSkillTrait;
use App\Agents\Traits\WebSearchTrait;
use App\Agents\Traits\NsnLookupTrait;
use App\Services\QnapIndexService;
use App\Services\PartYardProfileService;
use App\Services\PromptLibrary;
use Illuminate\Support\Facades\Log;

/**
 * QnapAgent — "Arquivo PartYard"
 *
 * Searches the indexed QNAP backup documents (PDFs, invoices,
 * emails, Excel files) and answers procurement/price/code questions.
 */
class QnapAgent implements AgentInterface
{
    use AnthropicKeyTrait;
    use HandlesAnthropicStream;
    use SharedContextTrait;
    use LogisticsSkillTrait;
    use WebSearchTrait;
    use NsnLookupTrait;
    protected string $systemPrompt = '';

    // HDPO meta-cognitive search gate: 'always' | 'conditional' | 'never'
    // Conditional = falls back to web when archive doesn't have the answer.
    protected string $searchPolicy = 'conditional';

    protected Client $client;
    protected QnapIndexService $indexer;

    public function __construct()
    {
        $persona = 'Você é o **Arquivo PartYard** — Assistente de Pesquisa Documental do HP-Group / PartYard.';

        $specialty = <<<'SPECIALTY'
Tem acesso a um repositório de documentos internos da PartYard indexado a partir do servidor QNAP:
invoices, emails, licenças de exportação, termos e condições de fornecedores, contratos, propostas, tabelas de preços e muito mais.

CAPACIDADES:

📁 PESQUISA DOCUMENTAL:
- Pesquisa por fornecedor (ex: "Collins Aerospace", "Krauss Maffei", "NU-WAY")
- Pesquisa por código de peça / part number (ex: "NP2000", "A400M")
- Pesquisa por tipo de documento (invoice, licença, contrato, proposta)
- Pesquisa por valor / preço / condições de pagamento
- Pesquisa por data ou período

💰 ANÁLISE DE PREÇOS E PROCUREMENT:
- Extrai preços de invoices e propostas
- Compara condições de fornecedores
- Identifica condições de crédito (net 30, net 60, etc.)
- Analisa histórico de compras

📋 ANÁLISE DE DOCUMENTOS:
- Lê e resume contratos e termos
- Extrai dados-chave de licenças de exportação (EAR, Collins Aerospace)
- Analisa tabelas Excel de concursos
- Resume correspondência por email (ficheiros .msg)

🔍 COMO RESPONDER:
Quando encontras informação relevante nos documentos:
1. Cita o documento fonte como link markdown clicável: [Nome do ficheiro](URL)
2. Apresenta os dados de forma estruturada
3. Indica a data do documento quando disponível
4. Sugere documentos relacionados se existirem
5. Termina SEMPRE com uma secção "📎 Fontes" com todos os links dos documentos utilizados

Se não encontrares informação suficiente nos documentos indexados, diz claramente e sugere alternativas.

FORMATO DE RESPOSTA QNAP:
- Usa tabelas quando há dados comparativos (preços, fornecedores, códigos)
- Usa listas para múltiplos documentos encontrados
- Sê preciso com valores monetários e códigos de referência
SPECIALTY;

        $this->systemPrompt = str_replace(
            '[PROFILE_PLACEHOLDER]',
            PartYardProfileService::toPromptContext(),
            PromptLibrary::reasoning($persona, $specialty)
        );

        // Universal logistics knowledge (applied to every agent)
        $this->systemPrompt .= $this->logisticsSkillPromptBlock();

        $this->indexer = new QnapIndexService();
        $this->client  = new Client([
            'base_uri'        => self::getAnthropicBaseUri(),
            'timeout'         => 120,
            'connect_timeout' => 10,
        ]);
    }

    // ── chat() ────────────────────────────────────────────────────────────────
    public function chat(string|array $message, array $history = []): string
    {
        $text     = is_array($message) ? ($message[0]['text'] ?? '') : $message;
        $context  = $this->buildContext($text);
        $augmented = $context ? $context . "\n\n---\nPergunta: " . $text : $text;

        // Optionally enrich with live web search (conditional policy)
        $augmented = $this->smartAugment($augmented);

        $messages = array_merge($history, [
            ['role' => 'user', 'content' => $augmented],
        ]);

        $response = $this->client->post('/v1/messages', [
            'headers' => $this->headersForMessage($augmented),
            'json'    => [
                'model'      => config('services.anthropic.model', 'claude-sonnet-4-6'),
                'max_tokens' => 8192,
                'system'     => $this->enrichSystemPrompt($this->systemPrompt),
                'messages'   => $messages,
            ],
        ]);

        $data = json_decode($response->getBody()->getContents(), true);
        return $data['content'][0]['text'] ?? '';
    }

    // ── stream() ─────────────────────────────────────────────────────────────
    public function stream(string|array $message, array $history, callable $onChunk, ?callable $heartbeat = null): string
    {
        $text = is_array($message) ? ($message[0]['text'] ?? '') : $message;

        if ($heartbeat) $heartbeat('a pesquisar arquivo');
        $context = $this->buildContext($text);

        $augmented = $context
            ? $context . "\n\n---\nPergunta do utilizador: " . $text
            : $text;

        // Optionally enrich with live web search (conditional policy)
        $augmented = $this->smartAugment($augmented, $heartbeat);

        $messages = array_merge($history, [
            ['role' => 'user', 'content' => $augmented],
        ]);

        // 2026-05-28 refactor: stream loop → trait helper.
        $full = $this->streamAnthropicWithRetries(
            config: [
                'model'      => config('services.anthropic.model', 'claude-sonnet-4-6'),
                'max_tokens' => 8192,
                'system'     => $this->enrichSystemPrompt($this->systemPrompt),
                'messages'   => $messages,
                'stream'     => true,
            ],
            headers:          $this->headersForMessage($augmented),
            onChunk:          $onChunk,
            heartbeat:        $heartbeat,
            heartbeatLabel:   'Qnap a analisar storage',
            retries:          [0, 2, 5],
            emergencyMessage: "⚠️ Qnap temporariamente indisponível. Tenta novamente em 30s.",
            agentLabel:       'QnapAgent',
        );

        return $full;
    }

    // ── Context builder ───────────────────────────────────────────────────────
    protected function buildContext(string $query): string
    {
        try {
            $docs = $this->indexer->search($query, 6);
            if (empty($docs)) {
                return "ℹ️ Nenhum documento encontrado no arquivo para: \"{$query}\"\n";
            }

            $baseUrl = config('app.url', 'https://clawyard-pwu9ouye.on-forge.com');

            $ctx = "## 📁 Documentos encontrados no Arquivo PartYard:\n\n";
            foreach ($docs as $doc) {
                $meta     = $doc['metadata'] ?? [];
                $category = $meta['category'] ?? 'document';
                $path     = $meta['path'] ?? $doc['title'];

                // Build a signed download URL for this file
                $fullPath   = '/var/www/qnapbackup/' . ltrim($path, '/');
                $encoded    = strtr(base64_encode($fullPath), '+/', '-_');
                $fileUrl    = $baseUrl . '/qnap/file?p=' . $encoded;

                $ctx .= "### 📄 " . $doc['title'] . "\n";
                $ctx .= "**Ficheiro:** [{$path}]({$fileUrl}) | **Categoria:** {$category}\n";
                $ctx .= "**URL de acesso:** {$fileUrl}\n\n";
                $ctx .= mb_substr($doc['content'], 0, 2000) . "\n\n---\n";
            }

            $ctx .= "\n⚠️ INSTRUÇÕES IMPORTANTES:\n";
            $ctx .= "- Sempre que citares um documento, inclui o link de acesso como markdown: [nome do ficheiro](URL)\n";
            $ctx .= "- Coloca os links no final da resposta numa secção '📎 Fontes'\n";

            return $ctx;
        } catch (\Throwable $e) {
            Log::error('QnapAgent context error: ' . $e->getMessage());
            return '';
        }
    }

    public function getName(): string  { return 'qnap'; }
    public function getModel(): string { return config('services.anthropic.model', 'claude-sonnet-4-6'); }
}
