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

        // 2026-05-18: SCRUB de info do cliente final antes de mostrar
        // ao fornecedor. Pedido directo: "para enviar o inquiry não
        // mencionas o nosso cliente". Remove NSPA / NATO / NCIA / etc.
        // do título E do bloco SoR antes de renderizar.
        $maskedTitle = $this->scrubCustomerInfo($tender->title, $tender);
        $sor         = $sor ? $this->scrubCustomerInfo($sor, $tender) : null;

        // Parse items do SoR via regex heurística — captura linhas
        // estruturadas e agrupa sub-specs no mesmo item (numera apenas
        // quando muda o equipamento — pedido directo do operador).
        $items = $this->parseItems($sor ?? '');

        $contactName  = Auth::user()?->name  ?? 'PartYard Defense';
        $contactEmail = Auth::user()?->email ?? config('mail.from.address', 'defense@hp-group.org');

        $pdf = Pdf::loadView('tenders.inquiry-partyard-pdf', [
            'tender'        => $tender,
            'supplier'      => $supplier,
            'items'         => $items,
            'sor'           => $sor,
            'maskedTitle'   => $maskedTitle,
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

        // 2026-05-18: filename usa SAP Opp se existir (referência limpa
        // que o operador reconhece no SAP B1), fallback para tender id.
        // Nunca usa $tender->reference porque pode revelar cliente final.
        $refForFile = $tender->sap_opportunity_number
            ? 'SAP' . $tender->sap_opportunity_number
            : 'PYD' . str_pad((string) $tender->id, 6, '0', STR_PAD_LEFT);
        $downloadName = 'Inquiry-PartYard-' . $refForFile . ($supplier ? '-' . $supplier->slug : '') . '.pdf';

        return response($bytes, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $downloadName . '"',
            'Cache-Control'       => 'private, max-age=0, no-store',
        ]);
    }

    /**
     * Parser heurístico de items + agrupamento de sub-specs.
     *
     * 2026-05-18 mudança: "é sempre as linhas não numeres apenas quando
     * passa para outro equipamento". Agora as linhas que NÃO têm sinal
     * de novo equipamento (P/N novo, Qty nova, header "Item N:") são
     * FUNDIDAS no item anterior como specs adicionais — em vez de
     * criarem novas linhas numeradas.
     *
     * Heurística "é novo equipamento":
     *   • Tem header "Item N:" / "N." / "Lot N:" / "• "
     *   • OU tem P/N próprio
     *   • OU tem Qty própria (números diferentes do anterior)
     *
     * Devolve até 20 items distintos.
     *
     * @return list<array{desc: string, pn: string, qty: string, specs: string, norms: string}>
     */
    private function parseItems(string $text): array
    {
        if ($text === '') return [];

        $items = [];
        $current = null;   // último item activo para acumular sub-specs

        $lines = preg_split('/\r?\n/', $text) ?: [];
        foreach ($lines as $line) {
            $line = trim((string) $line);
            if (mb_strlen($line) < 5 || mb_strlen($line) > 400) continue;

            // Sinais de NOVO equipamento (não sub-spec)
            $hasHeader = preg_match('/^(?:item\s+\d+[\.:]|\d+[\.\)]\s+|lot\s+\d+[\.:]|•\s+|\d+\s*x\s+)/iu', $line);
            $hasPn     = preg_match('/(P\/N|Part\s*Number|Item\s*Code|NSN)\s*[:=]?\s*([A-Z0-9\.\-\/_]+)/i', $line, $pnM);
            $hasQty    = preg_match('/(Qty|Quantity|QT|qtde)\s*[:=]?\s*(\d+(?:\s*(?:units?|pcs?|peças?|unidades?))?)/i', $line, $qtyM)
                      || preg_match('/^(\d+)\s*x\s+/u', $line, $qtyM);

            $isNewEquipment = $hasHeader || ($hasPn && (!$current || ($current['pn'] !== '' && trim($pnM[2]) !== $current['pn'])));

            // Extrai normas/specs sempre (acumulam no item actual ou novo)
            $norms = [];
            if (preg_match_all('/(CE-MDR|MIL-STD-\d+[A-Z]?|EUR\.1|Form\s*A|ISO\s*\d+|NATO\s+Mil-Std-\d+|REACH|RoHS)/i', $line, $normsM)) {
                $norms = array_values(array_unique($normsM[0]));
            }
            $parens = '';
            if (preg_match('/\(([^)]{8,120})\)/u', $line, $spM)) {
                $parens = trim($spM[1]);
            }

            if ($isNewEquipment) {
                // Fechar item anterior se existir
                if ($current !== null) {
                    $items[] = $current;
                    if (count($items) >= 20) break;
                }

                // Constrói descrição limpa
                $desc = $line;
                $desc = preg_replace('/(P\/N|Part\s*Number|Item\s*Code|NSN)\s*[:=]?\s*[A-Z0-9\.\-\/_]+/i', '', $desc) ?? $desc;
                $desc = preg_replace('/(Qty|Quantity|QT|qtde)\s*[:=]?\s*\d+(?:\s*(?:units?|pcs?|peças?|unidades?))?/i', '', $desc) ?? $desc;
                $desc = preg_replace('/^(?:item\s+\d+[\.:]|\d+[\.\)]\s+|lot\s+\d+[\.:]|•\s+|\d+\s*x\s+)/iu', '', $desc) ?? $desc;
                $desc = preg_replace('/\s+[·|]\s+$/', '', trim($desc)) ?? $desc;
                $desc = mb_substr(trim($desc, " ·|—-"), 0, 160);

                $current = [
                    'desc'   => $desc !== '' ? $desc : '(ver SoR)',
                    'pn'     => $hasPn  ? trim($pnM[2])  : '',
                    'qty'    => $hasQty ? trim($qtyM[count($qtyM) - 1]) : '',
                    'specs'  => $parens,
                    'norms'  => implode(', ', $norms),
                    '_specs' => $parens ? [$parens] : [],
                    '_norms' => $norms,
                ];
                continue;
            }

            // Não é novo equipamento — funde no current se existir
            if ($current === null) continue;

            // Acumula specs/normas na entry actual
            if ($parens !== '' && !in_array($parens, $current['_specs'], true)) {
                $current['_specs'][] = $parens;
                $current['specs'] = mb_substr(implode(' · ', $current['_specs']), 0, 200);
            }
            if (!empty($norms)) {
                $current['_norms'] = array_values(array_unique(array_merge($current['_norms'], $norms)));
                $current['norms'] = implode(', ', $current['_norms']);
            }
            // Linha extra de descrição (sem cabeçalho) — adiciona à desc
            // se ainda não tem muito conteúdo
            if (mb_strlen($current['desc']) < 100 && !preg_match('/^\s*[\(\[]/', $line)) {
                $extra = mb_substr($line, 0, 80);
                $current['desc'] = mb_substr($current['desc'] . ' · ' . $extra, 0, 200);
            }
        }

        // Fecha o último item activo
        if ($current !== null && count($items) < 20) {
            $items[] = $current;
        }

        // Cleanup dos _specs/_norms internos
        return array_map(function ($it) {
            unset($it['_specs'], $it['_norms']);
            return $it;
        }, $items);
    }

    /**
     * Remove referências ao cliente final (NSPA / NCIA / NATO / etc.) de
     * texto que vai para o fornecedor. Heurística:
     *   • Nome canónico da source (Tender::SOURCE_TO_BP_NAME)
     *   • Purchasing_org (nome literal do BP)
     *   • Reference quando começa com prefix do cliente
     *   • Frase comuns NATO/NSPA mantidas como "[end-customer]" apenas
     *     se o operador realmente precisar de contexto técnico
     *
     * 2026-05-18: pedido directo "para enviar o inquiry não mencionas o
     * nosso cliente". Pratica padrão de procurement — o fornecedor não
     * deve saber quem é o end-customer (margem, abordagem directa, etc).
     */
    private function scrubCustomerInfo(string $text, Tender $tender): string
    {
        if ($text === '') return $text;

        $masks = [];

        // Customer canonical name (NSPA, NATO, NCIA, …)
        $sourceBp = Tender::bpNameForSource($tender->source);
        if ($sourceBp) $masks[] = $sourceBp;

        // Purchasing org name (NSPA - NATO SUPPORT AND PROCUREMENT AGENCY)
        $org = trim((string) $tender->purchasing_org);
        if ($org !== '' && mb_strlen($org) >= 3) {
            $masks[] = $org;
            // Variantes / sub-strings importantes (e.g., "NATO SUPPORT")
            foreach (preg_split('/[\s,\-\.\/]+/u', $org) ?: [] as $part) {
                $part = trim($part);
                if (mb_strlen($part) >= 4 && !ctype_lower($part)) $masks[] = $part;
            }
        }

        // Reference completa (NSPA-2026-1234 etc.)
        $ref = trim((string) $tender->reference);
        if ($ref !== '' && mb_strlen($ref) >= 6) $masks[] = $ref;

        // Source key se for grande (não mascarar 'pt' etc.)
        $source = trim((string) $tender->source);
        if (in_array(strtolower($source), ['nspa', 'nato', 'ncia', 'sam_gov', 'ungm', 'unido'], true)) {
            $masks[] = strtoupper($source);
        }

        // Aplica mask — substitui por "[end-customer]" para o supplier
        // saber que há um cliente final mas não quem.
        $masks = array_values(array_unique(array_filter($masks, fn($m) => mb_strlen($m) >= 4)));
        // Ordena por length DESC — substitui longos primeiro para evitar
        // substituições parciais (ex.: "NSPA - NATO SUPPORT" antes de "NSPA")
        usort($masks, fn($a, $b) => mb_strlen($b) - mb_strlen($a));

        // Termos TÉCNICOS que NÃO devem ser mascarados mesmo contendo
        // "NATO" / "NSPA" etc. Pedido directo: queremos esconder o
        // cliente mas manter normas técnicas que o fornecedor precisa
        // de ver (NATO Mil-Std-461, NATO STANAG, NSN/NATO Stock Number,
        // NATO codification, format SNATO, etc).
        $technicalKeepers = [
            'mil-std',
            'stanag',
            'stock\s+number',
            'stock\s+format',
            'codification',
            'codification\s+number',
            'standard\s*\d',
            'nsn\b',
            'cage\s+code',
            'ncage',
        ];

        foreach ($masks as $mask) {
            // Negative lookahead: não substitui se for seguido por um
            // dos technical keepers (case-insensitive, com espaço opcional).
            $keepRe = '(?!\s+(?:' . implode('|', $technicalKeepers) . '))';
            $text = preg_replace(
                '/(?<![A-Za-z0-9])' . preg_quote($mask, '/') . $keepRe . '(?![A-Za-z0-9])/iu',
                '[end-customer]',
                $text
            ) ?? $text;
        }

        // Compacta repetições de "[end-customer]" consecutivas
        $text = preg_replace('/(\[end-customer\][\s,\-]*){2,}/u', '[end-customer] ', $text) ?? $text;

        return $text;
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
