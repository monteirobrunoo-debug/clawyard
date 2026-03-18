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
            'agent'   => 'nullable|string|in:nvidia,claude,auto',
        ]);

        try {
            $agentName = $request->input('agent', 'auto');

            $agent = $agentName === 'auto'
                ? $this->agentManager->route($request->input('message'))
                : $this->agentManager->agent($agentName);

            $reply = $agent->chat(
                $request->input('message'),
                $request->input('history', [])
            );

            return response()->json([
                'success' => true,
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
}
