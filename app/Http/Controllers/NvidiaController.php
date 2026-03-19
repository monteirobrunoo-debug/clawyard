<?php

namespace App\Http\Controllers;

use App\Agents\AgentManager;
use App\Models\Conversation;
use App\Models\Document;
use App\Services\RagService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
            'message'    => 'required|string|max:4096',
            'agent'      => 'nullable|string',
            'session_id' => 'nullable|string',
            'image'      => 'nullable|string', // base64 image
        ]);

        $agentName = $request->input('agent', 'auto');
        $message   = $request->input('message');
        $sessionId = $request->input('session_id', session()->getId());
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

            $reply = $agent->chat(
                is_array($augmentedMessage) ? json_encode($augmentedMessage) : $augmentedMessage,
                $history
            );

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
            return response()->json([
                'success' => false,
                'error'   => $e->getMessage(),
            ], 500);
        }
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
        $conversation = Conversation::where('session_id', $sessionId)->first();

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
