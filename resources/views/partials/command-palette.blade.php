{{-- ─────────────────────────────────────────────────────────
     Command palette (futurista upgrade #5)
     Open with Cmd+K (macOS) or Ctrl+K (Windows/Linux). Or click on
     any element with class "cy-cmdk-open".

     Searches across:
       • Static actions (Pesquisar concursos, Falar com Marta CRM, …)
       • Tenders (server-side via /api/cmdk-search?q=…)
       • Suppliers
       • Leads
       • Reports
       • Agents (catalogue)

     Architecture:
       — Frontend renders a modal sheet with backdrop blur
       — Inputs an Anglais query → debounced 180ms → AJAX
       — Server returns top 8 results across categories with type+url
     Use anywhere: @include('partials.command-palette')
───────────────────────────────────────────────────────── --}}
<style>
    .cy-cmdk-overlay {
        position: fixed; inset: 0; background: rgba(0,0,0,0.55);
        backdrop-filter: blur(6px); -webkit-backdrop-filter: blur(6px);
        z-index: 9998; display: none;
        animation: cy-cmdk-fade 0.15s ease-out;
    }
    .cy-cmdk-overlay.open { display: block; }
    @keyframes cy-cmdk-fade { from { opacity: 0; } to { opacity: 1; } }

    .cy-cmdk-modal {
        position: fixed; top: 14vh; left: 50%; transform: translateX(-50%);
        width: 92vw; max-width: 640px;
        background: rgba(17,17,17,0.92);
        border: 1px solid color-mix(in srgb, #76b900 30%, #2a2a2a);
        border-radius: 14px; z-index: 9999; display: none;
        box-shadow: 0 24px 80px rgba(0,0,0,0.55),
                    0 0 0 1px color-mix(in srgb, #76b900 18%, transparent);
        backdrop-filter: blur(20px) saturate(160%);
        -webkit-backdrop-filter: blur(20px) saturate(160%);
        overflow: hidden;
        animation: cy-cmdk-rise 0.18s cubic-bezier(0.4,0,0.2,1);
    }
    .cy-cmdk-modal.open { display: block; }
    @keyframes cy-cmdk-rise {
        from { opacity: 0; transform: translate(-50%, 8px); }
        to   { opacity: 1; transform: translate(-50%, 0); }
    }
    :root[data-theme="light"] .cy-cmdk-modal { background: rgba(255,255,255,0.96); border-color: rgba(118,185,0,0.4); }

    .cy-cmdk-input-wrap {
        display: flex; align-items: center; gap: 10px;
        padding: 14px 18px;
        border-bottom: 1px solid rgba(255,255,255,0.06);
    }
    :root[data-theme="light"] .cy-cmdk-input-wrap { border-bottom-color: rgba(0,0,0,0.06); }
    .cy-cmdk-icon { font-size: 16px; color: #76b900; }
    .cy-cmdk-input {
        flex: 1; background: transparent; border: none; outline: none;
        font-size: 15px; color: #e5e5e5; font-family: inherit;
    }
    :root[data-theme="light"] .cy-cmdk-input { color: #1f2937; }
    .cy-cmdk-input::placeholder { color: #888; }
    .cy-cmdk-kbd {
        font-size: 10px; padding: 2px 6px; border-radius: 4px;
        background: rgba(255,255,255,0.06); color: #888;
        border: 1px solid rgba(255,255,255,0.1);
    }
    :root[data-theme="light"] .cy-cmdk-kbd { background: #f3f4f6; color: #6b7280; border-color: #e5e7eb; }

    .cy-cmdk-list {
        max-height: 420px; overflow-y: auto; padding: 6px 0;
    }
    .cy-cmdk-section { padding: 6px 18px 4px; font-size: 10px;
        color: #888; text-transform: uppercase; letter-spacing: 1px; font-weight: 700; }
    .cy-cmdk-row {
        display: flex; align-items: center; gap: 10px;
        padding: 9px 18px; cursor: pointer; transition: background 0.1s;
        text-decoration: none; color: #e5e5e5; font-size: 13px;
    }
    .cy-cmdk-row:hover, .cy-cmdk-row.is-active {
        background: color-mix(in srgb, #76b900 14%, transparent);
        color: #fff;
    }
    :root[data-theme="light"] .cy-cmdk-row { color: #1f2937; }
    :root[data-theme="light"] .cy-cmdk-row:hover, :root[data-theme="light"] .cy-cmdk-row.is-active { color: #0f172a; }
    .cy-cmdk-row-icon { font-size: 18px; flex-shrink: 0; width: 24px; text-align: center; }
    .cy-cmdk-row-body { flex: 1; min-width: 0; }
    .cy-cmdk-row-title { font-weight: 600; }
    .cy-cmdk-row-sub { font-size: 11px; color: #888; margin-top: 2px;
        white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .cy-cmdk-row-type { font-size: 10px; padding: 2px 7px; border-radius: 4px;
        background: rgba(118,185,0,0.18); color: #76b900; flex-shrink: 0;
        text-transform: uppercase; font-weight: 700; }
    .cy-cmdk-empty { padding: 32px 18px; text-align: center; color: #888; font-size: 13px; }
    .cy-cmdk-footer {
        display: flex; gap: 16px; padding: 8px 18px;
        border-top: 1px solid rgba(255,255,255,0.06);
        font-size: 10px; color: #888;
    }
    :root[data-theme="light"] .cy-cmdk-footer { border-top-color: rgba(0,0,0,0.06); }
    .cy-cmdk-footer-key { padding: 1px 6px; border-radius: 3px;
        background: rgba(255,255,255,0.07); margin-right: 4px;
        border: 1px solid rgba(255,255,255,0.1); }
    :root[data-theme="light"] .cy-cmdk-footer-key { background: #f3f4f6; border-color: #e5e7eb; }
</style>

<div class="cy-cmdk-overlay" id="cyCmdkOverlay"></div>
<div class="cy-cmdk-modal" id="cyCmdkModal" role="dialog" aria-label="Command palette">
    <div class="cy-cmdk-input-wrap">
        <span class="cy-cmdk-icon">⌘</span>
        <input type="text" id="cyCmdkInput" class="cy-cmdk-input" placeholder="Pesquisar concursos, fornecedores, leads, agentes…" autocomplete="off">
        <span class="cy-cmdk-kbd">esc</span>
    </div>
    <div class="cy-cmdk-list" id="cyCmdkList"></div>
    <div class="cy-cmdk-footer">
        <span><span class="cy-cmdk-footer-key">↑↓</span>navegar</span>
        <span><span class="cy-cmdk-footer-key">↵</span>abrir</span>
        <span><span class="cy-cmdk-footer-key">esc</span>fechar</span>
        <span style="margin-left:auto">⌘K para abrir / fechar</span>
    </div>
</div>

<script>
(function () {
    const overlay = document.getElementById('cyCmdkOverlay');
    const modal   = document.getElementById('cyCmdkModal');
    const input   = document.getElementById('cyCmdkInput');
    const list    = document.getElementById('cyCmdkList');
    if (!overlay || !modal || !input || !list) return;

    const STATIC_ACTIONS = [
        { type: 'action', icon: '🏠', title: 'Dashboard',         sub: 'Voltar à página inicial', url: '/dashboard' },
        { type: 'action', icon: '📋', title: 'Concursos',         sub: 'Ver tabela de concursos',  url: '/tenders' },
        { type: 'action', icon: '🏭', title: 'Fornecedores',      sub: 'Directório H&P',           url: '/suppliers' },
        { type: 'action', icon: '⚡', title: 'Leads',             sub: 'Pipeline de oportunidades',url: '/leads' },
        { type: 'action', icon: '📁', title: 'Relatórios',        sub: 'Histórico de outputs',     url: '/reports' },
        { type: 'action', icon: '🔗', title: 'PSI Intel Bus',     sub: 'Ver conhecimento partilhado', url: '/intel' },
        { type: 'action', icon: '📊', title: 'Briefing executivo', sub: 'Falar com Renato',         url: '/chat?agent=briefing' },
        { type: 'action', icon: '💼', title: 'Falar com Marco Sales', sub: 'Vendas / RFQ',          url: '/chat?agent=sales' },
        { type: 'action', icon: '✉', title: 'Falar com Daniel Email', sub: 'Drafts maritime',     url: '/chat?agent=email' },
        { type: 'action', icon: '🤖', title: 'Falar com Marta CRM', sub: 'Criar oportunidades SAP', url: '/chat?agent=crm' },
        { type: 'action', icon: '🔬', title: 'Falar com Quantum',  sub: 'I&D científico',          url: '/chat?agent=quantum' },
        { type: 'action', icon: '🛡️', title: 'Falar com Cor. Rodrigues', sub: 'Defesa militar',   url: '/chat?agent=mildef' },
    ];

    let active = 0;
    let rows = [];

    function open() {
        overlay.classList.add('open');
        modal.classList.add('open');
        input.value = '';
        active = 0;
        renderResults('');
        setTimeout(() => input.focus(), 30);
        document.body.style.overflow = 'hidden';
    }
    function close() {
        overlay.classList.remove('open');
        modal.classList.remove('open');
        document.body.style.overflow = '';
    }

    overlay.addEventListener('click', close);

    // Cmd+K / Ctrl+K to open. Esc to close.
    document.addEventListener('keydown', (e) => {
        if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') {
            e.preventDefault();
            modal.classList.contains('open') ? close() : open();
            return;
        }
        if (e.key === 'Escape' && modal.classList.contains('open')) {
            close(); return;
        }
        if (modal.classList.contains('open')) {
            if (e.key === 'ArrowDown') { e.preventDefault(); moveActive(1); }
            if (e.key === 'ArrowUp')   { e.preventDefault(); moveActive(-1); }
            if (e.key === 'Enter')     { e.preventDefault(); pickActive(); }
        }
    });
    // Bind any element with class cy-cmdk-open as a trigger.
    document.querySelectorAll('.cy-cmdk-open').forEach(el => {
        el.addEventListener('click', (e) => { e.preventDefault(); open(); });
    });

    function moveActive(d) {
        if (rows.length === 0) return;
        active = (active + d + rows.length) % rows.length;
        const items = list.querySelectorAll('.cy-cmdk-row');
        items.forEach((r, i) => r.classList.toggle('is-active', i === active));
        items[active]?.scrollIntoView({ block: 'nearest' });
    }
    function pickActive() {
        if (rows[active]) window.location.href = rows[active].url;
    }

    let debounceT = null;
    input.addEventListener('input', () => {
        clearTimeout(debounceT);
        const q = input.value.trim();
        debounceT = setTimeout(() => renderResults(q), 180);
    });

    function escH(s) { return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

    function renderRows(groups) {
        rows = [];
        let html = '';
        for (const g of groups) {
            if (!g.items.length) continue;
            html += `<div class="cy-cmdk-section">${escH(g.label)}</div>`;
            for (const it of g.items) {
                rows.push(it);
                html += `
                <a class="cy-cmdk-row" href="${escH(it.url || '#')}">
                    <span class="cy-cmdk-row-icon">${escH(it.icon || '·')}</span>
                    <div class="cy-cmdk-row-body">
                        <div class="cy-cmdk-row-title">${escH(it.title)}</div>
                        ${it.sub ? `<div class="cy-cmdk-row-sub">${escH(it.sub)}</div>` : ''}
                    </div>
                    ${it.type ? `<span class="cy-cmdk-row-type">${escH(it.type)}</span>` : ''}
                </a>`;
            }
        }
        list.innerHTML = html || '<div class="cy-cmdk-empty">Sem resultados.</div>';
        active = 0;
        const items = list.querySelectorAll('.cy-cmdk-row');
        items.forEach((r, i) => r.classList.toggle('is-active', i === 0));
    }

    function localFilter(q) {
        if (!q) return STATIC_ACTIONS.slice(0, 8);
        const lq = q.toLowerCase();
        return STATIC_ACTIONS.filter(a =>
            a.title.toLowerCase().includes(lq) ||
            (a.sub && a.sub.toLowerCase().includes(lq))
        );
    }

    async function renderResults(q) {
        const local = localFilter(q);
        if (!q || q.length < 2) {
            renderRows([{ label: 'Atalhos', items: local }]);
            return;
        }

        // Show local matches immediately, then enrich with server results.
        renderRows([{ label: 'Atalhos', items: local }]);

        try {
            const csrf = document.querySelector('meta[name=csrf-token]')?.content;
            const r = await fetch('/api/cmdk-search?q=' + encodeURIComponent(q),
                { headers: { 'X-CSRF-TOKEN': csrf || '', 'Accept': 'application/json' }, credentials: 'same-origin' });
            if (!r.ok) return;
            const data = await r.json();
            const groups = [];
            if (local.length)               groups.push({ label: 'Atalhos',     items: local });
            if (data.tenders?.length)       groups.push({ label: 'Concursos',   items: data.tenders });
            if (data.suppliers?.length)     groups.push({ label: 'Fornecedores',items: data.suppliers });
            if (data.leads?.length)         groups.push({ label: 'Leads',       items: data.leads });
            if (data.reports?.length)       groups.push({ label: 'Relatórios',  items: data.reports });
            renderRows(groups);
        } catch (e) { /* silent */ }
    }
})();
</script>
