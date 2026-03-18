/**
 * layout.js — Vibe Studio Shared Layout Components (Self-Contained)
 * - Injects shared CSS into <head> (no dependency on page CSS)
 * - Injects nav into <nav id="site-nav">
 * - Injects footer into <footer id="site-footer">
 * - Handles theme icon, lucide, Administrator button
 */
(function () {

    // ── 1. Inject shared CSS ──────────────────────────────────
    var css = [
        /* ── top-nav: 모든 페이지 공통 (단일 소스) ── */
        '.top-nav{position:fixed;top:0;left:0;right:0;height:56px;' +
            'background:rgba(8,6,18,0.86);' +
            'backdrop-filter:blur(28px) saturate(180%);' +
            '-webkit-backdrop-filter:blur(28px) saturate(180%);' +
            'border-bottom:1px solid var(--glass-border,rgba(255,255,255,0.12));' +
            'display:flex;align-items:center;justify-content:space-between;' +
            'padding:0 22px;z-index:10000;box-sizing:border-box;color:var(--text-main,#f5f5f7);}',
        '[data-theme="light"] .top-nav{background:rgba(245,245,247,0.88);}',

        /* ── nav-brand + lightning 로고 ── */
        '.nav-brand{display:flex;align-items:center;gap:10px;' +
            'font-weight:800;font-size:15px;letter-spacing:-0.3px;' +
            'color:var(--text-main,#f5f5f7);text-decoration:none;}',
        '.nav-brand .lightning{width:28px;height:28px;' +
            'background:linear-gradient(135deg,#a855f7,#5e5ce6);' +
            'border-radius:8px;display:flex;align-items:center;' +
            'justify-content:center;flex-shrink:0;}',
        '.nav-brand .lightning svg{width:16px;height:16px;fill:#fff;stroke:none;}',

        /* ── nav-right + theme-btn ── */
        '.nav-right{display:flex;align-items:center;gap:12px;}',
        '.theme-btn{width:36px;height:36px;border-radius:8px;border:none;' +
            'background:transparent;cursor:pointer;' +
            'display:flex;align-items:center;justify-content:center;' +
            'color:var(--text-main,#f5f5f7);transition:background .15s,color .15s;}',
        '.theme-btn:hover{background:rgba(128,128,128,0.1);}',
        '.theme-btn i,.theme-btn svg{width:18px!important;height:18px!important;}',

        /* ── hamburger (모바일) ── */
        '.hamburger-wrap{display:none;}',
        '.hamburger-btn{display:flex;flex-direction:column;justify-content:center;' +
            'gap:5px;background:none;border:none;cursor:pointer;' +
            'padding:6px;border-radius:6px;}',
        '.hamburger-btn span{display:block;width:20px;height:2px;' +
            'background:var(--text-main,#f5f5f7);border-radius:2px;transition:opacity .2s;}',

        /* ── 모바일 그리드 레이아웃 ── */
        '@media(max-width:768px){' +
            '.hamburger-wrap{display:flex;align-items:center;}' +
            '.top-nav{display:grid;grid-template-columns:40px 1fr 40px;align-items:center;padding:0 14px;}' +
            '.nav-brand{grid-column:2;justify-content:center;}' +
            '.nav-right{grid-column:3;justify-content:flex-end;gap:8px;}' +
        '}',

        /* ── shared-nav-center (PC 중앙 링크) ── */
        '.shared-nav-center{display:flex;align-items:center;gap:4px;position:absolute;left:50%;transform:translateX(-50%);}',
        '@media(max-width:768px){.shared-nav-center{display:none;}}',
        '.shared-nav-sep{width:1px;height:14px;background:var(--glass-border,rgba(255,255,255,0.18));opacity:.6;margin:0 2px;flex-shrink:0;}',
        '.shared-nav-link{font-size:13px;font-weight:600;color:var(--text-muted,rgba(255,255,255,.5));text-decoration:none;padding:5px 10px;border-radius:8px;transition:color .15s,background .15s;}',
        '.shared-nav-link:hover{color:var(--text-main,#f5f5f7);background:rgba(255,255,255,0.07);}',
        '.shared-nav-link.active{color:var(--text-main,#f5f5f7);font-weight:700;}',

        /* ── footer ── */
        '.shared-footer-inner{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;max-width:900px;margin:0 auto 8px;}',
        '.shared-footer-brand{display:flex;align-items:center;gap:8px;font-weight:800;font-size:13px;color:var(--text-main,#f5f5f7);}',
        '.shared-footer-nav{display:flex;align-items:center;gap:2px;}',
        '.shared-footer-nav a,.shared-footer-nav button{font-size:12px;font-weight:600;color:var(--text-muted,rgba(255,255,255,.5));text-decoration:none;padding:4px 10px;border-radius:6px;background:none;border:none;cursor:pointer;transition:color .15s,background .15s;}',
        '.shared-footer-nav a:hover,.shared-footer-nav button:hover{color:var(--text-main,#f5f5f7);background:rgba(255,255,255,0.08);}',
        '.shared-footer-copy{font-size:11px;color:var(--text-muted,rgba(255,255,255,.5));opacity:.7;text-align:center;}'
    ].join('');

    var styleEl = document.createElement('style');
    styleEl.id = 'shared-layout-css';
    styleEl.textContent = css;
    document.head.appendChild(styleEl);

    // ── 2. Active page detection ──────────────────────────────
    var page = (location.pathname.split('/').pop() || 'index.html').toLowerCase();
    function act(p) { return page === p ? ' active' : ''; }

    // ── 3. Nav HTML ───────────────────────────────────────────
    var NAV_HTML = ''
        + '<div class="hamburger-wrap">'
        +   '<button class="hamburger-btn" id="hamburgerBtn" onclick="openNavDrawer()" aria-label="메뉴 열기">'
        +     '<span></span><span></span><span></span>'
        +   '</button>'
        + '</div>'
        + '<a class="nav-brand" href="index.html">'
        +   '<div class="lightning">'
        +     '<svg viewBox="0 0 24 24"><path d="M13 2L3 14H12L11 22L21 10H12L13 2Z"/></svg>'
        +   '</div>Vibe Studio'
        + '</a>'
        + '<nav class="shared-nav-center">'
        +   '<a class="shared-nav-link' + act('index.html') + '" href="index.html">Home</a>'
        +   '<div class="shared-nav-sep"></div>'
        +   '<a class="shared-nav-link' + act('about.html') + '" href="about.html">About</a>'
        +   '<div class="shared-nav-sep"></div>'
        +   '<a class="shared-nav-link' + act('contact.html') + '" href="contact.html">Contact</a>'
        + '</nav>'
        + '<div class="nav-right">'
        +   '<button class="theme-btn" id="themeBtn" onclick="if(window.toggleTheme)toggleTheme()" title="테마 전환">'
        +     '<i data-lucide="moon" id="themeIcon"></i>'
        +   '</button>'
        + '</div>';

    // ── 4. Footer HTML ────────────────────────────────────────
    var FOOTER_HTML = ''
        + '<div class="shared-footer-inner">'
        +   '<div class="shared-footer-brand">'
        +     '<div class="lightning" style="width:22px;height:22px;border-radius:6px;">'
        +       '<svg viewBox="0 0 24 24" style="width:12px;height:12px;fill:#fff;stroke:none;"><path d="M13 2L3 14H12L11 22L21 10H12L13 2Z"/></svg>'
        +     '</div>Vibe Studio'
        +   '</div>'
        +   '<nav class="shared-footer-nav">'
        +     '<a href="about.html">About</a>'
        +     '<a href="contact.html">Contact</a>'
        +     '<a href="index.html?admin=1">Administrator</a>'
        +   '</nav>'
        + '</div>'
        + '<div class="shared-footer-copy">'
        +   '&copy; 2026 Vibe Studio. All rights reserved. &nbsp;&middot;&nbsp; Powered by <strong>Prisincera</strong>'
        + '</div>';

    // ── 5. Inject nav ─────────────────────────────────────────
    var navEl = document.getElementById('site-nav');
    if (navEl) navEl.innerHTML = NAV_HTML;

    // ── 6. Inject footer ──────────────────────────────────────
    var footerEl = document.getElementById('site-footer');
    if (footerEl) footerEl.innerHTML = FOOTER_HTML;

    // ── 7. Theme icon init ────────────────────────────────────
    var storedTheme = localStorage.getItem('vibe-theme')
                   || document.documentElement.getAttribute('data-theme')
                   || 'dark';
    var icon = document.getElementById('themeIcon');
    if (icon) icon.setAttribute('data-lucide', storedTheme === 'dark' ? 'sun' : 'moon');

    // ── 8. Render lucide icons ────────────────────────────────
    if (window.lucide) lucide.createIcons();

})();
