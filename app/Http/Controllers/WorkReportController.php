<?php

namespace App\Http\Controllers;

use App\Services\DocxBuilder;
use App\Services\TechnicalBookSearch;
use App\Services\WorkReportBridgeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Endpoints do Eng. Repair (WorkReportAgent):
 *
 *   POST /workreport/bridge/analyze-pdf-scope
 *   POST /workreport/bridge/analyze-photos
 *   GET  /workreport/books/search?q=...&domain=soldadura
 *   POST /workreport/export.docx   — markdown body → download .docx
 */
class WorkReportController extends Controller
{
    /** Proxy: PDF do user → Work Report App standalone → JSON ao chat. */
    public function analyzePdfScope(Request $request, WorkReportBridgeService $bridge): JsonResponse
    {
        if (!Auth::check()) abort(401);
        $request->validate(['pdf' => ['required', 'file', 'mimes:pdf', 'max:10240']]);

        $bytes = file_get_contents($request->file('pdf')->getRealPath());
        $name  = $request->file('pdf')->getClientOriginalName();

        $result = $bridge->analyzePdfScope($bytes, $name);
        return response()->json($result, ($result['ok'] ?? false) ? 200 : 502);
    }

    /** Proxy: imagens (base64) → standalone vision API. */
    public function analyzePhotos(Request $request, WorkReportBridgeService $bridge): JsonResponse
    {
        if (!Auth::check()) abort(401);
        $data = $request->validate([
            'images'      => ['required', 'array', 'min:1', 'max:12'],
            'job_context' => ['nullable', 'array'],
        ]);
        $result = $bridge->analyzePhotos($data['images'], $data['job_context'] ?? []);
        return response()->json($result, ($result['ok'] ?? false) ? 200 : 502);
    }

    /**
     * Stream do PDF original do livro técnico, com hint de página
     * via fragment #page=N (browsers nativos + PDF.js respeitam).
     *
     * Auth: any authenticated non-guest user. Path traversal blocked
     * por whitelist do book_key contra a tabela technical_book_chunks.
     *
     *   GET /workreport/books/{book_key}.pdf?page=87
     *
     * Resposta tem Content-Disposition: inline para abrir no browser
     * (não download). Browser navega o PDF para a página correcta
     * via #page= fragment.
     */
    public function previewBook(Request $request, string $book_key): \Symfony\Component\HttpFoundation\Response
    {
        if (!Auth::check()) abort(401);
        $page = max(1, min(2000, (int) $request->query('page', 1)));

        // Resolve book_key → file path. Whitelist check: tem de existir
        // pelo menos 1 chunk para este book_key. Bloqueia path traversal.
        $exists = \App\Models\TechnicalBookChunk::where('book_key', $book_key)->exists();
        if (!$exists) abort(404, 'Livro desconhecido');

        // Procura o ficheiro físico nos sub-domains conhecidos
        $base   = storage_path('app/biblioteca-tecnica');
        $found  = null;
        foreach (['soldadura', 'naval', 'outros'] as $dom) {
            // Tenta nomes possíveis (com ou sem extensão sanitizada)
            foreach (glob("{$base}/{$dom}/*.pdf") ?: [] as $candidate) {
                $cand_key = preg_replace('/\.pdf$/i', '', basename($candidate));
                $cand_key = preg_replace('/[^A-Za-z0-9_\-]/', '-', $cand_key);
                if (mb_substr($cand_key, 0, 80) === $book_key) {
                    $found = $candidate;
                    break 2;
                }
            }
        }

        if (!$found || !is_readable($found)) {
            abort(404, 'Ficheiro PDF não encontrado no servidor');
        }

        return response()->file($found, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . basename($found) . '"',
            'X-Frame-Options'     => 'SAMEORIGIN',  // permite embed no clawyard
            // page hint via fragment é client-side; cache 1h é seguro
            'Cache-Control'       => 'private, max-age=3600',
        ]);
    }

    /** Pesquisa keyword na biblioteca técnica. */
    public function booksSearch(Request $request, TechnicalBookSearch $search): JsonResponse
    {
        if (!Auth::check()) abort(401);
        $q      = (string) $request->query('q', '');
        $domain = $request->query('domain') ?: null;

        if (mb_strlen(trim($q)) < 3) {
            return response()->json(['error' => 'query_too_short', 'detail' => 'Mínimo 3 chars'], 422);
        }

        return response()->json([
            'query'   => $q,
            'domain'  => $domain,
            'results' => $search->search($q, 8, $domain),
        ]);
    }

    /**
     * Recebe markdown do user (output do WorkReportAgent) e devolve
     * o ficheiro .docx para download. Usado pelo botão "📄 Download
     * Word" no chat após o agente gerar um relatório.
     */
    public function exportDocx(Request $request, DocxBuilder $builder): StreamedResponse
    {
        if (!Auth::check()) abort(401);
        $data = $request->validate([
            'markdown' => ['required', 'string', 'max:200000'],
            'title'    => ['nullable', 'string', 'max:120'],
        ]);

        $bytes = $builder->buildFromMarkdown($data['markdown'], $data['title'] ?? 'Work Report');
        $filename = 'work-report-' . now()->format('Y-m-d-Hi') . '.docx';

        return response()->streamDownload(
            fn() => print($bytes),
            $filename,
            [
                'Content-Type'        => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'Cache-Control'       => 'no-cache, no-store',
            ]
        );
    }
}
