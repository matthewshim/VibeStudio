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
        /* nav center links */
        '.shared-nav-center{display:flex;align-items:center;gap:4px;position:absolute;left:50%;transform:translateX(-50%);}',
        '@media(max-width:768px){.shared-nav-center{display:none;}}',
        '.shared-nav-sep{width:1px;height:14px;background:var(--glass-border,rgba(255,255,255,0.18));opacity:.6;margin:0 2px;flex-shrink:0;}',
        '.shared-nav-link{font-size:13px;font-weight:600;color:var(--text-muted);text-decoration:none;padding:5px 10px;border-radius:8px;transition:color .15s,background .15s;}',
        '.shared-nav-link:hover{color:var(--text-main);background:rgba(255,255,255,0.07);}',
        '.shared-nav-link.active{color:var(--text-main);font-weight:700;}',

        /* footer */
        '.shared-footer-inner{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;max-width:900px;margin:0 auto 8px;}',
        '.shared-footer-brand{display:flex;align-items:center;gap:8px;font-weight:800;font-size:13px;color:var(--text-main);}',
        '.shared-footer-nav{display:flex;align-items:center;gap:2px;}',
        '.shared-footer-nav a,.shared-footer-nav button{font-size:12px;font-weight:600;color:var(--text-muted);text-decoration:none;padding:4px 10px;border-radius:6px;background:none;border:none;cursor:pointer;transition:color .15s,background .15s;}',
        '.shared-footer-nav a:hover,.shared-footer-nav button:hover{color:var(--text-main);background:rgba(255,255,255,0.08);}',
        '.shared-footer-copy{font-size:11px;color:var(--text-muted);opacity:.7;text-align:center;}'
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
