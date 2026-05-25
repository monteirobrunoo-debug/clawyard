<?php

namespace App\Jobs;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\OrganizationalMemoryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * AutoExtractKnowledgeJob — analisa uma conversa e extrai factos para
 * a memória organizacional (PartYard).
 *
 * Filosofia isolada (lições do rollback):
 *   • Despachado POR CRON (não pelo agente)
 *   • Corre em queue 'low' — nunca compete com chats em curso
 *   • Falha grácil — se Haiku rebenta, log + skip, sem impacto
 *   • Não modifica conversação nem mensagens
 *   • 1 retry só (tries=2). Custo Haiku ~$0.0005 — não vale insistir
 */
class AutoExtractKnowledgeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 2;
    public int $timeout = 60;

    public function __construct(public int $conversationId) {
        // 2026-05-25: 'default' em vez de 'low' — Supervisor por defeito
        // só vigia 'default'+'high'. Jobs em 'low' ficariam parados.
        // Não é hot path (1 call Haiku, ~3s) — OK competir com default.
        $this->onQueue('default');
    }

    public function handle(OrganizationalMemoryService $svc): void
    {
        $conv = Conversation::find($this->conversationId);
        if (!$conv) {
            Log::info('AutoExtractKnowledge: conversation not found', ['id' => $this->conversationId]);
            return;
        }

        // Pega últimas 10 mensagens (user + assistant) por ordem cronológica.
        $messages = Message::where('conversation_id', $this->conversationId)
            ->orderByDesc('id')
            ->limit(10)
            ->get(['id', 'role', 'agent', 'content'])
            ->reverse()
            ->values();

        if ($messages->count() < 2) {
            return;  // sem trocas suficientes
        }

        // Constrói o texto da conversa.
        $textParts = [];
        foreach ($messages as $m) {
            $role = $m->role === 'user' ? 'USER' : 'AGENT';
            $agentTag = $m->role === 'assistant' && $m->agent ? " ({$m->agent})" : '';
            $content = mb_substr(strip_tags((string) $m->content), 0, 1500);
            $textParts[] = "{$role}{$agentTag}: {$content}";
        }
        $convText = implode("\n\n", $textParts);

        if (mb_strlen($convText) < 200) return;  // muito pouco para extrair

        // Resolve user_id pela email da conversa (best effort).
        $userId = null;
        if ($conv->email) {
            $userId = User::where('email', $conv->email)->value('id');
        }

        try {
            $saved = $svc->autoExtract(
                conversationText: $convText,
                extractedByUserId: $userId,
                context: "conversation:{$this->conversationId}",
            );
            if ($saved > 0) {
                Log::info('AutoExtractKnowledge: ok', [
                    'conversation_id' => $this->conversationId,
                    'saved' => $saved,
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('AutoExtractKnowledge: failed — ' . $e->getMessage(), [
                'conversation_id' => $this->conversationId,
            ]);
            // Não re-throw — failure grácil
        }
    }

    public function failed(?\Throwable $e): void
    {
        Log::warning('AutoExtractKnowledge: dropped after retries', [
            'conversation_id' => $this->conversationId,
            'reason' => $e?->getMessage() ?? '?',
        ]);
    }
}
