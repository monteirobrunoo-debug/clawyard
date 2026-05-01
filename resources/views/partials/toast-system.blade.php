{{--
    Toast notifications (glass slide-in, top-right) — futurista round 3.

    Public JS API exposed on window.cyToast:
        cyToast({ title, body, tone, duration, url, sound })

    Where:
        title    — required string
        body     — optional secondary line
        tone     — 'success' | 'info' | 'warn' | 'error' | 'announce' (default 'info')
        duration — ms (default 5000); pass 0 to keep open until clicked
        url      — optional; click on the toast navigates there
        sound    — boolean; play the ping (default false)

    Hooks:
      • Server flash messages (session('status'), session('error')) auto-toasted on
        page load.
      • If `meta name="cy-activity-toasts"` is `enabled`, polls /api/activity-feed
        every 30s and toasts unseen high-importance items (leads ≥ 70, drafts
        pending, swarm completion).

    Sound:
      • Web Audio API generates a soft 880Hz → 660Hz blip (~120ms). No assets.
      • Disabled by default; toggle via localStorage 'cy-sound-enabled'.
--}}
<style>
    .cy-toast-stack {
        position: fixed; top: 12px; right: 16px;
        display: flex; flex-direction: column; gap: 10px;
        z-index: 10001; max-width: 360px;
        pointer-events: none;
    }
    .cy-toast {
        pointer-events: auto;
        background: rgba(17,17,17,0.92);
        border: 1px solid color-mix(in srgb, #76b900 30%, #2a2a2a);
        border-radius: 12px; padding: 12px 14px 14px 14px;
        backdrop-filter: blur(14px) saturate(160%);
        -webkit-backdrop-filter: blur(14px) saturate(160%);
        box-shadow: 0 14px 40px rgba(0,0,0,0.45),
                    0 0 0 1px color-mix(in srgb, #76b900 10%, transparent);
        position: relative; overflow: hidden;
        animation: cy-toast-in 0.32s cubic-bezier(0.4, 0, 0.2, 1);
        cursor: default;
    }
    :root[data-theme="light"] .cy-toast {
        background: rgba(255,255,255,0.95);
        border-color: rgba(118,185,0,0.4);
    }
    @keyframes cy-toast-in {
        from { transform: translate(28px, -8px); opacity: 0; }
        to   { transform: translate(0, 0);          opacity: 1; }
    }
    .cy-toast.is-leaving {
        animation: cy-toast-out 0.22s cubic-bezier(0.4, 0, 1, 1) forwards;
    }
    @keyframes cy-toast-out {
        to { transform: translate(28px, -8px); opacity: 0; }
    }

    /* Tone-specific accent border + icon colour. */
    .cy-toast.tone-success  { border-color: rgba(16,185,129,0.55); }
    .cy-toast.tone-info     { border-color: rgba(59,130,246,0.55); }
    .cy-toast.tone-warn     { border-color: rgba(245,158,11,0.55); }
    .cy-toast.tone-error    { border-color: rgba(239,68,68,0.55); }
    .cy-toast.tone-announce { border-color: rgba(118,185,0,0.55); }

    .cy-toast-row { display: flex; align-items: flex-start; gap: 10px; }
    .cy-toast-icon { font-size: 18px; line-height: 1; flex-shrink: 0; padding-top: 1px; }
    .cy-toast-body { flex: 1; min-width: 0; }
    .cy-toast-title { font-weight: 700; font-size: 13px; color: #e5e5e5; line-height: 1.35; }
    .cy-toast-sub   { font-size: 12px; color: #aaa; margin-top: 3px; line-height: 1.45; }
    :root[data-theme="light"] .cy-toast-title { color: #1f2937; }
    :root[data-theme="light"] .cy-toast-sub   { color: #6b7280; }
    .cy-toast-close {
        background: none; border: none; color: #555; cursor: pointer;
        padding: 0 0 0 6px; font-size: 14px; line-height: 1;
        transition: color 0.12s;
    }
    .cy-toast-close:hover { color: #e5e5e5; }
    :root[data-theme="light"] .cy-toast-close:hover { color: #1f2937; }

    /* Auto-dismiss progress bar — anchored at the bottom, drains
       linearly over the toast's duration. */
    .cy-toast-progress {
        position: absolute; bottom: 0; left: 0; height: 2px;
        background: linear-gradient(90deg, var(--toast-c, #76b900), color-mix(in srgb, var(--toast-c, #76b900) 40%, transparent));
        animation: cy-toast-drain linear forwards;
        transform-origin: left center;
    }
    @keyframes cy-toast-drain {
        from { width: 100%; } to { width: 0%; }
    }

    /* Make the whole toast a hover target when it has a URL. */
    .cy-toast.is-link { cursor: pointer; }
    .cy-toast.is-link:hover { transform: translateX(-2px); transition: transform 0.12s; }
</style>

<div class="cy-toast-stack" id="cyToastStack" aria-live="polite" aria-atomic="false"></div>

<script>
(function () {
    const stack = document.getElementById('cyToastStack');
    if (!stack) return;

    const TONE_DATA = {
        success:  { icon: '✓', color: '#10b981' },
        info:     { icon: 'ℹ',  color: '#3b82f6' },
        warn:     { icon: '⚠', color: '#f59e0b' },
        error:    { icon: '✗', color: '#ef4444' },
        announce: { icon: '📣', color: '#76b900' },
    };

    function escH(s) { return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

    // ── Web Audio API soft blip — 880Hz → 660Hz, ~120ms.
    let audioCtx = null;
    function pingSound() {
        if (localStorage.getItem('cy-sound-enabled') !== '1') return;
        try {
            audioCtx = audioCtx || new (window.AudioContext || window.webkitAudioContext)();
            const o = audioCtx.createOscillator();
            const g = audioCtx.createGain();
            o.connect(g); g.connect(audioCtx.destination);
            o.type = 'sine';
            o.frequency.setValueAtTime(880, audioCtx.currentTime);
            o.frequency.exponentialRampToValueAtTime(660, audioCtx.currentTime + 0.12);
            g.gain.setValueAtTime(0.0001, audioCtx.currentTime);
            g.gain.exponentialRampToValueAtTime(0.18, audioCtx.currentTime + 0.02);
            g.gain.exponentialRampToValueAtTime(0.0001, audioCtx.currentTime + 0.14);
            o.start(); o.stop(audioCtx.currentTime + 0.16);
        } catch (e) { /* ignore — audio not allowed yet */ }
    }

    function show(opts) {
        const tone     = opts.tone || 'info';
        const meta     = TONE_DATA[tone] || TONE_DATA.info;
        const duration = (opts.duration === 0) ? 0 : (opts.duration || 5000);
        const wrapTag  = opts.url ? 'a' : 'div';
        const wrapAttr = opts.url ? ` href="${escH(opts.url)}"` : '';
        const linkCls  = opts.url ? ' is-link' : '';

        const el = document.createElement(wrapTag);
        el.className = 'cy-toast tone-' + tone + linkCls;
        el.style.setProperty('--toast-c', meta.color);
        if (opts.url) el.setAttribute('href', opts.url);
        el.style.textDecoration = 'none';

        el.innerHTML = `
            <div class="cy-toast-row">
                <div class="cy-toast-icon" style="color:${meta.color}">${escH(opts.icon || meta.icon)}</div>
                <div class="cy-toast-body">
                    <div class="cy-toast-title">${escH(opts.title || '')}</div>
                    ${opts.body ? `<div class="cy-toast-sub">${escH(opts.body)}</div>` : ''}
                </div>
                <button class="cy-toast-close" type="button" aria-label="Close">×</button>
            </div>
            ${duration > 0 ? `<div class="cy-toast-progress" style="animation-duration:${duration}ms"></div>` : ''}`;

        stack.appendChild(el);

        // Click on close button → dismiss without nav.
        el.querySelector('.cy-toast-close')?.addEventListener('click', (e) => {
            e.preventDefault(); e.stopPropagation();
            dismiss(el);
        });

        if (duration > 0) {
            setTimeout(() => dismiss(el), duration);
        }

        if (opts.sound) pingSound();
        return el;
    }

    function dismiss(el) {
        if (!el || el.classList.contains('is-leaving')) return;
        el.classList.add('is-leaving');
        setTimeout(() => el.remove(), 250);
    }

    // Public API.
    window.cyToast = show;
    window.cyToastDismiss = dismiss;

    // ── Server flash messages → toast on page load.
    document.addEventListener('DOMContentLoaded', () => {
        @if(session('status'))
            show({ title: @json(session('status')), tone: 'success', duration: 5000 });
        @endif
        @if(session('error'))
            show({ title: @json(session('error')),  tone: 'error', duration: 7000 });
        @endif
        @if($errors->any())
            show({ title: 'Erro de validação', body: @json($errors->first()), tone: 'error', duration: 7000 });
        @endif
    });

    // ── Optional: poll /api/activity-feed for high-importance new items.
    //    Opt-in via <meta name="cy-activity-toasts" content="enabled">.
    const meta = document.querySelector('meta[name="cy-activity-toasts"]');
    if (meta && meta.content === 'enabled') {
        let lastSeen = Date.now();
        async function pollActivity() {
            try {
                const r = await fetch('/api/activity-feed', { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' });
                if (!r.ok) return;
                const data = await r.json();
                const fresh = (data.items || []).filter(it => {
                    const ts = Date.parse(it.at);
                    return !isNaN(ts) && ts > lastSeen;
                }).slice(0, 3);   // never spam — max 3 per poll
                fresh.forEach(it => {
                    show({ title: (it.icon || '·') + ' ' + (it.label || 'evento'), tone: 'announce', duration: 6000, url: it.url || null, sound: true });
                });
                lastSeen = Date.now();
            } catch (e) { /* silent */ }
        }
        setInterval(pollActivity, 30000);
    }
})();
</script>
