<?php

namespace App\Http\Controllers;

use App\Models\Tender;
use App\Models\TenderAttachment;
use App\Models\TenderServiceAnalysis;
use App\Services\SapService;
use App\Services\TenderServiceAnalysisService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class TenderServiceAnalysisController extends Controller
{
    /**
     * POST /tenders/{tender}/service-analysis — kicks off (or refreshes)
     * the multi-agent service analysis. Sync execution, takes ~30-60s
     * for 5 agents, returns the persisted analysis as JSON.
     */
    public function generate(Request $request, Tender $tender, TenderServiceAnalysisService $svc): JsonResponse|\Illuminate\Http\Response
    {
        // Multi-agente pode demorar 60-90s. Bump timeouts agressivamente
        // (PHP-level — Nginx/Octane também têm limits separados).
        // Pedido directo Bruno 2026-05-28: HTTP 408 em "Análise do serviço".
        @set_time_limit(300);
        @ini_set('max_execution_time', '300');
        @ini_set('default_socket_timeout', '300');

        try {
            $this->authorizeTender($tender);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            // Convert abort()-thrown HTML 401/403 into JSON so the
            // frontend can show the message instead of "Unexpected token <"
            return response()->json([
                'error'  => $e->getStatusCode() === 401 ? 'unauthorized' : 'forbidden',
                'detail' => $e->getMessage() ?: 'Acesso negado.',
            ], $e->getStatusCode() ?: 403);
        }

        if ($tender->is_confidential) {
            return response()->json([
                'error'  => 'tender_confidential',
                'detail' => 'Concurso confidencial — análise multi-agente desligada (envia conteúdo a 5 agentes Claude). Desmarca a flag se realmente queres correr.',
            ], 403);
        }

        // Reuse cached if fresh and the user didn't force refresh
        $force = $request->boolean('force', false);
        $existing = TenderServiceAnalysis::where('tender_id', $tender->id)->first();
        if ($existing && $existing->status === 'done' && $existing->isFresh(24) && !$force) {
            return response()->json([
                'cached'  => true,
                'view_url' => route('tenders.service-analysis.show', $tender),
                'analysis' => $existing,
            ]);
        }

        try {
            $analysis = $svc->analyse($tender, Auth::id());
        } catch (\Throwable $e) {
            return response()->json([
                'error'  => 'analysis_failed',
                'detail' => $e->getMessage(),
            ], 502);
        }

        return $this->jsonSafe([
            'cached'   => false,
            'view_url' => route('tenders.service-analysis.show', $tender),
            'analysis' => $analysis,
        ]);
    }

    /**
     * Resposta JSON tolerante a UTF-8 malformado vindo do LLM.
     *
     * 2026-05-18 fix: TenderServiceAnalysis::sections (array de
     * payloads de 6 agentes) e executive_summary podem conter bytes
     * inválidos quando os LLMs retornam Portuguese accents partidos
     * em algumas sequências. Laravel response()->json() chama
     * json_encode() sem flags → fatal → HTML 500 → frontend tenta
     * await res.json() e dá "JSON.parse: unexpected character at line 1".
     *
     * Solução: codificar com JSON_INVALID_UTF8_SUBSTITUTE — substitui
     * bytes inválidos por U+FFFD em vez de explodir. Aplicado também
     * a Concursos para outras rotas que serializam strings vindas de
     * LLMs.
     */
    private function jsonSafe(array $data, int $status = 200): \Illuminate\Http\Response
    {
        $body = json_encode(
            $data,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR
        );
        if ($body === false) {
            $body = json_encode(['error' => 'json_encode_failed', 'detail' => json_last_error_msg()]);
            $status = 500;
        }
        return response((string) $body, $status, ['Content-Type' => 'application/json']);
    }

    /** GET /tenders/{tender}/service-analysis — full view (printable). */
    public function show(Tender $tender): View
    {
        $this->authorizeTender($tender);
        $analysis = TenderServiceAnalysis::where('tender_id', $tender->id)
            ->where('status', 'done')
            ->firstOrFail();

        return view('tenders.service-analysis', [
            'tender'   => $tender,
            'analysis' => $analysis,
        ]);
    }

    /**
     * GET /tenders/{tender}/service-analysis/pdf — gera PDF (dompdf),
     * anexa-o ao concurso na tabela `tender_attachments` (idempotente via
     * file_hash) e devolve o PDF como download inline.
     *
     * Operador clica uma vez:
     *   • Vê o PDF imediatamente no browser
     *   • E o ficheiro fica permanente em /tenders/{id} → secção Anexos
     *
     * Se a análise for re-corrida, o próximo PDF tem hash diferente
     * (executive_summary muda) e cria nova row em vez de duplicar.
     */
    public function pdf(Tender $tender): Response
    {
        $this->authorizeTender($tender);
        $analysis = TenderServiceAnalysis::where('tender_id', $tender->id)
            ->where('status', 'done')
            ->firstOrFail();

        // Renderiza a view PDF-friendly (sem @vite, fontes default, inline CSS)
        $pdf = Pdf::loadView('tenders.service-analysis-pdf', [
            'tender'   => $tender,
            'analysis' => $analysis,
        ])->setPaper('A4', 'portrait');

        $bytes = $pdf->output();

        // Hash + nome do ficheiro derivado do conteúdo (dedup automático
        // — re-gerar com a mesma análise não cria nova row).
        $hash = hash('sha256', $bytes);
        $slug = Str::slug(($tender->reference ?: 'concurso-' . $tender->id) . '-analise');
        $storedName = $slug . '-' . substr($hash, 0, 8) . '.pdf';
        $relPath = 'tender-attachments/' . $tender->id . '/' . $storedName;

        // Idempotência: se já existe um TenderAttachment com este hash,
        // re-aproveita-o em vez de duplicar.
        $existing = TenderAttachment::where('tender_id', $tender->id)
            ->where('file_hash', $hash)
            ->first();

        if (!$existing) {
            try {
                Storage::disk('local')->put($relPath, $bytes);
            } catch (\Throwable $e) {
                Log::error('ServiceAnalysisPDF: storage failed', [
                    'tender_id' => $tender->id,
                    'error'     => $e->getMessage(),
                ]);
                return response('Erro ao guardar PDF: ' . $e->getMessage(), 500);
            }

            TenderAttachment::create([
                'tender_id'           => $tender->id,
                'original_name'       => "Analise-multi-agente-#{$tender->id}-" . now()->format('Ymd-His') . '.pdf',
                'disk_path'           => $relPath,
                'mime_type'           => 'application/pdf',
                'size_bytes'          => strlen($bytes),
                'file_hash'           => $hash,
                'extraction_status'   => TenderAttachment::STATUS_OK,
                'extracted_text'      => ($analysis->executive_summary ?? '') . "\n\n" .
                                         collect($analysis->extractActionItems())->pluck('text')->implode("\n"),
                'extracted_chars'     => mb_strlen($analysis->executive_summary ?? ''),
                'uploaded_by_user_id' => Auth::id(),
            ]);
        } else {
            // touch para subir no histórico
            $existing->touch();
        }

        // Devolve o PDF imediatamente — o anexo fica guardado em background.
        return response($bytes, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'inline; filename="Analise-#' . $tender->id . '.pdf"',
            'Cache-Control'       => 'private, max-age=0, no-store',
        ]);
    }

    /**
     * POST /tenders/{tender}/service-analysis/sync-todo — extrai o plano
     * de acção consolidado da análise e mete-o em `tender.notes`, que
     * por sua vez sincroniza com `SAP Opportunity Remarks` (cap 254
     * chars do SAP B1).
     *
     * Comportamento:
     *   • Se já existe texto em tender.notes, faz APPEND (não substitui)
     *     marcado com timestamp.
     *   • Se não houver SAP opp num, guarda só localmente e avisa.
     *   • Se houver, faz updateOpportunity() imediatamente.
     */
    public function syncTodoToSap(Tender $tender, SapService $sap): RedirectResponse
    {
        $this->authorizeTender($tender);
        $analysis = TenderServiceAnalysis::where('tender_id', $tender->id)
            ->where('status', 'done')
            ->firstOrFail();

        $block = $analysis->toSapNotesBlock();
        if ($block === '') {
            return redirect()
                ->route('tenders.service-analysis.show', $tender)
                ->with('error', 'Análise não tem recomendações — nada para sincronizar.');
        }

        // Mete o plano de acção no campo notes. Se já há notas, anexa
        // por baixo separadas por linha em branco para preservar o que
        // o utilizador escreveu manualmente.
        $existing = (string) $tender->notes;
        $existing = trim($existing);
        $tender->notes = $existing === '' ? $block : ($existing . "\n\n" . $block);
        $tender->save();

        // SAP push — mesma lógica que TenderController::update()
        $seqNo = $tender->getSapSequentialNo();
        $sapConfigured = (bool) config('services.sap.username');

        if (!$sapConfigured) {
            return redirect()
                ->route('tenders.service-analysis.show', $tender)
                ->with('status', '✓ Plano de acção guardado em notas locais. SAP não está configurado — não houve push.');
        }
        if (!$seqNo) {
            return redirect()
                ->route('tenders.service-analysis.show', $tender)
                ->with('status', '⚠ Plano de acção guardado em notas locais. Concurso não tem Nº Oportunidade SAP — preenche o campo para activar sincronização.');
        }

        try {
            // 2026-05-21: appendRemarks (merge-safe). Não perde info dia-a-dia.
            $ok = $sap->appendRemarks($seqNo, (string) $tender->notes);
            $msg = $ok
                ? "✓ Plano sincronizado com SAP Opp #{$seqNo}."
                : "⚠ Plano guardado local mas falhou push ao SAP Opp #{$seqNo}: " . ($sap->getLastError() ?: 'erro desconhecido');
        } catch (\Throwable $e) {
            Log::warning('ServiceAnalysis sync-todo SAP failed', [
                'tender_id' => $tender->id,
                'error'     => $e->getMessage(),
            ]);
            $msg = "⚠ Plano guardado local, excepção ao sincronizar SAP Opp #{$seqNo}: {$e->getMessage()}";
        }

        return redirect()->route('tenders.service-analysis.show', $tender)->with('status', $msg);
    }

    /**
     * Authorisation for the multi-agent analysis is intentionally
     * permissive: any authenticated non-guest user can run/view the
     * report. The analysis is internal research — it consults LLMs
     * and stores a report row, but doesn't modify the tender or
     * notify anyone. Forcing collaborator-only would block managers
     * and analysts from running analyses on tenders they're
     * researching but haven't formally claimed.
     *
     * Confidential tenders are still blocked at the controller level
     * (see generate()); they never reach LLMs no matter who clicks.
     */
    private function authorizeTender(Tender $tender): void
    {
        $user = Auth::user();
        if (!$user) abort(401);
        if (method_exists($user, 'isGuest') && $user->isGuest()) {
            abort(403, 'Guests não podem correr análises multi-agente.');
        }
    }
}
