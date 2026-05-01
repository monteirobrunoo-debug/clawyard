{{--
    Keyboard shortcuts cheat sheet.
    Press '?' anywhere (when not typing in an input/textarea) to open.
    Static content rendered server-side; modal toggles via tiny vanilla JS.

    Goal: discoverable shortcuts. Linear/Github use the same pattern —
    a modal listing every key binding so power users learn quickly
    without docs.
--}}
<style>
    .cy-cheat-overlay {
        position: fixed; inset: 0; background: rgba(0,0,0,0.55);
        backdrop-filter: blur(6px); -webkit-backdrop-filter: blur(6px);
        z-index: 9997; display: none;
    }
    .cy-cheat-overlay.open { display: block; }
    .cy-cheat-modal {
        position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%) scale(0.96);
        width: 92vw; max-width: 720px; max-height: 80vh; overflow-y: auto;
        background: rgba(17,17,17,0.94);
        border: 1px solid color-mix(in srgb, #76b900 28%, #2a2a2a);
        border-radius: 16px; z-index: 9998; display: none;
        box-shadow: 0 30px 100px rgba(0,0,0,0.6),
                    0 0 0 1px color-mix(in srgb, #76b900 12%, transparent);
        backdrop-filter: blur(20px) saturate(160%);
        -webkit-backdrop-filter: blur(20px) saturate(160%);
        transition: transform 0.18s cubic-bezier(0.4, 0, 0.2, 1), opacity 0.18s;
        opacity: 0;
    }
    :root[data-theme="light"] .cy-cheat-modal { background: rgba(255,255,255,0.97); }
    .cy-cheat-modal.open { display: block; opacity: 1; transform: translate(-50%, -50%) scale(1); }

    .cy-cheat-head {
        display: flex; align-items: center; gap: 10px;
        padding: 16px 22px; border-bottom: 1px solid rgba(255,255,255,0.08);
    }
    :root[data-theme="light"] .cy-cheat-head { border-bottom-color: rgba(0,0,0,0.06); }
    .cy-cheat-title { font-weight: 800; font-size: 16px; color: #e5e5e5; flex: 1; }
    :root[data-theme="light"] .cy-cheat-title { color: #1f2937; }
    .cy-cheat-emoji { font-size: 22px; }
    .cy-cheat-close {
        background: none; border: 1px solid rgba(255,255,255,0.15); color: #aaa;
        padding: 4px 10px; border-radius: 6px; cursor: pointer; font-size: 12px;
    }
    .cy-cheat-close:hover { color: #e5e5e5; border-color: #76b900; }
    :root[data-theme="light"] .cy-cheat-close { color: #6b7280; border-color: #e5e7eb; }

    .cy-cheat-body { padding: 14px 22px 20px 22px; display: grid;
        grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 22px; }
    .cy-cheat-section h3 {
        font-size: 11px; font-weight: 800; color: #76b900;
        text-transform: uppercase; letter-spacing: 1.5px;
        margin: 0 0 8px 0;
    }
    .cy-cheat-row {
        display: flex; align-items: center; justify-content: space-between;
        padding: 6px 0; font-size: 13px; color: #ccc;
        border-bottom: 1px dashed rgba(255,255,255,0.05);
    }
    :root[data-theme="light"] .cy-cheat-row { color: #374151; border-bottom-color: rgba(0,0,0,0.04); }
    .cy-cheat-row:last-child { border-bottom: none; }
    .cy-cheat-keys { display: inline-flex; gap: 3px; }
    .cy-cheat-key {
        padding: 2px 8px; border-radius: 5px;
        background: rgba(255,255,255,0.08); border: 1px solid rgba(255,255,255,0.14);
        font-family: ui-monospace, SF Mono, Menlo, monospace; font-size: 11px; font-weight: 700;
        color: #e5e5e5;
    }
    :root[data-theme="light"] .cy-cheat-key {
        background: #f3f4f6; border-color: #e5e7eb; color: #1f2937;
    }

    .cy-cheat-footer {
        padding: 10px 22px; border-top: 1px solid rgba(255,255,255,0.06);
        font-size: 11px; color: #888; text-align: center;
    }
    :root[data-theme="light"] .cy-cheat-footer { border-top-color: rgba(0,0,0,0.06); }
</style>

<div class="cy-cheat-overlay" id="cyCheatOverlay"></div>
<div class="cy-cheat-modal" id="cyCheatModal" role="dialog" aria-label="Keyboard shortcuts">
    <div class="cy-cheat-head">
        <span class="cy-cheat-emoji">⌨️</span>
        <span class="cy-cheat-title">Atalhos do teclado</span>
        <button class="cy-cheat-close" type="button" id="cyCheatClose">esc</button>
    </div>

    <div class="cy-cheat-body">
        <div class="cy-cheat-section">
            <h3>Geral</h3>
            <div class="cy-cheat-row">
                <span>Abrir command palette</span>
                <span class="cy-cheat-keys"><kbd class="cy-cheat-key">⌘</kbd><kbd class="cy-cheat-key">K</kbd></span>
            </div>
            <div class="cy-cheat-row">
                <span>Mostrar este painel</span>
                <span class="cy-cheat-keys"><kbd class="cy-cheat-key">?</kbd></span>
            </div>
            <div class="cy-cheat-row">
                <span>Fechar overlay actual</span>
                <span class="cy-cheat-keys"><kbd class="cy-cheat-key">esc</kbd></span>
            </div>
            <div class="cy-cheat-row">
                <span>Toggle tema (dark/light)</span>
                <span class="cy-cheat-keys"><kbd class="cy-cheat-key">t</kbd></span>
            </div>
        </div>

        <div class="cy-cheat-section">
            <h3>Chat com agentes</h3>
            <div class="cy-cheat-row">
                <span>Enviar mensagem</span>
                <span class="cy-cheat-keys"><kbd class="cy-cheat-key">enter</kbd></span>
            </div>
            <div class="cy-cheat-row">
                <span>Nova linha</span>
                <span class="cy-cheat-keys"><kbd class="cy-cheat-key">⇧</kbd><kbd class="cy-cheat-key">enter</kbd></span>
            </div>
            <div class="cy-cheat-row">
                <span>Alternar microfone (voz)</span>
                <span class="cy-cheat-keys"><kbd class="cy-cheat-key">click</kbd> 🎤</span>
            </div>
            <div class="cy-cheat-row">
                <span>Idioma do microfone PT/EN</span>
                <span class="cy-cheat-keys"><kbd class="cy-cheat-key">duplo-click</kbd> 🎤</span>
            </div>
        </div>

        <div class="cy-cheat-section">
            <h3>Command palette</h3>
            <div class="cy-cheat-row">
                <span>Navegar resultados</span>
                <span class="cy-cheat-keys"><kbd class="cy-cheat-key">↑</kbd><kbd class="cy-cheat-key">↓</kbd></span>
            </div>
            <div class="cy-cheat-row">
                <span>Abrir resultado seleccionado</span>
                <span class="cy-cheat-keys"><kbd class="cy-cheat-key">enter</kbd></span>
            </div>
            <div class="cy-cheat-row">
                <span>Pesquisar concursos / fornecedores / leads</span>
                <span class="cy-cheat-keys"><span style="font-size:11px;color:#888">basta começar a escrever</span></span>
            </div>
        </div>

        <div class="cy-cheat-section">
            <h3>Listas + tabelas</h3>
            <div class="cy-cheat-row">
                <span>Pesquisar (lupa)</span>
                <span class="cy-cheat-keys"><span style="font-size:11px;color:#888">campo "Pesquisar…" no topo de cada lista</span></span>
            </div>
            <div class="cy-cheat-row">
                <span>Mudar ordem de coluna</span>
                <span class="cy-cheat-keys"><kbd class="cy-cheat-key">click</kbd> no nome da coluna</span>
            </div>
            <div class="cy-cheat-row">
                <span>Marcar fornecedor como favorito</span>
                <span class="cy-cheat-keys"><kbd class="cy-cheat-key">click</kbd> ⭐ no agent card</span>
            </div>
        </div>
    </div>

    <div class="cy-cheat-footer">
        Tens uma sugestão de atalho? Diz-me no chat — está sempre a evoluir.
    </div>
</div>

<script>
(function () {
    const overlay = document.getElementById('cyCheatOverlay');
    const modal   = document.getElementById('cyCheatModal');
    const close   = document.getElementById('cyCheatClose');
    if (!overlay || !modal) return;

    function open()  { overlay.classList.add('open');    modal.classList.add('open'); }
    function shut()  { overlay.classList.remove('open'); modal.classList.remove('open'); }

    overlay.addEventListener('click', shut);
    close?.addEventListener('click', shut);

    document.addEventListener('keydown', (e) => {
        // '?' (Shift+/) opens — but only when the user isn't typing
        // in an input/textarea/contenteditable.
        const tag = (e.target?.tagName || '').toLowerCase();
        const isInput = tag === 'input' || tag === 'textarea' || e.target?.isContentEditable;
        if (e.key === '?' && !e.metaKey && !e.ctrlKey && !isInput) {
            e.preventDefault(); open();
        }
        if (e.key === 'Escape' && modal.classList.contains('open')) {
            shut();
        }
    });
})();
</script>
