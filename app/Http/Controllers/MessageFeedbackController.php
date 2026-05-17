<?php

namespace App\Http\Controllers;

use App\Models\Message;
use App\Models\RewardEvent;
use App\Services\Rewards\RewardRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Auth;

/**
 * Recebe 👍 / 👎 da UI de chat (welcome.blade.php) sobre uma message
 * assistant específica.
 *
 *   POST /api/feedback {message_id, direction: 'up'|'down'}
 *
 * Side-effects:
 *   • RewardEvent gravado (agent_thumbs_up = +2 pts user · agent_thumbs_down = +1 pt user)
 *     — o "honest negative" também premeia para incentivar feedback sincero.
 *   • Em thumbs-down, marca messages.is_failed = true para o dashboard
 *     "Saúde dos agentes" detectar prompts maus e os incluir nas
 *     estatísticas de fail_pct.
 *
 * Idempotente: rejeitar o mesmo (user, message) duas vezes silentemente.
 */
class MessageFeedbackController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return ['auth'];
    }

    public function store(Request $req): JsonResponse
    {
        $data = $req->validate([
            'message_id' => ['required', 'integer'],
            'direction'  => ['required', 'in:up,down'],
        ]);

        $msg = Message::find($data['message_id']);
        if (!$msg || $msg->role !== 'assistant') {
            return response()->json(['ok' => false, 'error' => 'Mensagem não encontrada.'], 404);
        }

        $userId   = Auth::id();
        $agentKey = (string) ($msg->agent ?? 'auto');
        $up       = $data['direction'] === 'up';

        // Evita duplicar feedback do mesmo user para a mesma mensagem.
        $alreadyFor = RewardEvent::where('user_id', $userId)
            ->where('subject_type', Message::class)
            ->where('subject_id', $msg->id)
            ->whereIn('event_type', [
                RewardEvent::TYPE_AGENT_THUMBS_UP,
                RewardEvent::TYPE_AGENT_THUMBS_DOWN,
            ])
            ->exists();
        if ($alreadyFor) {
            return response()->json(['ok' => true, 'idempotent' => true]);
        }

        app(RewardRecorder::class)->record(
            eventType: $up ? RewardEvent::TYPE_AGENT_THUMBS_UP : RewardEvent::TYPE_AGENT_THUMBS_DOWN,
            userId:    $userId,
            agentKey:  $agentKey,
            subject:   $msg,
        );

        // Thumbs-down marca a mensagem como falhada para o dashboard
        // detectar prompts onde o agente respondeu mal.
        if (!$up) {
            try { $msg->update(['is_failed' => true]); } catch (\Throwable $e) { /* swallow */ }
        }

        return response()->json(['ok' => true]);
    }
}
