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
     *
     * 2026-05-19: pedido directo do operador
     *   "todos os agentes, menos os partilhados usam este modelo [MOD_072_V3]"
     *
     * Agora gera via PhpWord + PartYardMilitaryWordTemplate quando os
     * assets do template estão disponíveis em produção. Fallback para
     * DocxBuilder (manual ZIP) quando assets ausentes — env dev sem
     * o volume HP Group montado, ou se PhpWord falhar.
     */
    public function exportDocx(Request $request, DocxBuilder $builder): StreamedResponse
    {
        if (!Auth::check()) abort(401);
        $data = $request->validate([
            'markdown' => ['required', 'string', 'max:200000'],
            'title'    => ['nullable', 'string', 'max:120'],
        ]);

        $title    = $data['title'] ?? 'Work Report';
        $markdown = $data['markdown'];

        // Preferência: template MOD_072_V3 (header/footer Defense branding).
        $bytes = null;
        if (\App\Services\PartYardMilitaryWordTemplate::isAvailable()) {
            try {
                $bytes = $this->buildBrandedWorkReport($markdown, $title);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning(
                    'WorkReport: branded build failed, falling back to legacy — ' . $e->getMessage()
                );
            }
        }

        // Fallback: gerador legacy markdown→XML directo (sem branding).
        if ($bytes === null) {
            $bytes = $builder->buildFromMarkdown($markdown, $title);
        }

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

    /**
     * Gera o docx do Work Report com layout PartYard Military
     * (MOD_072_V3 header + footer + audit-line). Suporta markdown
     * com #/##/### headings, **bold**, *italic*, listas e tabelas.
     */
    private function buildBrandedWorkReport(string $markdown, string $title): string
    {
        $phpWord = new \PhpOffice\PhpWord\PhpWord();
        \App\Services\PartYardMilitaryWordTemplate::registerStyles($phpWord);

        $section = $phpWord->addSection(\App\Services\PartYardMilitaryWordTemplate::sectionConfig());
        \App\Services\PartYardMilitaryWordTemplate::apply($section, [
            'document_kind' => 'Work Report',
            'audit_ref'     => Auth::user()?->name
                ? 'Eng. ' . Auth::user()->name . ' · ' . now()->format('Y-m-d H:i')
                : 'Eng. Repair · ' . now()->format('Y-m-d H:i'),
        ]);

        // Título do relatório no topo (centrado, navy)
        $section->addText(
            \App\Services\PartYardMilitaryWordTemplate::xmlSafe($title),
            ['name' => 'Calibri', 'size' => 16, 'bold' => true, 'color' => '0F1B4C'],
            ['alignment' => \PhpOffice\PhpWord\SimpleType\Jc::CENTER]
        );
        $section->addText(
            'Gerado em ' . now()->format('d-m-Y H:i') . ' · ClawYard',
            ['size' => 8, 'color' => '94A3B8'],
            ['alignment' => \PhpOffice\PhpWord\SimpleType\Jc::CENTER]
        );
        $section->addTextBreak(1);

        $this->renderMarkdownToPhpWord($section, $markdown);

        $tmp = tempnam(sys_get_temp_dir(), 'workrep_') . '.docx';
        \PhpOffice\PhpWord\IOFactory::createWriter($phpWord, 'Word2007')->save($tmp);
        $bytes = (string) file_get_contents($tmp);
        @unlink($tmp);
        return $bytes;
    }

    /**
     * Markdown → PhpWord renderer minimal, suporta:
     *   • # H1, ## H2, ### H3
     *   • Parágrafos
     *   • **bold** e *italic*
     *   • Listas com - ou *
     *   • Tabelas pipe-markdown (| col | col |)
     *   • Quebras de linha duplas → novo parágrafo
     */
    private function renderMarkdownToPhpWord(\PhpOffice\PhpWord\Element\Section $section, string $md): void
    {
        $xmlSafe = fn(string $s) => \App\Services\PartYardMilitaryWordTemplate::xmlSafe($s);

        $blocks = preg_split("/\r?\n\r?\n+/", trim($md)) ?: [];
        foreach ($blocks as $block) {
            $block = trim($block);
            if ($block === '') continue;

            // Heading
            if (preg_match('/^(#{1,3})\s+(.+)$/m', $block, $m)) {
                $level    = strlen($m[1]);
                $headStyle = ['h1', 'h2', 'h3'][$level - 1];
                $section->addText($xmlSafe($this->stripInlineMd($m[2])), $headStyle);
                continue;
            }

            // Markdown table
            if (str_contains($block, '|') && preg_match('/^\s*\|/m', $block)) {
                $this->renderMarkdownTable($section, $block);
                continue;
            }

            // List
            $lines = preg_split("/\r?\n/", $block) ?: [];
            $isList = !empty(array_filter($lines, fn($l) => preg_match('/^\s*[-*]\s+/', $l)));
            if ($isList) {
                foreach ($lines as $line) {
                    if (preg_match('/^\s*[-*]\s+(.+)$/', $line, $mi)) {
                        $section->addListItem(
                            $xmlSafe($this->stripInlineMd(trim($mi[1]))),
                            0,
                            ['size' => 10],
                            'multilevel'
                        );
                    }
                }
                continue;
            }

            // Plain paragraph com soft line-breaks
            $p = $section->addTextRun();
            foreach ($lines as $idx => $line) {
                if ($idx > 0) $p->addTextBreak();
                $this->addInlineRun($p, $line, $xmlSafe);
            }
        }
    }

    /** Render inline com suporte a **bold** e *italic*. */
    private function addInlineRun(\PhpOffice\PhpWord\Element\TextRun $p, string $line, callable $xmlSafe): void
    {
        $tokens = preg_split('/(\*\*[^*]+\*\*|\*[^*]+\*)/', $line, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        foreach ($tokens as $t) {
            if ($t === '') continue;
            $bold = false; $italic = false; $text = $t;
            if (preg_match('/^\*\*(.+)\*\*$/', $t, $m)) { $bold = true;   $text = $m[1]; }
            elseif (preg_match('/^\*(.+)\*$/', $t, $m)) { $italic = true; $text = $m[1]; }
            $style = ['size' => 10];
            if ($bold)   $style['bold']   = true;
            if ($italic) $style['italic'] = true;
            $p->addText($xmlSafe($text), $style);
        }
    }

    /** Tabela markdown → PhpWord table. */
    private function renderMarkdownTable(\PhpOffice\PhpWord\Element\Section $section, string $block): void
    {
        $xmlSafe = fn(string $s) => \App\Services\PartYardMilitaryWordTemplate::xmlSafe($s);
        $lines  = array_values(array_filter(array_map('trim', preg_split("/\r?\n/", $block) ?: [])));
        $rows   = [];
        foreach ($lines as $line) {
            if (preg_match('/^\|?\s*[-:|\s]+\|?\s*$/', $line)) continue; // separator row
            $cells   = array_map('trim', explode('|', trim($line, '|')));
            $rows[]  = $cells;
        }
        if (empty($rows)) return;

        $table = $section->addTable([
            'borderColor' => 'CBD5E1',
            'borderSize'  => 4,
            'cellMargin'  => 80,
            'alignment'   => \PhpOffice\PhpWord\SimpleType\JcTable::CENTER,
        ]);

        $headerBg = ['bgColor' => '0F1B4C'];
        foreach ($rows as $i => $row) {
            $isHeader = ($i === 0);
            $table->addRow($isHeader ? 320 : 280);
            foreach ($row as $cell) {
                $bg   = $isHeader ? $headerBg : ($i % 2 === 0 ? ['bgColor' => 'F8FAFC'] : null);
                $cellNode = $table->addCell(2400, $bg);
                $cellNode->addText(
                    $xmlSafe($this->stripInlineMd($cell)),
                    $isHeader ? 'th' : 'body'
                );
            }
        }
    }

    private function stripInlineMd(string $s): string
    {
        $s = preg_replace('/\*\*(.+?)\*\*/', '$1', $s) ?? $s;
        $s = preg_replace('/\*(.+?)\*/',     '$1', $s) ?? $s;
        $s = preg_replace('/`(.+?)`/',       '$1', $s) ?? $s;
        return $s;
    }
}
