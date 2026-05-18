<?php

namespace App\Http\Controllers;

use App\Models\Supplier;
use App\Models\Tender;
use App\Models\TenderAttachment;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Geração do "PartYard Defense Inquiry" — PDF que mimica o modelo
 * MOD_072_V3 do Sistema_Gestão_Qualidade/QUALIDADE/MODELOS/PY Military.
 *
 * 2026-05-18: pedido directo do operador:
 *   "aceito um documento da partyard em word e pdf conforme os modelos
 *   para a partyard defense request"
 *
 * Inclui:
 *   • Cabeçalho PartYard Defense + referência do concurso
 *   • Contacto PartYard (vendedor) + Fornecedor destinatário
 *   • Tabela com items extraídos do RFP/SoR (descrição, P/N, qty, specs)
 *   • Statement of Requirements integral em bloco mono (fonte da verdade)
 *   • Termos standard NATO/NSPA (cotação por linha, lead time, EUR.1, etc.)
 *   • Bloco assinatura PartYard / Fornecedor
 *   • Rodapé MOD_072_V3 para auditoria SGQ
 *
 * O PDF gerado é automaticamente anexado ao concurso na tabela
 * TenderAttachment (idempotente via file_hash).
 */
class TenderInquiryController extends Controller
{
    /**
     * GET /tenders/{tender}/inquiry-pdf[?supplier_id=X]
     */
    public function generate(Request $request, Tender $tender): Response
    {
        $this->authorizeView($tender);

        if ($tender->is_confidential) {
            abort(403, 'Concurso confidencial — geração de Inquiry desligada por segurança.');
        }

        // Fornecedor específico (se fornecido) — preenche destinatário
        // no PDF. Sem ID gera versão genérica "para qualquer fornecedor".
        $supplier = null;
        if ($request->filled('supplier_id')) {
            $supplier = Supplier::find($request->integer('supplier_id'));
        }

        // Statement of Requirements — concatena de TODOS os anexos OK
        // que tenham secção SoR detectável. Se nenhum tiver, usa o texto
        // integral do primeiro anexo como fallback (operador vê tudo).
        $tender->load('attachments');
        $okAttachments = $tender->attachments->where('extraction_status', 'ok');
        $sors = [];
        foreach ($okAttachments as $att) {
            $sor = $att->extractStatementOfRequirements(10000);
            if ($sor) $sors[] = "[{$att->original_name}]\n{$sor}";
        }
        $sor = $sors ? implode("\n\n", $sors) : null;

        // Fallback: se SoR não foi detectado mas há anexos, usa o
        // primeiro até 8KB.
        if (!$sor && $okAttachments->isNotEmpty()) {
            $first = $okAttachments->first();
            $sor = "[{$first->original_name} · texto integral, SoR não detectado]\n"
                 . mb_substr((string) $first->extracted_text, 0, 8000);
        }

        // Parse items do SoR via regex heurística — captura linhas
        // estruturadas tipo:
        //   "Item 1: NETGATE-7100 · P/N NG-7100 · Qty 5"
        //   "2x TANQUE VERTICAL 500L · P/N HJI-500L"
        $items = $this->parseItems($sor ?? '');

        $contactName  = Auth::user()?->name  ?? 'PartYard Defense';
        $contactEmail = Auth::user()?->email ?? config('mail.from.address', 'defense@hp-group.org');

        $pdf = Pdf::loadView('tenders.inquiry-partyard-pdf', [
            'tender'        => $tender,
            'supplier'      => $supplier,
            'items'         => $items,
            'sor'           => $sor,
            'today'         => now(),
            'contactName'   => $contactName,
            'contactEmail'  => $contactEmail,
        ])->setPaper('A4', 'portrait');

        $bytes = $pdf->output();

        // Auto-anexar ao concurso (idempotente via file_hash).
        $supplierTag = $supplier ? '-' . Str::slug($supplier->name) : '';
        $slug = Str::slug(($tender->reference ?: 'concurso-' . $tender->id) . '-inquiry' . $supplierTag);
        $hash = hash('sha256', $bytes);
        $storedName = $slug . '-' . substr($hash, 0, 8) . '.pdf';
        $relPath = 'tender-attachments/' . $tender->id . '/' . $storedName;

        $existing = TenderAttachment::where('tender_id', $tender->id)
            ->where('file_hash', $hash)
            ->first();
        if (!$existing) {
            try {
                Storage::disk('local')->put($relPath, $bytes);
                TenderAttachment::create([
                    'tender_id'           => $tender->id,
                    'original_name'       => 'Inquiry-PartYard-' . $tender->id . ($supplier ? '-' . Str::slug($supplier->name) : '') . '.pdf',
                    'disk_path'           => $relPath,
                    'mime_type'           => 'application/pdf',
                    'size_bytes'          => strlen($bytes),
                    'file_hash'           => $hash,
                    'extraction_status'   => TenderAttachment::STATUS_OK,
                    'extracted_text'      => "Inquiry PartYard Defense gerado em " . now()->format('d/m/Y H:i')
                                            . ($supplier ? " para {$supplier->name}" : ''),
                    'extracted_chars'     => 80,
                    'uploaded_by_user_id' => Auth::id(),
                ]);
            } catch (\Throwable $e) {
                Log::warning('Inquiry PDF: storage failed', ['tender_id' => $tender->id, 'error' => $e->getMessage()]);
            }
        } else {
            $existing->touch();
        }

        $downloadName = 'Inquiry-PartYard-' . ($tender->reference ?: $tender->id) . ($supplier ? '-' . $supplier->slug : '') . '.pdf';

        return response($bytes, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $downloadName . '"',
            'Cache-Control'       => 'private, max-age=0, no-store',
        ]);
    }

    /**
     * Parser heurístico de items a partir do texto SoR.
     * Captura padrões comuns em RFPs NATO/NSPA:
     *   • "Item N: descrição · P/N XXX · Qty YY · specs"
     *   • "N. descrição · part number XXX · quantity YY"
     *   • "Lot N: descrição"
     *   • "Nx descrição · P/N XXX"
     * Devolve até 20 items (mais que isto fica ilegível na tabela A4).
     *
     * @return list<array{desc: string, pn: string, qty: string, specs: string, norms: string}>
     */
    private function parseItems(string $text): array
    {
        if ($text === '') return [];

        $items = [];
        $lines = preg_split('/\r?\n/', $text) ?: [];
        foreach ($lines as $line) {
            $line = trim((string) $line);
            if (mb_strlen($line) < 12 || mb_strlen($line) > 400) continue;

            // Padrões "Item N:", "N.", "Lot N", "Nx <coisa>", "• <coisa>"
            $isItem = preg_match('/^(?:item\s+\d+[\.:]|\d+[\.\)]\s+|lot\s+\d+[\.:]|•\s+|\d+\s*x\s+)/iu', $line);
            $hasPn  = preg_match('/(P\/N|Part\s*Number|Item\s*Code|NSN)\s*[:=]?\s*([A-Z0-9\.\-\/_]+)/i', $line, $pnM);
            $hasQty = preg_match('/(Qty|Quantity|QT|qtde)\s*[:=]?\s*(\d+(?:\s*(?:units?|pcs?|peças?|unidades?))?)/i', $line, $qtyM)
                  || preg_match('/^(\d+)\s*x\s+/u', $line, $qtyM);

            if (!$isItem && !$hasPn && !$hasQty) continue;

            // Desc: tira P/N, Qty, normas conhecidas, separadores
            $desc = $line;
            $desc = preg_replace('/(P\/N|Part\s*Number|Item\s*Code|NSN)\s*[:=]?\s*[A-Z0-9\.\-\/_]+/i', '', $desc) ?? $desc;
            $desc = preg_replace('/(Qty|Quantity|QT|qtde)\s*[:=]?\s*\d+(?:\s*(?:units?|pcs?|peças?|unidades?))?/i', '', $desc) ?? $desc;
            $desc = preg_replace('/^(?:item\s+\d+[\.:]|\d+[\.\)]\s+|lot\s+\d+[\.:]|•\s+|\d+\s*x\s+)/iu', '', $desc) ?? $desc;
            $desc = preg_replace('/\s+[·|]\s+$/', '', trim($desc)) ?? $desc;
            $desc = mb_substr(trim($desc, " ·|—-"), 0, 160);

            // Normas
            $norms = [];
            if (preg_match_all('/(CE-MDR|MIL-STD-\d+[A-Z]?|EUR\.1|Form\s*A|ISO\s*\d+|NATO\s+Mil-Std-\d+|REACH|RoHS)/i', $line, $normsM)) {
                $norms = array_values(array_unique($normsM[0]));
            }

            // Specs = qualquer coisa em parêntesis ou após específicos
            $specs = '';
            if (preg_match('/\(([^)]{8,120})\)/u', $line, $spM)) {
                $specs = trim($spM[1]);
            }

            $items[] = [
                'desc'  => $desc !== '' ? $desc : '(ver SoR)',
                'pn'    => $hasPn  ? trim($pnM[2])  : '',
                'qty'   => $hasQty ? trim($qtyM[count($qtyM) - 1]) : '',
                'specs' => $specs,
                'norms' => implode(', ', $norms),
            ];

            if (count($items) >= 20) break;
        }

        return $items;
    }

    private function authorizeView(Tender $tender): void
    {
        $user = Auth::user();
        if (!$user) abort(401);
        if ($user->can('tenders.view-all')) return;
        $collab = $tender->collaborator;
        if (!$collab || $collab->user_id !== $user->id) abort(403);
    }
}
