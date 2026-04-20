{{--
    Global keyboard shortcuts — include once per view in the page <body>
    (ideally just before the closing </body> tag so the DOM is ready).

    Shortcuts:
      /           Focus the first visible search input (or go to dashboard if none)
      g d         Go to dashboard
      g c         Go to chat
      g b         Go to briefing
      g r         Go to reports
      g h         Go to conversations history
      t           Toggle dark / light theme
      ?           Show this help overlay
      Esc         Close help overlay (also clears search when focused)

    Ignored while typing in <input>, <textarea>, contenteditable, or a select.
--}}
<style>
    #cy-kbd-help {
        position: fixed; inset: 0; z-index: 10000;
        background: rgba(0, 0, 0, 0.72);
        display: none; align-items: center; justify-content: center;
        backdrop-filter: blur(4px);
        -webkit-backdrop-filter: blur(4px);
        animation: cyKbdFade .15s ease-out;
    }
    :root[data-theme="light"] #cy-kbd-help { background: rgba(0, 0, 0, 0.35); }
    #cy-kbd-help.is-open { display: flex; }
    @keyframes cyKbdFade { from { opacity: 0; } to { opacity: 1; } }

    #cy-kbd-help .cy-kbd-panel {
        width: min(460px, 92vw);
        background: #111;
        border: 1px solid #2a2a2a;
        border-radius: 14px;
        padding: 22px 24px;
        color: #e5e5e5;
        font: 14px/1.5 Inter, system-ui, sans-serif;
        box-shadow: 0 20px 60px rgba(0,0,0,0.6);
    }
    :root[data-theme="light"] #cy-kbd-help .cy-kbd-panel {
        background: #fff;
        border-color: #e5e7eb;
        color: #111;
        box-shadow: 0 20px 60px rgba(0,0,0,0.18);
    }
    #cy-kbd-help h3 {
        margin: 0 0 14px; font-size: 15px; font-weight: 700;
        display: flex; align-items: center; gap: 8px;
    }
    #cy-kbd-help .cy-kbd-row {
        display: flex; justify-content: space-between; align-items: center;
        padding: 7px 0; border-bottom: 1px dashed rgba(127,127,127,0.15);
    }
    #cy-kbd-help .cy-kbd-row:last-child { border-bottom: 0; }
    #cy-kbd-help .cy-kbd-label { opacity: .85; font-size: 13px; }
    #cy-kbd-help kbd {
        display: inline-block;
        padding: 2px 7px;
        background: #222;
        border: 1px solid #333;
        border-radius: 5px;
        font: 11px/1 'SF Mono', Menlo, monospace;
        color: #76b900;
        min-width: 18px; text-align: center;
    }
    :root[data-theme="light"] #cy-kbd-help kbd {
        background: #f3f4f6; border-color: #d1d5db; color: #059669;
    }
    #cy-kbd-help .cy-kbd-hint {
        margin-top: 14px; font-size: 11px; opacity: .55; text-align: center;
    }
    #cy-kbd-help .cy-kbd-close {
        position: absolute; top: 14px; right: 18px;
        background: none; border: 0; color: inherit; cursor: pointer;
        font-size: 22px; opacity: .6;
    }
    #cy-kbd-help .cy-kbd-close:hover { opacity: 1; }
</style>

<div id="cy-kbd-help" role="dialog" aria-hidden="true" aria-label="Keyboard shortcuts">
    <div class="cy-kbd-panel" style="position: relative;">
        <button type="button" class="cy-kbd-close" onclick="document.getElementById('cy-kbd-help').classList.remove('is-open')" aria-label="Close">×</button>
        <h3>⌨️ Keyboard shortcuts</h3>
        <div class="cy-kbd-row"><span class="cy-kbd-label">Focus search</span><kbd>/</kbd></div>
        <div class="cy-kbd-row"><span class="cy-kbd-label">Go to Dashboard</span><span><kbd>g</kbd> <kbd>d</kbd></span></div>
        <div class="cy-kbd-row"><span class="cy-kbd-label">Go to Chat</span><span><kbd>g</kbd> <kbd>c</kbd></span></div>
        <div class="cy-kbd-row"><span class="cy-kbd-label">Go to Briefing</span><span><kbd>g</kbd> <kbd>b</kbd></span></div>
        <div class="cy-kbd-row"><span class="cy-kbd-label">Go to Reports</span><span><kbd>g</kbd> <kbd>r</kbd></span></div>
        <div class="cy-kbd-row"><span class="cy-kbd-label">Go to Conversations</span><span><kbd>g</kbd> <kbd>h</kbd></span></div>
        <div class="cy-kbd-row"><span class="cy-kbd-label">Toggle dark / light</span><kbd>t</kbd></div>
        <div class="cy-kbd-row"><span class="cy-kbd-label">Show this help</span><kbd>?</kbd></div>
        <div class="cy-kbd-row"><span class="cy-kbd-label">Close</span><kbd>Esc</kbd></div>
        <div class="cy-kbd-hint">Tip: press <kbd>?</kbd> from any page to see this again</div>
    </div>
</div>

<script>
(function () {
    // Don't double-init if partial is accidentally included twice.
    if (window.__cyKbdInit) return;
    window.__cyKbdInit = true;

    const helpEl = document.getElementById('cy-kbd-help');
    let gPressed = false;
    let gTimeout = null;

    function isTyping(target) {
        if (!target) return false;
        const tag = (target.tagName || '').toLowerCase();
        if (tag === 'input' || tag === 'textarea' || tag === 'select') return true;
        if (target.isContentEditable) return true;
        return false;
    }

    function focusSearch() {
        // Try common search selectors — if none found, fall back to dashboard.
        const selectors = [
            '#agentSearch',          // dashboard search
            'input[type="search"]',
            'input[name="q"]',
            'input[placeholder*="earch"]', // "Search" / "search"
        ];
        for (const sel of selectors) {
            const el = document.querySelector(sel);
            if (el && el.offsetParent !== null) { // visible
                el.focus();
                el.select && el.select();
                return true;
            }
        }
        return false;
    }

    function toggleTheme() {
        const cur = document.documentElement.getAttribute('data-theme') === 'light' ? 'light' : 'dark';
        const next = cur === 'light' ? 'dark' : 'light';
        if (next === 'light') document.documentElement.setAttribute('data-theme', 'light');
        else                  document.documentElement.removeAttribute('data-theme');
        try { localStorage.setItem('cy-theme', next); } catch (e) {}
    }

    function openHelp()  { helpEl && helpEl.classList.add('is-open'); }
    function closeHelp() { helpEl && helpEl.classList.remove('is-open'); }

    // Close help on backdrop click
    helpEl && helpEl.addEventListener('click', (e) => {
        if (e.target === helpEl) closeHelp();
    });

    document.addEventListener('keydown', (e) => {
        // If a modifier is held (Cmd/Ctrl/Alt/Meta), let the browser handle it.
        if (e.metaKey || e.ctrlKey || e.altKey) return;

        // Escape always closes the help overlay.
        if (e.key === 'Escape' && helpEl && helpEl.classList.contains('is-open')) {
            closeHelp();
            return;
        }

        // Don't intercept while typing in forms.
        if (isTyping(e.target)) return;

        // "?" help overlay (Shift+/ on most layouts)
        if (e.key === '?') {
            e.preventDefault();
            openHelp();
            return;
        }

        // "/" focus search (or go to dashboard if no search on this page)
        if (e.key === '/') {
            e.preventDefault();
            if (!focusSearch()) window.location.href = '/dashboard';
            return;
        }

        // Theme toggle
        if (e.key === 't' || e.key === 'T') {
            e.preventDefault();
            toggleTheme();
            return;
        }

        // "g" prefix (vim-style) — wait for next key within 800ms
        if (e.key === 'g' || e.key === 'G') {
            e.preventDefault();
            gPressed = true;
            clearTimeout(gTimeout);
            gTimeout = setTimeout(() => { gPressed = false; }, 800);
            return;
        }

        if (gPressed) {
            gPressed = false;
            clearTimeout(gTimeout);
            const map = {
                d: '/dashboard',
                c: '/chat',
                b: '/briefing',
                r: '/reports',
                h: '/conversations',
            };
            const dest = map[e.key.toLowerCase()];
            if (dest) {
                e.preventDefault();
                window.location.href = dest;
            }
        }
    });
})();
</script>
