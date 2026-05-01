{{--
    Universal drag-drop overlay.
    When the user drags a file anywhere on the page, a glass overlay
    appears explaining what'll happen based on the current URL:

      • /tenders/*  → "Anexar ao concurso #X"  (TODO server-side)
      • /suppliers/* → "Anexar ao fornecedor X"  (TODO server-side)
      • everything else → "Enviar para hp-history" (uses /hp-history/upload UI)

    For v1 the action is uniform: show a toast confirming the file
    was received and redirect to /hp-history/upload pre-staging the
    file via sessionStorage so the manager doesn't have to drag again.

    Files: PDF / TXT / MD only (mirrors the hp-history endpoint cap).
--}}
<style>
    .cy-drop-overlay {
        position: fixed; inset: 0;
        background: rgba(10,10,10,0.78);
        backdrop-filter: blur(10px) saturate(160%);
        -webkit-backdrop-filter: blur(10px) saturate(160%);
        z-index: 9996; display: none;
        align-items: center; justify-content: center;
        animation: cy-drop-in 0.2s ease-out;
    }
    .cy-drop-overlay.is-active { display: flex; }
    @keyframes cy-drop-in { from { opacity: 0; } to { opacity: 1; } }

    .cy-drop-card {
        width: 92vw; max-width: 540px; padding: 32px 28px;
        background: rgba(17,17,17,0.95);
        border: 2px dashed color-mix(in srgb, #76b900 60%, transparent);
        border-radius: 18px; text-align: center; color: #e5e5e5;
        animation: cy-drop-pulse 1.4s ease-in-out infinite;
    }
    :root[data-theme="light"] .cy-drop-card { background: rgba(255,255,255,0.96); color: #1f2937; }
    @keyframes cy-drop-pulse {
        0%, 100% { box-shadow: 0 0 40px color-mix(in srgb, #76b900 24%, transparent); }
        50%      { box-shadow: 0 0 70px color-mix(in srgb, #76b900 38%, transparent); }
    }
    .cy-drop-icon { font-size: 48px; line-height: 1; margin-bottom: 14px; animation: cy-drop-float 2.5s ease-in-out infinite; }
    @keyframes cy-drop-float {
        0%, 100% { transform: translateY(0); }
        50%      { transform: translateY(-6px); }
    }
    .cy-drop-title { font-size: 20px; font-weight: 800; margin-bottom: 6px; }
    .cy-drop-sub   { font-size: 13px; color: #aaa; margin-bottom: 14px; }
    :root[data-theme="light"] .cy-drop-sub { color: #6b7280; }
    .cy-drop-context-pill {
        display: inline-block; padding: 4px 12px; border-radius: 999px;
        background: color-mix(in srgb, #76b900 15%, transparent);
        color: #76b900; font-size: 11px; font-weight: 700;
        text-transform: uppercase; letter-spacing: 1px; margin-bottom: 18px;
    }
    .cy-drop-types { font-size: 11px; color: #888; margin-top: 8px; }
</style>

<div class="cy-drop-overlay" id="cyDropOverlay" aria-hidden="true">
    <div class="cy-drop-card">
        <div class="cy-drop-icon">📎</div>
        <div class="cy-drop-context-pill" id="cyDropContext">a determinar contexto…</div>
        <div class="cy-drop-title">Larga aqui o ficheiro</div>
        <div class="cy-drop-sub" id="cyDropSub">PDF, TXT ou MD &middot; até 16 MB</div>
        <div class="cy-drop-types">A drag entrou — solta para fazer upload</div>
    </div>
</div>

<script>
(function () {
    const overlay = document.getElementById('cyDropOverlay');
    const ctxPill = document.getElementById('cyDropContext');
    const subEl   = document.getElementById('cyDropSub');
    if (!overlay) return;

    // Resolve context from URL — purely client-side. The server
    // upload itself goes through /hp-history/upload for v1; future
    // versions can branch by URL prefix to per-resource attach.
    function resolveContext() {
        const p = location.pathname;
        if (p.match(/^\/tenders\/(\d+)/)) {
            const id = p.match(/^\/tenders\/(\d+)/)[1];
            return { label: 'Concurso #' + id, sub: 'Vai para hp-history com tag deste concurso' };
        }
        if (p.match(/^\/suppliers\/(\d+)/)) {
            return { label: 'Fornecedor', sub: 'Vai para hp-history com tag deste fornecedor' };
        }
        if (p.match(/^\/leads\/(\d+)/)) {
            return { label: 'Lead', sub: 'Vai para hp-history' };
        }
        return { label: 'hp-history', sub: 'PDF de proposta / contrato / RFQ histórico' };
    }

    let dragDepth = 0;
    let lastFiles = null;

    function isFileDrag(e) {
        // Detect external file drags (not text selection or DOM elements).
        const types = Array.from(e.dataTransfer?.types || []);
        return types.includes('Files');
    }

    function activate() {
        const ctx = resolveContext();
        ctxPill.textContent = ctx.label;
        subEl.textContent   = ctx.sub;
        overlay.classList.add('is-active');
    }
    function deactivate() {
        overlay.classList.remove('is-active');
        dragDepth = 0;
    }

    document.addEventListener('dragenter', (e) => {
        if (!isFileDrag(e)) return;
        e.preventDefault();
        dragDepth++;
        if (dragDepth === 1) activate();
    });
    document.addEventListener('dragover', (e) => {
        if (!isFileDrag(e)) return;
        e.preventDefault();
        e.dataTransfer.dropEffect = 'copy';
    });
    document.addEventListener('dragleave', (e) => {
        if (!isFileDrag(e)) return;
        dragDepth--;
        if (dragDepth <= 0) deactivate();
    });
    document.addEventListener('drop', (e) => {
        if (!isFileDrag(e)) return;
        e.preventDefault();
        deactivate();

        const files = Array.from(e.dataTransfer?.files || [])
            .filter(f => /\.(pdf|txt|md)$/i.test(f.name));
        if (files.length === 0) {
            window.cyToast && window.cyToast({
                title: 'Tipo de ficheiro não suportado',
                body: 'Aceita-se PDF, TXT ou MD.',
                tone: 'warn',
            });
            return;
        }

        // Stash file metadata so /hp-history/upload can show a friendly
        // pre-pop message ("3 ficheiros prontos a enviar"). Browsers
        // can't transfer the actual File object across navigation, so
        // the user re-confirms via the existing UI — but the visual
        // continuity is preserved.
        try {
            const meta = files.map(f => ({ name: f.name, size: f.size }));
            sessionStorage.setItem('cy-drop-files', JSON.stringify(meta));
        } catch (e) { /* ignore */ }

        const ctx = resolveContext();
        window.cyToast && window.cyToast({
            title: '📎 Ficheiro(s) recebidos',
            body:  `${files.length} para ${ctx.label} → vai para hp-history para confirmar`,
            tone:  'info',
            duration: 3500,
        });
        // Redirect to the central upload UI after a short pause so the
        // user sees the toast.
        setTimeout(() => { location.href = '/hp-history/upload'; }, 700);
    });
})();
</script>
