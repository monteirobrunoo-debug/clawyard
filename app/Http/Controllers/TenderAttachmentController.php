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
 * Per-tender PDF attachment management.
 *
 * Routes:
 *   POST   /tenders/{tender}/attachments        — drag-drop upload
 *   GET    /tenders/{tender}/attachments/{id}   — download (auth proxy)
 *   DELETE /tenders/{tender}/attachments/{id}   — soft-delete (manager+)
 *
 * Accepts: PDF only (10 MB cap per file). Multiple files per request.
 * Side effect on success: text is extracted synchronously via
 * PdfTextExtractor and persisted to extracted_text/extracted_chars/
 * extraction_status. The suggester + Marta CRM trigger read from
 * those columns later — no async re-fetch.
 *
 * Idempotency: file_hash + tender_id is unique. Re-uploading the
 * same PDF returns the existing row instead of creating a duplicate.
 */
class TenderAttachmentController extends Controller
{
    private const DISK            = 'local';
    private const PATH_PREFIX     = 'tender-attachments';
    private const MAX_BYTES_PER   = 10 * 1024 * 1024;   // 10 MB
    private const MAX_FILES_PER   = 10;

    public function store(Request $request, Tender $tender, PdfTextExtractor $extractor): JsonResponse
    {
        $this->authorizeView($tender);

        $request->validate([
            'files'   => ['required', 'array', 'min:1', 'max:' . self::MAX_FILES_PER],
            'files.*' => ['file', 'mimes:pdf', 'max:' . (self::MAX_BYTES_PER / 1024)],
        ], [
            'files.required' => 'Anexa pelo menos um PDF.',
            'files.max'      => 'Máximo ' . self::MAX_FILES_PER . ' ficheiros por upload.',
            'files.*.mimes'  => 'Aceita apenas PDF.',
            'files.*.max'    => 'Cada PDF tem de ter ≤ 10 MB.',
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

            // Persist file → /storage/app/private/tender-attachments/{id}/{slug}.pdf
            $slug    = Str::slug(pathinfo($f->getClientOriginalName(), PATHINFO_FILENAME));
            $slug    = $slug !== '' ? $slug : 'doc';
            $relPath = self::PATH_PREFIX . '/' . $tender->id . '/' . $slug . '-' . substr($hash, 0, 8) . '.pdf';
            try {
                Storage::disk(self::DISK)->putFileAs(
                    self::PATH_PREFIX . '/' . $tender->id,
                    $f,
                    $slug . '-' . substr($hash, 0, 8) . '.pdf',
                );
            } catch (\Throwable $e) {
                $failed[] = [$f->getClientOriginalName(), 'storage_failed: ' . $e->getMessage()];
                continue;
            }

            $att = TenderAttachment::create([
                'tender_id'           => $tender->id,
                'original_name'       => $f->getClientOriginalName(),
                'disk_path'           => $relPath,
                'mime_type'           => $f->getClientMimeType(),
                'size_bytes'          => $f->getSize(),
                'file_hash'           => $hash,
                'extraction_status'   => TenderAttachment::STATUS_PENDING,
                'uploaded_by_user_id' => Auth::id(),
            ]);

            // Synchronous extraction — small PDFs parse in < 1s. For
            // larger ones this could move to a queued job, but for
            // typical tender RFQs (≤ 30 pages) the user benefits from
            // immediate text-availability when they hit "Sugerir".
            $absolutePath = Storage::disk(self::DISK)->path($relPath);
            $res = $extractor->extract($absolutePath);
            if ($res['ok']) {
                $att->extracted_text   = $res['text'];
                $att->extracted_chars  = mb_strlen($res['text']);
                $att->extraction_status = TenderAttachment::STATUS_OK;
                $att->extraction_error = null;
            } else {
                $att->extraction_status = TenderAttachment::STATUS_FAILED;
                $att->extraction_error  = mb_substr((string) ($res['error'] ?? 'unknown'), 0, 500);
            }
            $att->save();
            $created[] = $att->id;
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

        return Storage::disk(self::DISK)->download($attachment->disk_path, $attachment->original_name, [
            'Content-Type'  => 'application/pdf',
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
