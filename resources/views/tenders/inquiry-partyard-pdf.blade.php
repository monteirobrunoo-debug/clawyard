{{-- ═══════════════════════════════════════════════════════════════════════
     PartYard Militar — RFQ Inquiry per supplier (formato MOD_072_V3)
     Reescrito 2026-05-18 conforme PDFs de referência reais que o operador
     enviou (RFQ_10_Erbe_email.pdf, RFQ_11_Rhinomanometry, RFQ_12_BoneDrill).
     Estrutura: header corporativo · supplier title · To/CC/Subject ·
     greeting · tender context paragraph · items table · spec + compliance
     matrix POR ITEM · what-we-need response checklist · mandatory docs ·
     deadline · signature · footer NCAGE.
     ═══════════════════════════════════════════════════════════════════════ --}}
@php
    // Refª SAP Opp é a usada como RFQ number. Fallback PYD-NNNNNN.
    $rfqRef = $tender->sap_opportunity_number
        ? $tender->sap_opportunity_number . '/' . $today->format('Y')
        : 'PYD-' . str_pad((string) $tender->id, 6, '0', STR_PAD_LEFT);

    // Anonimização cliente: usa "Portuguese MoD" (cover term) e revela só
    // a refª SAP. Pedido: "não menciones o nosso cliente" — mais soft que
    // [end-customer] porque o operador no PDF de referência usa este formato.
    $tenderContext = 'Portuguese Ministry of Defence tender ref. ' . $rfqRef;

    $supplierName = $supplier?->name ?? 'Supplier';
    // Supplier.country_code é ISO-2 (DE, PT, FR…). Mapa pequeno para os
    // países que aparecem com frequência nos nossos OEMs militares; resto
    // fica com o código (DE, PL, US…) que é universalmente legível.
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

    // To/CC line — múltiplos emails se houver
    $toEmails = [];
    if ($supplier?->primary_email) $toEmails[] = $supplier->primary_email;
    if (!empty($supplier?->additional_emails)) {
        foreach ((array) $supplier->additional_emails as $e) {
            $e = trim((string) $e);
            if ($e !== '') $toEmails[] = $e;
        }
    }
    $toLine = $toEmails ? implode(' ; ', array_slice($toEmails, 0, 3))
                        : '____________________________________';

    // Subject line conforme reference: short + clear
    $subjectKeyword = '';
    if (!empty($items[0]['desc'])) {
        // Tira "Apparatus", "System", etc. para summary do subject
        $first = $items[0]['desc'];
        $subjectKeyword = trim(preg_replace('/\s*[\(\[].*$/', '', $first));
    }
    $subject = 'RFQ ' . $rfqRef . ' — ' . ($subjectKeyword ?: 'ENT/ORL Equipment Package')
             . ' (Portugal MoD Tender)';
    if ($tender->deadline_at) {
        $subject .= ' — Response by ' . $tender->deadline_at->format('Y-m-d');
    }
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>RFQ {{ $rfqRef }} — {{ $supplierName }}</title>
    <style>
        @page { margin: 16mm 14mm; }
        body { font-family: DejaVu Sans, sans-serif; color: #1f2937; font-size: 10.5px; line-height: 1.55; margin: 0; }

        /* Corporate header — dark navy block top of every page */
        .corp-header {
            background: #0f1b4c; color: #ffffff;
            padding: 8px 14px; margin: -2px -2px 14px;
            display: table; width: 100%;
        }
        .corp-header .left { display: table-cell; vertical-align: middle; font-size: 13px; font-weight: 700; letter-spacing: 1px; }
        .corp-header .right { display: table-cell; vertical-align: middle; text-align: right; font-size: 8px; color: #c7d2fe; line-height: 1.3; }

        /* Footer corporativo na base de cada página */
        .corp-footer {
            position: fixed; bottom: -10mm; left: 0; right: 0;
            font-size: 7.5px; color: #6b7280;
            border-top: 1px solid #e2e8f0; padding-top: 4px;
            text-align: left; padding-left: 14mm; padding-right: 14mm;
        }
        .corp-footer .right { float: right; }

        h1.rfq-title { font-size: 15px; color: #0f1b4c; margin: 0 0 10px; font-weight: 700; }

        .meta-block { margin: 8px 0 14px; font-size: 10px; }
        .meta-block .row { margin: 1.5px 0; }
        .meta-block .label { font-weight: 700; min-width: 70px; display: inline-block; }
        .meta-block .subject { font-weight: 700; }

        hr.divider { border: 0; border-top: 1px solid #cbd5e1; margin: 12px 0; }

        p.greeting { margin: 8px 0; }
        p.body-p { margin: 8px 0; line-height: 1.6; }

        /* Items table — Line | Equipment | Qty | Reference product */
        .items-tbl { width: 100%; border-collapse: collapse; margin: 10px 0 14px; font-size: 9.5px; }
        .items-tbl th { background: #2563eb; color: #ffffff; padding: 6px 8px; text-align: left; font-weight: 700; font-size: 9px; text-transform: none; }
        .items-tbl td { padding: 6px 8px; border-bottom: 1px solid #e2e8f0; vertical-align: top; }
        .items-tbl tr:nth-child(even) td { background: #f8fafc; }

        /* Item block — per-item spec + compliance matrix UNDER the items table */
        .item-block { margin: 14px 0 16px; page-break-inside: avoid; }
        .item-block .item-head { font-size: 11px; font-weight: 700; color: #0f1b4c; margin-bottom: 6px; border-bottom: 1px solid #cbd5e1; padding-bottom: 3px; }
        .spec-list { margin: 4px 0 8px 14px; padding: 0; }
        .spec-list li { margin: 2px 0; }

        .matrix-tbl { width: 100%; border-collapse: collapse; margin: 6px 0 8px; font-size: 9px; }
        .matrix-tbl th { background: #e0e7ff; color: #1e3a8a; padding: 4px 6px; text-align: left; font-weight: 700; font-size: 8px; border: 1px solid #c7d2fe; }
        .matrix-tbl td { padding: 4px 6px; border: 1px solid #c7d2fe; vertical-align: top; background: #fff; min-height: 24px; }
        .matrix-tbl td.fillin { background: #fffbeb; }

        h2.section { font-size: 12px; color: #0f1b4c; margin: 14px 0 6px; font-weight: 700; }

        .checklist { margin: 4px 0 0 0; padding: 0 0 0 20px; }
        .checklist li { margin: 3px 0; font-size: 10px; }
        .checklist strong { color: #0f1b4c; }

        .docs-list { margin: 4px 0 0 14px; padding: 0; }
        .docs-list li { margin: 2px 0; font-size: 10px; }

        .deadline-block { background: #fef3c7; border-left: 3px solid #f59e0b; padding: 8px 12px; margin: 10px 0; font-size: 10px; }
        .deadline-block strong { color: #92400e; }

        .signature { margin-top: 22px; font-size: 10px; }
        .signature .name { font-weight: 700; color: #0f1b4c; font-size: 11px; }
        .signature .role { font-style: italic; color: #4b5563; }
        .signature .creds { font-size: 9px; color: #6b7280; margin-top: 8px; }
    </style>
</head>
<body>

<div class="corp-header">
    <div class="left">PARTYARD MILITAR, LDA.</div>
    <div class="right">
        RFQ {{ $rfqRef }} — ENT/ORL Equipment Package<br>
        Portuguese Ministry of Defence Tender
    </div>
</div>

<h1 class="rfq-title">RFQ {{ $rfqRef }} — {{ $supplierName }}{{ $supplierCountry ? ' (' . $supplierCountry . ')' : '' }}</h1>

<div class="meta-block">
    <div class="row"><span class="label">To:</span> {{ $toLine }}</div>
    <div class="row"><span class="label">CC:</span> procurement@partyard.eu</div>
    <div class="row"><span class="label">Subject:</span> <span class="subject">{{ $subject }}</span></div>
</div>

<hr class="divider">

<p class="greeting">Dear {{ $supplierName }} Sales Team,</p>

<p class="body-p">
    PartYard Militar, Lda. (NCAGE P3527, Portugal) is bidding for
    <strong>{{ $tenderContext }}</strong>
    and requests your binding quotation for:
</p>

@if(!empty($items))
<table class="items-tbl">
    <thead>
        <tr>
            <th style="width:8%">Line</th>
            <th style="width:46%">Equipment</th>
            <th style="width:14%">Qty (up to)</th>
            <th style="width:32%">Reference product</th>
        </tr>
    </thead>
    <tbody>
        @foreach($items as $i => $it)
        <tr>
            <td><strong>{{ $it['line'] ?? (($i + 1) * 10) }}</strong></td>
            <td>{{ $it['desc'] ?? '—' }}</td>
            <td>{{ $it['qty'] ?? '—' }}</td>
            <td>{{ $it['reference'] ?? $it['pn'] ?? '—' }}</td>
        </tr>
        @endforeach
    </tbody>
</table>

{{-- 2026-05-18: PEDIDO DIRECTO — "por por baixo de cada item a
     especificação e matrix request". Cada item ganha um bloco próprio
     com (a) Technical specification bullets, (b) Compliance Matrix
     em formato tabela com Comply/Not Comply/Partial + Remarks. --}}
@foreach($items as $i => $it)
    <div class="item-block">
        <div class="item-head">
            Line {{ $it['line'] ?? (($i + 1) * 10) }} — {{ $it['desc'] ?? 'Item ' . ($i + 1) }}
        </div>

        <strong style="font-size:10px;color:#0f1b4c;">Technical specification</strong>
        @php
            // Constrói bullets a partir de specs + norms + parens
            $bullets = [];
            if (!empty($it['specs'])) {
                foreach (preg_split('/[·;]|\s—\s/', $it['specs']) as $b) {
                    $b = trim($b);
                    if ($b !== '' && mb_strlen($b) > 3) $bullets[] = $b;
                }
            }
            if (!empty($it['norms'])) $bullets[] = 'Compliance: ' . $it['norms'];
            if (empty($bullets)) $bullets[] = 'Specifications per RFP attachments. Confirm compatibility with reference product.';
        @endphp
        <ul class="spec-list">
            @foreach($bullets as $b)
                <li>{{ $b }}</li>
            @endforeach
            <li>220V / 50Hz, European plug</li>
            <li>Accessories ready for immediate use</li>
        </ul>

        <strong style="font-size:10px;color:#0f1b4c;">Compliance Matrix (please complete)</strong>
        <table class="matrix-tbl">
            <thead>
                <tr>
                    <th style="width:46%">Requirement</th>
                    <th style="width:14%">Comply</th>
                    <th style="width:14%">Not Comply</th>
                    <th style="width:12%">Partial</th>
                    <th style="width:14%">Remarks</th>
                </tr>
            </thead>
            <tbody>
                @foreach($bullets as $b)
                <tr>
                    <td>{{ $b }}</td>
                    <td class="fillin">☐</td>
                    <td class="fillin">☐</td>
                    <td class="fillin">☐</td>
                    <td class="fillin"></td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endforeach
@else
    <p class="body-p" style="font-style:italic;color:#6b7280;">
        Detailed item specifications are provided in the attached RFP package.
    </p>
@endif

<h2 class="section">What we need in your response</h2>
<ol class="checklist">
    <li><strong>Unit price</strong> EXW (your facility) and DAP Lisbon, Portugal (Incoterms 2020), EUR excl. VAT</li>
    <li><strong>Lead time</strong> PO → DAP Lisbon</li>
    <li><strong>Offer validity:</strong> minimum 120 days</li>
    <li><strong>MoQ, payment terms, warranty</strong> (period + coverage)</li>
    <li><strong>Consumables</strong> (reusable vs disposable) — pricing for 24 months</li>
    <li><strong>On-site training</strong> (days, language)</li>
</ol>

<h2 class="section">Mandatory documentation</h2>
<ul class="docs-list">
    <li>CE MDR certificate (Reg. EU 2017/745)</li>
    <li>EU Declaration of Conformity</li>
    <li>ISO 13485:2016 certificate (Medical devices QMS)</li>
    <li>Country of Origin</li>
    <li>IEC 60601 compliance (electrical safety, medical electrical equipment)</li>
    <li>Datasheets and operator manuals (English)</li>
</ul>

<div class="deadline-block">
    <strong>Response deadline:</strong>
    @if($tender->deadline_at)
        {{ $tender->deadline_at->format('l, Y-m-d, H:i') }} WET.
    @else
        Please indicate your earliest response timeline.
    @endif
    Send to <strong>procurement@partyard.eu</strong>, copy {{ $contactEmail ?? 'bruno.monteiro@partyard.eu' }}.
</div>

<div class="signature">
    Best regards,
    <div class="name">{{ $contactName ?? 'Bruno Monteiro' }}</div>
    <div class="role">Military Procurement Lead</div>
    <div>PartYard Militar, Lda.</div>
    <div class="creds">NCAGE P3527 · ISO 9001:2015 · AS9120 Rev B</div>
</div>

<div class="corp-footer">
    PartYard Militar, Lda. · Setúbal, Portugal · NCAGE P3527 · ISO 9001:2015 · AS9120 Rev B · www.partyard.eu
    <span class="right">RFQ {{ $rfqRef }}</span>
</div>

</body>
</html>
