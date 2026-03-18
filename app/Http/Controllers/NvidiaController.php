<?php

namespace App\Http\Controllers;

use App\Agents\AgentManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NvidiaController extends Controller
{
    public function __construct(protected AgentManager $agentManager) {}

    /**
     * POST /api/chat
     */
    public function chat(Request $request): JsonResponse
    {
        $request->validate([
            'message' => 'required|string|max:4096',
            'history' => 'nullable|array',
            'agent'   => 'nullable|string',
        ]);

        $agentName = $request->input('agent', 'auto');
        $message   = $request->input('message');
        $history   = $request->input('history', []);

        try {
            // Multi-agent orchestration mode
            if ($agentName === 'orchestrator') {
                $results = $this->agentManager->orchestrate($message, $history);

                return response()->json([
                    'success'   => true,
                    'mode'      => 'orchestrator',
                    'results'   => $results,
                    'reply'     => $this->combineReplies($results),
                    'agents'    => array_column($results, 'agent'),
                ]);
            }

            // Single agent mode
            $agent = $agentName === 'auto'
                ? $this->agentManager->route($message)
                : $this->agentManager->agent($agentName);

            $reply = $agent->chat($message, $history);

            return response()->json([
                'success' => true,
                'mode'    => 'single',
                'reply'   => $reply,
                'agent'   => $agent->getName(),
                'model'   => $agent->getModel(),
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
