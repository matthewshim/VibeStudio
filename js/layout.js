/**
 * layout.js — Vibe Studio Shared Layout Components
 * Injects common nav + footer into about.html / contact.html
 * Place <script src="js/layout.js"> at end of <body>.
 * Target elements: <nav id="site-nav"> and <footer id="site-footer">
 */
(function () {
    // Detect current page for active link
    const page = (location.pathname.split('/').pop() || 'index.html').toLowerCase();
    function act(p) { return page === p ? ' active' : ''; }

    // ── Shared Nav ───────────────────────────────────────────
    const NAV_HTML = `
        <div class="hamburger-wrap">
            <button class="hamburger-btn" id="hamburgerBtn" onclick="openNavDrawer()" aria-label="메뉴 열기">
                <span></span><span></span><span></span>
            </button>
        </div>
        <a class="nav-brand" href="index.html">
            <div class="lightning">
                <svg viewBox="0 0 24 24"><path d="M13 2L3 14H12L11 22L21 10H12L13 2Z"/></svg>
            </div>
            Vibe Studio
        </a>
        <nav class="nav-center-about">
            <a class="nav-link-about${act('index.html')}" href="index.html">Home</a>
            <div class="nav-sep-about"></div>
            <a class="nav-link-about${act('about.html')}" href="about.html">About</a>
            <div class="nav-sep-about"></div>
            <a class="nav-link-about${act('contact.html')}" href="contact.html">Contact</a>
        </nav>
        <div class="nav-right">
            <button class="theme-btn" id="themeBtn" onclick="if(window.toggleTheme) toggleTheme()" title="테마 전환">
                <i data-lucide="moon" id="themeIcon"></i>
            </button>
        </div>`;

    // ── Shared Footer ────────────────────────────────────────
    const FOOTER_HTML = `
        <div class="footer-inner-about">
            <div class="footer-brand-about">
                <div class="lightning" style="width:22px;height:22px;border-radius:6px;">
                    <svg viewBox="0 0 24 24" style="width:12px;height:12px;fill:#fff;stroke:none;"><path d="M13 2L3 14H12L11 22L21 10H12L13 2Z"/></svg>
                </div>
                Vibe Studio
            </div>
            <nav class="footer-nav-about">
                <a href="about.html">About</a>
                <a href="contact.html">Contact</a>
                <button onclick="window.location.href='index.html?admin=1'">Administrator</button>
            </nav>
        </div>
        <div class="footer-copy-about">
            &copy; 2026 Vibe Studio. All rights reserved. &nbsp;&middot;&nbsp; Powered by <strong>Prisincera</strong>
        </div>`;

    // ── Inject components (script runs at end of body) ───────
    const navEl = document.getElementById('site-nav');
    if (navEl) navEl.innerHTML = NAV_HTML;

    const footerEl = document.getElementById('site-footer');
    if (footerEl) footerEl.innerHTML = FOOTER_HTML;

    // ── Theme icon initialisation ────────────────────────────
    var storedTheme = localStorage.getItem('vibe-theme') ||
                      document.documentElement.getAttribute('data-theme') || 'dark';
    var icon = document.getElementById('themeIcon');
    if (icon) icon.setAttribute('data-lucide', storedTheme === 'dark' ? 'sun' : 'moon');

    // ── Render lucide icons if library loaded ───────────────
    if (window.lucide) lucide.createIcons();
})();
