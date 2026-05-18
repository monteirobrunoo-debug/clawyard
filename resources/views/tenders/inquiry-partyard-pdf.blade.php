{{-- ═══════════════════════════════════════════════════════════════════════
     PartYard Defense INQUIRY — mimica Inquiry_MILITARY_MOD_072_V3.docx
     do Sistema_Gestão_Qualidade/QUALIDADE/MODELOS/PY Military_(em vigor).
     Renderizado por dompdf — sem JS, fontes default, layout A4.

     Usado por TenderInquiryController::generate() para gerar pedido de
     cotação em formato PartYard pronto a enviar ao fornecedor (anexa
     automaticamente ao concurso).

     Variáveis disponíveis:
       $tender    — App\Models\Tender
       $supplier  — App\Models\Supplier ou null (se geração genérica)
       $items     — array de [['desc','pn','qty','specs','norms']]
                    extraídos do SoR pelo controller
       $sor       — string com o texto do Statement of Requirements
                    (para anexar como secção integral, fonte da verdade)
       $today     — Carbon\Carbon
     ═══════════════════════════════════════════════════════════════════════ --}}
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <title>INQUIRY {{ $tender->reference ?: '#'.$tender->id }} · PartYard Defense</title>
    <style>
        @page { margin: 16mm 14mm; }
        body { font-family: DejaVu Sans, sans-serif; color: #1f2937; font-size: 10.5px; line-height: 1.45; margin: 0; }
        .head-bar { border-bottom: 2px solid #1e3a8a; padding-bottom: 6px; margin-bottom: 14px; }
        .head-bar .title { font-size: 18px; font-weight: 700; color: #1e3a8a; letter-spacing: 2px; }
        .head-bar .ref   { float: right; font-size: 11px; color: #64748b; font-weight: 600; }
        .head-bar .sub   { font-size: 10px; color: #6b7280; margin-top: 2px; }
        h2 { font-size: 12px; margin: 18px 0 6px; color: #1e3a8a; border-bottom: 1px solid #cbd5e1; padding-bottom: 3px; }
        .meta-table { width: 100%; border-collapse: collapse; font-size: 10px; margin-bottom: 12px; }
        .meta-table td { padding: 5px 7px; border: 1px solid #e2e8f0; vertical-align: top; }
        .meta-table td.label { background: #f1f5f9; font-weight: 700; color: #475569; width: 22%; }
        .items-table { width: 100%; border-collapse: collapse; font-size: 9.5px; margin: 8px 0 12px; }
        .items-table th { background: #1e3a8a; color: #ffffff; padding: 6px 7px; text-align: left; font-weight: 700; font-size: 9px; text-transform: uppercase; letter-spacing: 0.5px; }
        .items-table td { padding: 5px 7px; border-bottom: 1px solid #e2e8f0; vertical-align: top; }
        .items-table tr:nth-child(even) td { background: #f8fafc; }
        .terms { background: #f9fafb; border-left: 3px solid #1e3a8a; padding: 8px 12px; margin: 10px 0; font-size: 9.5px; }
        .terms h3 { margin: 0 0 4px; font-size: 10px; color: #1e3a8a; }
        .terms ul { margin: 0; padding-left: 16px; }
        .terms li { margin: 2px 0; }
        .sor-block { background: #fffbeb; border: 1px solid #fde68a; padding: 8px 12px; margin: 8px 0; font-size: 9px; font-family: DejaVu Sans Mono, monospace; white-space: pre-wrap; max-height: 380px; overflow: hidden; }
        .sig-block { margin-top: 26px; }
        .sig-block td { padding: 4px 8px; vertical-align: top; }
        .sig-block .line { border-top: 1px solid #94a3b8; min-width: 180px; height: 0; margin-top: 26px; }
        .footer-mod {
            position: fixed; bottom: -10mm; left: 0; right: 0;
            text-align: center; font-size: 8px; color: #94a3b8;
            border-top: 1px solid #e2e8f0; padding-top: 4px;
        }
    </style>
</head>
<body>

<div class="head-bar">
    <div class="title">INQUIRY <span class="ref">{{ $tender->reference ?: '#'.$tender->id }} · {{ $today->format('d-m-Y') }}</span></div>
    <div class="sub">PartYard — Defense Procurement · Pedido de Cotação ao Fornecedor</div>
</div>

<table class="meta-table">
    <tr>
        <td class="label">PARTYARD CONTACTO</td>
        <td>
            <strong>{{ $contactName ?? 'Pedro Duarte' }}</strong> · {{ $contactEmail ?? 'pedro.duarte@hp-group.org' }}<br>
            HP-Group · PartYard Defense · Lisboa, Portugal
        </td>
    </tr>
    <tr>
        <td class="label">FORNECEDOR</td>
        <td>
            @if($supplier)
                <strong>{{ $supplier->name }}</strong>
                @if($supplier->primary_email) · {{ $supplier->primary_email }}@endif
                @if(!empty($supplier->brands)) · Marcas: {{ implode(', ', (array) $supplier->brands) }}@endif
            @else
                <em>Para qualquer fornecedor convidado a apresentar oferta</em>
            @endif
        </td>
    </tr>
    <tr>
        <td class="label">CONCURSO</td>
        <td>
            <strong>{{ $tender->title }}</strong><br>
            Referência: {{ $tender->reference ?: '—' }} ·
            Organização Compradora: {{ $tender->purchasing_org ?: '—' }} ·
            Fonte: {{ strtoupper((string) $tender->source) }}
        </td>
    </tr>
    @if($tender->deadline_at)
    <tr>
        <td class="label">DEADLINE</td>
        <td>
            <strong style="color:#b91c1c">{{ $tender->deadline_at->format('d/m/Y H:i') }}</strong>
            ({{ \Carbon\Carbon::parse($tender->deadline_at)->diffForHumans() }})
        </td>
    </tr>
    @endif
    @if($tender->sap_opportunity_number)
    <tr>
        <td class="label">SAP OPP</td>
        <td>#{{ $tender->sap_opportunity_number }}</td>
    </tr>
    @endif
</table>

<h2>📋 Items a Cotar</h2>

@if(empty($items))
    <p style="font-style:italic;color:#6b7280;font-size:10px;">
        Items não extraídos automaticamente do RFP. Ver Statement of Requirements abaixo (texto integral).
    </p>
@else
<table class="items-table">
    <thead>
        <tr>
            <th style="width:5%">#</th>
            <th style="width:34%">Descrição</th>
            <th style="width:14%">P/N · Item Code</th>
            <th style="width:7%">Qty</th>
            <th style="width:25%">Specs / Normas</th>
            <th style="width:15%">Cotação Fornecedor</th>
        </tr>
    </thead>
    <tbody>
        @foreach($items as $i => $it)
        <tr>
            <td>{{ $i + 1 }}</td>
            <td>{{ $it['desc'] ?? '—' }}</td>
            <td style="font-family:DejaVu Sans Mono,monospace;">{{ $it['pn'] ?? '—' }}</td>
            <td>{{ $it['qty'] ?? '—' }}</td>
            <td>{{ $it['specs'] ?? '' }}{{ !empty($it['norms']) ? ' · ' . $it['norms'] : '' }}</td>
            <td style="background:#fffbeb;">__________</td>
        </tr>
        @endforeach
    </tbody>
</table>
@endif

<div class="terms">
    <h3>⚙ Termos do Pedido</h3>
    <ul>
        <li><strong>Cotação por linha</strong> — preço unitário + total + IVA (se aplicável). Indica isenção NATO/Mil/EUR.1 quando aplicável.</li>
        <li><strong>Lead time</strong> por item — incluir prazo de entrega EXW / DAP / DDP conforme melhor opção.</li>
        <li><strong>Condições de pagamento</strong> — confirmar (default 30/60 dias após factura, NSPA paga 60).</li>
        <li><strong>Validade da oferta</strong> — mínimo 60 dias para concursos NATO/NSPA.</li>
        <li><strong>Compliance</strong> — certificado de origem (EUR.1 / Form A), Mil-Std, CE-MDR, ISO conforme RFP.</li>
        <li><strong>Documentos</strong> — material safety data sheets (MSDS), datasheets, declaração de conformidade.</li>
        <li><strong>Garantia</strong> — indicar período + cobertura.</li>
    </ul>
</div>

@if($sor)
<h2>📄 Statement of Requirements (extracto integral do RFP)</h2>
<div class="sor-block">{{ $sor }}</div>
@endif

<table class="sig-block" width="100%">
    <tr>
        <td width="50%">
            <strong>PARTYARD DEFENSE</strong><br>
            {{ $contactName ?? 'Pedro Duarte' }}<br>
            {{ $contactEmail ?? 'pedro.duarte@hp-group.org' }}<br>
            <span class="line"></span>
            <span style="font-size:9px;color:#6b7280;">Data: {{ $today->format('d/m/Y') }}</span>
        </td>
        <td width="50%">
            <strong>FORNECEDOR</strong><br>
            @if($supplier)
                {{ $supplier->name }}<br>
                {{ $supplier->primary_email ?: '' }}<br>
            @else
                ____________________<br>
                ____________________<br>
            @endif
            <span class="line"></span>
            <span style="font-size:9px;color:#6b7280;">Data + Assinatura</span>
        </td>
    </tr>
</table>

<div class="footer-mod">
    MOD_072_V3 · Inquiry Military Defense · PartYard SGQ · ClawYard {{ $tender->id }} · Página <span class="pagenum"></span>
</div>

</body>
</html>
