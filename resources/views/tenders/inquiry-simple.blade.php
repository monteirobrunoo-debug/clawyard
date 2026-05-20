{{--
    Inquiry simplificado — PartYard.
    Pedido directo 2026-05-20:
      "poe apenas o Nome PartYard - Refº Sap e descicao e item do
       equiapamento a pedir, apenas mencionar, Dear Sirs, Please inform
       us your Best Price and Delivery time for the following:"
    Sem branding militar, sem MOD_072_V3, sem campos de assinatura
    complexos. Layout limpo para enviar a fornecedores.
--}}<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Inquiry · PartYard · {{ $rfqRef }}</title>
    <style>
        @page { margin: 28mm 22mm 25mm 22mm; }
        body {
            font-family: 'DejaVu Sans', Helvetica, Arial, sans-serif;
            font-size: 11pt;
            line-height: 1.5;
            color: #1a1a1a;
        }
        .brand {
            font-size: 22pt;
            font-weight: bold;
            color: #1e3a8a;
            margin-bottom: 4mm;
        }
        .meta {
            border-top: 1px solid #ddd;
            border-bottom: 1px solid #ddd;
            padding: 4mm 0;
            margin-bottom: 8mm;
            font-size: 10pt;
        }
        .meta-row { margin-bottom: 2mm; }
        .meta-label {
            display: inline-block;
            min-width: 38mm;
            font-weight: bold;
            color: #475569;
        }
        .greeting { margin-top: 6mm; margin-bottom: 4mm; }
        .request { margin-bottom: 6mm; }
        .items {
            border-collapse: collapse;
            width: 100%;
            margin-bottom: 8mm;
        }
        .items th, .items td {
            border: 1px solid #cbd5e1;
            padding: 3mm 4mm;
            text-align: left;
            vertical-align: top;
        }
        .items th {
            background: #f1f5f9;
            font-size: 9.5pt;
            color: #334155;
        }
        .items td.num {
            text-align: center;
            width: 12mm;
            color: #64748b;
        }
        .items td.desc { font-size: 10.5pt; }
        .items td.qty {
            text-align: center;
            width: 18mm;
        }
        .signature {
            margin-top: 10mm;
            font-size: 10.5pt;
        }
        .signature .name { font-weight: bold; }
        .signature .role { color: #64748b; }
    </style>
</head>
<body>
    <div class="brand">PartYard</div>

    <div class="meta">
        <div class="meta-row">
            <span class="meta-label">Ref. SAP:</span>
            <span>{{ $rfqRef }}</span>
        </div>
        <div class="meta-row">
            <span class="meta-label">Description:</span>
            <span>{{ $description }}</span>
        </div>
        <div class="meta-row">
            <span class="meta-label">Date:</span>
            <span>{{ $today->format('d M Y') }}</span>
        </div>
    </div>

    <div class="greeting">Dear Sirs,</div>

    <div class="request">
        Please inform us your <strong>Best Price</strong> and <strong>Delivery time</strong> for the following:
    </div>

    <table class="items">
        <thead>
            <tr>
                <th class="num">#</th>
                <th>Equipment / Item</th>
                <th class="qty">Qty</th>
            </tr>
        </thead>
        <tbody>
            @forelse($items as $idx => $item)
                <tr>
                    <td class="num">{{ $idx + 1 }}</td>
                    <td class="desc">{{ $item['desc'] ?? '—' }}</td>
                    <td class="qty">{{ $item['qty'] ?? '—' }}</td>
                </tr>
            @empty
                <tr>
                    <td class="num">1</td>
                    <td class="desc">{{ $description }}</td>
                    <td class="qty">—</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <div class="signature">
        Best regards,<br>
        <span class="name">{{ $contactName }}</span><br>
        <span class="role">PartYard</span><br>
        {{ $contactEmail }}
    </div>
</body>
</html>
