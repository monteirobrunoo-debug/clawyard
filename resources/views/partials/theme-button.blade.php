{{--
    Standalone theme toggle button + pre-paint script + base vars.
    Legacy views that hardcode dark colors should also paste a
    [data-theme="light"] override block into their own <style>; this
    partial only provides the scaffolding (var map + button + JS).
    Include once per view, inside the <body> — the inner <script>
    handles the pre-paint timing via document.documentElement writes.
--}}

{{-- Pre-paint: runs inline before anything is rendered, so we never
     flash the wrong theme when reloading a light-mode page. --}}
<script>
(function () {
    try {
        var t = localStorage.getItem('cy-theme');
        if (t === 'light' || t === 'dark') {
            document.documentElement.setAttribute('data-theme', t);
        }
    } catch (e) {}
})();
</script>

<style>
    .cy-theme-btn {
        width: 34px; height: 34px;
        border-radius: 50%;
        background: #1a1a1a;
        border: 1px solid #2a2a2a;
        color: #e5e5e5;
        cursor: pointer;
        font-size: 15px;
        display: inline-flex; align-items: center; justify-content: center;
        transition: all .15s;
        padding: 0;
    }
    .cy-theme-btn:hover { border-color: #76b900; color: #76b900; }
    :root[data-theme="light"] .cy-theme-btn {
        background: #f3f4f6; border-color: #d1d5db; color: #1f2937;
    }
    :root[data-theme="light"] .cy-theme-btn:hover { border-color: #059669; color: #059669; }
</style>

<script>
(function () {
    // Attach behavior after DOM is ready so button exists.
    document.addEventListener('DOMContentLoaded', () => {
        const btn = document.getElementById('cyThemeBtn');
        if (!btn) return;
        function sync() {
            const isLight = document.documentElement.getAttribute('data-theme') === 'light';
            btn.textContent = isLight ? '🌙' : '☀️';
            btn.title = isLight ? 'Switch to dark (t)' : 'Switch to light (t)';
        }
        sync();
        btn.addEventListener('click', () => {
            const cur  = document.documentElement.getAttribute('data-theme') === 'light' ? 'light' : 'dark';
            const next = cur === 'light' ? 'dark' : 'light';
            if (next === 'light') document.documentElement.setAttribute('data-theme', 'light');
            else                  document.documentElement.removeAttribute('data-theme');
            try { localStorage.setItem('cy-theme', next); } catch (e) {}
            sync();
        });
    });
})();
</script>
