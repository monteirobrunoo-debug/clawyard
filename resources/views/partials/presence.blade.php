{{--
    Real-time "who else is here" presence.
    Shows a stack of user avatars in the top-right corner, just below
    the header, when ≥ 1 other user is on the same path right now.

    Heartbeat: every 30s POST to /api/presence/heartbeat.
    Read:      every 30s (offset by 5s) GET /api/presence/who.

    Privacy/security: presence is scoped per-path (URL pathname only,
    no query string), so accessing /tenders/12?secret=… doesn't reveal
    the secret to anyone else. Backend already enforces auth.
--}}
<style>
    .cy-presence {
        position: fixed; top: 78px; right: 18px; z-index: 80;
        display: none;     /* shown via JS when there's >=1 other */
        align-items: center; gap: 6px;
        padding: 6px 10px 6px 8px; border-radius: 999px;
        background: rgba(17,17,17,0.78);
        border: 1px solid color-mix(in srgb, #76b900 28%, #2a2a2a);
        backdrop-filter: blur(10px) saturate(140%);
        -webkit-backdrop-filter: blur(10px) saturate(140%);
        box-shadow: 0 6px 20px rgba(0,0,0,0.25);
        font-size: 11px;
    }
    :root[data-theme="light"] .cy-presence {
        background: rgba(255,255,255,0.92); border-color: rgba(118,185,0,0.4);
    }
    .cy-presence.is-active { display: inline-flex; }
    .cy-presence-stack { display: inline-flex; }
    .cy-presence-avatar {
        width: 24px; height: 24px; border-radius: 50%;
        background: linear-gradient(135deg, #76b900, #4dd0ff);
        color: #000; font-weight: 800; font-size: 10px;
        display: inline-flex; align-items: center; justify-content: center;
        border: 2px solid rgba(17,17,17,0.78);
        margin-left: -8px;     /* overlap stack */
        box-shadow: 0 0 0 1px rgba(118,185,0,0.4);
    }
    :root[data-theme="light"] .cy-presence-avatar { border-color: rgba(255,255,255,0.95); }
    .cy-presence-avatar:first-child { margin-left: 0; }
    .cy-presence-text { color: #aaa; font-weight: 600; padding-right: 4px; }
    :root[data-theme="light"] .cy-presence-text { color: #4b5563; }
    .cy-presence-pulse {
        width: 6px; height: 6px; border-radius: 50%;
        background: #76b900; box-shadow: 0 0 6px #76b900;
        animation: pulse-dot 2s ease-in-out infinite;
    }
</style>

<div class="cy-presence" id="cyPresence" aria-live="polite">
    <span class="cy-presence-pulse"></span>
    <span class="cy-presence-stack" id="cyPresenceStack"></span>
    <span class="cy-presence-text" id="cyPresenceText"></span>
</div>

<script>
(function () {
    const container = document.getElementById('cyPresence');
    const stack     = document.getElementById('cyPresenceStack');
    const textEl    = document.getElementById('cyPresenceText');
    if (!container || !stack || !textEl) return;

    const csrf = document.querySelector('meta[name=csrf-token]')?.content;
    const path = location.pathname;

    function escH(s) { return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

    async function heartbeat() {
        try {
            await fetch('/api/presence/heartbeat', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf || '', 'Accept': 'application/json' },
                body: JSON.stringify({ url: path }),
                credentials: 'same-origin',
            });
        } catch (e) { /* offline or auth lost — ignore */ }
    }
    async function who() {
        try {
            const r = await fetch('/api/presence/who?url=' + encodeURIComponent(path), {
                headers: { 'Accept': 'application/json' }, credentials: 'same-origin',
            });
            if (!r.ok) return;
            const d = await r.json();
            render(d.live || []);
        } catch (e) { /* silent */ }
    }

    function render(others) {
        if (others.length === 0) {
            container.classList.remove('is-active');
            return;
        }
        const max = 4;
        const visible = others.slice(0, max);
        stack.innerHTML = visible.map(u => {
            const initials = u.initials || (u.name?.[0] || '?').toUpperCase();
            return `<span class="cy-presence-avatar" title="${escH(u.name)} — também aqui">${escH(initials)}</span>`;
        }).join('');
        const extra = others.length > max ? ` +${others.length - max}` : '';
        textEl.textContent = (others.length === 1 ? '1 pessoa aqui' : others.length + ' pessoas aqui') + extra;
        container.classList.add('is-active');
    }

    // First call: immediate heartbeat + delayed who (server needs to
    // index the heartbeat before answering "who" honestly).
    heartbeat();
    setTimeout(who, 1500);

    // Then every 30s.
    setInterval(heartbeat, 30000);
    setInterval(who,       30000);

    // Send a final heartbeat with empty url on unload so the user is
    // moved off this page from other people's POVs faster.
    window.addEventListener('pagehide', () => {
        // sendBeacon: fires even if the page is being unloaded.
        try {
            const data = JSON.stringify({ url: '/__leaving__' });
            navigator.sendBeacon('/api/presence/heartbeat', new Blob([data], { type: 'application/json' }));
        } catch (e) { /* ignore */ }
    });
})();
</script>
