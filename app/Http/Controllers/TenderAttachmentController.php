<?php

namespace App\Http\Controllers;

use App\Models\Tender;
use App\Models\TenderAttachment;
use App\Services\ImageOcrService;
use App\Services\PdfTextExtractor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Per-tender attachment management.
 *
 * Routes:
 *   POST   /tenders/{tender}/attachments        — drag-drop upload
 *   GET    /tenders/{tender}/attachments/{id}   — download (auth proxy)
 *   DELETE /tenders/{tender}/attachments/{id}   — soft-delete (manager+)
 *
 * 2026-05-18: aceita qualquer tipo de ficheiro até 50 MB. Antes era
 * apenas PDF — operadores frustravam-se ao tentar anexar xlsx/docx/eml/
 * imagens que normalmente acompanham um concurso. Agora:
 *   • PDF                            → extracção via PdfTextExtractor
 *   • Outros (xlsx, docx, eml, jpg…) → STATUS_SKIPPED (guardado para
 *                                       download, sem extracção)
 * O suggester + Marta CRM continuam a ler apenas onde STATUS_OK.
 *
 * Idempotency: file_hash + tender_id é unique. Re-upload do mesmo
 * ficheiro toca a row existente em vez de duplicar.
 */
class TenderAttachmentController extends Controller
{
    private const DISK            = 'local';
    private const PATH_PREFIX     = 'tender-attachments';
    private const MAX_BYTES_PER   = 50 * 1024 * 1024;   // 50 MB (2026-05-18 bump: 10→50)
    private const MAX_FILES_PER   = 10;

    public function store(
        Request $request,
        Tender $tender,
        PdfTextExtractor $extractor,
        ImageOcrService $ocr
    ): JsonResponse {
        $this->authorizeView($tender);

        // 2026-05-18: sem restrição de mime — operador pode anexar
        // qualquer formato (xlsx, docx, eml, jpg, …). Só o tamanho é
        // limitado. Extracção é tentada para PDFs; outros formatos
        // ficam em STATUS_SKIPPED (download disponível, sem texto).
        $request->validate([
            'files'   => ['required', 'array', 'min:1', 'max:' . self::MAX_FILES_PER],
            'files.*' => ['file', 'max:' . (self::MAX_BYTES_PER / 1024)],
        ], [
            'files.required' => 'Anexa pelo menos um ficheiro.',
            'files.max'      => 'Máximo ' . self::MAX_FILES_PER . ' ficheiros por upload.',
            'files.*.max'    => 'Cada ficheiro tem de ter ≤ 50 MB.',
        ]);

        $created   = [];
        $duplicate = [];
        $failed    = [];

        foreach ($request->file('files', []) as $f) {
            if (!$f || !$f->isValid()) continue;

            try {
                $hash = hash_file('sha256', $f->getRealPath());
            } catch (\Throwable $e) {
                $failed[] = [$f->getClientOriginalName(), 'hash_failed'];
                continue;
            }

            // Dedup per tender — same PDF re-uploaded just touches the
            // existing row's updated_at (so the operator sees it bubble
            // up as "recent" without growing the table).
            $existing = TenderAttachment::where('tender_id', $tender->id)
                ->where('file_hash', $hash)
                ->first();
            if ($existing) {
                $existing->touch();
                $duplicate[] = $existing->id;
                continue;
            }

            // Persist file → /storage/app/private/tender-attachments/{id}/{slug}.{ext}
            // 2026-05-18: extensão derivada do ficheiro original (era .pdf
            // hard-coded). Slug + hash continuam a garantir nome único.
            $originalName = $f->getClientOriginalName();
            $ext = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));
            if ($ext === '') $ext = 'bin'; // fallback para ficheiros sem extensão
            $slug = Str::slug(pathinfo($originalName, PATHINFO_FILENAME));
            $slug = $slug !== '' ? $slug : 'doc';
            $storedName = $slug . '-' . substr($hash, 0, 8) . '.' . $ext;
            $relPath    = self::PATH_PREFIX . '/' . $tender->id . '/' . $storedName;
            try {
                Storage::disk(self::DISK)->putFileAs(
                    self::PATH_PREFIX . '/' . $tender->id,
                    $f,
                    $storedName,
                );
            } catch (\Throwable $e) {
                $failed[] = [$originalName, 'storage_failed: ' . $e->getMessage()];
                continue;
            }

            $att = TenderAttachment::create([
                'tender_id'           => $tender->id,
                'original_name'       => $originalName,
                'disk_path'           => $relPath,
                'mime_type'           => $f->getClientMimeType(),
                'size_bytes'          => $f->getSize(),
                'file_hash'           => $hash,
                'extraction_status'   => TenderAttachment::STATUS_PENDING,
                'uploaded_by_user_id' => Auth::id(),
            ]);

            // Extracção:
            //   • PDF   → smalot/pdfparser
            //   • Imagens (jpg/jpeg/png/webp/gif/heic) → Claude Vision OCR
            //   • Outros (xlsx/docx/eml/...) → STATUS_SKIPPED
            //
            // 2026-05-19: imagens deixaram de ficar SKIPPED. O operador
            // pode tirar foto a um documento via "📷 Capturar" no mobile
            // e o texto fica extraído automaticamente (Marta CRM + agentes
            // depois já o vêem como qualquer outro PDF).
            $isImage = in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif', 'heic'], true);

            if ($ext === 'pdf') {
                $absolutePath = Storage::disk(self::DISK)->path($relPath);
                $res = $extractor->extract($absolutePath);
                if ($res['ok']) {
                    $att->extracted_text    = $res['text'];
                    $att->extracted_chars   = mb_strlen($res['text']);
                    $att->extraction_status = TenderAttachment::STATUS_OK;
                    $att->extraction_error  = null;
                } else {
                    $att->extraction_status = TenderAttachment::STATUS_FAILED;
                    $att->extraction_error  = mb_substr((string) ($res['error'] ?? 'unknown'), 0, 500);
                }
            } elseif ($isImage) {
                $absolutePath = Storage::disk(self::DISK)->path($relPath);
                $res = $ocr->extract($absolutePath, $f->getClientMimeType() ?: null);
                if ($res['ok']) {
                    $att->extracted_text    = $res['text'];
                    $att->extracted_chars   = mb_strlen((string) $res['text']);
                    $att->extraction_status = TenderAttachment::STATUS_OK;
                    $att->extraction_error  = $res['text'] === ''
                        ? 'OCR via Claude Vision concluído — imagem sem texto legível.'
                        : null;
                    Log::info('TenderAttachment: image OCR done', [
                        'attachment_id' => $att->id,
                        'chars'         => $att->extracted_chars,
                    ]);
                } else {
                    $att->extraction_status = TenderAttachment::STATUS_FAILED;
                    $att->extraction_error  = mb_substr('OCR falhou: ' . (string) ($res['error'] ?? 'unknown'), 0, 500);
                }
            } else {
                $att->extraction_status = TenderAttachment::STATUS_SKIPPED;
                // Nota descritiva para o operador saber porque ficou "skipped".
                $att->extraction_error  = 'Formato ' . strtoupper($ext) . ' — guardado sem extracção de texto. Disponível para download.';
            }
            $att->save();
            $created[] = $att->id;

            // 2026-05-19: espelho para QNAP. Pedido directo do operador:
            //   "quando envio pdf para analisar no dashboard de concursos,
            //    ele ter maneira de criar pasta no nosso servidor interno…
            //    cria um passo para gravar logo de uma vez no server qnap"
            // Best-effort — falha não bloqueia upload. Config flag
            // QNAP_MIRROR_ENABLED controla activação.
            try {
                $qnapDest = app(\App\Services\TenderQnapMirror::class)->mirrorAttachment($att);
                if ($qnapDest) {
                    Log::info('TenderAttachment: mirrored to QNAP', [
                        'attachment_id' => $att->id,
                        'dest'          => $qnapDest,
                    ]);
                }
            } catch (\Throwable $e) {
                // best-effort
            }
        }

        if (!empty($failed)) {
            Log::warning('TenderAttachment: some files failed', ['tender_id' => $tender->id, 'failed' => $failed]);
        }

        // 2026-05-20 (reverted): Marta NÃO corre automaticamente após upload.
        // Decisão do operador — fica manual via:
        //   • Botão "✨ Auto-resumo → Notas+SAP" (martaSummarize)
        //   • Botão "Criar SAP Opp" (createSapOpp)
        // Texto extraído fica disponível imediatamente a todos via tender
        // attachments — basta abrir o tender para ler. Marta só processa
        // quando o operador clica.
        //
        // AutoMartaResumoJob fica no repo para uso futuro (CLI / cron
        // / endpoint manager) mas o dispatch automático foi removido.

        // Reload tender with attachments + return summary for the UI.
        $tender->load('attachments');
        return response()->json([
            'ok'          => true,
            'created'     => count($created),
            'duplicates'  => count($duplicate),
            'failed'      => $failed,
            'attachments' => $tender->attachments->map(fn(TenderAttachment $a) => $this->shape($a))->values()->all(),
        ]);
    }

    public function download(Tender $tender, TenderAttachment $attachment): StreamedResponse
    {
        $this->authorizeView($tender);
        if ($attachment->tender_id !== $tender->id) abort(404);

        $abs = Storage::disk(self::DISK)->path($attachment->disk_path);
        if (!is_file($abs)) abort(404);

        // 2026-05-18: Content-Type derivado do mime_type guardado (era
        // hard-coded application/pdf). Fallback para octet-stream se
        // não tivermos mime (ficheiros legacy ou sem detecção).
        $contentType = $attachment->mime_type ?: 'application/octet-stream';
        return Storage::disk(self::DISK)->download($attachment->disk_path, $attachment->original_name, [
            'Content-Type'  => $contentType,
            'Cache-Control' => 'private, max-age=0, no-store',
        ]);
    }

    public function destroy(Tender $tender, TenderAttachment $attachment)
    {
        // 2026-05-21: regra de delete alargada. Antes só manager podia
        // apagar — pedido "User joao murta tentou apagar um ficheiro no
        // marine departmemt e nao consegui deu erro 403". João é role
        // 'user' (não manager) e bateu no abort(403) da linha 253.
        //
        // Nova política (espelha o flow de upload, que é aberto a todos
        // os authenticated em tenders não-confidenciais):
        //
        //   ✓ Managers              — sempre podem apagar tudo
        //   ✓ Próprio uploader      — apaga o que carregou (corrige asneira)
        //   ✓ Colaborador atribuído — apaga anexos do seu tender (workflow)
        //   ✗ Outros users          — view-only, têm de pedir ao dono
        //
        // Continua a respeitar a flag is_confidential via authorizeView().
        $this->authorizeView($tender);   // sem requireManager
        if ($attachment->tender_id !== $tender->id) abort(404);

        $u = Auth::user();
        $isManager  = $u->isManager();
        $isUploader = (int) $attachment->uploaded_by_user_id === (int) $u->id;
        $isAssigned = $tender->collaborator
            && (int) $tender->collaborator->user_id === (int) $u->id;

        if (!$isManager && !$isUploader && !$isAssigned) {
            abort(403,
                'Só o uploader original, o colaborador atribuído ao concurso, '
                . 'ou um manager pode apagar este anexo. Pede a um deles para o remover, '
                . 'ou contacta o admin.'
            );
        }

        try {
            Storage::disk(self::DISK)->delete($attachment->disk_path);
        } catch (\Throwable $e) { /* file already gone — fine */ }
        $attachment->delete();

        Log::info('Tender attachment deleted', [
            'tender_id'      => $tender->id,
            'attachment_id'  => $attachment->id,
            'original_name'  => $attachment->original_name,
            'deleted_by'     => $u->id,
            'reason'         => $isManager ? 'manager' : ($isUploader ? 'uploader' : 'assigned'),
        ]);

        return back()->with('status', '✓ Anexo removido.');
    }

    // ── Helpers ────────────────────────────────────────────────────────

    private function authorizeView(Tender $tender, bool $requireManager = false): void
    {
        $u = Auth::user();
        if (!$u) abort(401);
        if ($requireManager && !$u->isManager()) abort(403);

        // 2026-05-20: pedido directo do operador
        //   "quando adcionar psds ou exceleis ou ficheiros qualquer user
        //    pode entra e ver"
        //
        // Anexos (PDFs/Excel/etc) são recursos partilhados — qualquer
        // user autenticado faz upload E download, EXCEPTO em tenders
        // confidenciais. Igual ao enforceVisibility no TenderController.
        if ($u->can('tenders.view-all')) return;

        if ($tender->is_confidential) {
            $collab = $tender->collaborator;
            if (!$collab || $collab->user_id !== $u->id) {
                abort(403, 'Concurso confidencial — só atribuído + managers.');
            }
            return;
        }

        // Não-confidencial: aberto a todos os authenticated users.
    }

    /**
     * Tiny JSON shape used by the upload response and the page render.
     * Keeps front/back contracts in one place.
     */
    private function shape(TenderAttachment $a): array
    {
        return [
            'id'          => $a->id,
            'name'        => $a->original_name,
            'size_kb'     => (int) round($a->size_bytes / 1024),
            'extracted'   => $a->extraction_status === TenderAttachment::STATUS_OK,
            'chars'       => $a->extracted_chars,
            'error'       => $a->extraction_status === TenderAttachment::STATUS_FAILED ? $a->extraction_error : null,
            'uploaded_at' => $a->created_at?->diffForHumans(['short' => true]),
            'download_url'=> route('tenders.attachments.download', [$a->tender_id, $a->id]),
        ];
    }
}
