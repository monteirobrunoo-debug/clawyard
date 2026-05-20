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
use PhpOffice\PhpWord\IOFactory as WordIOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Shared\Converter;
use PhpOffice\PhpWord\SimpleType\JcTable;
use PhpOffice\PhpWord\SimpleType\Jc;
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

        // 2026-05-18: pipeline de limpeza em 2 passos:
        //   1. cleanSorBoilerplate — remove headers/footers RFP (UNCLASSIFIED,
        //      addresses, page numbers, URLs, form refs) que vazam cliente
        //   2. scrubCustomerInfo — mascara nomes específicos (NSPA, NATO...)
        // Pedido directo do operador: nas primeiras versões havia "[end-customer]"
        // a meio das frases por causa do boilerplate — agora as linhas inteiras
        // de boilerplate são removidas, o que reduz drasticamente o ruído.
        $maskedTitle = $this->scrubCustomerInfo($tender->title, $tender);
        if ($sor) {
            $sor = $this->cleanSorBoilerplate($sor);
            $sor = $this->scrubCustomerInfo($sor, $tender);
        }

        // Parse items do SoR via regex heurística — captura linhas
        // estruturadas e agrupa sub-specs no mesmo item (numera apenas
        // quando muda o equipamento — pedido directo do operador).
        $items = $this->parseItems($sor ?? '', $supplier);

        $contactName  = Auth::user()?->name  ?? 'PartYard';
        $contactEmail = Auth::user()?->email ?? config('mail.from.address', 'info@partyard.eu');

        // 2026-05-20: layout simplificado
        //   "poe apenas o Nome PartYard - Refº Sap e descicao e item do
        //    equiapamento a pedir, apenas mencionar, Dear Sirs, Please
        //    inform us your Best Price and Delivery time for the following:"
        // Sem branding militar, sem campos complexos.
        $rfqRef = $tender->sap_opportunity_number
            ? $tender->sap_opportunity_number . '/' . now()->format('Y')
            : 'PY-' . str_pad((string) $tender->id, 6, '0', STR_PAD_LEFT);

        $pdf = Pdf::loadView('tenders.inquiry-simple', [
            'rfqRef'        => $rfqRef,
            'description'   => mb_substr($maskedTitle ?: ($tender->title ?? ''), 0, 300),
            'items'         => $items,
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
     * 2026-05-18: adicionados campos `line` (10, 20, 30… formato NATO) e
     * `reference` (P/N ou brand match) para o novo formato de RFQ por
     * fornecedor (ver resources/views/tenders/inquiry-partyard-pdf.blade.php).
     *
     * @return list<array{line:int, desc:string, pn:string, reference:string, qty:string, specs:string, norms:string}>
     */
    private function parseItems(string $text, ?Supplier $supplier = null): array
    {
        if ($text === '') return [];

        // Brands do fornecedor (se houver) — usado como hint para campo
        // "Reference product": se a desc menciona uma das marcas do
        // fornecedor, é essa que aparece no RFQ. Senão usa o P/N.
        $supplierBrands = [];
        if ($supplier && !empty($supplier->brands)) {
            foreach ((array) $supplier->brands as $b) {
                $b = trim((string) $b);
                if ($b !== '' && mb_strlen($b) >= 2) $supplierBrands[] = $b;
            }
        }

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

                // Reference product = brand match (se desc menciona marca
                // do fornecedor) OU P/N OU vazio.
                $refProduct = '';
                if ($supplierBrands) {
                    foreach ($supplierBrands as $b) {
                        if (mb_stripos($desc, $b) !== false) { $refProduct = $b; break; }
                    }
                }
                if ($refProduct === '' && $hasPn) $refProduct = trim($pnM[2]);

                $current = [
                    'line'      => (count($items) + 1) * 10,  // NATO style: 10, 20, 30…
                    'desc'      => $desc !== '' ? $desc : '(ver SoR)',
                    'pn'        => $hasPn  ? trim($pnM[2])  : '',
                    'reference' => $refProduct,
                    'qty'       => $hasQty ? trim($qtyM[count($qtyM) - 1]) : '',
                    'specs'     => $parens,
                    'norms'     => implode(', ', $norms),
                    '_specs'    => $parens ? [$parens] : [],
                    '_norms'    => $norms,
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
     * Limpa o SoR de boilerplate RFP que vaza cliente: classificação,
     * páginas, endereços, telefones, websites, form numbers.
     * Aplica-se ANTES do scrubCustomerInfo para reduzir o ruído de
     * "[end-customer]" repetido em headers/footers de cada página.
     *
     * 2026-05-18 fix: utilizador viu SoR com 30+ "[end-customer]" em
     * footers tipo "NSPA L-8302 CAPELLEN Luxembourg TEL +352 ..."
     * — esses blocos têm de sair, não basta mascarar.
     */
    private function cleanSorBoilerplate(string $text): string
    {
        if ($text === '') return $text;

        $lines = preg_split('/\r?\n/', $text) ?: [];
        $kept  = [];

        foreach ($lines as $line) {
            $t = trim($line);
            if ($t === '') { $kept[] = ''; continue; }

            // Classificação NATO/UE/US — sai sempre
            if (preg_match('/^(\[end-customer\]\s+)?(unclassified|classified|confidential|restricted|releasable|secret|top\s+secret)\b/iu', $t)) continue;
            // Cabeçalhos "AGENCE OTAN DE SOUTIEN" etc.
            if (preg_match('/agence\s+otan|nato\s+(support|procurement|csa)/i', $t)) continue;
            // "Page N of M", "Pg N", "Página N/N", "(page X)"
            if (preg_match('/^(page|p[áa]gina|pg\.?)\s+\d+\s*(of|de|\/)\s*\d+/i', $t)) continue;
            if (preg_match('/^\(?\s*page\s+\d+\s*\)?$/i', $t)) continue;
            // Endereços: linha com TEL: / FAX: / Website:
            if (preg_match('/^\s*(tel\.?|fax|t\.|telefon[eo]|telephone|website|email|e-?mail)[\s:]/iu', $t)) continue;
            // Linha de endereço típica RFP NATO: "L-8302 CAPELLEN(Luxembourg)" ou "B-1110 BRUSSELS"
            if (preg_match('/^[A-Z]{1,3}[-\s]?\d{4,5}\s+[A-ZÁÉÍÓÚ]/u', $t) && mb_strlen($t) < 120) continue;
            // URLs sozinhas
            if (preg_match('/^https?:\/\/\S+\s*$/i', $t)) continue;
            // Form numbers tipo "/LB-UR-6001054519" ou "Form 6001054519"
            if (preg_match('/^[\/\-\s]?(form\s+)?[A-Z]{1,3}-?[A-Z]{1,3}-?\d{6,}/i', $t) && mb_strlen($t) < 80) continue;
            // Linhas só com símbolos / pontilhados / underscores
            if (preg_match('/^[\s\-_=•·…]+$/u', $t)) continue;
            // Linhas tipo "===" (separadores)
            if (preg_match('/^.{1,5}$/u', $t)) continue;

            $kept[] = $t;
        }

        // Colapsa runs de 3+ linhas em branco
        $out = implode("\n", $kept);
        $out = preg_replace('/\n{3,}/', "\n\n", $out) ?? $out;
        return trim($out);
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

    /**
     * Sanitiza string para PhpWord/OOXML:
     *   • Força UTF-8 válido (substitui bytes maus por '')
     *   • Strip XML-illegal control chars (XML 1.0: 0x00-0x08, 0x0B, 0x0C, 0x0E-0x1F)
     *     — único permitidos são \t \n \r — caso contrário o docx fica
     *     corrupto e o Word recusa-se a abrir.
     *
     * 2026-05-18 fix: utilizador reportou "Word experienced an error
     * trying to open the file" no Inquiry-PartYard-SAP17509(2).docx.
     * Causa: bytes inválidos da extração de PDF chegavam ao XML do docx.
     */
    private function xmlSafe(string $s): string
    {
        if ($s === '') return '';
        // 1) UTF-8 válido
        if (!mb_check_encoding($s, 'UTF-8')) {
            $s = @iconv('UTF-8', 'UTF-8//IGNORE', $s) ?: mb_convert_encoding($s, 'UTF-8', 'UTF-8');
        }
        // 2) XML 1.0 illegal control chars — apenas \t \n \r são válidos
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s) ?? $s;
        return (string) $s;
    }

    /**
     * GET /tenders/{tender}/inquiry-word[?supplier_id=X]
     *
     * Gera o Inquiry como ficheiro .docx editável (PhpWord). Estrutura
     * idêntica ao PDF mas o operador pode rever / editar / adicionar
     * cláusulas antes de enviar. Pedido directo:
     *   "em vez de pdf, ficheiro word para ser alterado"
     */
    public function generateWord(Request $request, Tender $tender): Response
    {
        $this->authorizeView($tender);
        if ($tender->is_confidential) {
            abort(403, 'Concurso confidencial — geração de Inquiry desligada.');
        }

        $supplier = $request->filled('supplier_id') ? Supplier::find($request->integer('supplier_id')) : null;

        // Mesmo pipeline do PDF — SoR + scrub + parseItems
        $tender->load('attachments');
        $okAttachments = $tender->attachments->where('extraction_status', 'ok');
        $sors = [];
        foreach ($okAttachments as $att) {
            $sor = $att->extractStatementOfRequirements(10000);
            if ($sor) $sors[] = "[{$att->original_name}]\n{$sor}";
        }
        $sor = $sors ? implode("\n\n", $sors) : null;
        if (!$sor && $okAttachments->isNotEmpty()) {
            $first = $okAttachments->first();
            $sor = "[{$first->original_name} · texto integral]\n" . mb_substr((string) $first->extracted_text, 0, 8000);
        }
        if ($sor) {
            $sor = $this->cleanSorBoilerplate($sor);
            $sor = $this->scrubCustomerInfo($sor, $tender);
        }
        $items = $this->parseItems($sor ?? '', $supplier);

        $contactName  = Auth::user()?->name  ?? 'Bruno Monteiro';
        $contactEmail = Auth::user()?->email ?? config('mail.from.address', 'bruno.monteiro@partyard.eu');

        // RFQ ref: SAP Opp + ano (formato Erbe reference RFQ_10).
        $rfqRef = $tender->sap_opportunity_number
            ? $tender->sap_opportunity_number . '/' . now()->format('Y')
            : 'PYD-' . str_pad((string) $tender->id, 6, '0', STR_PAD_LEFT);

        // Cover term para anonimizar end-customer no email ao fornecedor
        // (pedido directo: "para enviar o inquiry não menciones o nosso
        // cliente"). Soft form que dá contexto militar sem revelar NSPA.
        $tenderContext = 'Portuguese Ministry of Defence tender ref. ' . $rfqRef;

        $supplierName = $supplier?->name ?? 'Supplier';
        // Mapa pequeno ISO-2 → nome legível, alinhado com a Blade view
        $countryMap = [
            'DE' => 'Germany', 'PT' => 'Portugal', 'FR' => 'France', 'ES' => 'Spain',
            'IT' => 'Italy',   'NL' => 'Netherlands','BE' => 'Belgium','CH' => 'Switzerland',
            'AT' => 'Austria', 'PL' => 'Poland',   'CZ' => 'Czechia', 'SE' => 'Sweden',
            'DK' => 'Denmark', 'FI' => 'Finland',  'NO' => 'Norway',  'UK' => 'United Kingdom',
            'GB' => 'United Kingdom', 'US' => 'United States', 'CA' => 'Canada',
            'JP' => 'Japan',   'KR' => 'South Korea','TR' => 'Türkiye','IL' => 'Israel',
        ];
        $cc = strtoupper((string) ($supplier?->country_code ?? ''));
        $supplierCountry = $cc !== '' ? ($countryMap[$cc] ?? $cc) : '';
        $supplierTitle = $supplierName . ($supplierCountry !== '' ? ' (' . $supplierCountry . ')' : '');

        // Subject keyword from first item
        $subjectKeyword = !empty($items[0]['desc'])
            ? trim(preg_replace('/\s*[\(\[].*$/', '', (string) $items[0]['desc']))
            : 'ENT/ORL Equipment Package';
        $subject = 'RFQ ' . $rfqRef . ' — ' . $subjectKeyword . ' (Portugal MoD Tender)';
        if ($tender->deadline_at) {
            $subject .= ' — Response by ' . $tender->deadline_at->format('Y-m-d');
        }

        // To-line: emails do supplier (primary + additional, max 3)
        $toEmails = [];
        if ($supplier?->primary_email) $toEmails[] = $supplier->primary_email;
        if (!empty($supplier?->additional_emails)) {
            foreach ((array) $supplier->additional_emails as $e) {
                $e = trim((string) $e);
                if ($e !== '') $toEmails[] = $e;
            }
        }
        $toLine = $toEmails
            ? implode(' ; ', array_slice($toEmails, 0, 3))
            : '____________________________________';

        // ── Build docx (simple layout) ─────────────────────────────────
        // 2026-05-20 pedido directo:
        //   "poe apenas o Nome PartYard - Refº Sap e descicao e item do
        //    equiapamento a pedir, apenas mencionar, Dear Sirs, Please
        //    inform us your Best Price and Delivery time for the following:"
        // Sem MOD_072_V3, sem branding militar. PhpWord from-scratch
        // mantendo só o essencial: cabeçalho PartYard, ref. SAP, descrição,
        // saudação, tabela de items, assinatura.
        $phpWord = new PhpWord();
        $section = $phpWord->addSection([
            'marginLeft'   => Converter::cmToTwip(2.2),
            'marginRight'  => Converter::cmToTwip(2.2),
            'marginTop'    => Converter::cmToTwip(2.8),
            'marginBottom' => Converter::cmToTwip(2.5),
        ]);

        // Brand header
        $section->addText('PartYard', ['size' => 22, 'bold' => true, 'color' => '1E3A8A']);
        $section->addTextBreak(1);

        // Meta (ref + description)
        $tblStyle = ['borderTopSize' => 6, 'borderTopColor' => 'DDDDDD',
                     'borderBottomSize' => 6, 'borderBottomColor' => 'DDDDDD',
                     'cellMargin' => 80];
        $phpWord->addTableStyle('metaTable', $tblStyle);
        $meta = $section->addTable('metaTable');
        foreach ([
            ['Ref. SAP:',     $rfqRef],
            ['Description:',  $this->xmlSafe(mb_substr($maskedTitle ?: ($tender->title ?? ''), 0, 300))],
            ['Date:',         now()->format('d M Y')],
        ] as [$lbl, $val]) {
            $meta->addRow();
            $c1 = $meta->addCell(Converter::cmToTwip(3.8));
            $c1->addText($lbl, ['size' => 10, 'bold' => true, 'color' => '475569']);
            $c2 = $meta->addCell(Converter::cmToTwip(11));
            $c2->addText($val, ['size' => 11]);
        }
        $section->addTextBreak(1);

        // Greeting + request (addTextRun para misturar bold/regular)
        $section->addText('Dear Sirs,', ['size' => 11]);
        $section->addTextBreak(1);
        $run = $section->addTextRun(['size' => 11]);
        $run->addText('Please inform us your ', ['size' => 11]);
        $run->addText('Best Price', ['size' => 11, 'bold' => true]);
        $run->addText(' and ', ['size' => 11]);
        $run->addText('Delivery time', ['size' => 11, 'bold' => true]);
        $run->addText(' for the following:', ['size' => 11]);
        $section->addTextBreak(1);

        // Items table
        $itemsStyle = [
            'borderSize'  => 6,
            'borderColor' => 'CBD5E1',
            'cellMargin'  => 80,
        ];
        $phpWord->addTableStyle('itemsTable', $itemsStyle);
        $itemsTbl = $section->addTable('itemsTable');
        // Header row
        $itemsTbl->addRow(null, ['tblHeader' => true]);
        $head = ['#', 'Equipment / Item', 'Qty'];
        $widths = [Converter::cmToTwip(1.4), Converter::cmToTwip(11.6), Converter::cmToTwip(2.5)];
        foreach ($head as $i => $h) {
            $cell = $itemsTbl->addCell($widths[$i], ['bgColor' => 'F1F5F9']);
            $cell->addText($h, ['size' => 9, 'bold' => true, 'color' => '334155']);
        }
        // Body rows — fallback para 1 linha "description" se items vazio
        $rows = !empty($items) ? $items : [[
            'desc' => mb_substr($maskedTitle ?: ($tender->title ?? '—'), 0, 300),
            'qty'  => '—',
        ]];
        foreach ($rows as $idx => $it) {
            $itemsTbl->addRow();
            $itemsTbl->addCell($widths[0])->addText(
                (string) ($idx + 1),
                ['size' => 10, 'color' => '64748B'],
                ['alignment' => Jc::CENTER]
            );
            $itemsTbl->addCell($widths[1])->addText(
                $this->xmlSafe((string) ($it['desc'] ?? '—')),
                ['size' => 10.5]
            );
            $itemsTbl->addCell($widths[2])->addText(
                $this->xmlSafe((string) ($it['qty'] ?? '—')),
                ['size' => 10],
                ['alignment' => Jc::CENTER]
            );
        }
        $section->addTextBreak(2);

        // Signature
        $section->addText('Best regards,', ['size' => 10.5]);
        $section->addText($this->xmlSafe($contactName), ['size' => 10.5, 'bold' => true]);
        $section->addText('PartYard', ['size' => 10.5, 'color' => '64748B']);
        $section->addText($this->xmlSafe($contactEmail), ['size' => 10.5]);

        // ── Stream the docx ────────────────────────────────────────────
        $refForFile = $tender->sap_opportunity_number
            ? 'SAP' . $tender->sap_opportunity_number
            : 'PYD' . str_pad((string) $tender->id, 6, '0', STR_PAD_LEFT);
        $downloadName = 'Inquiry-PartYard-' . $refForFile . ($supplier ? '-' . $supplier->slug : '') . '.docx';

        $tmp = tempnam(sys_get_temp_dir(), 'inquiry_') . '.docx';
        WordIOFactory::createWriter($phpWord, 'Word2007')->save($tmp);
        $bytes = file_get_contents($tmp);
        @unlink($tmp);

        // Idempotente: anexa também ao concurso (operador encontra na
        // secção Anexos depois). Hash do conteúdo evita duplicados.
        $hash = hash('sha256', $bytes);
        $slug = Str::slug(($tender->reference ?: 'concurso-' . $tender->id) . '-inquiry' . ($supplier ? '-' . Str::slug($supplier->name) : ''));
        $storedName = $slug . '-' . substr($hash, 0, 8) . '.docx';
        $relPath = 'tender-attachments/' . $tender->id . '/' . $storedName;
        $existing = TenderAttachment::where('tender_id', $tender->id)->where('file_hash', $hash)->first();
        if (!$existing) {
            try {
                Storage::disk('local')->put($relPath, $bytes);
                TenderAttachment::create([
                    'tender_id'           => $tender->id,
                    'original_name'       => $downloadName,
                    'disk_path'           => $relPath,
                    'mime_type'           => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                    'size_bytes'          => strlen($bytes),
                    'file_hash'           => $hash,
                    'extraction_status'   => TenderAttachment::STATUS_OK,
                    'extracted_text'      => 'Inquiry PartYard Word editável gerado em ' . now()->format('d/m/Y H:i'),
                    'extracted_chars'     => 60,
                    'uploaded_by_user_id' => Auth::id(),
                ]);
            } catch (\Throwable $e) {
                Log::warning('Inquiry Word: storage failed', ['tender_id' => $tender->id, 'error' => $e->getMessage()]);
            }
        }

        return response($bytes, 200, [
            'Content-Type'        => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'Content-Disposition' => 'attachment; filename="' . $downloadName . '"',
            'Cache-Control'       => 'private, max-age=0, no-store',
        ]);
    }

    private function authorizeView(Tender $tender): void
    {
        $user = Auth::user();
        if (!$user) abort(401);
        if ($user->can('tenders.view-all')) return;
        // 2026-05-19: Acingov/Vortal/Anogov sao pool publico interno.
        if (in_array($tender->source, Tender::PUBLIC_SOURCES, true)) return;
        $collab = $tender->collaborator;
        if (!$collab || $collab->user_id !== $user->id) abort(403);
    }
}
