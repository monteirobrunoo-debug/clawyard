{{--
    Mini activity meter — small floating chip in the bottom-right
    corner showing live system pulse.

    Reads /api/activity-feed every 60s (the same endpoint the ticker
    + Mission Control toasts use, so cache hits the existing 30s
    Cache::remember layer with zero extra DB cost) and shows:

      • Number of events in the last hour (across tenders, leads,
        messages, swarm runs, reports)
      • A breathing dot: green if ≥1 event in last 5 min, amber if
        last hour, gray if quieter.

    Click → opens /mission for the manager view.
--}}
<style>
    .cy-meter {
        position: fixed; bottom: 16px; right: 16px; z-index: 60;
        display: inline-flex; align-items: center; gap: 8px;
        padding: 7px 12px 7px 9px; border-radius: 999px;
        background: rgba(17,17,17,0.78);
        border: 1px solid color-mix(in srgb, #76b900 24%, #2a2a2a);
        backdrop-filter: blur(10px) saturate(140%);
        -webkit-backdrop-filter: blur(10px) saturate(140%);
        box-shadow: 0 6px 20px rgba(0,0,0,0.25);
        color: #ccc; font-size: 11px; font-weight: 600;
        text-decoration: none; cursor: pointer;
        transition: transform 0.15s, color 0.15s;
        opacity: 0;     /* hidden until first poll completes */
    }
    .cy-meter.is-ready { opacity: 1; }
    .cy-meter:hover { transform: translateY(-2px); color: #76b900; }
    :root[data-theme="light"] .cy-meter {
        background: rgba(255,255,255,0.92); border-color: rgba(118,185,0,0.4); color: #4b5563;
    }
    .cy-meter-dot {
        width: 8px; height: 8px; border-radius: 50%;
        background: #888;
        box-shadow: 0 0 6px transparent;
        transition: background 0.3s, box-shadow 0.3s;
    }
    .cy-meter-dot.is-hot {
        background: #76b900; box-shadow: 0 0 8px #76b900;
        animation: pulse-dot 1.6s ease-in-out infinite;
    }
    .cy-meter-dot.is-warm {
        background: #f59e0b; box-shadow: 0 0 6px #f59e0b;
    }
    .cy-meter-num { color: #e5e5e5; font-weight: 800; }
    :root[data-theme="light"] .cy-meter-num { color: #1f2937; }
    .cy-meter-sep { color: #555; }
</style>

<a href="{{ route('mission') ?? '/mission' }}" class="cy-meter" id="cyMeter" title="Pulso do sistema (1h) — clica para Mission Control">
    <span class="cy-meter-dot" id="cyMeterDot"></span>
    <span><span class="cy-meter-num" id="cyMeterNum">·</span> eventos<span class="cy-meter-sep"> · </span><span id="cyMeterAgo">1h</span></span>
</a>

<script>
(function () {
    const root = document.getElementById('cyMeter');
    const dot  = document.getElementById('cyMeterDot');
    const num  = document.getElementById('cyMeterNum');
    if (!root || !dot || !num) return;

    async function refresh() {
        try {
            const r = await fetch('/api/activity-feed', { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' });
            if (!r.ok) return;
            const data = await r.json();
            const now  = Date.now();
            const items = (data.items || []);
            // Bucket events by recency.
            let hot = 0;     // last 5 min
            let warm = 0;    // last hour
            for (const it of items) {
                const ts = Date.parse(it.at);
                if (isNaN(ts)) continue;
                const ageMs = now - ts;
                if (ageMs <= 5 * 60 * 1000) hot++;
                if (ageMs <= 60 * 60 * 1000) warm++;
            }
            num.textContent = warm;
            dot.classList.remove('is-hot', 'is-warm');
            if (hot > 0)        dot.classList.add('is-hot');
            else if (warm > 0)  dot.classList.add('is-warm');
            root.classList.add('is-ready');
        } catch (e) { /* silent */ }
    }
    refresh();
    setInterval(refresh, 60000);
})();
</script>
