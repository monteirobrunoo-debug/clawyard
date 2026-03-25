<?php

namespace App\Http\Controllers;

use App\Agents\AgentManager;
use App\Models\Conversation;
use App\Models\Document;
use App\Services\RagService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class NvidiaController extends Controller
{
    public function __construct(
        protected AgentManager $agentManager,
        protected RagService $ragService,
    ) {}

    /**
     * POST /api/chat
     */
    public function chat(Request $request): JsonResponse
    {
        $request->validate([
            'message'    => 'required|string|min:1|max:4096',
            'agent'      => 'nullable|string|in:auto,orchestrator,nvidia,claude,sales,support,email,sap,document,maritime,cyber,aria,quantum,finance,research,capitao,acingov',
            'session_id' => 'nullable|string|max:64|regex:/^[a-zA-Z0-9_\-]+$/',
            'image'      => 'nullable|string|max:5242880', // max ~4MB base64
        ]);

        $agentName = $request->input('agent', 'auto');
        $message   = $request->input('message');

        // Always bind session to authenticated user — never trust raw client session_id
        $userId    = auth()->id();
        $clientSid = $request->input('session_id');
        $sessionId = $clientSid
            ? 'u' . $userId . '_' . $clientSid   // prefix with user ID to prevent hijacking
            : 'u' . $userId . '_' . bin2hex(random_bytes(16));

        $imageB64  = $request->input('image');

        try {
            // Load or create conversation (Memory)
            $conversation = Conversation::firstOrCreate(
                ['session_id' => $sessionId],
                ['channel' => 'web', 'agent' => $agentName]
            );

            // Get conversation history (Memory)
            $history = $conversation->history;

            // RAG: augment message with relevant documents
            $augmentedMessage = $this->ragService->augmentMessage($message);

            // Multimodal: add image to message if provided
            if ($imageB64) {
                $augmentedMessage = [
                    ['type' => 'text', 'text' => $augmentedMessage],
                    ['type' => 'image', 'source' => [
                        'type'       => 'base64',
                        'media_type' => 'image/jpeg',
                        'data'       => $imageB64,
                    ]],
                ];
            }

            // Save user message (Memory)
            $conversation->messages()->create([
                'role'    => 'user',
                'content' => $message,
            ]);

            // Multi-agent orchestration mode
            if ($agentName === 'orchestrator') {
                $results = $this->agentManager->orchestrate($message, $history);

                $reply = $this->combineReplies($results);

                $conversation->messages()->create([
                    'role'    => 'assistant',
                    'agent'   => 'orchestrator',
                    'content' => $reply,
                ]);

                return response()->json([
                    'success'    => true,
                    'mode'       => 'orchestrator',
                    'results'    => $results,
                    'reply'      => $reply,
                    'agents'     => array_column($results, 'agent'),
                    'session_id' => $sessionId,
                ]);
            }

            // Single agent mode
            $agent = $agentName === 'auto'
                ? $this->agentManager->route($message)
                : $this->agentManager->agent($agentName);

            $agentLog = [
                ['icon' => '🔍', 'text' => 'A analisar a mensagem...', 'done' => true],
                ['icon' => '📚', 'text' => 'A consultar base de conhecimento (RAG)...', 'done' => true],
                ['icon' => '🤖', 'text' => 'Agente ' . ucfirst($agent->getName()) . ' a processar...', 'done' => true],
            ];

            $reply = $agent->chat($augmentedMessage, $history);

            $agentLog[] = ['icon' => '✅', 'text' => 'Resposta pronta', 'done' => true];

            // Save assistant reply (Memory)
            $conversation->messages()->create([
                'role'    => 'assistant',
                'agent'   => $agent->getName(),
                'content' => $reply,
            ]);

            return response()->json([
                'success'      => true,
                'mode'         => 'single',
                'reply'        => $reply,
                'agent'        => $agent->getName(),
                'model'        => $agent->getModel(),
                'session_id'   => $sessionId,
                'agent_log'    => $agentLog,
                'suggestions'  => $this->getSuggestions($agent->getName(), $message),
            ]);

        } catch (\Exception $e) {
            // Log full error server-side, never expose to client
            \Log::error('ClawYard chat error', [
                'user_id'   => auth()->id(),
                'agent'     => $agentName,
                'exception' => $e->getMessage(),
                'trace'     => $e->getTraceAsString(),
            ]);
            return response()->json([
                'success' => false,
                'error'   => 'Erro ao processar a mensagem. Por favor tente novamente.',
            ], 500);
        }
    }

    /**
     * POST /api/chat/stream — SSE streaming chat (fixes Cloudflare 504 timeouts)
     */
    public function chatStream(Request $request): StreamedResponse
    {
        set_time_limit(300); // 5 minutes for long Quantum/ARIA responses

        $request->validate([
            'message'   => 'required|string|min:1|max:20000',
            'agent'     => 'nullable|string|in:auto,orchestrator,nvidia,claude,sales,support,email,sap,document,maritime,cyber,aria,quantum,finance,research,capitao,acingov',
            'session_id'=> 'nullable|string|max:64|regex:/^[a-zA-Z0-9_\-]+$/',
            'image'     => 'nullable|string|max:10485760',  // base64 image ~7.5MB
            'file_b64'  => 'nullable|string|max:20971520',  // base64 file up to ~15MB binary
            'file_type' => 'nullable|string|max:200',
            'file_name' => 'nullable|string|max:255',
        ]);

        $agentName = $request->input('agent', 'auto');
        $message   = $request->input('message');

        $userId    = auth()->id();
        $clientSid = $request->input('session_id');
        $sessionId = $clientSid
            ? 'u' . $userId . '_' . $clientSid
            : 'u' . $userId . '_' . bin2hex(random_bytes(16));

        $imageB64  = $request->input('image');
        $fileB64   = $request->input('file_b64');
        $fileType  = $request->input('file_type', 'application/octet-stream');
        $fileName  = $request->input('file_name', 'ficheiro');

        // Resolve agent and augment message *before* streaming so any validation
        // errors surface as JSON, not mid-stream garbage.
        $conversation = Conversation::firstOrCreate(
            ['session_id' => $sessionId],
            ['channel' => 'web', 'agent' => $agentName]
        );

        $history = $conversation->history;

        $augmentedMessage = $this->ragService->augmentMessage($message);

        // Write base64 file to temp path for ZIP-based parsers (Excel/Word)
        $filePath = null;
        if ($fileB64) {
            $filePath = tempnam(sys_get_temp_dir(), 'upl_');
            file_put_contents($filePath, base64_decode($fileB64));
        }

        if ($imageB64) {
            // Image attachment (vision)
            $augmentedMessage = [
                ['type' => 'text', 'text' => $augmentedMessage],
                ['type' => 'image', 'source' => [
                    'type'       => 'base64',
                    'media_type' => 'image/jpeg',
                    'data'       => $imageB64,
                ]],
            ];
        } elseif ($fileB64 && preg_match('/pdf/i', $fileType . $fileName)) {
            // PDF — Claude native document processing (use original base64 directly)
            \Log::info("ClawYard: PDF attached [{$fileName}] " . round(strlen($fileB64) * 0.75 / 1024) . ' KB');
            $augmentedMessage = [
                ['type' => 'text',     'text'   => $augmentedMessage],
                ['type' => 'document', 'source' => [
                    'type'       => 'base64',
                    'media_type' => 'application/pdf',
                    'data'       => $fileB64,
                ]],
            ];
            @unlink($filePath); // clean up temp file
        } elseif ($filePath && preg_match('/spreadsheet|excel|\.xlsx|\.xls/i', $fileType . $fileName)) {
            // Excel XLSX — extract text directly from ZIP XML (no ext-gd required)
            \Log::info("ClawYard: Excel attached [{$fileName}] " . round(filesize($filePath) / 1024) . ' KB');
            $tmp = $filePath;
            try {
                $zip = new \ZipArchive();
                if ($zip->open($tmp) === true) {
                    // Load shared strings (cell values stored by index)
                    $sharedStrings = [];
                    $ssXml = $zip->getFromName('xl/sharedStrings.xml');
                    if ($ssXml) {
                        $ssXml = preg_replace('/<r>.*?<\/r>/s', '', $ssXml); // keep only <t> nodes
                        preg_match_all('/<t[^>]*>(.*?)<\/t>/s', $ssXml, $m);
                        $sharedStrings = array_map('html_entity_decode', $m[1]);
                    }
                    // Parse sheet1 (main sheet)
                    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml') ?: '';
                    $zip->close();
                    $text  = '';
                    $lines = [];
                    preg_match_all('/<row[^>]*>(.*?)<\/row>/s', $sheetXml, $rows);
                    foreach ($rows[1] as $rowXml) {
                        $cells = [];
                        preg_match_all('/<c [^>]*t="s"[^>]*>.*?<v>(\d+)<\/v>.*?<\/c>/s', $rowXml, $strCells);
                        preg_match_all('/<c [^>]*>.*?<v>([^<]*)<\/v>.*?<\/c>/s', $rowXml, $numCells);
                        // Rebuild cells in column order
                        preg_match_all('/<c r="([A-Z]+)\d+"([^>]*)>(.*?)<\/c>/s', $rowXml, $allCells, PREG_SET_ORDER);
                        foreach ($allCells as $cell) {
                            $isStr = str_contains($cell[2], 't="s"');
                            preg_match('/<v>([^<]*)<\/v>/', $cell[3], $vMatch);
                            $val = $vMatch[1] ?? '';
                            if ($isStr) $val = $sharedStrings[(int)$val] ?? $val;
                            $cells[] = $val;
                        }
                        if (array_filter($cells, fn($c) => $c !== '')) {
                            $lines[] = implode(' | ', $cells);
                        }
                    }
                    $text = implode("\n", $lines);
                    $augmentedMessage .= "\n\n---\n**Ficheiro Excel: {$fileName}**\n```\n" . substr(trim($text), 0, 15000) . "\n```";
                } else {
                    $augmentedMessage .= "\n\n[Excel: não foi possível abrir o ficheiro]";
                }
            } catch (\Throwable $e) {
                \Log::warning("Excel parse failed ({$fileName}): " . $e->getMessage());
                $augmentedMessage .= "\n\n[Excel: erro ao processar — " . $e->getMessage() . "]";
            } finally {
                @unlink($tmp); // clean up temp file
            }
        } elseif ($filePath && preg_match('/wordprocessing|msword|\.docx|\.doc$/i', $fileType . $fileName)) {
            // Word DOCX — extract text from ZIP XML (no external library needed)
            \Log::info("ClawYard: Word attached [{$fileName}] " . round(filesize($filePath) / 1024) . ' KB');
            $tmp = $filePath;
            try {
                $zip = new \ZipArchive();
                if ($zip->open($tmp) === true) {
                    $xml = $zip->getFromName('word/document.xml') ?: '';
                    $zip->close();
                    $xml  = str_replace(['</w:p>', '</w:tr>', '<w:br/>'], ["\n", "\n", "\n"], $xml);
                    $text = strip_tags($xml);
                    $text = preg_replace('/[ \t]+/', ' ', $text);
                    $text = preg_replace('/\n{3,}/', "\n\n", trim($text));
                    $augmentedMessage .= "\n\n---\n**Ficheiro Word: {$fileName}**\n" . substr($text, 0, 15000);
                } else {
                    $augmentedMessage .= "\n\n[Word: não foi possível abrir o ficheiro]";
                }
            } catch (\Throwable $e) {
                \Log::warning("Word parse failed ({$fileName}): " . $e->getMessage());
                $augmentedMessage .= "\n\n[Word: erro ao processar — " . $e->getMessage() . "]";
            } finally {
                @unlink($tmp);
            }
        } elseif ($filePath) {
            // Other binary file — unsupported
            @unlink($filePath);
            $augmentedMessage .= "\n\n[Ficheiro binário: {$fileName} ({$fileType}) — formato não suportado para análise]";
        }

        // Save user message before streaming
        $conversation->messages()->create([
            'role'    => 'user',
            'content' => $message,
        ]);

        // Resolve agent (orchestrator falls back to non-streaming chat())
        if ($agentName === 'orchestrator') {
            $results = $this->agentManager->orchestrate(
                is_array($augmentedMessage) ? $message : $augmentedMessage,
                $history
            );
            $reply = $this->combineReplies($results);

            $conversation->messages()->create([
                'role'    => 'assistant',
                'agent'   => 'orchestrator',
                'content' => $reply,
            ]);

            // Return as SSE with a single chunk + metadata + DONE
            return response()->stream(function () use ($reply, $results, $sessionId) {
                // Send agent_log
                $meta = [
                    'type'       => 'meta',
                    'mode'       => 'orchestrator',
                    'agents'     => array_column($results, 'agent'),
                    'session_id' => $sessionId,
                ];
                echo 'data: ' . json_encode($meta) . "\n\n";
                flush();

                // Single chunk with full reply
                echo 'data: ' . json_encode(['chunk' => $reply]) . "\n\n";
                flush();

                echo "data: [DONE]\n\n";
                flush();
            }, 200, [
                'Content-Type'      => 'text/event-stream',
                'Cache-Control'     => 'no-cache',
                'X-Accel-Buffering' => 'no',
                'Connection'        => 'keep-alive',
            ]);
        }

        $agent = $agentName === 'auto'
            ? $this->agentManager->route($message)
            : $this->agentManager->agent($agentName);

        $agentLog = [
            ['icon' => '🔍', 'text' => 'A analisar a mensagem...', 'done' => true],
            ['icon' => '📚', 'text' => 'A consultar base de conhecimento (RAG)...', 'done' => true],
            ['icon' => '🤖', 'text' => 'Agente ' . ucfirst($agent->getName()) . ' a processar...', 'done' => true],
        ];

        $resolvedAgent    = $agent;
        $resolvedHistory  = $history;
        $resolvedMessage  = $augmentedMessage; // pass array as-is for multimodal (PDF/image)
        $resolvedAgentLog = $agentLog;
        $suggestions      = $this->getSuggestions($agent->getName(), $message);
        $agentModel       = $agent->getModel();
        $agentName_final  = $agent->getName();
        $conversationRef  = $conversation;

        return response()->stream(function () use (
            $resolvedAgent, $resolvedMessage, $resolvedHistory,
            $resolvedAgentLog, $suggestions, $agentModel, $agentName_final,
            $conversationRef, $sessionId, $userId
        ) {
            // Flush all PHP output buffers so SSE data reaches the browser immediately
            // (PHP-FPM output_buffering=4096 would otherwise hold small packets)
            while (ob_get_level() > 0) { ob_end_flush(); }
            flush();

            // Send metadata first so the JS can set up the message bubble correctly
            $meta = [
                'type'        => 'meta',
                'mode'        => 'single',
                'agent'       => $agentName_final,
                'model'       => $agentModel,
                'session_id'  => $sessionId,
                'agent_log'   => $resolvedAgentLog,
                'suggestions' => $suggestions,
            ];
            echo 'data: ' . json_encode($meta) . "\n\n";
            flush();

            // Heartbeat: keeps Nginx/Cloudflare alive during slow external fetches
            $heartbeat = function (string $status = '') {
                echo ': heartbeat' . ($status ? " {$status}" : '') . "\n\n";
                flush();
            };

            try {
                $fullReply = $resolvedAgent->stream(
                    $resolvedMessage,
                    $resolvedHistory,
                    function (string $chunk) {
                        echo 'data: ' . json_encode(['chunk' => $chunk]) . "\n\n";
                        flush();
                    },
                    $heartbeat
                );
            } catch (\Throwable $e) {
                \Log::error('ClawYard stream error', [
                    'agent'     => $agentName_final,
                    'exception' => $e->getMessage(),
                    'file'      => $e->getFile(),
                    'line'      => $e->getLine(),
                ]);
                $errMsg = app()->environment('production')
                    ? 'Erro ao processar: ' . $e->getMessage()
                    : $e->getMessage();
                echo 'data: ' . json_encode(['error' => $errMsg]) . "\n\n";
                flush();
                echo "data: [DONE]\n\n";
                flush();
                return;
            }

            // Save assistant reply after streaming completes
            try {
                $conversationRef->messages()->create([
                    'role'    => 'assistant',
                    'agent'   => $agentName_final,
                    'content' => $fullReply,
                ]);
            } catch (\Throwable $e) {
                \Log::warning('ClawYard: could not save assistant message — ' . $e->getMessage());
            }

            // Auto-save report for all agents (responses > 150 chars)
            if (strlen($fullReply) > 150) {
                try {
                    $agentLabels = [
                        'quantum'      => 'Prof. Quantum Leap',
                        'aria'         => 'ARIA Security',
                        'sales'        => 'Sales Agent',
                        'email'        => 'Email Agent',
                        'support'      => 'Marcus Suporte',
                        'orchestrator' => 'Orchestrator',
                        'auto'         => 'Auto Agent',
                    ];
                    $agentLabel = $agentLabels[$agentName_final] ?? ucfirst($agentName_final);
                    $validTypes = ['quantum','aria','market','sales','email','support','orchestrator','custom'];
                    $type       = in_array($agentName_final, $validTypes) ? $agentName_final : 'custom';
                    // Title includes session so same agent can have multiple reports per day
                    $title = $agentLabel . ' — ' . now()->format('Y-m-d H:i');
                    \App\Models\Report::create([
                        'title'   => $title,
                        'user_id' => $userId,
                        'type'    => $type,
                        'content' => $fullReply,
                        'summary' => substr(strip_tags($fullReply), 0, 300),
                    ]);
                } catch (\Throwable $e) {
                    \Log::warning('ClawYard: could not auto-save report — ' . $e->getMessage());
                }
            }

            echo "data: [DONE]\n\n";
            flush();
        }, 200, [
            'Content-Type'      => 'text/event-stream',
            'Cache-Control'     => 'no-cache',
            'X-Accel-Buffering' => 'no',
            'Connection'        => 'keep-alive',
        ]);
    }

    /**
     * GET /api/agents
     */
    public function agents(): JsonResponse
    {
        return response()->json([
            'agents' => $this->agentManager->available(),
        ]);
    }

    /**
     * POST /api/documents — Upload document to RAG knowledge base
     */
    public function uploadDocument(Request $request): JsonResponse
    {
        $request->validate([
            'title'   => 'required|string',
            'content' => 'required|string',
            'source'  => 'nullable|string',
        ]);

        $doc = $this->ragService->ingest(
            $request->input('title'),
            $request->input('content'),
            $request->input('source', 'partyard')
        );

        return response()->json([
            'success' => true,
            'document' => $doc,
        ]);
    }

    /**
     * GET /api/history/{session_id}
     */
    public function history(string $sessionId): JsonResponse
    {
        // Bind lookup to authenticated user — prevents session enumeration
        $userId     = auth()->id();
        $prefixed   = 'u' . $userId . '_' . $sessionId;

        $conversation = Conversation::where('session_id', $prefixed)->first();

        if (!$conversation) {
            return response()->json(['messages' => []]);
        }

        return response()->json([
            'messages' => $conversation->messages()->orderBy('created_at')->get(),
        ]);
    }

    /**
     * Generate contextual suggestions based on agent and message.
     */
    protected function getSuggestions(string $agent, string $message): array
    {
        $suggestions = [
            'email'    => [
                '📧 Enviar este email agora',
                '🌍 Traduzir para inglês',
                '📋 Criar versão formal',
                '📤 Enviar para outro destinatário',
            ],
            'sales'    => [
                '💰 Gerar proposta comercial completa',
                '📧 Enviar proposta por email',
                '🏷️ Ver lista de preços',
                '📊 Comparar com concorrentes',
            ],
            'support'  => [
                '📄 Ver documentação técnica',
                '🔧 Abrir ticket de suporte',
                '📞 Escalar para técnico',
                '📧 Enviar relatório por email',
            ],
            'sap'      => [
                '📦 Ver stock actual',
                '🧾 Gerar ordem de compra',
                '📊 Relatório financeiro',
                '📧 Enviar relatório SAP',
            ],
            'document' => [
                '📤 Carregar outro documento',
                '📧 Enviar resumo por email',
                '🔍 Pesquisar mais documentos',
                '📋 Exportar análise',
            ],
            'maritime' => [
                '🚢 Ver portos disponíveis',
                '📊 Analisar concorrentes',
                '📧 Contactar armador por email',
                '🗺️ Ver rotas marítimas',
            ],
            'claude'   => [
                '🔄 Reformular resposta',
                '📧 Criar email com este conteúdo',
                '📊 Análise mais detalhada',
                '🌍 Traduzir',
            ],
            'nvidia'   => [
                '🔄 Gerar alternativa',
                '📧 Enviar resultado por email',
                '📊 Ver mais detalhes',
                '🤖 Modo multi-agente',
            ],
            'cyber'    => [
                '🔴 Ver vulnerabilidades críticas',
                '🛡️ Gerar patch de segurança',
                '📋 Relatório OWASP completo',
                '🔒 Verificar autenticação e API',
            ],
            'aria'     => [
                '🔐 Scan STRIDE completo ao partyard.eu',
                '🛡️ Relatório OWASP Top 10',
                '🔒 Verificar certificados SSL',
                '📋 Gerar threat model da API',
            ],
            'quantum'  => [
                '⚛️ Ver papers de quantum de hoje',
                '🏛️ Top 7 patentes USPTO para PartYard',
                '💡 Professor\'s strategic insight',
                '🔬 Analisar paper específico do arXiv',
            ],
        ];

        $default = [
            '📧 Criar email sobre este tema',
            '📊 Análise mais detalhada',
            '🔄 Reformular',
            '🚢 Pesquisar portos relacionados',
        ];

        $list = $suggestions[$agent] ?? $default;
        shuffle($list);
        return array_slice($list, 0, 3);
    }

    protected function combineReplies(array $results): string
    {
        $agentLabels = [
            'sales'    => '💼 Sales',
            'support'  => '🔧 Support',
            'email'    => '📧 Email',
            'sap'      => '📊 SAP',
            'document' => '📄 Document',
            'maritime' => '🚢 Maritime',
            'stock'    => '📦 Stock',
            'claude'   => '🧠 Claude',
            'nvidia'   => '⚡ NVIDIA',
            'aria'     => '🔐 ARIA Security',
            'quantum'  => '⚛️ Prof. Quantum Leap',
        ];

        if (count($results) === 1) {
            return $results[0]['reply'];
        }

        $combined = '';
        foreach ($results as $result) {
            $label     = $agentLabels[$result['agent']] ?? ucfirst($result['agent']);
            $combined .= "## {$label}\n\n{$result['reply']}\n\n---\n\n";
        }

        return trim($combined);
    }
}
