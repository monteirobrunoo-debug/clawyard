<?php

namespace App\Http\Controllers;

use App\Agents\CrmAgent;
use App\Models\Supplier;
use App\Models\Tender;
use App\Models\TenderAttachment;
use App\Models\TenderSupplierQuotation;
use App\Services\AgentSwarm\AgentDispatcher;
use App\Services\PdfTextExtractor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Fase B — cotações dos fornecedores no dashboard do tender.
 *
 * Endpoints:
 *   POST   /tenders/{tender}/quotations          → store (manual)
 *   POST   /tenders/{tender}/quotations/extract  → upload PDF + Marta parse
 *   PATCH  /tenders/{tender}/quotations/{q}      → update inline
 *   DELETE /tenders/{tender}/quotations/{q}      → soft remove
 *   GET    /tenders/{tender}/quotations/export   → Excel comparativo
 *
 * Manter o flow simples: o operador pode (A) meter manualmente os
 * valores ou (B) carregar o PDF do fornecedor e a Marta extrai
 * unit_price, delivery_days, validity_days, incoterm. Excel exporta
 * todas as cotações da tender em formato comparativo.
 */
class TenderSupplierQuotationController extends Controller
{
    public function __construct(
        private AgentDispatcher $dispatcher,
        private PdfTextExtractor $pdfExtractor,
    ) {}

    /** Inserção manual rápida. Aceita formulário inline da tabela. */
    public function store(Request $request, Tender $tender): JsonResponse
    {
        $this->authorizeView($tender);

        $data = $request->validate([
            'supplier_id'             => ['nullable', 'integer', 'exists:suppliers,id'],
            'supplier_name_freetext'  => ['nullable', 'string', 'max:200'],
            'unit_price'              => ['nullable', 'numeric', 'min:0'],
            'currency'                => ['nullable', 'string', 'size:3'],
            'quantity'                => ['nullable', 'integer', 'min:1'],
            'delivery_days'           => ['nullable', 'integer', 'min:0', 'max:9999'],
            'validity_days'           => ['nullable', 'integer', 'min:0', 'max:9999'],
            'incoterm'                => ['nullable', 'string', 'max:10'],
            'notes'                   => ['nullable', 'string', 'max:5000'],
        ]);

        if (empty($data['supplier_id']) && empty($data['supplier_name_freetext'])) {
            return response()->json([
                'ok' => false,
                'error' => 'Precisas de identificar o fornecedor (supplier_id ou supplier_name_freetext).',
            ], 422);
        }

        $q = new TenderSupplierQuotation([
            'tender_id'              => $tender->id,
            'supplier_id'            => $data['supplier_id'] ?? null,
            'supplier_name_freetext' => $data['supplier_name_freetext'] ?? null,
            'unit_price'             => $data['unit_price'] ?? null,
            'currency'               => strtoupper($data['currency'] ?? 'EUR'),
            'quantity'               => $data['quantity'] ?? 1,
            'delivery_days'          => $data['delivery_days'] ?? null,
            'validity_days'          => $data['validity_days'] ?? null,
            'incoterm'               => isset($data['incoterm']) ? strtoupper($data['incoterm']) : null,
            'notes'                  => $data['notes'] ?? null,
            'created_by_user_id'     => Auth::id(),
        ]);
        $q->total_price = $q->effectiveTotal();
        $q->save();

        return response()->json([
            'ok'        => true,
            'quotation' => $this->shape($q->fresh(['supplier', 'attachment'])),
        ]);
    }

    /** Update inline (ex: mudar markup ou correção de preço). */
    public function update(Request $request, Tender $tender, TenderSupplierQuotation $quotation): JsonResponse
    {
        $this->authorizeView($tender);
        if ($quotation->tender_id !== $tender->id) abort(404);

        $data = $request->validate([
            'unit_price'    => ['nullable', 'numeric', 'min:0'],
            'currency'      => ['nullable', 'string', 'size:3'],
            'quantity'      => ['nullable', 'integer', 'min:1'],
            'delivery_days' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'validity_days' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'incoterm'      => ['nullable', 'string', 'max:10'],
            'notes'         => ['nullable', 'string', 'max:5000'],
        ]);

        foreach ($data as $k => $v) {
            if ($k === 'currency' && $v !== null) $v = strtoupper($v);
            if ($k === 'incoterm' && $v !== null) $v = strtoupper($v);
            $quotation->{$k} = $v;
        }
        $quotation->total_price = $quotation->effectiveTotal();
        $quotation->save();

        return response()->json([
            'ok'        => true,
            'quotation' => $this->shape($quotation->fresh(['supplier', 'attachment'])),
        ]);
    }

    public function destroy(Tender $tender, TenderSupplierQuotation $quotation): JsonResponse
    {
        $this->authorizeView($tender);
        if ($quotation->tender_id !== $tender->id) abort(404);
        $quotation->delete();
        return response()->json(['ok' => true]);
    }

    /**
     * Upload PDF da cotação + Marta extrai. Salva como TenderAttachment +
     * cria a TenderSupplierQuotation com campos preenchidos pelo LLM.
     *
     * 2026-05-25: outer try/catch defensivo — qualquer exception inesperada
     * (encryption SafeEncryptedString, DB constraint, Marta hang) devolve
     * JSON com diagnóstico em vez de 500 HTML opaco. JS modal mostra o
     * erro real ao user via alert().
     */
    public function extract(Request $request, Tender $tender): JsonResponse
    {
        $this->authorizeView($tender);

        $request->validate([
            'file'                    => ['required', 'file', 'mimes:pdf', 'max:30720'],
            'supplier_id'             => ['nullable', 'integer', 'exists:suppliers,id'],
            'supplier_name_freetext'  => ['nullable', 'string', 'max:200'],
        ], [
            'file.required' => 'Selecciona o PDF da cotação do fornecedor.',
            'file.mimes'    => 'Só PDFs aceites.',
            'file.max'      => 'PDF máximo 30 MB.',
        ]);

        if (!$request->filled('supplier_id') && !$request->filled('supplier_name_freetext')) {
            return response()->json([
                'ok' => false,
                'error' => 'Identifica o fornecedor (supplier_id ou nome livre).',
            ], 422);
        }

        $file = $request->file('file');

        // 1. Persistir o PDF como tender attachment (com extracção normal)
        $originalName = $file->getClientOriginalName();
        $hash = hash_file('sha256', $file->getRealPath());
        $slug = Str::slug(pathinfo($originalName, PATHINFO_FILENAME)) ?: 'quote';
        $storedName = 'quote-' . $slug . '-' . substr($hash, 0, 8) . '.pdf';
        $relPath = 'tender-attachments/' . $tender->id . '/' . $storedName;

        try {
            Storage::disk('local')->putFileAs(
                'tender-attachments/' . $tender->id,
                $file,
                $storedName,
            );
        } catch (\Throwable $e) {
            Log::warning('Quotation extract: storage failed', [
                'tender_id' => $tender->id,
                'error'     => $e->getMessage(),
            ]);
            return response()->json(['ok' => false, 'error' => 'Storage falhou: ' . $e->getMessage()], 500);
        }

        // Outer try/catch — qualquer fatal após upload retorna JSON diagnostic
        // em vez de 500 HTML opaco.
        try {
            $absolute = Storage::disk('local')->path($relPath);

            // 2026-05-25: reuse attachment existente quando o mesmo PDF
            // já foi carregado para este tender. Unique constraint
            // (tender_id, file_hash) bloqueava re-upload mas user pode
            // querer criar múltiplas cotações que partilham o mesmo PDF
            // (mesmo doc, fornecedores diferentes na tabela comparativa).
            $att = TenderAttachment::where('tender_id', $tender->id)
                ->where('file_hash', $hash)
                ->first();

            if ($att) {
                // PDF já indexado anteriormente — reusa texto extraído.
                Log::info('Quotation extract: reusing existing attachment', [
                    'tender_id'     => $tender->id,
                    'attachment_id' => $att->id,
                ]);
                $pdfText = (string) ($att->extracted_text ?? '');
            } else {
                // Novo PDF — extrai e cria.
                $extracted = $this->pdfExtractor->extract($absolute);
                $pdfText   = ($extracted['ok'] ?? false) ? (string) ($extracted['text'] ?? '') : '';

                $att = TenderAttachment::create([
                    'tender_id'           => $tender->id,
                    'original_name'       => $originalName,
                    'disk_path'           => $relPath,
                    'mime_type'           => $file->getClientMimeType() ?: 'application/pdf',
                    'size_bytes'          => $file->getSize(),
                    'file_hash'           => $hash,
                    'extraction_status'   => $pdfText !== '' ? TenderAttachment::STATUS_OK : TenderAttachment::STATUS_FAILED,
                    'extracted_text'      => $pdfText !== '' ? $pdfText : null,
                    'extracted_chars'     => mb_strlen($pdfText),
                    'uploaded_by_user_id' => Auth::id(),
                ]);
            }

            // 2. Marta extrai campos do PDF
            $parsed = [];
            if ($pdfText !== '') {
                try {
                    $parsed = $this->callMartaParse($pdfText);
                } catch (\Throwable $e) {
                    Log::warning('Quotation extract: Marta failed', [
                        'tender_id' => $tender->id,
                        'error'     => $e->getMessage(),
                    ]);
                }
            }

            // 3. Cria a quotation com defaults do PDF + override do request
            $q = new TenderSupplierQuotation([
                'tender_id'              => $tender->id,
                'supplier_id'            => $request->integer('supplier_id') ?: null,
                'supplier_name_freetext' => $request->input('supplier_name_freetext'),
                'unit_price'             => $parsed['unit_price'] ?? null,
                'currency'               => strtoupper((string) ($parsed['currency'] ?? 'EUR')) ?: 'EUR',
                'quantity'               => (int) ($parsed['quantity'] ?? 1),
                'delivery_days'          => $parsed['delivery_days'] ?? null,
                'validity_days'          => $parsed['validity_days'] ?? null,
                'incoterm'               => isset($parsed['incoterm']) ? strtoupper((string) $parsed['incoterm']) : null,
                'notes'                  => $parsed['notes'] ?? null,
                'pdf_attachment_id'      => $att->id,
                'parsed_by_marta_at'     => !empty($parsed) ? now() : null,
                'created_by_user_id'     => Auth::id(),
            ]);
            $q->total_price = $q->effectiveTotal();
            $q->save();

            return response()->json([
                'ok'        => true,
                'quotation' => $this->shape($q->fresh(['supplier', 'attachment'])),
                'parsed_ok' => !empty($parsed),
            ]);

        } catch (\Throwable $e) {
            Log::error('Quotation extract: fatal', [
                'tender_id' => $tender->id,
                'user_id'   => Auth::id(),
                'file'      => $originalName,
                'error'     => $e->getMessage(),
                'file_line' => $e->getFile() . ':' . $e->getLine(),
                'trace'     => mb_substr($e->getTraceAsString(), 0, 1500),
            ]);
            return response()->json([
                'ok'    => false,
                'error' => 'Erro ao processar cotação: ' . $e->getMessage(),
            ], 500);
        }
    }

    /** Excel comparativo de TODAS as cotações da tender. */
    public function export(Tender $tender): StreamedResponse
    {
        $this->authorizeView($tender);

        $quotes = TenderSupplierQuotation::where('tender_id', $tender->id)
            ->with('supplier')
            ->orderBy('unit_price', 'asc')  // melhor preço primeiro
            ->get();

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Cotações');

        // Header info do tender
        $sheet->setCellValue('A1', 'Concurso');
        $sheet->setCellValue('B1', $tender->reference . ' — ' . mb_strimwidth((string) $tender->title, 0, 80, '…'));
        $sheet->setCellValue('A2', 'Organização');
        $sheet->setCellValue('B2', $tender->purchasing_org ?: '—');
        $sheet->setCellValue('A3', 'Deadline');
        $sheet->setCellValue('B3', $tender->deadline_lisbon?->format('d/m/Y H:i') ?? '—');
        $sheet->setCellValue('A4', 'Exportado');
        $sheet->setCellValue('B4', now()->format('d/m/Y H:i'));
        $sheet->getStyle('A1:A4')->getFont()->setBold(true);

        // Table headers
        $headerRow = 6;
        $headers = ['#', 'Fornecedor', 'Preço Unit.', 'Qty', 'Total', 'Moeda',
                    'Entrega (dias)', 'Validade (dias)', 'Incoterm', 'Notas', 'Marta?', 'Data'];
        foreach ($headers as $i => $h) {
            $col = chr(ord('A') + $i);
            $sheet->setCellValue($col . $headerRow, $h);
        }
        $sheet->getStyle('A' . $headerRow . ':L' . $headerRow)->getFont()->setBold(true);
        $sheet->getStyle('A' . $headerRow . ':L' . $headerRow)
              ->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
              ->getStartColor()->setRGB('E2E8F0');

        // Data rows
        $row = $headerRow + 1;
        foreach ($quotes as $i => $q) {
            $sheet->setCellValue('A' . $row, $i + 1);
            $sheet->setCellValue('B' . $row, $q->supplierName());
            $sheet->setCellValue('C' . $row, $q->unit_price);
            $sheet->setCellValue('D' . $row, $q->quantity);
            $sheet->setCellValue('E' . $row, $q->total_price ?? $q->effectiveTotal());
            $sheet->setCellValue('F' . $row, $q->currency);
            $sheet->setCellValue('G' . $row, $q->delivery_days);
            $sheet->setCellValue('H' . $row, $q->validity_days);
            $sheet->setCellValue('I' . $row, $q->incoterm);
            $sheet->setCellValue('J' . $row, $q->notes);
            $sheet->setCellValue('K' . $row, $q->parsed_by_marta_at ? '✓' : '—');
            $sheet->setCellValue('L' . $row, $q->created_at?->format('d/m/Y'));
            $row++;
        }

        if ($quotes->isEmpty()) {
            $sheet->setCellValue('A' . $row, 'Sem cotações registadas ainda.');
            $sheet->mergeCells('A' . $row . ':L' . $row);
        }

        foreach (range('A', 'L') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $refForFile = $tender->sap_opportunity_number ?: 'PY' . $tender->id;
        $fileName   = 'Cotacoes-' . $refForFile . '-' . now()->format('Ymd-Hi') . '.xlsx';

        return response()->streamDownload(function () use ($spreadsheet) {
            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
        }, $fileName, [
            'Content-Type'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control'       => 'private, max-age=0, no-store',
        ]);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────

    private function authorizeView(Tender $tender): void
    {
        $u = Auth::user();
        if (!$u) abort(401);
        if ($u->can('tenders.view-all')) return;
        if ($tender->is_confidential) {
            $collab = $tender->collaborator;
            if (!$collab || $collab->user_id !== $u->id) abort(403);
        }
    }

    private function shape(TenderSupplierQuotation $q): array
    {
        return [
            'id'             => $q->id,
            'supplier_id'    => $q->supplier_id,
            'supplier_name'  => $q->supplierName(),
            'unit_price'     => $q->unit_price,
            'currency'       => $q->currency,
            'quantity'       => $q->quantity,
            'total_price'    => $q->total_price ?? $q->effectiveTotal(),
            'delivery_days'  => $q->delivery_days,
            'validity_days'  => $q->validity_days,
            'incoterm'       => $q->incoterm,
            'notes'          => $q->notes,
            'attachment_url' => $q->pdf_attachment_id && $q->tender
                ? route('tenders.attachments.download', [$q->tender_id, $q->pdf_attachment_id])
                : null,
            'parsed_by_marta'=> $q->parsed_by_marta_at !== null,
            'created_at'     => $q->created_at?->diffForHumans(['short' => true]),
        ];
    }

    /**
     * Pede à Marta CRM os campos da cotação a partir do texto do PDF.
     * Devolve array com chaves: unit_price, currency, quantity,
     * delivery_days, validity_days, incoterm, notes.
     */
    private function callMartaParse(string $pdfText): array
    {
        $snippet = mb_substr($pdfText, 0, 12000);

        $system = <<<PROMPT
És a Marta CRM do PartYard. Recebes o texto extraído de um PDF de
cotação enviado por um fornecedor. Devolve APENAS este JSON:

{
  "unit_price": 1234.56,          // preço unitário (decimal · null se não claro)
  "currency": "EUR",              // ISO 3 chars (EUR, USD, GBP) · default EUR
  "quantity": 1,                  // quantidade (default 1 se único item)
  "delivery_days": 56,            // lead time em dias (null se não dito)
  "validity_days": 30,            // validade da cotação em dias (null se não dito)
  "incoterm": "DAP",              // CIF/FCA/DAP/EXW/DDP/etc · null se não claro
  "notes": "≤200 chars com qualquer info relevante (ex: descontos, condições especiais, P/N)"
}

REGRAS:
  • Se não tens a certeza, devolve null em vez de inventar.
  • Aceita formats europeus (1.234,56) e converte para 1234.56.
  • Devolve APENAS o JSON, sem markdown ou texto antes/depois.
PROMPT;

        $userMsg = "Texto do PDF da cotação:\n\n{$snippet}\n\nDevolve o JSON.";

        $res = $this->dispatcher->dispatch(
            systemPrompt: $system,
            userMessage:  $userMsg,
            maxTokens:    600,
        );

        if (!($res['ok'] ?? false)) return [];

        $raw = trim((string) ($res['text'] ?? ''));
        $clean = preg_replace('/^```(?:json)?\s*|\s*```\s*$/m', '', $raw) ?? $raw;
        if (!preg_match('/\{[\s\S]*\}/', $clean, $m)) return [];

        $decoded = json_decode($m[0], true);
        return is_array($decoded) ? $decoded : [];
    }
}
