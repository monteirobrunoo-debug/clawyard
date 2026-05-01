{{--
    Ring-chart component — replaces flat numeric stats with an animated
    SVG donut. Usage:

        @include('partials.ring-chart', [
            'value'   => 782,
            'total'   => 805,
            'label'   => 'Aprovados',
            'tone'    => 'emerald',          // emerald | amber | red | blue | gray
            'subline' => '97% do directório', // optional
        ])

    The dasharray is computed server-side; the dashoffset animates from
    full → target on first paint via a CSS @keyframes (each chart has a
    unique id so multiple animate independently). Pure SVG — no JS, no
    chart library. ~2 kB gzipped per page even with 5 instances.
--}}
@php
    $rcValue   = (float) ($value   ?? 0);
    $rcTotal   = max(1.0, (float) ($total ?? max($rcValue, 1)));
    $rcPct     = max(0.0, min(1.0, $rcValue / $rcTotal));
    $rcLabel   = (string) ($label   ?? '');
    $rcTone    = (string) ($tone    ?? 'emerald');
    $rcSubline = (string) ($subline ?? '');
    $rcId      = 'rc-' . substr(md5(($rcLabel ?: 'x') . microtime(true) . rand()), 0, 8);

    $rcColors = [
        'emerald' => ['#10b981', 'text-emerald-700 bg-emerald-50 border-emerald-200'],
        'amber'   => ['#f59e0b', 'text-amber-800  bg-amber-50  border-amber-200'],
        'red'     => ['#ef4444', 'text-red-700    bg-red-50    border-red-200'],
        'blue'    => ['#3b82f6', 'text-blue-800   bg-blue-50   border-blue-200'],
        'gray'    => ['#6b7280', 'text-gray-700   bg-gray-50   border-gray-200'],
        'indigo'  => ['#6366f1', 'text-indigo-700 bg-indigo-50 border-indigo-200'],
    ];
    [$rcStroke, $rcChipClass] = $rcColors[$rcTone] ?? $rcColors['emerald'];

    // Geometry: viewBox 80×80, radius 32, circumference ≈ 201.06.
    $rcCircum  = 201.06;
    $rcOffset  = round($rcCircum * (1 - $rcPct), 2);
    $rcDisplay = $rcValue >= 1000 ? number_format($rcValue / 1000, 1) . 'k' : (string) (int) $rcValue;
@endphp
<div class="rounded-xl border bg-white shadow-sm overflow-hidden flex items-center gap-3 px-3 py-2">
    <style>
        @keyframes {{ $rcId }}-anim {
            from { stroke-dashoffset: {{ $rcCircum }}; }
            to   { stroke-dashoffset: {{ $rcOffset }}; }
        }
        #{{ $rcId }} .rc-arc {
            stroke-dasharray: {{ $rcCircum }};
            stroke-dashoffset: {{ $rcOffset }};
            animation: {{ $rcId }}-anim 1.1s cubic-bezier(0.4, 0, 0.2, 1) both;
        }
    </style>
    <svg id="{{ $rcId }}" viewBox="0 0 80 80" width="48" height="48" class="shrink-0">
        <circle cx="40" cy="40" r="32" stroke="#e5e7eb" stroke-width="7" fill="none"></circle>
        <circle class="rc-arc" cx="40" cy="40" r="32" stroke="{{ $rcStroke }}" stroke-width="7"
                fill="none" stroke-linecap="round" transform="rotate(-90 40 40)"></circle>
        <text x="40" y="46" text-anchor="middle" font-size="20" font-weight="700"
              font-family="Inter, system-ui, sans-serif" fill="{{ $rcStroke }}">{{ $rcDisplay }}</text>
    </svg>
    <div class="min-w-0">
        <div class="text-[10px] uppercase tracking-wider opacity-70">{{ $rcLabel }}</div>
        <div class="text-sm font-semibold text-gray-800">
            {{ number_format($rcValue) }}<span class="text-xs text-gray-500"> / {{ number_format($rcTotal) }}</span>
        </div>
        @if($rcSubline)
            <div class="text-[11px] text-gray-500 truncate">{{ $rcSubline }}</div>
        @endif
    </div>
</div>
