<?php

namespace App\Jobs;

use App\Models\MultiAgentDebate;
use App\Services\MultiAgentDebateService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * RunMultiAgentDebateJob — corre debate 3-round em background.
 *
 * Disparado pelo TenderController::launchDebate quando o user
 * clica "🧠 Debate multi-agente" no /tenders/{id}. Demora 30-90s
 * (3 agentes × Sonnet 2 rounds + Haiku synthesis).
 *
 * Persiste resultado em multi_agent_debates table. UI faz refresh
 * para ler o estado (pending → running → done|failed).
 *
 * Queue 'default' — Supervisor está sempre a watchar este.
 */
class RunMultiAgentDebateJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Timeout 5min — debate pode demorar até 3min em pior caso. */
    public int $timeout = 300;

    /** 1 retry — se falhar 2× consecutivas, deixa morto para investigação. */
    public int $tries = 2;

    public function __construct(public int $debateId) {}

    public function handle(MultiAgentDebateService $svc): void
    {
        $debate = MultiAgentDebate::find($this->debateId);
        if (!$debate) {
            Log::warning('RunMultiAgentDebateJob: debate not found', ['id' => $this->debateId]);
            return;
        }
        if ($debate->status === MultiAgentDebate::STATUS_DONE) {
            return;  // already done
        }

        $tender = $debate->tender;
        if (!$tender) {
            $debate->update([
                'status'      => MultiAgentDebate::STATUS_FAILED,
                'synthesis'   => 'Tender associado não existe (foi apagado?).',
                'finished_at' => now(),
            ]);
            return;
        }

        try {
            // Mark as running. MultiAgentDebateService::debate() CRIA um
            // novo registo — não actualiza o pending que criámos no controller.
            // Estratégia: ignoramos o pending record (apagamos no fim) e
            // usamos o que o service criar.
            $debate->update([
                'status'     => MultiAgentDebate::STATUS_RUNNING,
                'started_at' => now(),
            ]);

            $result = $svc->debate(
                tender: $tender,
                topic:  $debate->topic,
                agents: $debate->agent_keys, // null = service usa defaults por source
            );

            // Service criou o seu próprio record — copia os campos relevantes
            // para o nosso pending record (consistência) e elimina duplicado.
            $debate->update([
                'agent_keys'     => $result->agent_keys,
                'status'         => $result->status,
                'rounds'         => $result->rounds,
                'synthesis'      => $result->synthesis,
                'disagreements'  => $result->disagreements,
                'confidence_pct' => $result->confidence_pct,
                'cost_usd'       => $result->cost_usd,
                'started_at'     => $result->started_at ?: $debate->started_at,
                'finished_at'    => $result->finished_at ?: now(),
            ]);

            if ($result->id !== $debate->id) {
                $result->delete();
            }

            Log::info('RunMultiAgentDebateJob: completed', [
                'debate_id'      => $debate->id,
                'tender_id'      => $tender->id,
                'confidence_pct' => $debate->confidence_pct,
                'cost_usd'       => $debate->cost_usd,
            ]);
        } catch (\Throwable $e) {
            Log::error('RunMultiAgentDebateJob: failed', [
                'debate_id' => $debate->id,
                'error'     => $e->getMessage(),
            ]);
            $debate->update([
                'status'      => MultiAgentDebate::STATUS_FAILED,
                'synthesis'   => 'Debate falhou: ' . mb_substr($e->getMessage(), 0, 500),
                'finished_at' => now(),
            ]);
            throw $e;  // marca o job como failed → retry once
        }
    }

    public function failed(\Throwable $e): void
    {
        Log::error('RunMultiAgentDebateJob: exhausted retries', [
            'debate_id' => $this->debateId,
            'error'     => $e->getMessage(),
        ]);
        try {
            MultiAgentDebate::where('id', $this->debateId)->update([
                'status'      => MultiAgentDebate::STATUS_FAILED,
                'finished_at' => now(),
            ]);
        } catch (\Throwable) { /* swallow */ }
    }
}
