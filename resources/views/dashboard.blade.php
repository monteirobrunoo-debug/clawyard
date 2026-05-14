<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ClawYard — Agents</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;700;800&display=swap" rel="stylesheet">

    {{-- Apply saved theme BEFORE first paint to avoid FOUC (flash of wrong theme) --}}
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
        * { box-sizing: border-box; margin: 0; padding: 0; }

        /* ── DARK (default) ── */
        :root {
            --green: #76b900;
            --green-hover: #8fd400;
            --bg: #0a0a0a;
            --bg2: #111;
            --bg3: #1a1a1a;
            --border: #1e1e1e;
            --border2: #2a2a2a;
            --text: #e5e5e5;
            --text-strong: #ffffff;
            --muted: #555;
            --muted2: #888;
            --role: #666;
            --section-dim: #2a2a2a;
            --cat-bg: rgba(255,255,255,0.02);
            --cat-border: rgba(255,255,255,0.06);
            --search-bg: #111;
            --search-border: #2a2a2a;
            --search-focus: #3a3a3a;
        }

        /* ── LIGHT ── */
        :root[data-theme="light"] {
            --green: #5a9300;
            --green-hover: #6ead00;
            --bg: #f7f8fa;
            --bg2: #ffffff;
            --bg3: #f1f3f5;
            --border: #e5e7eb;
            --border2: #d1d5db;
            --text: #1f2937;
            --text-strong: #0f172a;
            --muted: #6b7280;
            --muted2: #4b5563;
            --role: #64748b;
            --section-dim: #9ca3af;
            --cat-bg: rgba(15,23,42,0.015);
            --cat-border: rgba(15,23,42,0.08);
            --search-bg: #ffffff;
            --search-border: #e5e7eb;
            --search-focus: #9ca3af;
        }

        body {
            font-family: 'Inter', system-ui, sans-serif;
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
            /* Smooth 0.4s cross-fade when toggling dark/light. The
               longer duration (vs old 0.2s) plus extra colour-channel
               coverage means card backgrounds, borders, and accents
               all animate together rather than popping at different
               speeds — feels deliberate instead of glitchy. */
            transition:
                background-color 0.4s ease,
                color 0.4s ease;
        }
        /* Animate the gradient layers behind the page (hero, header)
           so they breathe through the theme change instead of a hard
           swap. */
        .header, .hero, .agent-card, .ticker-rail {
            transition:
                background-color 0.4s ease,
                background-image 0.4s ease,
                border-color 0.4s ease,
                box-shadow 0.4s ease,
                color 0.4s ease;
        }
        /* Small "flash ring" emitted from the theme button on toggle —
           gives the eye a focal point so the transition feels intentional
           rather than something randomly happening. */
        .theme-toggle.cy-flash::after {
            content: ''; position: absolute; inset: -4px;
            border: 2px solid var(--green); border-radius: 50%;
            opacity: 0;
            animation: theme-flash-ring 0.55s ease-out;
        }
        .theme-toggle { position: relative; }
        @keyframes theme-flash-ring {
            0%   { transform: scale(0.85); opacity: 0.9; }
            100% { transform: scale(2.2);  opacity: 0;   }
        }

        /* ── HEADER ── */
        /* ─────────────────────────────────────────────────────────
           COMMAND-RAIL HEADER (futurista upgrade #1, 2026-05-01)
           — Glass backdrop blur over the page background
           — Gradient-border buttons that pulse on hover
           — Scroll-aware: shrinks to 48px when window scrollY > 80
           — Live-status pulse on the user chip
           ───────────────────────────────────────────────────────── */
        .header {
            display: flex; align-items: center; gap: 12px;
            padding: 14px 32px;
            border-bottom: 1px solid color-mix(in srgb, var(--green) 12%, var(--border));
            background: color-mix(in srgb, var(--bg2) 70%, transparent);
            backdrop-filter: blur(14px) saturate(160%);
            -webkit-backdrop-filter: blur(14px) saturate(160%);
            position: sticky; top: 0; z-index: 100;
            transition: padding 0.18s ease, backdrop-filter 0.2s, background 0.2s;
        }
        .header.is-scrolled {
            padding: 8px 32px;
            background: color-mix(in srgb, var(--bg2) 85%, transparent);
            box-shadow: 0 4px 24px rgba(0,0,0,0.18);
        }
        .header.is-scrolled .logo { font-size: 17px; }
        .header.is-scrolled .hero-counter-chip { display: none; }

        .logo {
            font-size: 20px; font-weight: 800;
            background: linear-gradient(120deg, var(--green) 0%, #b3ff4a 50%, var(--green) 100%);
            background-size: 200% 100%; -webkit-background-clip: text;
            background-clip: text; color: transparent;
            letter-spacing: -0.5px;
            animation: logo-shimmer 6s linear infinite;
            transition: font-size 0.18s;
        }
        @keyframes logo-shimmer {
            0%   { background-position: 0% 50%; }
            100% { background-position: 200% 50%; }
        }

        .badge {
            font-size: 10px; background: var(--green); color: #000;
            padding: 2px 8px; border-radius: 20px; font-weight: 700;
            box-shadow: 0 0 10px color-mix(in srgb, var(--green) 50%, transparent);
        }
        .nav-links { margin-left: auto; display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }

        /* Gradient-border command button. Uses the double-background
           trick (padding-box + border-box) so border can be a gradient. */
        .nav-link {
            position: relative;
            font-size: 12px; color: var(--muted2); text-decoration: none;
            padding: 6px 13px; border-radius: 10px;
            transition: color 0.15s, transform 0.15s;
            display: flex; align-items: center; gap: 5px;
            background:
                linear-gradient(var(--bg2), var(--bg2)) padding-box,
                linear-gradient(135deg, color-mix(in srgb, var(--green) 18%, transparent), transparent 50%, color-mix(in srgb, var(--green) 8%, transparent)) border-box;
            border: 1px solid transparent;
        }
        .nav-link:hover {
            color: var(--text-strong);
            transform: translateY(-1px);
            background:
                linear-gradient(var(--bg2), var(--bg2)) padding-box,
                linear-gradient(135deg, var(--green), #4dd0ff, var(--green)) border-box;
            background-size: 100% 100%, 200% 200%;
            animation: nav-border-shift 2.4s linear infinite;
            box-shadow: 0 0 0 1px color-mix(in srgb, var(--green) 14%, transparent),
                        0 6px 18px color-mix(in srgb, var(--green) 18%, transparent);
        }
        @keyframes nav-border-shift {
            0%   { background-position: 0% 0%, 0% 50%; }
            100% { background-position: 0% 0%, 200% 50%; }
        }

        .nav-link.admin {
            background:
                linear-gradient(var(--bg2), var(--bg2)) padding-box,
                linear-gradient(135deg, #ff4444, transparent 60%) border-box;
            color: #ff7070;
        }
        .nav-link.admin:hover {
            background:
                linear-gradient(var(--bg2), var(--bg2)) padding-box,
                linear-gradient(135deg, #ff6666, #ff9900, #ff6666) border-box;
            color: #ff9090;
        }
        .nav-link.briefing {
            color: var(--green); font-weight: 700;
            background:
                linear-gradient(color-mix(in srgb, var(--green) 8%, var(--bg2)), color-mix(in srgb, var(--green) 8%, var(--bg2))) padding-box,
                linear-gradient(135deg, var(--green), color-mix(in srgb, var(--green) 30%, transparent)) border-box;
        }

        /* User identity chip — circular avatar + live dot.
           Replaces the plain ".user-name" span with a richer block. */
        .user-chip {
            display: flex; align-items: center; gap: 8px;
            padding: 4px 10px 4px 4px; border-radius: 999px;
            background:
                linear-gradient(var(--bg2), var(--bg2)) padding-box,
                linear-gradient(135deg, color-mix(in srgb, var(--green) 25%, transparent), transparent) border-box;
            border: 1px solid transparent;
        }
        .user-chip-avatar {
            width: 26px; height: 26px; border-radius: 50%;
            background: linear-gradient(135deg, var(--green), #4dd0ff);
            display: flex; align-items: center; justify-content: center;
            color: #000; font-weight: 800; font-size: 11px;
            flex-shrink: 0;
        }
        .user-chip-name { font-size: 12px; color: var(--text); font-weight: 600; }
        .user-chip-live {
            width: 7px; height: 7px; border-radius: 50%;
            background: var(--green);
            box-shadow: 0 0 8px var(--green);
            animation: pulse-dot 2s ease-in-out infinite;
            margin-left: 2px;
        }
        .user-name { font-size: 13px; color: var(--muted2); font-weight: 500; }   /* legacy fallback */

        .logout-form { display: inline; }
        .logout-btn {
            font-size: 12px; color: var(--muted); background: transparent;
            border: 1px solid var(--border2);
            padding: 5px 12px; border-radius: 8px; cursor: pointer; transition: all 0.15s;
        }
        .logout-btn:hover { color: var(--text); border-color: var(--green); }

        /* ─────────────────────────────────────────────────────────
           LIVE TICKER (futurista upgrade #4)
           — Sits between the header and the hero
           — Auto-refreshes every 30s from /api/activity-feed
           — Seamless infinite-scroll loop using a duplicated track
           ───────────────────────────────────────────────────────── */
        .ticker-rail {
            display: flex; align-items: center; gap: 12px;
            padding: 7px 16px;
            background: color-mix(in srgb, var(--green) 5%, var(--bg2));
            border-bottom: 1px solid color-mix(in srgb, var(--green) 14%, var(--border));
            overflow: hidden; position: relative;
            font-size: 12px;
        }
        .ticker-rail::before, .ticker-rail::after {
            content: ''; position: absolute; top: 0; bottom: 0; width: 60px; z-index: 2;
            pointer-events: none;
        }
        .ticker-rail::before { left: 0;  background: linear-gradient(90deg, var(--bg), transparent); }
        .ticker-rail::after  { right: 0; background: linear-gradient(270deg, var(--bg), transparent); }
        .ticker-label {
            color: var(--green); font-weight: 700; letter-spacing: 0.6px;
            text-transform: uppercase; font-size: 10px;
            display: inline-flex; align-items: center; gap: 6px;
            white-space: nowrap; flex-shrink: 0; z-index: 3;
        }
        .ticker-label .live-dot {
            width: 6px; height: 6px; border-radius: 50%;
            background: var(--green); box-shadow: 0 0 6px var(--green);
            animation: pulse-dot 1.6s ease-in-out infinite;
        }
        .ticker-track-wrap { flex: 1; overflow: hidden; position: relative; }
        .ticker-track {
            display: inline-flex; gap: 28px; white-space: nowrap;
            animation: ticker-scroll 60s linear infinite;
            will-change: transform;
        }
        .ticker-rail:hover .ticker-track { animation-play-state: paused; }
        @keyframes ticker-scroll {
            0%   { transform: translateX(0); }
            100% { transform: translateX(-50%); }
        }
        .ticker-item {
            color: var(--text); text-decoration: none;
            display: inline-flex; align-items: center; gap: 6px;
        }
        .ticker-item:hover { color: var(--green); }
        .ticker-item .ti-ago { color: var(--muted); font-size: 10px; }
        .ticker-item .ti-sep { color: var(--muted); }

        /* ── THEME TOGGLE ── */
        .theme-toggle {
            width: 32px; height: 32px; border-radius: 50%;
            border: 1px solid var(--border2); background: transparent;
            cursor: pointer; display: flex; align-items: center; justify-content: center;
            font-size: 14px; color: var(--muted2); transition: all 0.15s;
        }
        .theme-toggle:hover { color: var(--text); border-color: var(--muted); transform: rotate(20deg); }
        .theme-icon-dark, .theme-icon-light { display: none; }
        :root[data-theme="light"] .theme-icon-dark { display: inline; }
        :root[data-theme="dark"] .theme-icon-light,
        :root:not([data-theme]) .theme-icon-light { display: inline; }

        /* ─────────────────────────────────────────────────────────
           HERO with depth (futurista upgrade #2)
           — Aurora blobs floating behind the title (mix-blend screen)
           — H1 .accent shimmers green→cyan every 8s
           — Counter chip pinned top-right of the section
           ───────────────────────────────────────────────────────── */
        .hero {
            text-align: center; padding: 56px 32px 32px;
            position: relative; overflow: hidden; isolation: isolate;
        }
        .hero::before, .hero::after {
            content: ''; position: absolute; pointer-events: none;
            border-radius: 50%; filter: blur(60px);
            opacity: 0.55; z-index: -1;
            mix-blend-mode: screen;
        }
        :root[data-theme="light"] .hero::before,
        :root[data-theme="light"] .hero::after {
            mix-blend-mode: multiply; opacity: 0.18;
        }
        .hero::before {
            width: 380px; height: 380px;
            background: radial-gradient(circle, var(--green), transparent 70%);
            top: -120px; left: 12%;
            animation: aurora-1 22s ease-in-out infinite;
        }
        .hero::after {
            width: 320px; height: 320px;
            background: radial-gradient(circle, #4dd0ff, transparent 70%);
            top: -60px; right: 12%;
            animation: aurora-2 28s ease-in-out infinite;
        }
        @keyframes aurora-1 {
            0%, 100% { transform: translate(0, 0) scale(1); }
            50%      { transform: translate(40px, 30px) scale(1.15); }
        }
        @keyframes aurora-2 {
            0%, 100% { transform: translate(0, 0) scale(1); }
            50%      { transform: translate(-30px, 50px) scale(0.9); }
        }

        .hero-label {
            font-size: 11px; color: var(--muted); text-transform: uppercase;
            letter-spacing: 2.5px; margin-bottom: 14px;
            display: inline-flex; align-items: center; gap: 6px;
        }
        .hero-label::before, .hero-label::after {
            content: ''; width: 22px; height: 1px;
            background: linear-gradient(90deg, transparent, var(--muted));
        }
        .hero-label::after { background: linear-gradient(90deg, var(--muted), transparent); }

        .hero h1 {
            font-size: 40px; font-weight: 800; color: var(--text-strong);
            margin-bottom: 10px; letter-spacing: -1.2px;
        }
        .hero h1 .accent {
            background: linear-gradient(120deg, var(--green) 0%, #4dd0ff 30%, var(--green) 60%, #b3ff4a 100%);
            background-size: 280% 100%; -webkit-background-clip: text;
            background-clip: text; color: transparent;
            animation: hero-shimmer 8s linear infinite;
        }
        @keyframes hero-shimmer {
            0%   { background-position: 0% 50%; }
            100% { background-position: 280% 50%; }
        }
        .hero p { font-size: 14px; color: var(--muted2); max-width: 480px; margin: 0 auto; line-height: 1.6; }

        /* Floating counter chip — "27 agentes" / "live" indicator. */
        .hero-counter-chip {
            position: absolute; top: 18px; right: 24px;
            display: inline-flex; align-items: center; gap: 8px;
            padding: 6px 14px; border-radius: 999px;
            font-size: 11px; font-weight: 700; letter-spacing: 0.8px;
            text-transform: uppercase; color: var(--green);
            background: color-mix(in srgb, var(--green) 8%, var(--bg2));
            border: 1px solid color-mix(in srgb, var(--green) 28%, transparent);
            box-shadow: 0 0 16px color-mix(in srgb, var(--green) 18%, transparent);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
        }
        .hero-counter-chip .live-dot {
            width: 6px; height: 6px; border-radius: 50%;
            background: var(--green);
            box-shadow: 0 0 8px var(--green);
            animation: pulse-dot 1.8s ease-in-out infinite;
        }

        /* ── SEARCH BAR ── */
        .search-wrap {
            max-width: 520px; margin: 18px auto 0; position: relative;
        }
        .search-input {
            width: 100%; padding: 11px 16px 11px 42px; font-size: 14px;
            background: var(--search-bg); color: var(--text);
            border: 1px solid var(--search-border); border-radius: 999px;
            outline: none; font-family: inherit;
            transition: border-color 0.15s, box-shadow 0.15s;
        }
        .search-input::placeholder { color: var(--muted); }
        .search-input:focus {
            border-color: var(--green);
            box-shadow: 0 0 0 3px color-mix(in srgb, var(--green) 18%, transparent);
        }
        .search-icon {
            position: absolute; left: 16px; top: 50%; transform: translateY(-50%);
            color: var(--muted); font-size: 14px; pointer-events: none;
        }
        .search-clear {
            position: absolute; right: 10px; top: 50%; transform: translateY(-50%);
            width: 24px; height: 24px; border-radius: 50%; border: none;
            background: var(--border2); color: var(--text); cursor: pointer;
            display: none; align-items: center; justify-content: center; font-size: 12px;
        }
        .search-clear.visible { display: flex; }

        /* ── RECENT CONVERSATIONS STRIP ── */
        .recent-wrap {
            max-width: 1280px; margin: 40px auto 0; padding: 0 32px;
        }
        .recent-header {
            display: flex; align-items: center; justify-content: space-between;
            margin-bottom: 14px;
        }
        .recent-title {
            font-size: 13px; font-weight: 700; color: var(--text-strong);
            letter-spacing: 0.3px;
        }
        .recent-view-all {
            font-size: 12px; color: var(--muted2); text-decoration: none;
            padding: 4px 12px; border: 1px solid var(--border2); border-radius: 20px;
            transition: all 0.15s;
        }
        .recent-view-all:hover { color: var(--text); border-color: var(--muted); }
        .recent-strip {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
            gap: 12px;
        }
        .recent-card {
            display: flex; align-items: center; gap: 12px;
            padding: 12px 14px;
            background: var(--bg2); border: 1px solid var(--border); border-radius: 14px;
            text-decoration: none; color: inherit;
            transition: all 0.2s;
            border-left: 3px solid var(--card-color, var(--green));
        }
        .recent-card:hover {
            transform: translateX(3px);
            border-color: color-mix(in srgb, var(--card-color, var(--green)) 35%, transparent);
            box-shadow: 0 4px 16px color-mix(in srgb, var(--card-color, var(--green)) 12%, transparent);
        }
        .recent-avatar {
            width: 42px; height: 42px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 20px; background: var(--bg3);
            border: 1px solid var(--border2); overflow: hidden; flex-shrink: 0;
        }
        .recent-avatar img { width: 100%; height: 100%; object-fit: cover; }
        .recent-body { flex: 1; min-width: 0; }
        .recent-agent-name {
            font-size: 13px; font-weight: 700; color: var(--text-strong);
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .recent-meta {
            font-size: 11px; color: var(--muted);
            margin-top: 2px;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }

        /* ── CATEGORIES ── */
        .categories-wrap { max-width: 1280px; margin: 40px auto 0; padding: 0 32px 60px; }
        .category-section { margin-bottom: 44px; }
        .category-section.empty { display: none; }
        .category-header {
            display: flex; align-items: center; gap: 12px; margin-bottom: 18px;
            padding: 12px 16px; background: var(--cat-bg);
            border: 1px solid var(--cat-border); border-radius: 12px;
        }
        .category-icon {
            width: 36px; height: 36px; border-radius: 10px;
            background: color-mix(in srgb, var(--cat-color, var(--green)) 16%, transparent);
            display: flex; align-items: center; justify-content: center;
            font-size: 18px;
            border: 1px solid color-mix(in srgb, var(--cat-color, var(--green)) 30%, transparent);
        }
        .category-title {
            font-size: 14px; font-weight: 700; color: var(--text-strong);
            letter-spacing: 0.3px;
        }
        .category-subtitle {
            font-size: 11px; color: var(--muted); text-transform: uppercase; letter-spacing: 1.5px;
            margin-top: 2px;
        }
        .category-count {
            margin-left: auto; font-size: 11px; color: var(--muted);
            padding: 3px 9px; border: 1px solid var(--border2); border-radius: 20px;
        }

        /* ── GRID ── */
        .agents-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(230px, 1fr));
            gap: 16px;
        }

        /* ─────────────────────────────────────────────────────────
           HOLOGRAPHIC AGENT CARDS (futurista upgrade #3)
           — Cursor-tracked highlight: a soft radial gradient follows
             the mouse via two CSS vars (--mx, --my) updated by JS
           — 3D tilt: ±5° rotateX/rotateY following cursor (also via JS)
           — Top accent bar widens + glows on hover
           — Active-status dot is a multi-layer ring pulse
           ───────────────────────────────────────────────────────── */
        .agent-card {
            background: var(--bg2); border: 1px solid var(--border); border-radius: 18px;
            padding: 28px 20px 22px; text-align: center; cursor: pointer;
            transition: transform 0.18s ease-out, box-shadow 0.2s, border-color 0.2s, background 0.2s;
            text-decoration: none; display: block; position: relative; overflow: hidden;
            transform-style: preserve-3d;
            /* Cursor coords default to centre so non-JS users see no glitch. */
            --mx: 50%; --my: 50%;
        }
        .agent-card.hidden-by-search { display: none; }

        /* Top accent bar — widens + glows on hover. */
        .agent-card::before {
            content: ''; position: absolute; top: 0; left: 50%; height: 3px;
            width: 38%; transform: translateX(-50%);
            background: var(--card-color, var(--green));
            opacity: 0.55; border-radius: 0 0 4px 4px;
            transition: width 0.3s ease, opacity 0.2s, box-shadow 0.2s;
        }
        .agent-card:hover::before {
            width: 100%;
            opacity: 1;
            box-shadow: 0 0 14px var(--card-color, var(--green));
        }

        /* Cursor-tracked highlight — radial gradient following mouse.
           Boosted to 22% saturation + a secondary inner ring at 35%
           so the brand colour really pops on hover. */
        .agent-card::after {
            content: ''; position: absolute; inset: 0;
            background: radial-gradient(
                420px circle at var(--mx) var(--my),
                color-mix(in srgb, var(--card-color, var(--green)) 22%, transparent) 0%,
                color-mix(in srgb, var(--card-color, var(--green)) 8%, transparent) 22%,
                transparent 50%
            );
            opacity: 0; transition: opacity 0.25s;
            pointer-events: none; z-index: 1;
        }
        .agent-card:hover::after { opacity: 1; }
        /* Keep all child content above the highlight overlay. */
        .agent-card > * { position: relative; z-index: 2; }

        /* Brand-coloured outer glow on hover — pulsates softly so the
           card feels "alive" rather than just statically lit. Each
           agent has its own --card-color from the catalogue, so the
           glow tints to that brand. */
        @keyframes card-glow-breathe {
            0%, 100% { box-shadow:
                0 16px 44px color-mix(in srgb, var(--card-color, var(--green)) 28%, transparent),
                0 0 0 1px  color-mix(in srgb, var(--card-color, var(--green)) 28%, transparent),
                0 0 32px   color-mix(in srgb, var(--card-color, var(--green)) 16%, transparent); }
            50%      { box-shadow:
                0 22px 56px color-mix(in srgb, var(--card-color, var(--green)) 38%, transparent),
                0 0 0 1px   color-mix(in srgb, var(--card-color, var(--green)) 36%, transparent),
                0 0 48px    color-mix(in srgb, var(--card-color, var(--green)) 28%, transparent); }
        }
        .agent-card:hover {
            transform: translateY(-6px);
            border-color: color-mix(in srgb, var(--card-color, var(--green)) 60%, transparent);
            animation: card-glow-breathe 2.6s ease-in-out infinite;
        }

        /* Avatar gets a brand-coloured halo on hover (subtle radial
           around the photo). */
        .agent-card:hover .avatar {
            box-shadow:
                0 0 0 3px color-mix(in srgb, var(--card-color, var(--green)) 12%, transparent),
                0 0 24px color-mix(in srgb, var(--card-color, var(--green)) 32%, transparent);
        }

        .agent-card .avatar {
            width: 80px; height: 80px; border-radius: 50%; margin: 0 auto 16px;
            display: flex; align-items: center; justify-content: center;
            font-size: 38px; background: var(--bg3);
            border: 2px solid var(--border2); overflow: hidden;
            transition: border-color 0.2s;
        }
        .agent-card:hover .avatar {
            border-color: color-mix(in srgb, var(--card-color, var(--green)) 60%, transparent);
        }
        .agent-card .avatar img { width: 100%; height: 100%; object-fit: cover; }

        .agent-name { font-size: 14px; font-weight: 700; color: var(--text-strong); margin-bottom: 6px; }
        .agent-role { font-size: 12px; color: var(--role); margin-bottom: 18px; line-height: 1.45; }

        .talk-btn {
            display: inline-block; background: var(--green); color: #000;
            padding: 8px 22px; border-radius: 20px; font-size: 12px; font-weight: 700;
            transition: background 0.15s, transform 0.15s;
        }
        .agent-card:hover .talk-btn {
            background: var(--green-hover); transform: scale(1.05);
        }

        /* Status dot — now with a ripple ring around it for "live" feel. */
        .status-dot {
            position: absolute; top: 14px; right: 14px;
            width: 9px; height: 9px; background: var(--green);
            border-radius: 50%;
            box-shadow: 0 0 8px var(--green);
            animation: pulse-dot 2.5s ease-in-out infinite;
            z-index: 3;
        }
        .status-dot::after {
            content: ''; position: absolute; inset: -3px;
            border: 2px solid var(--green); border-radius: 50%;
            opacity: 0.7;
            animation: ripple 2.5s ease-out infinite;
        }
        @keyframes ripple {
            0%   { transform: scale(0.85); opacity: 0.7; }
            70%  { transform: scale(2.2);  opacity: 0;   }
            100% { transform: scale(2.2);  opacity: 0;   }
        }

        /* ── FAVORITE STAR BUTTON ── */
        .fav-btn {
            position: absolute; top: 12px; left: 12px;
            width: 28px; height: 28px; border-radius: 50%;
            background: transparent; border: none;
            display: flex; align-items: center; justify-content: center;
            font-size: 16px; cursor: pointer; z-index: 5;
            opacity: 0; transition: opacity 0.2s, transform 0.15s, background 0.15s;
            color: var(--muted);
            line-height: 1;
        }
        .agent-card:hover .fav-btn { opacity: 1; }
        .fav-btn:hover { background: color-mix(in srgb, #fbbf24 18%, transparent); transform: scale(1.15); color: #fbbf24; }
        .agent-card.is-favorite .fav-btn {
            opacity: 1;
            color: #fbbf24;
            filter: drop-shadow(0 0 4px rgba(251, 191, 36, 0.6));
        }
        .agent-card.is-favorite {
            box-shadow: inset 0 0 0 1px color-mix(in srgb, #fbbf24 30%, transparent);
        }

        /* ── PROFILE INFO BUTTON ── */
        .info-btn {
            position: absolute; top: 12px; right: 12px;
            width: 28px; height: 28px; border-radius: 50%;
            background: transparent; border: none;
            display: flex; align-items: center; justify-content: center;
            font-size: 13px; cursor: pointer; z-index: 5;
            opacity: 0; transition: opacity 0.2s, transform 0.15s, background 0.15s;
            color: var(--muted); line-height: 1;
            text-decoration: none;
        }
        .agent-card:hover .info-btn { opacity: 1; }
        .info-btn:hover { background: color-mix(in srgb, var(--card-color) 18%, transparent); transform: scale(1.15); color: var(--card-color); }

        /* ── FAVORITES SECTION (top of grid) ── */
        #favoritesSection { display: none; }
        #favoritesSection.has-items { display: block; }
        #favoritesSection.empty { display: none; } /* search filter: no matches */
        #favoritesSection .category-header { --cat-color: #fbbf24; }
        #favoritesSection .category-icon {
            background: color-mix(in srgb, #fbbf24 20%, transparent);
            border-color: color-mix(in srgb, #fbbf24 50%, transparent);
        }
        @keyframes pulse-dot {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.4; transform: scale(0.8); }
        }

        /* ── BRIEFING — special card ── */
        .agent-card.briefing-card {
            background: linear-gradient(135deg, #001a2e, #0a0a16);
            border-color: #003366;
            --card-color: #00aaff;
        }
        :root[data-theme="light"] .agent-card.briefing-card {
            background: linear-gradient(135deg, #e0f2ff, #f0f8ff);
            border-color: #93c5fd;
        }
        .briefing-card .agent-name { color: #00aaff; }
        :root[data-theme="light"] .briefing-card .agent-name { color: #0369a1; }
        .briefing-card .agent-role { color: #4b5b74; }
        :root[data-theme="light"] .briefing-card .agent-role { color: #475569; }
        .briefing-card .talk-btn { background: #003366; color: #00aaff; border: 1px solid #0055aa; }
        :root[data-theme="light"] .briefing-card .talk-btn { background: #0369a1; color: #ffffff; border-color: #0284c7; }
        .briefing-card:hover .talk-btn { background: #004488; transform: scale(1.05); }
        :root[data-theme="light"] .briefing-card:hover .talk-btn { background: #0284c7; }
        .briefing-card .status-dot { background: #00aaff; box-shadow: 0 0 8px #00aaff; }

        /* ── EMPTY STATE ── */
        .empty-state {
            text-align: center; padding: 60px 20px; color: var(--muted);
            display: none;
        }
        .empty-state.visible { display: block; }
        .empty-state .big { font-size: 48px; margin-bottom: 12px; }
        .empty-state h3 { font-size: 16px; color: var(--text); margin-bottom: 6px; }
        .empty-state p { font-size: 13px; }

        /* ── MOBILE ── */
        @media (max-width: 640px) {
            .header { padding: 10px 16px; }
            .nav-links { gap: 6px; }
            .nav-link { font-size: 11px; padding: 4px 9px; }
            .hero { padding: 32px 16px 20px; }
            .hero h1 { font-size: 26px; }
            .recent-wrap { padding: 0 16px; margin-top: 26px; }
            .recent-strip { grid-template-columns: 1fr; }
            .recent-card { padding: 10px 12px; }
            .categories-wrap { padding: 0 16px 40px; margin-top: 26px; }
            .category-section { margin-bottom: 32px; }
            .agents-grid { gap: 12px; grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); }
            .agent-card { padding: 20px 14px 16px; }
            .agent-card .avatar { width: 64px; height: 64px; font-size: 30px; }
            .agent-name { font-size: 13px; }
            .agent-role { font-size: 11px; }
        }
    </style>
</head>
<body>

<header class="header">
    <a href="/dashboard" style="display:flex;align-items:center;text-decoration:none;"><img src="/images/clawyard-logo.svg" alt="ClawYard" style="height:36px;filter:drop-shadow(0 0 4px rgba(118,185,0,0.3));"></a>
    <span class="badge">© PartYard/Setq.AI Rights reserved 2026</span>
    {{-- Header navbar — visual uniformity (revisão 2026-04-29):
         todos os nav-link partilham o mesmo estilo base (cinzento neutro).
         Apenas DOIS botões se distinguem por boa razão funcional:
           • .briefing  → green CTA (call-to-action principal do produto)
           • .admin     → red alerta (acesso restrito, segurança)
         Tudo o resto era mistura caótica de roxo/azul/amarelo/verde —
         removido. Adicionado 🏆 Rewards e 🛒 Marketplace que estavam em falta. --}}
    <div class="nav-links">
        {{-- Nav driven by User::canSeeNav() — per-user whitelist or role defaults.
             Toggle per user at /admin/nav-access. Admin panel stays hardcoded. --}}
        @if(Auth::user()->canSeeNav('briefing'))
            <a href="/briefing" class="nav-link briefing">📊 Briefing</a>
        @endif
        @if(Auth::user()->canSeeNav('tenders'))
            <a href="{{ route('tenders.index') }}" class="nav-link">📋 Concursos</a>
        @endif
        @if(Auth::user()->canSeeNav('rewards'))
            <a href="{{ route('rewards.me') }}" class="nav-link">🏆 Rewards</a>
        @endif
        @if(Auth::user()->canSeeNav('marketplace'))
            <a href="{{ route('marketplace.index') }}" class="nav-link">🛒 Marketplace</a>
        @endif
        @if(Auth::user()->canSeeNav('discoveries'))
            <a href="/discoveries" class="nav-link">🔬 Discoveries</a>
        @endif
        @if(Auth::user()->canSeeNav('patents'))
            <a href="/patents/library" class="nav-link">🏛️ Patents</a>
        @endif
        @if(Auth::user()->canSeeNav('robot'))
            <a href="{{ route('robot.index') }}" class="nav-link">🤖 Robot</a>
        @endif
        @if(Auth::user()->canSeeNav('council'))
            <a href="{{ route('robot.research') }}" class="nav-link">🔬 Council</a>
        @endif
        @if(Auth::user()->canSeeNav('intel'))
            <a href="/intel" class="nav-link">🔗 Intel Bus</a>
        @endif
        @if(Auth::user()->canSeeNav('activity'))
            <a href="/agents/activity" class="nav-link">🤖 Activity</a>
        @endif
        @if(Auth::user()->canSeeNav('mission'))
            <a href="/mission" class="nav-link" title="Mission Control — single-pane view">🛰️ Mission</a>
        @endif
        @if(Auth::user()->canSeeNav('reports'))
            <a href="/reports" class="nav-link">📁 Reports</a>
        @endif
        @if(Auth::user()->canSeeNav('stats'))
            <a href="/stats" class="nav-link">📈 Stats</a>
        @endif
        @if(Auth::user()->canSeeNav('schedules'))
            <a href="/schedules" class="nav-link">🗓️ Schedule</a>
        @endif
        @if(Auth::user()->canSeeNav('shares'))
            <a href="/shares" class="nav-link">👥 Shared</a>
        @endif

        {{-- Admin panel: sempre hardcoded a isAdmin() — fora da matriz --}}
        @if(Auth::user()->isAdmin())
            <a href="{{ route('admin.panel') }}" class="nav-link admin" title="Admin Panel — health + secrets + flags">⚙️ Admin</a>
        @endif
        @php
            $cyName = trim((string) Auth::user()->name);
            $cyParts = preg_split('/\s+/', $cyName) ?: [];
            $cyInitials = mb_strtoupper(mb_substr($cyParts[0] ?? '?', 0, 1) . mb_substr($cyParts[count($cyParts)-1] ?? '', 0, 1));
        @endphp
        <span class="user-chip" title="Sessão activa — {{ $cyName }}">
            <span class="user-chip-avatar">{{ $cyInitials }}</span>
            <span class="user-chip-name">{{ $cyName }}</span>
            <span class="user-chip-live" title="online"></span>
        </span>
        <button type="button" class="theme-toggle" id="themeToggle" title="Toggle theme" aria-label="Toggle dark/light theme">
            <span class="theme-icon-light">🌙</span>
            <span class="theme-icon-dark">☀️</span>
        </button>
        <form method="POST" action="{{ route('logout') }}" class="logout-form">
            @csrf
            <button type="submit" class="logout-btn">Log out</button>
        </form>
    </div>
</header>

{{-- Live activity ticker — populated via /api/activity-feed every 30s.
     Hidden initially; the script reveals it once data is ready so we
     don't flash an empty rail on first paint. --}}
<div class="ticker-rail" id="cy-ticker" style="display:none">
    <span class="ticker-label"><span class="live-dot"></span>Live</span>
    <div class="ticker-track-wrap">
        <div class="ticker-track" id="cy-ticker-track"></div>
    </div>
</div>

<div class="hero">
    <span class="hero-counter-chip" title="Total de agentes prontos">
        <span class="live-dot"></span>
        {{ $agentCount ?? 27 }} agentes · live
    </span>
    <p class="hero-label">HP-Group · PartYard Marine · PartYard Military</p>
    <h1>Choose your <span class="accent">Agent</span></h1>
    <p>© PartYard/Setq.AI Rights reserved 2026 — {{ $agentCount ?? 27 }} specialised agents ready to help</p>

    <div class="search-wrap">
        <span class="search-icon">🔍</span>
        <input type="text" id="agentSearch" class="search-input" placeholder="Pesquisar agente (nome, função, tecnologia…)" autocomplete="off">
        <button type="button" class="search-clear" id="searchClear" aria-label="Clear search">✕</button>
    </div>
</div>

@php
// Each agent tagged with a category for grouping on the dashboard.
// Keep this stable — the search filter reads from the same structure.
$agents = [
    ['key' => 'briefing',    'category' => 'strategic',  'name' => 'Strategist Renato',      'emoji' => '📊', 'role' => 'Executive briefing — combines Quantum, ARIA, Sales and all agents into an action plan', 'color' => '#00aaff', 'special' => true],
    ['key' => 'orchestrator','category' => 'strategic',  'name' => 'All Agents',             'emoji' => '🌐', 'role' => 'Orchestrator — activates multiple agents in parallel',                                    'color' => '#76b900'],
    ['key' => 'thinking',    'category' => 'strategic',  'name' => 'Prof. Deep Thought',     'emoji' => '🧠', 'role' => 'Extended thinking — complex multi-step reasoning and deep analysis',                      'color' => '#a855f7'],
    ['key' => 'claude',      'category' => 'strategic',  'name' => 'Bruno AI',               'emoji' => '🧠', 'role' => 'Claude — advanced reasoning and complex analysis',                                        'color' => '#a855f7'],
    ['key' => 'nvidia',      'category' => 'strategic',  'name' => 'Carlos NVIDIA',          'emoji' => '⚡', 'role' => 'NVIDIA NeMo — maximum speed and efficiency',                                              'color' => '#76b900'],

    ['key' => 'sales',       'category' => 'commercial', 'name' => 'Marco Sales',            'emoji' => '💼', 'role' => 'Sales MTU · CAT · MAK · Jenbacher · SKF · Schottel',                                     'color' => '#3b82f6'],
    ['key' => 'support',     'category' => 'commercial', 'name' => 'Marcus Support',         'emoji' => '🔧', 'role' => 'Technical Support — engine and system fault diagnosis',                                   'color' => '#f59e0b'],
    ['key' => 'email',       'category' => 'commercial', 'name' => 'Daniel Email',           'emoji' => '📧', 'role' => 'Maritime email — shipowners, agents and vessels',                                         'color' => '#8b5cf6'],
    ['key' => 'crm',         'category' => 'commercial', 'name' => 'Marta CRM',              'emoji' => '🎯', 'role' => 'SAP B1 CRM — cria oportunidades a partir de emails, pipeline por vendedor',              'color' => '#e11d48'],
    ['key' => 'shipping',    'category' => 'commercial', 'name' => 'Logística/PartYard',     'emoji' => '🚚', 'role' => 'Transporte UPS 2026, catalogação de faturas, alfândega (Incoterms, TARIC, VIES, DAU/SAD, IVA intra-UE)', 'color' => '#8b5cf6'],
    ['key' => 'mildef',      'category' => 'commercial', 'name' => 'Cor. Rodrigues Defesa',  'emoji' => '🎖️', 'role' => 'Military procurement — worldwide defence suppliers excl. China/Russia, NATO/EU/USLI context', 'color' => '#6b3fa0'],

    ['key' => 'sap',         'category' => 'operations', 'name' => 'Richard SAP',            'emoji' => '📊', 'role' => 'SAP B1 — stock, invoices, pipeline CRM and ERP data',                                    'color' => '#06b6d4'],
    ['key' => 'capitao',     'category' => 'operations', 'name' => 'Captain Porto',          'emoji' => '⚓', 'role' => 'Port operations — port calls, documentation and maritime logistics',                      'color' => '#0ea5e9'],
    ['key' => 'document',    'category' => 'operations', 'name' => 'Commander Doc',          'emoji' => '📄', 'role' => 'Documents — analyses PDFs, contracts and technical certificates',                         'color' => '#94a3b8'],
    ['key' => 'qnap',        'category' => 'operations', 'name' => 'PartYard Archive',       'emoji' => '🗄️', 'role' => 'Document archive — search prices, codes, invoices, licences and contracts on QNAP',       'color' => '#f59e0b'],
    ['key' => 'finance',     'category' => 'operations', 'name' => 'Dr. Luís Finance',       'emoji' => '💰', 'role' => 'ROC · TOC · PhD Banking Management — Accounting, Audit and Taxation',                    'color' => '#10b981'],
    ['key' => 'hr',          'category' => 'operations', 'name' => 'Dr.ª Ana Sobral RH',     'emoji' => '👥', 'role' => 'Recursos Humanos PartYard — avaliação de desempenho, KPI/OKR, formação, organigrama, código do trabalho PT', 'color' => '#ec4899'],
    ['key' => 'marketing',   'category' => 'commercial', 'name' => 'Ana Monteiro Marketing', 'emoji' => '🎨', 'role' => 'Marketing Director — B2C/D2C (Meta/Google/TikTok ads, content calendars, influencers) + B2B (LinkedIn, white papers, PR procurement)', 'color' => '#f97316'],
    ['key' => 'acingov',     'category' => 'operations', 'name' => 'Dr. Ana Contracts',      'emoji' => '🏛️', 'role' => 'Public procurement — Acingov tenders ranked by relevance for PartYard',                  'color' => '#f59e0b'],
    ['key' => 'vessel',      'category' => 'operations', 'name' => 'Capitão Vasco',          'emoji' => '⚓', 'role' => 'Vessel search + naval repair — ship brokers, drydocks, IACS class, inland waterways',     'color' => '#0ea5e9'],
    ['key' => 'workreport',  'category' => 'operations', 'name' => 'Eng. Repair',            'emoji' => '🛠️', 'role' => 'Marine/Shipping/Repairs Work Reports — soldadura naval, WPS, NDT/UTM, PartYard Job Reports',  'color' => '#0891b2'],

    ['key' => 'engineer',    'category' => 'rd',         'name' => 'Eng. Victor R&D',        'emoji' => '🔩', 'role' => 'R&D and Product Development — TRL plans, CAPEX, roadmap for new PartYard equipment',      'color' => '#f97316'],
    ['key' => 'patent',      'category' => 'rd',         'name' => 'Dr. Sofia IP',           'emoji' => '🏛️', 'role' => 'Intellectual Property — patent validation, prior art EPO/USPTO, patentability and FTO',   'color' => '#8b5cf6'],
    ['key' => 'quantum',     'category' => 'rd',         'name' => 'Prof. Quantum Leap',     'emoji' => '⚛️', 'role' => 'Quantum — arXiv papers + USPTO patents for PartYard',                                      'color' => '#22d3ee'],
    ['key' => 'energy',      'category' => 'rd',         'name' => 'Eng. Sofia Energy',      'emoji' => '⚡', 'role' => 'Maritime decarbonisation — Fuzzy TOPSIS, CII/EEXI, LNG/Biofuel/H2, Fleet Energy Mgmt',   'color' => '#10b981'],
    ['key' => 'research',    'category' => 'rd',         'name' => 'Marina Research',        'emoji' => '🔍', 'role' => 'Competitive intelligence — benchmarking, market analysis and site improvements',          'color' => '#f97316'],

    ['key' => 'aria',        'category' => 'security',   'name' => 'ARIA Security',          'emoji' => '🔐', 'role' => 'Cybersecurity — STRIDE, OWASP, daily site scanning',                                      'color' => '#ef4444'],
    ['key' => 'kyber',       'category' => 'security',   'name' => 'KYBER Encryption',       'emoji' => '🔒', 'role' => 'Post-quantum encryption — Kyber-1024 + AES-256-GCM, key generation and encrypted email',  'color' => '#76b900'],
    ['key' => 'computer',    'category' => 'security',   'name' => 'RoboDesk',               'emoji' => '🖥️', 'role' => 'Web automation — Computer Use API, browser control and desktop tasks',                    'color' => '#22c55e'],
    ['key' => 'batch',       'category' => 'security',   'name' => 'Max Batch',              'emoji' => '📦', 'role' => 'Batch processing — run multiple tasks in parallel with async queues',                     'color' => '#06b6d4'],
];

$categories = [
    'strategic'  => ['title' => 'Strategic & AI Core',         'subtitle' => 'Executive briefings, orchestration and deep reasoning', 'icon' => '🎯', 'color' => '#00aaff'],
    'commercial' => ['title' => 'Commercial & Logistics',      'subtitle' => 'Sales, support, email, CRM, shipping and defence',       'icon' => '💼', 'color' => '#3b82f6'],
    'operations' => ['title' => 'Operations & Finance',        'subtitle' => 'SAP, port ops, documents, accounting and procurement',   'icon' => '⚙️', 'color' => '#06b6d4'],
    'rd'         => ['title' => 'R&D · Engineering · IP',      'subtitle' => 'Product development, patents, quantum and research',      'icon' => '🔬', 'color' => '#f97316'],
    'security'   => ['title' => 'Security · Automation · Ops', 'subtitle' => 'Cybersecurity, encryption, web automation and batching',  'icon' => '🔐', 'color' => '#ef4444'],
];

// Filter agents to only those the current user is allowed to use.
// canUseAgent(): admin → always true; allowed_agents=null → all; array → whitelist.
// This hides cards at render time — no more "visible but 403 on click".
$_dashUser = Auth::user();
$agents = array_values(array_filter($agents, fn($a) => $_dashUser->canUseAgent($a['key'])));

// Group agents by category in order; drop categories with no visible agents
// so a user on vendor_spares never sees an empty "Security" header.
$grouped = [];
foreach ($categories as $catKey => $catMeta) {
    $catAgents = array_values(array_filter($agents, fn($a) => $a['category'] === $catKey));
    if (!empty($catAgents)) $grouped[$catKey] = $catAgents;
}

// Quick lookup by key for the recent-conversations strip
$agentByKey = [];
foreach ($agents as $a) $agentByKey[$a['key']] = $a;
@endphp

@php
    // "Partilhados comigo" strip — visible only if the user actually owns
    // something. Hidden entirely for admins with no assignments so the
    // dashboard stays clean. The agent catalog lookup lets us render the
    // shared agents with the same name/emoji/colour as the main grid.
    $hasTenders = !empty($myTenderStats) && ($myTenderStats['total'] ?? 0) > 0;
    $hasSharedAgents = isset($mySharedAgents) && $mySharedAgents->isNotEmpty();
    $showMineStrip = $hasTenders || $hasSharedAgents;
@endphp

{{-- E2 — Rewards strip — points + level + streak + last 3 events.
     Hidden when the user has zero activity (clean dashboard for admins
     who don't earn via the system). Mirrors the "Partilhados comigo"
     strip styling to feel native. --}}
@php
    $hasRewardActivity = isset($myPoints) && $myPoints
        && ((int) $myPoints->total_points > 0 || (isset($myRecentRewards) && $myRecentRewards->count() > 0));
@endphp
@if($hasRewardActivity)
<div class="recent-wrap">
    <div class="recent-header">
        <span class="recent-title">🏆 Os teus rewards</span>
        <a href="{{ route('rewards.me') }}" class="recent-view-all">ver todos →</a>
    </div>
    <div class="recent-strip" style="display:flex;gap:12px;align-items:stretch;flex-wrap:wrap;">
        {{-- Points + level + streak — single hero card --}}
        <div style="flex:1;min-width:260px;background:linear-gradient(135deg,#1a2a3a 0%,#2a1a3a 100%);border:1px solid #2a3a4a;border-radius:12px;padding:14px 18px;">
            <div style="display:flex;justify-content:space-between;align-items:baseline;gap:12px;flex-wrap:wrap;">
                <div>
                    <div style="font-size:10px;color:#9ab;text-transform:uppercase;letter-spacing:1px;">Pontos</div>
                    <div style="font-size:28px;font-weight:bold;color:#fff;line-height:1;">{{ number_format($myPoints->total_points) }}</div>
                </div>
                <div>
                    <div style="font-size:10px;color:#9ab;text-transform:uppercase;letter-spacing:1px;">Nível</div>
                    <div style="font-size:14px;font-weight:bold;color:#7c3;">{{ $myPoints->level }} · {{ $myPoints->levelName() }}</div>
                </div>
                <div>
                    <div style="font-size:10px;color:#9ab;text-transform:uppercase;letter-spacing:1px;">Streak</div>
                    <div style="font-size:14px;font-weight:bold;color:#f93;">🔥 {{ $myPoints->current_streak_days }}d</div>
                </div>
            </div>
            @if($myPoints->pointsToNextLevel() > 0)
                @php
                    $cur  = \App\Models\UserPoints::LEVEL_THRESHOLDS[$myPoints->level] ?? 0;
                    $next = \App\Models\UserPoints::LEVEL_THRESHOLDS[$myPoints->level + 1] ?? null;
                    $pct  = $next ? min(100, round((($myPoints->total_points - $cur) / max(1, $next - $cur)) * 100)) : 100;
                @endphp
                <div style="margin-top:10px;font-size:10px;color:#9ab;">
                    faltam <strong>{{ number_format($myPoints->pointsToNextLevel()) }}</strong> para
                    {{ \App\Models\UserPoints::LEVEL_NAMES[$myPoints->level + 1] ?? '?' }}
                </div>
                <div style="margin-top:4px;height:4px;background:rgba(255,255,255,0.1);border-radius:2px;overflow:hidden;">
                    <div style="height:4px;background:linear-gradient(90deg,#7c3,#3c7);width:{{ $pct }}%;"></div>
                </div>
            @endif
        </div>

        {{-- Last 3 reward events — compact list --}}
        @if($myRecentRewards && $myRecentRewards->count() > 0)
        <div style="flex:1.5;min-width:300px;background:#111;border:1px solid #1e1e1e;border-radius:12px;padding:14px 18px;">
            <div style="font-size:10px;color:#9ab;text-transform:uppercase;letter-spacing:1px;margin-bottom:8px;">Atividade recente</div>
            @foreach($myRecentRewards as $ev)
                <div style="display:flex;justify-content:space-between;gap:10px;padding:5px 0;border-bottom:1px solid #1e1e1e;font-size:12px;">
                    <span style="color:#bcd;">{{ str_replace('_', ' ', $ev->event_type) }}</span>
                    <span style="display:flex;gap:8px;align-items:center;">
                        <span style="color:#789;font-size:10px;">{{ $ev->created_at->diffForHumans() }}</span>
                        <span style="color:{{ $ev->points > 0 ? '#7c3' : '#666' }};font-family:monospace;font-weight:bold;min-width:36px;text-align:right;">
                            {{ $ev->points > 0 ? '+' : '' }}{{ $ev->points }}
                        </span>
                    </span>
                </div>
            @endforeach
        </div>
        @endif
    </div>
</div>
@endif

@if($showMineStrip)
<div class="recent-wrap">
    <div class="recent-header">
        <span class="recent-title">🎯 Partilhados comigo</span>
        <span class="recent-view-all" style="pointer-events:none;opacity:0.7;">
            {{ ($hasTenders ? 1 : 0) + ($hasSharedAgents ? $mySharedAgents->count() : 0) }} atribuído(s)
        </span>
    </div>
    <div class="recent-strip">
        {{-- Tender bucket card: links to /tenders filtered to mine. --}}
        @if($hasTenders)
            @php
                $deadline = $myTenderStats['next_deadline'] ?? null;
                $deadlineTxt = $deadline ? \Illuminate\Support\Carbon::parse($deadline)->setTimezone('Europe/Lisbon')->format('d/m H:i') : null;
                $overdue = $myTenderStats['overdue'] ?? 0;
            @endphp
            <a href="{{ route('tenders.index') }}" class="recent-card" style="--card-color: #fbbf24">
                <div class="recent-avatar" style="background:#1a1200;border-color:#3a2a0a;">📑</div>
                <div class="recent-body">
                    <div class="recent-agent-name">Os meus concursos</div>
                    <div class="recent-meta">
                        {{ $myTenderStats['total'] }} activo{{ $myTenderStats['total'] === 1 ? '' : 's' }}
                        @if($overdue > 0) · <span style="color:#f87171">{{ $overdue }} em atraso</span> @endif
                        @if($deadlineTxt) · próxima {{ $deadlineTxt }} @endif
                    </div>
                </div>
            </a>
        @endif

        {{-- Shared agent cards. Look up the catalog entry so the name/colour
             match the main grid below; fall back to raw agent_key if missing. --}}
        @if($hasSharedAgents)
            @foreach($mySharedAgents as $share)
                @php
                    $meta     = $agentByKey[$share->agent_key] ?? null;
                    $label    = $share->custom_title ?: ($meta['name'] ?? ucfirst($share->agent_key));
                    $emoji    = $meta['emoji'] ?? '🤝';
                    $color    = $meta['color'] ?? '#60a5fa';
                    $portalUrl = $share->getPortalUrl() ?: '/shares/' . $share->id;
                    $agentK   = $meta['key'] ?? $share->agent_key;
                    $imgPath  = null;
                    if ($agentK) {
                        foreach (['.png', '.jpg', '.jpeg', '.webp'] as $ext) {
                            if (file_exists(public_path('images/agents/' . $agentK . $ext))) {
                                $imgPath = '/images/agents/' . $agentK . $ext;
                                break;
                            }
                        }
                    }
                    $creatorName = $share->creator?->name ?? 'alguém';
                @endphp
                <a href="{{ $portalUrl }}" class="recent-card" style="--card-color: {{ $color }}" title="Partilhado por {{ $creatorName }}">
                    <div class="recent-avatar">
                        @if($imgPath)
                            <img src="{{ $imgPath }}" alt="{{ $label }}">
                        @else
                            {{ $emoji }}
                        @endif
                    </div>
                    <div class="recent-body">
                        <div class="recent-agent-name">{{ $label }}</div>
                        <div class="recent-meta">
                            🔗 partilhado · por {{ $creatorName }}
                            @if($share->expires_at) · expira {{ $share->expires_at->diffForHumans() }} @endif
                        </div>
                    </div>
                </a>
            @endforeach
        @endif
    </div>
</div>
@endif

@if(!empty($recentConversations) && $recentConversations->count() > 0)
<div class="recent-wrap">
    <div class="recent-header">
        <span class="recent-title">💬 Continua onde paraste</span>
        <a href="/conversations" class="recent-view-all">Ver histórico completo →</a>
    </div>
    <div class="recent-strip">
        @foreach($recentConversations as $conv)
            @php
                $agent   = $agentByKey[$conv->agent] ?? null;
                $label   = $agent['name'] ?? ucfirst($conv->agent ?: 'Chat');
                $emoji   = $agent['emoji'] ?? '💬';
                $color   = $agent['color'] ?? '#76b900';
                $agentK  = $agent['key'] ?? $conv->agent;
                $imgPath = null;
                if ($agentK) {
                    foreach (['.png', '.jpg', '.jpeg', '.webp'] as $ext) {
                        if (file_exists(public_path('images/agents/' . $agentK . $ext))) {
                            $imgPath = '/images/agents/' . $agentK . $ext;
                            break;
                        }
                    }
                }
                // DB session_id is `u{userId}_cyw_TIMESTAMP_RAND`; welcome.blade.php
                // stores only the `cyw_...` part in localStorage. Strip the prefix
                // so the chat view can re-pin this exact session and auto-load
                // history — making the card a true "continue" action, not a
                // read-only history view.
                $clientSid = preg_replace('/^u\d+_/', '', (string) $conv->session_id);
                $chatUrl   = '/chat?agent=' . urlencode($conv->agent ?: 'auto')
                           . '&session=' . urlencode($clientSid);
            @endphp
            <a href="{{ $chatUrl }}" class="recent-card" style="--card-color: {{ $color }}">
                <div class="recent-avatar">
                    @if($imgPath)
                        <img src="{{ $imgPath }}" alt="{{ $label }}">
                    @else
                        {{ $emoji }}
                    @endif
                </div>
                <div class="recent-body">
                    <div class="recent-agent-name">{{ $label }}</div>
                    <div class="recent-meta">
                        {{ $conv->messages_count }} {{ $conv->messages_count === 1 ? 'mensagem' : 'mensagens' }}
                        · {{ $conv->updated_at->diffForHumans() }}
                    </div>
                </div>
            </a>
        @endforeach
    </div>
</div>
@else
{{-- Discoverability for users who haven't chatted yet (or whose last
     5 conversations got pruned). Without this they don't realise
     /conversations exists at all — exactly the 2026-04-27 complaint
     "users say they don't have history". --}}
<div class="recent-wrap" style="opacity:.85;">
    <div class="recent-header">
        <span class="recent-title">💬 Histórico de conversas</span>
        <a href="/conversations" class="recent-view-all">Abrir histórico →</a>
    </div>
    <div style="padding:18px 22px;font-size:13px;color:#888;">
        Cada conversa que tens com um agente fica guardada aqui.
        Pesquisa pelo texto das mensagens ou filtra por agente.
    </div>
</div>
@endif

<div class="categories-wrap" id="categoriesWrap">

{{-- Favorites section — populated by JS from localStorage. Hidden when empty. --}}
<section class="category-section" id="favoritesSection" data-category="favorites">
    <div class="category-header">
        <div class="category-icon">⭐</div>
        <div>
            <div class="category-title">Your Favorites</div>
            <div class="category-subtitle">Starred agents — quick access</div>
        </div>
        <span class="category-count" id="favoritesCount">0 agents</span>
    </div>
    <div class="agents-grid" id="favoritesGrid"></div>
</section>

{{-- Iterate over $grouped (already filtered by canUseAgent + non-empty
     check). Categories with no visible agents for this user are skipped
     entirely. Defensive lookup of $cat handles a future scenario where
     $grouped contains a key not in $categories. --}}
@foreach($grouped as $catKey => $catAgents)
    @php $cat = $categories[$catKey] ?? null; @endphp
    @if($cat)
    <section class="category-section" data-category="{{ $catKey }}">
        <div class="category-header" style="--cat-color: {{ $cat['color'] }}">
            <div class="category-icon">{{ $cat['icon'] }}</div>
            <div>
                <div class="category-title">{{ $cat['title'] }}</div>
                <div class="category-subtitle">{{ $cat['subtitle'] }}</div>
            </div>
            <span class="category-count">{{ count($catAgents) }} agent{{ count($catAgents) === 1 ? '' : 's' }}</span>
        </div>

        <div class="agents-grid">
            @foreach($catAgents as $agent)
                @php
                    $href    = isset($agent['special']) && $agent['key'] === 'briefing' ? '/briefing' : '/chat?agent=' . $agent['key'];
                    $imgPath = null;
                    foreach (['.png', '.jpg', '.jpeg', '.webp'] as $ext) {
                        if (file_exists(public_path('images/agents/' . $agent['key'] . $ext))) {
                            $imgPath = '/images/agents/' . $agent['key'] . $ext;
                            break;
                        }
                    }
                    $isSpecial = isset($agent['special']) && $agent['special'];
                    // Search index: everything a user might type to find this agent
                    $searchIdx = mb_strtolower($agent['name'] . ' ' . $agent['role'] . ' ' . $agent['key'] . ' ' . $catKey);
                @endphp

                <a href="{{ $href }}"
                   class="agent-card {{ $isSpecial ? 'briefing-card' : '' }}"
                   data-search="{{ $searchIdx }}"
                   data-agent-key="{{ $agent['key'] }}"
                   style="--card-color: {{ $agent['color'] }}">
                    <button type="button" class="fav-btn" title="Adicionar aos favoritos" aria-label="Toggle favorite">★</button>
                    <button type="button" class="info-btn" data-agent-profile="{{ $agent['key'] }}" title="Ver perfil do agente" aria-label="Agent profile">ℹ</button>
                    <div class="status-dot" style="{{ $isSpecial ? 'background:#00aaff;box-shadow:0 0 8px #00aaff' : 'background:' . $agent['color'] . ';box-shadow:0 0 6px ' . $agent['color'] }}"></div>
                    <div class="avatar">
                        @if($imgPath)
                            <img src="{{ $imgPath }}" alt="{{ $agent['name'] }}">
                        @else
                            {{ $agent['emoji'] }}
                        @endif
                    </div>
                    <div class="agent-name">{{ $agent['name'] }}</div>
                    <div class="agent-role">{{ $agent['role'] }}</div>
                    @if($isSpecial)
                        <span class="talk-btn">Generate Briefing →</span>
                    @else
                        <span class="talk-btn">Chat</span>
                    @endif
                </a>
            @endforeach
        </div>
    </section>
    @endif {{-- /if($cat) --}}
@endforeach

<div class="empty-state" id="emptyState">
    <div class="big">🔍</div>
    <h3>Nenhum agente encontrado</h3>
    <p>Tenta outras palavras-chave (ex: "fatura", "patent", "NSN", "UPS", "naval")</p>
</div>

</div>

<script>
// ── THEME TOGGLE ──────────────────────────────────────────
(function () {
    const root = document.documentElement;
    const btn  = document.getElementById('themeToggle');
    if (!btn) return;

    // Default to dark if nothing set
    const current = root.getAttribute('data-theme') || 'dark';
    if (!root.hasAttribute('data-theme')) root.setAttribute('data-theme', current);

    btn.addEventListener('click', () => {
        const next = root.getAttribute('data-theme') === 'light' ? 'dark' : 'light';
        root.setAttribute('data-theme', next);
        try { localStorage.setItem('cy-theme', next); } catch (e) {}
    });
})();

// ── FAVORITES ─────────────────────────────────────────────
(function () {
    const STORAGE_KEY = 'cy-favorites';
    const section = document.getElementById('favoritesSection');
    const grid    = document.getElementById('favoritesGrid');
    const count   = document.getElementById('favoritesCount');
    if (!section || !grid) return;

    function readFavs() {
        try {
            const raw = localStorage.getItem(STORAGE_KEY);
            const arr = raw ? JSON.parse(raw) : [];
            return Array.isArray(arr) ? arr : [];
        } catch (e) { return []; }
    }
    function writeFavs(arr) {
        try { localStorage.setItem(STORAGE_KEY, JSON.stringify(arr)); } catch (e) {}
    }

    function refreshFavoritesSection() {
        const favs = readFavs();
        grid.innerHTML = '';

        // Build clones in favorite-order
        favs.forEach(key => {
            const src = document.querySelector('.category-section:not(#favoritesSection) .agent-card[data-agent-key="' + key + '"]');
            if (!src) return;
            const clone = src.cloneNode(true);
            clone.setAttribute('data-fav-clone', '1');
            // Re-attach fav button handler on clone
            const favBtn = clone.querySelector('.fav-btn');
            if (favBtn) {
                favBtn.addEventListener('click', (e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    toggleFavorite(key);
                });
            }
            grid.appendChild(clone);
        });

        // Update originals' is-favorite class
        document.querySelectorAll('.agent-card[data-agent-key]').forEach(card => {
            if (card.hasAttribute('data-fav-clone')) return; // only originals
            const k = card.getAttribute('data-agent-key');
            card.classList.toggle('is-favorite', favs.includes(k));
        });

        // Show/hide section
        section.classList.toggle('has-items', favs.length > 0);
        count.textContent = favs.length + ' ' + (favs.length === 1 ? 'agent' : 'agents');
    }

    function toggleFavorite(key) {
        const favs = readFavs();
        const idx  = favs.indexOf(key);
        if (idx >= 0) favs.splice(idx, 1);
        else          favs.push(key);
        writeFavs(favs);
        refreshFavoritesSection();
    }

    // Wire up original fav buttons
    document.querySelectorAll('.category-section:not(#favoritesSection) .agent-card .fav-btn').forEach(btn => {
        btn.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            const card = btn.closest('.agent-card');
            const key  = card?.getAttribute('data-agent-key');
            if (key) toggleFavorite(key);
        });
    });

    // Profile info buttons — navigate to /agents/{key} without triggering
    // the outer card's /chat link. Delegated so cloned cards in the
    // Favorites section work too.
    document.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-agent-profile]');
        if (!btn) return;
        e.preventDefault();
        e.stopPropagation();
        const key = btn.getAttribute('data-agent-profile');
        if (key) window.location.href = '/agents/' + encodeURIComponent(key);
    });

    // ── Drag-reorder of favorites (futurista round 4) ──────────────
    // Make every favorite-clone draggable so the user can re-order
    // their bookmarked agents by drag. The order persists via the
    // existing favs[] in localStorage (writeFavs).
    function enableReorder() {
        const cards = grid.querySelectorAll('.agent-card[data-fav-clone]');
        cards.forEach(card => {
            card.setAttribute('draggable', 'true');
            // Visual cue on hover only — no permanent grip handle so
            // the look stays clean for users who don't reorder.
            card.style.cursor = 'grab';
        });
    }
    let dragSrc = null;
    grid.addEventListener('dragstart', (e) => {
        const card = e.target.closest('.agent-card');
        if (!card || !card.hasAttribute('data-fav-clone')) return;
        dragSrc = card;
        card.style.opacity = '0.4';
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/plain', card.getAttribute('data-agent-key') || '');
    });
    grid.addEventListener('dragend', (e) => {
        if (dragSrc) dragSrc.style.opacity = '';
        dragSrc = null;
    });
    grid.addEventListener('dragover', (e) => {
        if (!dragSrc) return;
        e.preventDefault();
        const target = e.target.closest('.agent-card[data-fav-clone]');
        if (!target || target === dragSrc) return;
        const rect = target.getBoundingClientRect();
        // Insert before or after based on cursor position relative to
        // the target's horizontal centre.
        const before = (e.clientX - rect.left) < rect.width / 2;
        if (before) target.parentNode.insertBefore(dragSrc, target);
        else        target.parentNode.insertBefore(dragSrc, target.nextSibling);
    });
    grid.addEventListener('drop', (e) => {
        if (!dragSrc) return;
        e.preventDefault();
        // Read the new order from the DOM and persist.
        const newOrder = Array.from(grid.querySelectorAll('.agent-card[data-fav-clone]'))
            .map(c => c.getAttribute('data-agent-key'))
            .filter(Boolean);
        writeFavs(newOrder);
        // Toast the user so they know it stuck.
        if (window.cyToast) {
            window.cyToast({ title: '✓ Ordem dos favoritos guardada', tone: 'success', duration: 2000 });
        }
        dragSrc = null;
    });

    // Re-enable drag attribute every time the favorites section
    // refreshes (cloned cards lose attributes otherwise).
    const _origRefresh = refreshFavoritesSection;
    refreshFavoritesSection = function () {
        _origRefresh();
        enableReorder();
    };

    // Initial render
    refreshFavoritesSection();
})();

// ── SEARCH FILTER ─────────────────────────────────────────
(function () {
    const input = document.getElementById('agentSearch');
    const clear = document.getElementById('searchClear');
    const empty = document.getElementById('emptyState');
    const sections = document.querySelectorAll('.category-section');
    if (!input) return;

    function norm(s) {
        return (s || '').toString().toLowerCase()
            .normalize('NFD').replace(/[\u0300-\u036f]/g, ''); // strip accents
    }

    function applyFilter(q) {
        const query = norm(q.trim());
        let totalVisible = 0;

        sections.forEach(section => {
            const cards = section.querySelectorAll('.agent-card');
            let visibleInSection = 0;
            cards.forEach(card => {
                const idx = norm(card.getAttribute('data-search') || '');
                const matches = !query || idx.includes(query);
                card.classList.toggle('hidden-by-search', !matches);
                if (matches) visibleInSection++;
            });
            section.classList.toggle('empty', visibleInSection === 0);
            totalVisible += visibleInSection;

            // Update category count on-the-fly if searching
            const countEl = section.querySelector('.category-count');
            if (countEl) {
                if (query) {
                    countEl.textContent = visibleInSection + ' of ' + cards.length;
                } else {
                    countEl.textContent = cards.length + (cards.length === 1 ? ' agent' : ' agents');
                }
            }
        });

        empty.classList.toggle('visible', totalVisible === 0);
        clear.classList.toggle('visible', !!query);
    }

    input.addEventListener('input', (e) => applyFilter(e.target.value));
    clear.addEventListener('click', () => {
        input.value = '';
        applyFilter('');
        input.focus();
    });

    // '/' key focuses search (power users)
    document.addEventListener('keydown', (e) => {
        if (e.key === '/' && document.activeElement !== input && !e.metaKey && !e.ctrlKey && !e.altKey) {
            e.preventDefault();
            input.focus();
            input.select();
        }
        if (e.key === 'Escape' && document.activeElement === input) {
            input.value = '';
            applyFilter('');
            input.blur();
        }
    });
})();
</script>

@include('partials.keyboard-shortcuts')
@include('partials.command-palette')
@include('partials.toast-system')
@include('partials.view-transitions')
@include('partials.cheat-sheet')
@include('partials.global-dropzone')
@include('partials.presence')
@include('partials.activity-meter')

{{-- ─── Futurista UX layer (added 2026-05-01) ─────────────────
     1. Scroll-aware shrink of the sticky header
     2. Cursor-tracked highlight on agent cards (via CSS vars)
     Pure-vanilla JS, ~30 lines, no framework cost.
--}}
<script>
(function () {
    // 1) Header shrink on scroll. Threshold = 80px so a tiny scroll
    //    doesn't trigger the transition repeatedly.
    const header = document.querySelector('.header');
    if (header) {
        const onScroll = () => {
            const past = window.scrollY > 80;
            header.classList.toggle('is-scrolled', past);
        };
        window.addEventListener('scroll', onScroll, { passive: true });
        onScroll();
    }

    // 1b) Live activity ticker. Polls /api/activity-feed every 30s
    //     and renders items twice in the track (so the keyframe that
    //     translates -50% creates a seamless infinite scroll).
    const tickerEl   = document.getElementById('cy-ticker');
    const trackEl    = document.getElementById('cy-ticker-track');
    function escTick(s) {
        return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }
    async function refreshTicker() {
        if (!trackEl) return;
        try {
            const r = await fetch('/api/activity-feed', { headers: { 'Accept':'application/json' }, credentials:'same-origin' });
            if (!r.ok) return;
            const data = await r.json();
            const items = data.items || [];
            if (items.length === 0) {
                tickerEl.style.display = 'none';
                return;
            }
            const renderOne = (it) => {
                const inner = `${escTick(it.icon || '·')} ${escTick(it.label)}<span class="ti-ago">· ${escTick(it.ago)}</span>`;
                return it.url
                    ? `<a class="ticker-item" href="${escTick(it.url)}">${inner}</a><span class="ti-sep">·</span>`
                    : `<span class="ticker-item">${inner}</span><span class="ti-sep">·</span>`;
            };
            // Duplicate the list so the -50% keyframe loops seamlessly.
            const html = items.map(renderOne).join('');
            trackEl.innerHTML = html + html;
            tickerEl.style.display = 'flex';
        } catch (e) { /* silent */ }
    }
    refreshTicker();
    setInterval(refreshTicker, 30000);

    // 2) Cursor-tracked highlight + 3D tilt on every .agent-card.
    //    We update --mx and --my CSS vars + a CSS transform on
    //    each mousemove. Disabled on touch devices and when the
    //    user prefers reduced motion.
    const reduceMotion = window.matchMedia &&
        window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const isTouch = 'ontouchstart' in window && !window.matchMedia('(hover: hover)').matches;
    if (!reduceMotion && !isTouch) {
        document.querySelectorAll('.agent-card').forEach((card) => {
            card.addEventListener('mousemove', (e) => {
                const rect = card.getBoundingClientRect();
                const x = e.clientX - rect.left;
                const y = e.clientY - rect.top;
                card.style.setProperty('--mx', x + 'px');
                card.style.setProperty('--my', y + 'px');
                // Tilt: ±5° based on cursor position relative to centre.
                const tiltX = ((y / rect.height) - 0.5) * -5;
                const tiltY = ((x / rect.width)  - 0.5) *  5;
                card.style.transform =
                    'translateY(-6px) perspective(900px) ' +
                    'rotateX(' + tiltX.toFixed(2) + 'deg) ' +
                    'rotateY(' + tiltY.toFixed(2) + 'deg)';
            });
            card.addEventListener('mouseleave', () => {
                card.style.transform = '';
                card.style.setProperty('--mx', '50%');
                card.style.setProperty('--my', '50%');
            });
        });
    }
})();
</script>

</body>
</html>
