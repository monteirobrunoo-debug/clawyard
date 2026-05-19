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

        // ── Build docx ─────────────────────────────────────────────────
        // 2026-05-19 — pedido directo do operador:
        //   "O ficheiro inquiry militar tem de ser este [MOD_072_V3]"
        //
        // Preferência: ABRIR o .docx original do SGQ (Inquiry_MILITARY_MOD_072_V3.docx)
        // e ANEXAR o body técnico no fim. Garante 100% fidelidade visual da
        // capa (logo "Partyard military division", contactos, INQUIRY title,
        // form fields To/Att/Email/From/Telef/Email/Page/Date/Our Ref.,
        // footer NCAGE/ISO/H&P).
        //
        // Fallback: se o .docx do template não estiver acessível, cai para
        // o approach antigo de reconstruir o layout via PhpWord do zero
        // usando os assets PNG/JPG isolados.
        $phpWord = \App\Services\PartYardMilitaryWordTemplate::loadOriginalAsPhpWord();
        $usingOriginalTemplate = ($phpWord !== null);

        if (!$usingOriginalTemplate) {
            $phpWord = new PhpWord();
        }
        $phpWord->getCompatibility()->setOoxmlVersion(15);

        // Estilos PartYard Militar — navy corporativo + Calibri.
        // try/catch porque se o template original já registou estilos com
        // o mesmo nome, PhpWord lança exception "Style 'X' already exists".
        $stylesToRegister = [
            'corp'  => ['name' => 'Calibri', 'size' => 11, 'bold' => true, 'color' => 'FFFFFF'],
            'corpR' => ['name' => 'Calibri', 'size' => 8,  'color' => 'C7D2FE'],
            'h1'    => ['name' => 'Calibri', 'size' => 15, 'bold' => true, 'color' => '0F1B4C'],
            'h2'    => ['name' => 'Calibri', 'size' => 12, 'bold' => true, 'color' => '0F1B4C'],
            'h3'    => ['name' => 'Calibri', 'size' => 10, 'bold' => true, 'color' => '0F1B4C'],
            'body'  => ['name' => 'Calibri', 'size' => 10],
            'mono'  => ['name' => 'Consolas','size' => 9],
            'th'    => ['name' => 'Calibri', 'size' => 9, 'bold' => true, 'color' => 'FFFFFF'],
            'thMx'  => ['name' => 'Calibri', 'size' => 8, 'bold' => true, 'color' => '1E3A8A'],
            'label' => ['name' => 'Calibri', 'size' => 9, 'bold' => true, 'color' => '475569'],
            'muted' => ['name' => 'Calibri', 'size' => 8, 'color' => '6B7280'],
        ];
        foreach ($stylesToRegister as $key => $style) {
            try { $phpWord->addFontStyle($key, $style); } catch (\Throwable $e) {}
        }

        // Quando usamos o template original, NÃO chamamos apply() — já tem
        // header/footer próprios. Adicionamos uma section nova vazia e
        // anexamos o body técnico aí (Word coloca-a a seguir à capa).
        // Quando NÃO temos template, chamamos apply() para emitir header/
        // footer via PhpWord (fallback do dev environment).
        if ($usingOriginalTemplate) {
            // Page break antes da nova section para o body arrancar em
            // página nova depois da capa MOD_072_V3.
            $section = $phpWord->addSection([
                'marginLeft'   => Converter::cmToTwip(1.5),
                'marginRight'  => Converter::cmToTwip(1.5),
                'marginTop'    => Converter::cmToTwip(2.0),
                'marginBottom' => Converter::cmToTwip(2.0),
                // Type "nextPage" — Word começa a section nova em página nova
                'breakType'    => 'nextPage',
            ]);
            $hasHeaderAsset = true;
            $hasFooterAsset = true;
        } else {
            $section = $phpWord->addSection(\App\Services\PartYardMilitaryWordTemplate::sectionConfig());
            $templateApplied = \App\Services\PartYardMilitaryWordTemplate::apply($section, [
                'document_kind' => 'Defense Inquiry',
                'audit_ref'     => 'ClawYard #' . $tender->id . ' · RFQ ' . $rfqRef,
            ]);
            $hasHeaderAsset = $templateApplied;
            $hasFooterAsset = $templateApplied;
        }

        // Quando temos o header image MOD_072_V3 activo, o navy-bar
        // textual seria redundante (a imagem já tem "Partyard military
        // division" + contactos). Fallback continua para shares sem assets.
        if (!$hasHeaderAsset) {
            $tblHdr = $section->addTable([
                'borderSize' => 0,
                'cellMargin' => 120,
                'unit'       => 'pct',
                'width'      => 100 * 50,
            ]);
            $tblHdr->addRow(420);
            $cellHL = $tblHdr->addCell(6500, ['bgColor' => '0F1B4C']);
            $cellHL->addText($this->xmlSafe('PARTYARD MILITAR, LDA.'), 'corp');
            $cellHR = $tblHdr->addCell(3500, ['bgColor' => '0F1B4C']);
            $cellHR->addText($this->xmlSafe('RFQ ' . $rfqRef . ' — ' . $subjectKeyword), 'corpR', ['alignment' => Jc::END]);
            $cellHR->addText($this->xmlSafe('Portuguese Ministry of Defence Tender'), 'corpR', ['alignment' => Jc::END]);
            $section->addTextBreak(1);
        }

        // ── MOD_072_V3 form fields (To/Att/Email · From/Telef/Email · Page/Date/Our Ref.)
        //    Reproduz o layout do bloco branco do template (image2.jpg).
        //    Quando usamos o template original, esta tabela é ÚTIL como
        //    versão "pré-preenchida" — o operador pode copiar daqui para
        //    a capa (que tem os campos em branco do SGQ) OU enviar o
        //    .docx tal-e-qual com a página 2 a complementar.
        if ($usingOriginalTemplate) {
            $section->addText(
                $this->xmlSafe('Detalhes do RFQ — pré-preenchidos automaticamente'),
                ['size' => 9, 'italic' => true, 'color' => '6B7280'],
                ['alignment' => Jc::CENTER]
            );
            $section->addTextBreak(1);
        }

        $tblFields = $section->addTable([
            'borderColor' => 'CBD5E1',
            'borderSize'  => 0,
            'cellMargin'  => 80,
            'alignment'   => JcTable::CENTER,
        ]);
        $tblFields->addRow();
        $cT = $tblFields->addCell(3300);
        $cT->addText('To:',    ['bold' => true, 'size' => 10]);
        $cT->addText($this->xmlSafe($supplierName), ['size' => 10]);
        // Supplier model não tem contact_name dedicado — operador preenche
        // manualmente o "Att:" depois de descarregar e editar o Word.
        $cT->addText('Att: ____________________', ['size' => 9, 'color' => '475569']);
        $cT->addText('Email: ' . $this->xmlSafe($supplier?->primary_email ?? '____________________'), ['size' => 9, 'color' => '475569']);

        $cF = $tblFields->addCell(3300);
        $cF->addText('From:',  ['bold' => true, 'size' => 10]);
        $cF->addText($this->xmlSafe($contactName), ['size' => 10]);
        $cF->addText('Telef.: +351 265 544 370', ['size' => 9, 'color' => '475569']);
        $cF->addText('Email: ' . $this->xmlSafe($contactEmail), ['size' => 9, 'color' => '475569']);

        $cP = $tblFields->addCell(3300);
        $cP->addText('Page:',  ['bold' => true, 'size' => 10]);
        $cP->addText('1 de 1',  ['size' => 10]);
        $cP->addText('Date: ' . now()->format('d-m-Y'), ['size' => 9, 'color' => '475569']);
        $cP->addText('Our Ref.: RFQ ' . $rfqRef, ['size' => 9, 'color' => '475569']);

        $section->addTextBreak(1);

        // Title — só repetir "INQUIRY" no body quando NÃO temos a capa
        // do template (a capa já tem o título em destaque).
        if (!$usingOriginalTemplate) {
            $section->addText($this->xmlSafe('INQUIRY'), ['size' => 22, 'bold' => true, 'color' => '0F1B4C'], ['alignment' => Jc::CENTER]);
            $section->addText(
                $this->xmlSafe('RFQ ' . $rfqRef . ' — ' . $supplierTitle),
                ['size' => 12, 'color' => '475569'],
                ['alignment' => Jc::CENTER]
            );
            $section->addTextBreak(1);
        } else {
            // Com capa: só o subtítulo da página de detalhes
            $section->addText(
                $this->xmlSafe('RFQ ' . $rfqRef . ' — ' . $supplierTitle),
                ['size' => 13, 'bold' => true, 'color' => '0F1B4C'],
                ['alignment' => Jc::CENTER]
            );
            $section->addTextBreak(1);
        }

        // Subject (mantido — útil para o operador quando reenvia por email)
        $section->addText($this->xmlSafe('Subject: ' . $subject), ['bold' => true, 'size' => 10]);
        $section->addText($this->xmlSafe('CC: procurement@partyard.eu'), ['size' => 9, 'color' => '6B7280']);
        $section->addTextBreak(1);

        // Greeting + tender context paragraph
        $section->addText($this->xmlSafe('Dear ' . $supplierName . ' Sales Team,'), 'body');
        $section->addTextBreak(1);
        $section->addText(
            $this->xmlSafe('PartYard Militar, Lda. (NCAGE P3527, Portugal) is bidding for '
                . $tenderContext . ' and requests your binding quotation for:'),
            'body'
        );
        $section->addTextBreak(1);

        // ── Items table (Line | Equipment | Qty up to | Reference) ──
        $tblItems = $section->addTable([
            'borderColor' => 'E2E8F0',
            'borderSize'  => 4,
            'cellMargin'  => 80,
            'alignment'   => JcTable::CENTER,
        ]);
        $headerBg = ['bgColor' => '2563EB'];
        $tblItems->addRow(280);
        $tblItems->addCell( 800, $headerBg)->addText('Line',              'th');
        $tblItems->addCell(4400, $headerBg)->addText('Equipment',         'th');
        $tblItems->addCell(1400, $headerBg)->addText('Qty (up to)',       'th');
        $tblItems->addCell(3400, $headerBg)->addText('Reference product', 'th');

        if (empty($items)) {
            $tblItems->addRow();
            $tblItems->addCell(10000, ['gridSpan' => 4])
                ->addText($this->xmlSafe('Detailed item specifications are provided in the attached RFP package.'),
                          ['italic' => true, 'size' => 9, 'color' => '6B7280']);
        } else {
            foreach ($items as $i => $it) {
                $tblItems->addRow();
                $bg = $i % 2 === 0 ? null : ['bgColor' => 'F8FAFC'];
                $line = $it['line'] ?? (($i + 1) * 10);
                $tblItems->addCell( 800, $bg)->addText((string) $line, ['bold' => true]);
                $tblItems->addCell(4400, $bg)->addText($this->xmlSafe($it['desc'] ?? '—'), 'body');
                $tblItems->addCell(1400, $bg)->addText($this->xmlSafe($it['qty']  ?? '—'), 'body');
                $tblItems->addCell(3400, $bg)->addText(
                    $this->xmlSafe($it['reference'] ?? ($it['pn'] ?? '—')),
                    'body'
                );
            }
        }

        $section->addTextBreak(1);

        // ── PER-ITEM BLOCKS: spec + compliance matrix ──────────────────
        // Pedido directo do operador: "por por baixo de cada item a
        // especificação e matrix request". Cada item ganha bullets +
        // tabela Comply/Not Comply/Partial/Remarks.
        foreach ($items as $i => $it) {
            $line = $it['line'] ?? (($i + 1) * 10);
            $section->addText(
                $this->xmlSafe('Line ' . $line . ' — ' . ($it['desc'] ?? 'Item ' . ($i + 1))),
                'h3'
            );

            // Constrói bullets de specs
            $bullets = [];
            if (!empty($it['specs'])) {
                foreach (preg_split('/[·;]|\s—\s/', (string) $it['specs']) as $b) {
                    $b = trim($b);
                    if ($b !== '' && mb_strlen($b) > 3) $bullets[] = $b;
                }
            }
            if (!empty($it['norms'])) $bullets[] = 'Compliance: ' . $it['norms'];
            if (empty($bullets)) {
                $bullets[] = 'Specifications per RFP attachments. Confirm compatibility with reference product.';
            }
            $bullets[] = '220V / 50Hz, European plug';
            $bullets[] = 'Accessories ready for immediate use';

            $section->addText($this->xmlSafe('Technical specification'), 'h3');
            foreach ($bullets as $b) {
                $section->addListItem($this->xmlSafe($b), 0, ['size' => 9], 'multilevel');
            }

            $section->addTextBreak(1);

            // Compliance Matrix
            $section->addText($this->xmlSafe('Compliance Matrix (please complete)'), 'h3');
            $tblMx = $section->addTable([
                'borderColor' => 'C7D2FE',
                'borderSize'  => 4,
                'cellMargin'  => 60,
                'alignment'   => JcTable::CENTER,
            ]);
            $mxHeadBg = ['bgColor' => 'E0E7FF'];
            $tblMx->addRow(260);
            $tblMx->addCell(4600, $mxHeadBg)->addText('Requirement', 'thMx');
            $tblMx->addCell(1300, $mxHeadBg)->addText('Comply',      'thMx');
            $tblMx->addCell(1300, $mxHeadBg)->addText('Not Comply',  'thMx');
            $tblMx->addCell(1100, $mxHeadBg)->addText('Partial',     'thMx');
            $tblMx->addCell(1700, $mxHeadBg)->addText('Remarks',     'thMx');

            $fill = ['bgColor' => 'FFFBEB'];
            foreach ($bullets as $b) {
                $tblMx->addRow();
                $tblMx->addCell(4600)->addText($this->xmlSafe($b), ['size' => 9]);
                $tblMx->addCell(1300, $fill)->addText('☐', ['size' => 10]);
                $tblMx->addCell(1300, $fill)->addText('☐', ['size' => 10]);
                $tblMx->addCell(1100, $fill)->addText('☐', ['size' => 10]);
                $tblMx->addCell(1700, $fill)->addText('', 'body');
            }

            $section->addTextBreak(1);
        }

        // ── What we need in your response ──────────────────────────────
        $section->addText($this->xmlSafe('What we need in your response'), 'h2');
        $responseItems = [
            'Unit price EXW (your facility) and DAP Lisbon, Portugal (Incoterms 2020), EUR excl. VAT',
            'Lead time PO → DAP Lisbon',
            'Offer validity: minimum 120 days',
            'MoQ, payment terms, warranty (period + coverage)',
            'Consumables (reusable vs disposable) — pricing for 24 months',
            'On-site training (days, language)',
        ];
        $idx = 1;
        foreach ($responseItems as $r) {
            $section->addText($this->xmlSafe($idx . '. ' . $r), ['size' => 10]);
            $idx++;
        }
        $section->addTextBreak(1);

        // ── Mandatory documentation ────────────────────────────────────
        $section->addText($this->xmlSafe('Mandatory documentation'), 'h2');
        foreach ([
            'CE MDR certificate (Reg. EU 2017/745)',
            'EU Declaration of Conformity',
            'ISO 13485:2016 certificate (Medical devices QMS)',
            'Country of Origin',
            'IEC 60601 compliance (electrical safety, medical electrical equipment)',
            'Datasheets and operator manuals (English)',
        ] as $doc) {
            $section->addListItem($this->xmlSafe($doc), 0, ['size' => 10], 'multilevel');
        }
        $section->addTextBreak(1);

        // ── Deadline block ─────────────────────────────────────────────
        $tblDeadline = $section->addTable([
            'borderSize' => 0,
            'cellMargin' => 120,
        ]);
        $tblDeadline->addRow();
        $cellDl = $tblDeadline->addCell(10000, ['bgColor' => 'FEF3C7']);
        $deadlineLabel = $tender->deadline_at
            ? $tender->deadline_at->format('l, Y-m-d, H:i') . ' WET.'
            : 'Please indicate your earliest response timeline.';
        $cellDl->addText(
            $this->xmlSafe('Response deadline: ' . $deadlineLabel),
            ['bold' => true, 'color' => '92400E', 'size' => 10]
        );
        $cellDl->addText(
            $this->xmlSafe('Send to procurement@partyard.eu, copy ' . $contactEmail),
            ['size' => 9, 'color' => '92400E']
        );
        $section->addTextBreak(1);

        // ── Signature ─────────────────────────────────────────────────
        $section->addText($this->xmlSafe('Best regards,'), 'body');
        $section->addText($this->xmlSafe($contactName), ['bold' => true, 'color' => '0F1B4C', 'size' => 11]);
        $section->addText($this->xmlSafe('Military Procurement Lead'), ['italic' => true, 'color' => '4B5563', 'size' => 10]);
        $section->addText($this->xmlSafe('PartYard Militar, Lda.'), 'body');
        $section->addText($this->xmlSafe($contactEmail), 'muted');
        $section->addText($this->xmlSafe('NCAGE P3527 · ISO 9001:2015 · AS9120 Rev B'), 'muted');

        $section->addTextBreak(1);

        // ── Corporate footer no body (redundante se o footer image
        //    do MOD_072_V3 já está activo via section footer). Mantido
        //    como fallback quando os assets do template não existem.
        if (!$hasFooterAsset) {
            $section->addText(
                $this->xmlSafe('PartYard Militar, Lda. · Setúbal, Portugal · NCAGE P3527 · ISO 9001:2015 · AS9120 Rev B · www.partyard.eu  |  RFQ ' . $rfqRef),
                ['size' => 7, 'color' => '94A3B8'],
                ['alignment' => Jc::CENTER]
            );
        }

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
