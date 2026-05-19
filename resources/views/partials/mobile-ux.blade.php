{{--
    Mobile UX improvements — incluído globalmente em app.blade.php.

    Resolve o problema reportado 2026-05-19:
      "versao mobile nao dá apra escerever quase"

    Três fixes:
      1. Modais → bottom-sheet em viewport <640px (sm:). Antes flutuavam ao
         centro e o teclado virtual escondia o submit.
      2. Textareas com [data-autogrow] crescem conforme o user escreve, em
         vez de obrigar a scroll dentro do input.
      3. Inputs/selects/textareas com font-size <16px viram 16px em mobile,
         para o iOS não dar zoom forçado quando o user toca no campo.

    Não toca em desktop. Anything com class "no-mobile-bottomsheet" é
    excluído (caso algum modal não funcione bem em fullscreen mobile).
--}}
<style>
    @media (max-width: 639px) {
        /* ── Inputs: previne iOS zoom-on-focus ─────────────────────────
           iOS Safari faz zoom-in automático quando um input/textarea/
           select tem font-size <16px e recebe focus. O zoom estraga o
           layout e o user fica preso até dar pinch-out. Forçando 16px
           o zoom não acontece. */
        input[type="text"], input[type="email"], input[type="password"],
        input[type="number"], input[type="tel"], input[type="url"],
        input[type="search"], input[type="date"], input[type="datetime-local"],
        textarea, select {
            font-size: 16px !important;
        }

        /* ── Modais → bottom-sheet ──────────────────────────────────────
           Detecta qualquer container fixed-overlay e converte para slide
           from bottom: anchored ao fundo da viewport, max-height 90vh,
           interior scrollable, submit fica visível mesmo com teclado
           aberto graças ao position:sticky no .modal-actions. */
        .fixed.inset-0[class*="z-50"]:not(.no-mobile-bottomsheet) {
            align-items: flex-end !important;
            padding-top: 0 !important;
            padding-bottom: 0 !important;
            padding-left: 0 !important;
            padding-right: 0 !important;
        }

        .fixed.inset-0[class*="z-50"]:not(.no-mobile-bottomsheet) > div {
            max-width: 100% !important;
            width: 100% !important;
            max-height: 92vh;
            overflow-y: auto;
            border-radius: 1rem 1rem 0 0 !important;
            animation: cy-bottomsheet-up 0.18s ease-out;
        }

        @keyframes cy-bottomsheet-up {
            from { transform: translateY(20%); opacity: 0.6; }
            to   { transform: translateY(0);    opacity: 1;   }
        }

        /* Submit row sticky no fundo da modal — sempre visível mesmo
           com o keyboard a ocupar 50% do écran. */
        .modal-actions, .fixed.inset-0[class*="z-50"]:not(.no-mobile-bottomsheet) form > div:last-child {
            position: sticky;
            bottom: 0;
            background: white;
            padding-top: 0.5rem;
            padding-bottom: env(safe-area-inset-bottom, 0.5rem);
            border-top: 1px solid rgb(229 231 235);
        }

        /* Botões em modais ganham touch target maior. */
        .fixed.inset-0[class*="z-50"]:not(.no-mobile-bottomsheet) button[type="submit"],
        .fixed.inset-0[class*="z-50"]:not(.no-mobile-bottomsheet) button[type="button"] {
            min-height: 44px;
        }
    }

    /* ── Auto-grow textarea base styles ────────────────────────────────
       Aplicado a qualquer <textarea data-autogrow>. O JS abaixo trata
       do resize; este só põe overflow hidden para esconder o scroll
       que apareceria durante o crescimento. */
    textarea[data-autogrow] {
        overflow-y: hidden;
        resize: none;
        transition: height 0.05s linear;
        min-height: 2.5rem;
    }

    /* ── Voice input button (wrapping) ─────────────────────────────────
       O JS abaixo envolve cada textarea[data-voice] num wrapper relativo
       e injecta um botão 🎤 no canto superior-direito. */
    .cy-voice-wrap {
        position: relative;
    }
    .cy-voice-btn {
        position: absolute;
        top: 6px;
        right: 6px;
        z-index: 2;
        width: 32px;
        height: 32px;
        border-radius: 9999px;
        background: white;
        border: 1px solid rgb(209 213 219);
        cursor: pointer;
        font-size: 16px;
        line-height: 1;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        color: rgb(75 85 99);
        transition: all 0.15s ease;
    }
    .cy-voice-btn:hover {
        background: rgb(243 244 246);
        border-color: rgb(99 102 241);
        color: rgb(67 56 202);
    }
    .cy-voice-btn.recording {
        background: rgb(220 38 38);
        border-color: rgb(185 28 28);
        color: white;
        animation: cy-voice-pulse 1s ease-in-out infinite;
    }
    @keyframes cy-voice-pulse {
        0%, 100% { box-shadow: 0 0 0 0 rgba(220, 38, 38, 0.5); }
        50%      { box-shadow: 0 0 0 8px rgba(220, 38, 38, 0); }
    }
    .cy-voice-btn[disabled] {
        opacity: 0.5;
        cursor: not-allowed;
    }
</style>

<script>
(function () {
    'use strict';

    // ── Auto-grow textareas ────────────────────────────────────────────
    // Encontra todos <textarea data-autogrow> e faz height = scrollHeight
    // a cada input. Funciona com textareas adicionadas dinamicamente via
    // MutationObserver.
    const grow = (ta) => {
        if (!ta || ta.tagName !== 'TEXTAREA') return;
        ta.style.height = 'auto';
        // +2px buffer para evitar o "1-px shake" em alguns browsers
        ta.style.height = (ta.scrollHeight + 2) + 'px';
    };

    const wireUp = (ta) => {
        if (ta._cyAutogrowWired) return;
        ta._cyAutogrowWired = true;
        // Init: definir altura logo (se já tem conteúdo)
        requestAnimationFrame(() => grow(ta));
        ta.addEventListener('input', () => grow(ta));
        // Quando o user cola texto, focus, ou o form é reset
        ta.addEventListener('focus', () => grow(ta));
    };

    const scan = (root) => {
        (root || document).querySelectorAll('textarea[data-autogrow]').forEach(wireUp);
    };

    // Init em DOMContentLoaded + scan ao mudar o DOM (modais que abrem,
    // etc.)
    document.addEventListener('DOMContentLoaded', () => scan());
    if (document.readyState !== 'loading') scan();

    const mo = new MutationObserver((muts) => {
        for (const m of muts) {
            for (const n of m.addedNodes) {
                if (n.nodeType === 1) {
                    if (n.matches && n.matches('textarea[data-autogrow]')) wireUp(n);
                    scan(n);
                }
            }
        }
    });
    mo.observe(document.body, { childList: true, subtree: true });

    // ── Voice input (Web Speech API) ───────────────────────────────────
    // Para cada <textarea data-voice>, envolve no .cy-voice-wrap e
    // injecta botão 🎤. Click → SpeechRecognition arranca em pt-PT,
    // append do transcript ao value. Click de novo → para.
    const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
    const voiceSupported = typeof SpeechRecognition === 'function';

    const wireVoice = (ta) => {
        if (!ta || ta.tagName !== 'TEXTAREA') return;
        if (ta._cyVoiceWired) return;
        ta._cyVoiceWired = true;

        // Garante o wrapper relativo (preserva qualquer label/classes externas).
        let wrap = ta.parentElement;
        if (!wrap || !wrap.classList.contains('cy-voice-wrap')) {
            wrap = document.createElement('div');
            wrap.className = 'cy-voice-wrap';
            ta.parentNode.insertBefore(wrap, ta);
            wrap.appendChild(ta);
        }

        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'cy-voice-btn';
        btn.title = voiceSupported
            ? 'Ditar texto (pt-PT) · clica para começar/parar'
            : 'Voice input não suportado neste browser';
        btn.textContent = '🎤';
        if (!voiceSupported) btn.disabled = true;
        wrap.appendChild(btn);

        // Padding-right na textarea para o texto não ficar por baixo do botão.
        const cs = window.getComputedStyle(ta);
        const cur = parseInt(cs.paddingRight, 10) || 0;
        if (cur < 44) ta.style.paddingRight = '44px';

        if (!voiceSupported) return;

        let rec = null;
        let baseValue = '';

        const stop = () => {
            try { rec && rec.stop(); } catch (_) { /* ignore */ }
            btn.classList.remove('recording');
        };

        btn.addEventListener('click', () => {
            if (btn.classList.contains('recording')) {
                stop();
                return;
            }

            try {
                rec = new SpeechRecognition();
            } catch (e) {
                console.warn('SpeechRecognition init failed', e);
                return;
            }

            rec.lang = 'pt-PT';
            rec.interimResults = true;
            rec.continuous = true;

            baseValue = ta.value;
            const prefix = baseValue && !/\s$/.test(baseValue) ? baseValue + ' ' : baseValue;

            rec.onresult = (e) => {
                let final = '';
                let interim = '';
                for (let i = e.resultIndex; i < e.results.length; i++) {
                    const r = e.results[i];
                    if (r.isFinal) final += r[0].transcript;
                    else interim += r[0].transcript;
                }
                ta.value = prefix + final + interim;
                ta.dispatchEvent(new Event('input', { bubbles: true }));
            };
            rec.onerror = (e) => {
                console.warn('SpeechRecognition error', e.error);
                stop();
            };
            rec.onend = () => { btn.classList.remove('recording'); };

            try {
                rec.start();
                btn.classList.add('recording');
                btn.title = 'A gravar… clica para parar';
            } catch (e) {
                console.warn('SpeechRecognition start failed', e);
            }
        });
    };

    const scanVoice = (root) => {
        (root || document).querySelectorAll('textarea[data-voice]').forEach(wireVoice);
    };
    document.addEventListener('DOMContentLoaded', () => scanVoice());
    if (document.readyState !== 'loading') scanVoice();
    new MutationObserver((muts) => {
        for (const m of muts) for (const n of m.addedNodes) {
            if (n.nodeType === 1) {
                if (n.matches && n.matches('textarea[data-voice]')) wireVoice(n);
                scanVoice(n);
            }
        }
    }).observe(document.body, { childList: true, subtree: true });

    // ── Mobile: scroll input focado para a viewport ────────────────────
    // Em iOS quando o teclado abre, o navegador às vezes não faz scroll
    // do input focado para acima do teclado. Forçamos manualmente.
    if (window.matchMedia('(max-width: 639px)').matches) {
        document.addEventListener('focusin', (e) => {
            const t = e.target;
            if (!t || !t.matches) return;
            if (t.matches('input, textarea, select')) {
                // delay pequeno para o teclado já estar a abrir antes do scroll
                setTimeout(() => {
                    try {
                        t.scrollIntoView({ block: 'center', behavior: 'smooth' });
                    } catch (_) { /* old browsers */ }
                }, 280);
            }
        });
    }
})();
</script>
