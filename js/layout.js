/**
 * layout.js — Vibe Studio Shared Layout Components (Self-Contained)
 * - Injects shared CSS into <head> (no dependency on page CSS)
 * - Injects nav into <nav id="site-nav">
 * - Injects footer into <footer id="site-footer">
 * - Handles theme icon, lucide, Administrator button
 */
(function () {

    // ── 0. Root path (支援 apps/x/landing.html 等 하위 경로) ──
    var root = (typeof window.LAYOUT_ROOT === 'string') ? window.LAYOUT_ROOT : '';

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
            'background:linear-gradient(135deg,#a855f7,#d946ef);' +
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
        /* nav-admin-btn — theme-btn과 동일 스타일 (admin app의 .admin-btn과 충돌 방지) */
        '.nav-admin-btn{width:36px;height:36px;border-radius:8px;border:none;' +
            'background:transparent;cursor:pointer;' +
            'display:flex;align-items:center;justify-content:center;' +
            'color:var(--text-main,#f5f5f7);transition:background .15s,color .15s;}',
        '.nav-admin-btn:hover{background:rgba(128,128,128,0.1);color:var(--accent,#818cf8);}',
        '.nav-admin-btn svg{width:18px!important;height:18px!important;}',

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
            '.top-nav{display:grid;grid-template-columns:40px 1fr auto;align-items:center;padding:0 14px;}' +
            '.nav-brand{grid-column:2;justify-content:center;}' +
            '.nav-right{grid-column:3;justify-content:flex-end;gap:4px;}' +
        '}',

        /* ── shared-nav-center (PC 중앙 링크) ── */
        '.shared-nav-center{display:flex;align-items:center;gap:4px;position:absolute;left:50%;transform:translateX(-50%);}',
        '@media(max-width:768px){.shared-nav-center{display:none;}}',
        '.shared-nav-sep{width:1px;height:14px;background:var(--glass-border,rgba(255,255,255,0.18));opacity:.6;margin:0 2px;flex-shrink:0;}',
        '.shared-nav-link{font-size:13px;font-weight:600;color:var(--text-muted,rgba(255,255,255,.5));text-decoration:none;padding:5px 10px;border-radius:8px;transition:color .15s,background .15s;}',
        '.shared-nav-link:hover{color:var(--text-main,#f5f5f7);background:rgba(255,255,255,0.07);}',
        '.shared-nav-link.active{color:var(--text-main,#f5f5f7);font-weight:700;}',
        /* Signal / Apps / Support — 통일 스타일 (별도 강조 없음) */

        /* ── footer ── */
        '.footer-inner{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:6px;max-width:900px;margin:0 auto;text-align:center;}',
        '.footer-brand{display:flex;align-items:center;gap:8px;font-weight:800;font-size:13px;color:var(--text-main,#f5f5f7);}',
        '.footer-copy{font-size:11px;color:var(--text-muted,rgba(255,255,255,.5));opacity:.6;}'
    ].join('');

    var styleEl = document.createElement('style');
    styleEl.id = 'shared-layout-css';
    styleEl.textContent = css;
    document.head.appendChild(styleEl);

    // ── 2. Active page detection ──────────────────────────────
    var page = (location.pathname.split('/').pop() || '').replace(/\.html$/, '').toLowerCase();
    if (!page || page === 'index') page = '';
    // /signal/* 하위 경로 전체를 'signal' active로 처리
    var pathParts = location.pathname.split('/').filter(function(p){ return p; });
    if (pathParts.length > 0 && pathParts[0] === 'signal') page = 'signal';
    function act(p) { return page === p ? ' active' : ''; }

    // ── 3. Nav HTML ───────────────────────────────────────────
    var NAV_HTML = ''
        + '<div class="hamburger-wrap">'
        +   '<button class="hamburger-btn" id="hamburgerBtn" onclick="openNavDrawer()" aria-label="메뉴 열기">'
        +     '<span></span><span></span><span></span>'
        +   '</button>'
        + '</div>'
        + '<a class="nav-brand" href="/">'
        +   '<div class="lightning">'
        +     '<svg viewBox="0 0 28 28"><path fill-rule="evenodd" fill="white" d="M14,9.5 C15.5,7.8 18.2,7 20.8,7.8 C23.4,8.6 24.8,11 24.2,13.2 C23.6,15.4 21.4,17.2 18.8,17.8 C16.8,18.2 15.2,17 14,15.8 C12.8,17 11.2,18.2 9.2,17.8 C6.6,17.2 4.4,15.4 3.8,13.2 C3.2,11 4.6,8.6 7.2,7.8 C9.8,7 12.5,7.8 14,9.5 Z M8.8,10.2 C10.2,9.4 12.2,10.6 12.2,13 C12.2,15.4 10.2,16.6 8.8,16.2 C7.4,15.8 5.8,14.2 5.8,13 C5.8,11.5 7.4,10.8 8.8,10.2 Z M19.2,10.2 C20.6,10.8 22.2,11.5 22.2,13 C22.2,14.2 20.6,15.8 19.2,16.2 C17.8,16.6 15.8,15.4 15.8,13 C15.8,10.6 17.8,9.4 19.2,10.2 Z"/></svg>'
        +   '</div>Vibe Studio'
        + '</a>'
        + '<nav class="shared-nav-center">'
        +   '<a class="shared-nav-link' + act('') + '" href="/">Home</a>'
        +   '<div class="shared-nav-sep"></div>'
        +   '<a class="shared-nav-link' + act('history') + '" href="' + root + 'history">History</a>'
        +   '<div class="shared-nav-sep"></div>'
        +   '<a class="shared-nav-link' + act('signal') + '" href="' + root + 'signal">Signal</a>'
        +   '<div class="shared-nav-sep"></div>'
        +   '<a class="shared-nav-link' + act('about') + '" href="' + root + 'about">Apps</a>'
        +   '<div class="shared-nav-sep"></div>'
        +   '<a class="shared-nav-link' + act('donating') + '" href="' + root + 'donating">Support</a>'
        +   '<div class="shared-nav-sep"></div>'
        +   '<a class="shared-nav-link' + act('contact') + '" href="' + root + 'contact">Contact</a>'
        + '</nav>'
        + '<div class="nav-right">'
        +   '<button class="theme-btn" id="themeBtn" onclick="if(window.toggleTheme)toggleTheme()" title="테마 전환">'
        +     '<i data-lucide="moon" id="themeIcon"></i>'
        +   '</button>'
        +   '<button class="nav-admin-btn" id="navAdminBtn" title="Admin Panel" onclick="if(window.openAdmin){openAdmin();}else{location.href=\'/?admin=1\';}" >'
        +     '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'
        +       '<circle cx="12" cy="12" r="3"/>'
        +       '<path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>'
        +     '</svg>'
        +   '</button>'
        + '</div>';

    // ── 4. Footer HTML (로고 + 카피라이트만) ─────────────────────
    var FOOTER_HTML = ''
        + '<div class="footer-inner">'
        +   '<div class="footer-brand">'
        +     '<i data-lucide="zap" style="width:14px;height:14px;"></i>'
        +     'Vibe Studio'
        +   '</div>'
        +   '<div class="footer-copy">'
        +     '&copy; 2026 Vibe Studio. All rights reserved. &nbsp;&middot;&nbsp; Powered by <strong>Prisincera</strong>'
        +   '</div>'
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
