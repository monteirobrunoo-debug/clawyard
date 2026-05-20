<?php

namespace App\Jobs;

use App\Agents\CrmAgent;
use App\Models\Tender;
use App\Services\SapService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Auto-trigger Marta CRM auto-resumo após upload de PDF.
 *
 * Pedido directo 2026-05-20:
 *   "a mesma coisa nos concursos, toda a info tem de estar disponível
 *    para todos os users assim que carregar pdf"
 *
 * Flow:
 *   1. User upload PDF → TenderAttachmentController salva + extrai texto
 *   2. Controller dispatch this job ->afterCommit() (não-bloqueante)
 *   3. Worker do Supervisor (queue 'default') corre Marta:
 *      - Constrói prompt com tender + anexos
 *      - Chama CrmAgent (Claude Sonnet 4.6)
 *      - Append à tender.notes com header [Marta CRM · DD/MM HH:MM]
 *      - Sync para SAP Opp Remarks se houver SequentialNo
 *   4. Em ~10-30s o user (e todos os outros) vê o resumo nas notas
 *
 * Safety:
 *   - Skip se confidential (Marta envia conteúdo a LLM externo)
 *   - Skip se Marta já correu nesta tender há <1h (debounce)
 *   - Skip se 0 anexos com texto extraído
 *   - Erros logados, não retry para evitar tempest de calls
 */
class AutoMartaResumoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;            // Sem retry — Marta calls são $$, evitar tempest
    public int $timeout = 120;        // 2min — Marta típica demora 5-20s
    public string $queue = 'default';

    public function __construct(public int $tenderId) {}

    public function handle(SapService $sap): void
    {
        $tender = Tender::with('attachments')->find($this->tenderId);
        if (!$tender) {
            Log::info('AutoMartaResumoJob: tender not found', ['id' => $this->tenderId]);
            return;
        }

        if ($tender->is_confidential) {
            Log::info('AutoMartaResumoJob: skipped (confidential)', ['id' => $this->tenderId]);
            return;
        }

        // Debounce — se Marta já correu nesta tender há <1h, skip.
        // Evita corrida quando o user faz 3 uploads rápidos.
        if (preg_match('/\[Marta CRM · (\d{2}\/\d{2}\/\d{4} \d{2}:\d{2})\]/', (string) $tender->notes, $m)) {
            try {
                $last = \Carbon\Carbon::createFromFormat('d/m/Y H:i', $m[1]);
                if ($last && $last->gt(now()->subHour())) {
                    Log::info('AutoMartaResumoJob: skipped (Marta ran <1h ago)', [
                        'id'   => $this->tenderId,
                        'last' => $m[1],
                    ]);
                    return;
                }
            } catch (\Throwable $e) { /* parse falha → corre na mesma */ }
        }

        $okAtts = $tender->attachments->where('extraction_status', 'ok');
        if ($okAtts->isEmpty()) {
            Log::info('AutoMartaResumoJob: skipped (no OK attachments)', ['id' => $this->tenderId]);
            return;
        }

        // Constrói prompt — replicado do martaSummarize controller method,
        // mais condensado porque é background (não tem o longo guidance de
        // formato dado que o resultado já vai parar às notas como bloco).
        $deadline = $tender->deadline_lisbon?->format('d/m/Y H:i') ?? '—';
        $prompt = "Analisa este concurso e dá-me um RESUMO EXECUTIVO para meter nas Notas do ClawYard. "
            . "Sem limite de chars. Inclui: objecto/material com quantidades e P/N, organização compradora, "
            . "deadline, condições de pagamento, prazo de entrega, compliance (NATO/CE/EUR.1/Mil-Std), "
            . "fornecedores candidatos, valor estimado, pontos críticos (urgência/dependências/riscos). "
            . "Formato: texto plano, sem markdown, sem emojis, separadores ' · ' OU linhas \\n.\n\n"
            . "=== CONCURSO ===\n"
            . "• Título: {$tender->title}\n"
            . "• Referência: " . ($tender->reference ?: '—') . "\n"
            . "• Fonte: " . strtoupper((string) $tender->source) . "\n"
            . "• Organização: " . ($tender->purchasing_org ?: '—') . "\n"
            . "• Deadline: {$deadline}\n";

        if ($tender->sap_opportunity_number) {
            $prompt .= "• SAP Opp: #{$tender->sap_opportunity_number}\n";
        }

        $prompt .= "\n=== ANEXOS ({$okAtts->count()}) ===\n";
        foreach ($okAtts as $a) {
            $snippet = mb_substr((string) $a->extracted_text, 0, 8000);
            $prompt .= "\n--- {$a->original_name} ({$a->extracted_chars} chars) ---\n";
            $prompt .= $snippet . "\n";
        }

        $prompt .= "\nDevolve APENAS o resumo. Se a deadline for ≤7 dias, começa com 'URGENTE · '.";

        try {
            $marta = app(CrmAgent::class);
            $summary = trim($marta->chat($prompt, []));
        } catch (\Throwable $e) {
            Log::error('AutoMartaResumoJob: chat failed', [
                'tender_id' => $this->tenderId,
                'error'     => $e->getMessage(),
            ]);
            return;
        }

        if ($summary === '') {
            Log::info('AutoMartaResumoJob: empty summary', ['id' => $this->tenderId]);
            return;
        }

        if (mb_strlen($summary) > 10000) {
            $summary = mb_substr($summary, 0, 10000) . '… (cortado em 10k chars)';
        }

        // Append à tender.notes preservando manuais.
        $date  = now()->format('d/m/Y H:i');
        $block = "[Marta CRM · {$date} · auto-trigger]\n{$summary}";
        $existing = trim((string) $tender->notes);
        $tender->notes = $existing === '' ? $block : ($existing . "\n\n" . $block);
        $tender->save();

        Log::info('AutoMartaResumoJob: notes updated', [
            'tender_id' => $this->tenderId,
            'chars'     => mb_strlen($summary),
        ]);

        // Sync para SAP Remarks se houver Opp number. Best-effort.
        if ($tender->sap_opportunity_number && config('services.sap.username')) {
            $seqNo = $tender->getSapSequentialNo();
            if ($seqNo) {
                try {
                    $sap->updateOpportunity($seqNo, ['Remarks' => (string) $tender->notes]);
                    Log::info('AutoMartaResumoJob: SAP Remarks synced', [
                        'tender_id' => $this->tenderId,
                        'sap_opp'   => $seqNo,
                    ]);
                } catch (\Throwable $e) {
                    Log::warning('AutoMartaResumoJob: SAP sync failed (non-blocking)', [
                        'tender_id' => $this->tenderId,
                        'sap_opp'   => $seqNo,
                        'error'     => $e->getMessage(),
                    ]);
                }
            }
        }
    }
}
