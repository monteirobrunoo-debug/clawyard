{{--
    Smooth page-to-page navigation via the View Transitions API.
    Progressive enhancement — browsers without support (Safari < 18,
    Firefox) fall back to instant navigation. Chrome/Edge/Safari 18+
    get a 220ms cross-fade with the header morphing in place.

    Strategy:
      • Intercept clicks on internal <a> tags within the same origin
        (skip external, target=_blank, mailto:, tel:, anchor-only,
        and ⌘+click / Ctrl+click for new-tab).
      • Wrap the navigation in document.startViewTransition.
      • The header has view-transition-name: cy-header so it shares
        a single visual identity across pages instead of flashing.

    No frameworks required — ~25 lines of vanilla JS.
--}}
<style>
    /* Safari/old browsers: these rules are simply ignored. */
    @supports (view-transition-name: cy-header) {
        ::view-transition-old(root),
        ::view-transition-new(root) {
            animation-duration: 220ms;
            animation-timing-function: cubic-bezier(0.4, 0, 0.2, 1);
        }
        /* Header stays anchored — its name is set inline below on the
           common header element so the browser tweens between pages. */
        ::view-transition-old(cy-header),
        ::view-transition-new(cy-header) {
            animation-duration: 220ms;
        }
    }

    /* Apply the named transition to common chrome elements. Pages
       that don't have these elements simply skip the rule. */
    .header                { view-transition-name: cy-header; }
    nav.bg-white           { view-transition-name: cy-header; }
    nav[x-data]            { view-transition-name: cy-header; }
</style>

<script>
(function () {
    if (!('startViewTransition' in document)) return;

    document.addEventListener('click', (e) => {
        // Only intercept primary-button, no-modifier clicks.
        if (e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;

        const a = e.target.closest('a');
        if (!a) return;
        // Skip external + new-tab + non-http(s) + anchor-only links.
        if (a.target && a.target !== '' && a.target !== '_self') return;
        const href = a.getAttribute('href');
        if (!href) return;
        if (href.startsWith('#')) return;
        if (href.startsWith('mailto:') || href.startsWith('tel:') || href.startsWith('javascript:')) return;
        try {
            const u = new URL(href, location.href);
            if (u.origin !== location.origin) return;
            // Skip downloads — they don't navigate.
            if (a.hasAttribute('download')) return;
            // Skip same-page anchor links (foo.html → foo.html).
            if (u.pathname === location.pathname && u.search === location.search && u.hash) return;
        } catch (err) { return; }

        e.preventDefault();
        document.startViewTransition(() => {
            window.location.href = a.href;
        });
    });
})();
</script>
