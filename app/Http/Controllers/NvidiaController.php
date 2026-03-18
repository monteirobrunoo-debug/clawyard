<?php

namespace App\Http\Controllers;

use App\Services\NvidiaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NvidiaController extends Controller
{
    public function __construct(protected NvidiaService $nvidia) {}

    /**
     * POST /api/chat
     */
    public function chat(Request $request): JsonResponse
    {
        $request->validate([
            'message' => 'required|string|max:4096',
            'history' => 'nullable|array',
        ]);

        try {
            $reply = $this->nvidia->chat(
                $request->input('message'),
                $request->input('history', [])
            );

            return response()->json([
                'success' => true,
                'reply'   => $reply,
                'model'   => config('services.nvidia.model'),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error'   => $e->getMessage(),
            ], 500);
        }
    }
}
