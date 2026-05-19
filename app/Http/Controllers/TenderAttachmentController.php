<?php

namespace App\Http\Controllers;

use App\Models\Tender;
use App\Models\TenderAttachment;
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

    public function store(Request $request, Tender $tender, PdfTextExtractor $extractor): JsonResponse
    {
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

            // Extracção apenas para PDFs. Outros formatos (xlsx, docx,
            // eml, imagens, …) ficam em STATUS_SKIPPED — guardados para
            // download mas sem texto extraído. O suggester / Marta CRM
            // já filtram por STATUS_OK antes de injectar no contexto.
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
            } else {
                $att->extraction_status = TenderAttachment::STATUS_SKIPPED;
                $att->extraction_error  = null;
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
        $this->authorizeView($tender, requireManager: true);
        if ($attachment->tender_id !== $tender->id) abort(404);

        try {
            Storage::disk(self::DISK)->delete($attachment->disk_path);
        } catch (\Throwable $e) { /* file already gone — fine */ }
        $attachment->delete();

        return back()->with('status', '✓ Anexo removido.');
    }

    // ── Helpers ────────────────────────────────────────────────────────

    private function authorizeView(Tender $tender, bool $requireManager = false): void
    {
        $u = Auth::user();
        if (!$u) abort(401);
        if ($requireManager && !$u->isManager()) abort(403);
        if ($u->can('tenders.view-all')) return;
        // 2026-05-19: Acingov/Vortal/Anogov = pool publico interno  qualquer
        // user autenticado pode ver (e descarregar/upload anexos). Pedido
        // directo da admin Monica.
        if (in_array($tender->source, \App\Models\Tender::PUBLIC_SOURCES, true)) return;
        $collab = $tender->collaborator;
        if (!$collab || $collab->user_id !== $u->id) abort(403);
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
