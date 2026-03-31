/**
 * Vibe Studio Landing Page Template Engine
 * 각 앱의 landing.html을 최소 설정만 남기고 공통 HTML을 렌더링합니다.
 *
 * 사용법: 각 landing.html에서
 *   window.LANDING_CONFIG = { ... }; 를 정의한 뒤
 *   이 스크립트를 로드하면 body를 자동으로 렌더링합니다.
 *
 * 핵심: innerHTML 안의 <script>는 브라우저가 실행하지 않으므로
 *       loadScript() 헬퍼로 layout.js → landing-common.js 순서 보장.
 */
(function () {
    'use strict';

    // 모든 앱 정보 (추천 앱 섹션 렌더용)
    var ALL_APPS = [
        { key: 'spell',    emoji: '✍️',  label: 'Spell Check',   grad: 'linear-gradient(160deg,#c4b5fd,#8b5cf6)' },
        { key: 'qr',       emoji: '🔗',  label: 'QR Master',     grad: 'linear-gradient(160deg,#40b3ff,#007aff)' },
        { key: 'pdf',      emoji: '📄',  label: 'PDF Splitter',  grad: 'linear-gradient(160deg,#ff6b63,#ff3b30)' },
        { key: 'tangram',  emoji: '🧩',  label: 'Tangram',       grad: 'linear-gradient(160deg,#ff8c5a,#ff6b35)' },
        { key: 'pachinko', emoji: '🎰',  label: 'Pachinko',      grad: 'linear-gradient(160deg,#ff5274,#d81b60)' },
        { key: 'server',   emoji: '🖥️', label: 'Server Monitor',grad: 'linear-gradient(160deg,#34d399,#10b981)' },
        { key: 'meter',    emoji: '👏',  label: 'Clap Meter',    grad: 'linear-gradient(160deg,#ffcc00,#ff9500)' },
    ];

    // 스크립트를 동적으로 로드하는 헬퍼
    function loadScript(src, onload) {
        var s = document.createElement('script');
        s.src = src;
        if (typeof onload === 'function') s.onload = onload;
        document.body.appendChild(s);
    }

    function render() {
        var cfg = window.LANDING_CONFIG;
        if (!cfg) { console.error('[landing-template] LANDING_CONFIG is not defined'); return; }

        var root = window.LAYOUT_ROOT || '../../';

        // ── <head> 메타 업데이트 ──────────────────────────────
        document.title = cfg.title + ' | ' + cfg.titleSuffix + ' \u2014 Vibe Studio';

        function setMeta(name, val) {
            var el = document.querySelector('meta[name="' + name + '"]') ||
                     document.querySelector('meta[property="' + name + '"]');
            if (el) el.content = val;
        }
        setMeta('description',    cfg.metaDesc);
        setMeta('og:title',       cfg.title + ' | ' + cfg.titleSuffix + ' \u2014 Vibe Studio');
        setMeta('og:description', cfg.ogDesc);
        setMeta('og:image',       'https://vibestudio.prisincera.com/images/apps/' + cfg.thumb);
        setMeta('og:url',         'https://vibestudio.prisincera.com/apps/' + cfg.appKey + '/landing.html');

        // Schema.org JSON-LD
        document.querySelectorAll('script[type="application/ld+json"]').forEach(function(s){ s.remove(); });

        function addJsonLd(obj) {
            var s = document.createElement('script');
            s.type = 'application/ld+json';
            s.textContent = JSON.stringify(obj);
            document.head.appendChild(s);
        }
        addJsonLd({
            '@context': 'https://schema.org', '@type': 'WebApplication',
            name: cfg.title, description: cfg.schemaDesc,
            url: 'https://vibestudio.prisincera.com/?app=' + cfg.appKey,
            applicationCategory: 'UtilitiesApplication', operatingSystem: 'Web',
            inLanguage: 'ko',
            isPartOf: { '@type': 'WebSite', name: 'Vibe Studio', url: 'https://vibestudio.prisincera.com/' }
        });
        addJsonLd({
            '@context': 'https://schema.org', '@type': 'BreadcrumbList',
            itemListElement: [
                { '@type': 'ListItem', position: 1, name: '\ud648', item: 'https://vibestudio.prisincera.com/' },
                { '@type': 'ListItem', position: 2, name: 'About', item: 'https://vibestudio.prisincera.com/about.html' },
                { '@type': 'ListItem', position: 3, name: cfg.title,
                  item: 'https://vibestudio.prisincera.com/apps/' + cfg.appKey + '/landing.html' }
            ]
        });

        // ── <body> 공통 구조 렌더링 ───────────────────────────
        var relatedHtml = ALL_APPS
            .filter(function(a){ return a.key !== cfg.appKey; })
            .map(function(a){
                return '<a class="related-dock-item" href="../' + a.key + '/landing.html">' +
                    '<div class="related-dock-icon" style="background:' + a.grad + '">' + a.emoji + '</div>' +
                    '<span class="related-dock-label">' + a.label + '</span></a>';
            }).join('');

        var badgeRgb = cfg.colorRgb || '94,92,230';

        // ⚠️ innerHTML 안의 <script>는 브라우저가 실행하지 않으므로 본문 HTML만 설정
        document.body.innerHTML =
            '<div class="nav-drawer-overlay" id="navDrawerOverlay" onclick="closeNavDrawer()"></div>' +
            '<nav class="nav-drawer" id="navDrawer">' +
            '  <div class="nav-drawer-header"><a class="nav-drawer-brand" href="' + root + 'index.html"><div class="lightning"><svg viewBox="0 0 24 24"><path d="M13 2L3 14H12L11 22L21 10H12L13 2Z"/></svg></div>Vibe Studio</a>' +
            '  <button class="nav-drawer-close" onclick="closeNavDrawer()"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button></div>' +
            '  <div class="nav-drawer-body">' +
            '    <a class="nav-drawer-item" href="' + root + 'index.html"><div class="nav-drawer-item-icon" style="background:rgba(168,85,247,.15)"><svg viewBox="0 0 24 24" fill="none" stroke="#a855f7" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg></div>Home</a>' +
            '    <a class="nav-drawer-item" href="' + root + 'about.html"><div class="nav-drawer-item-icon" style="background:rgba(56,189,248,.12)"><svg viewBox="0 0 24 24" fill="none" stroke="#38bdf8" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg></div>About</a>' +
            '    <a class="nav-drawer-item" href="' + root + 'contact.html"><div class="nav-drawer-item-icon" style="background:rgba(52,211,153,.12)"><svg viewBox="0 0 24 24" fill="none" stroke="#34d399" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg></div>Contact</a>' +
            '  </div>' +
            '  <div class="nav-drawer-footer"><div class="nav-drawer-footer-brand"><svg viewBox="0 0 24 24" style="width:12px;height:12px" fill="currentColor"><path d="M13 2L3 14H12L11 22L21 10H12L13 2Z"/></svg>Vibe Studio</div>' +
            '  <div class="nav-drawer-footer-copy">&copy; 2026 Vibe Studio. All rights reserved.<br>Powered by <strong>Prisincera</strong></div></div>' +
            '</nav>' +
            '<div id="spotlight"></div>' +
            '<nav class="top-nav" id="site-nav"></nav>' +
            '<main class="page-main">' +
            '  <article class="landing-card">' +
            '    <div class="thumb-wrap"><img src="' + root + 'images/apps/' + cfg.thumb + '" alt="' + cfg.title + ' ' + cfg.thumbAlt + '" width="600" height="340" loading="eager"></div>' +
            '    <div class="card-body">' +
            '      <div class="badge" style="background:rgba(' + badgeRgb + ',.15);color:' + cfg.color + ';border-color:rgba(' + badgeRgb + ',.3)">Utility &middot; \ubb34\ub8cc</div>' +
            '      <h1>' + cfg.emoji + ' ' + cfg.title + '</h1>' +
            '      <p class="intro">' + cfg.intro + '</p>' +
            '      <div class="desc-box">' + cfg.desc + '</div>' +
            '      <a href="https://vibestudio.prisincera.com/?app=' + cfg.appKey + '" class="launch-btn" style="background:' + cfg.color + '">\u2728 \uc571 \ubc14\ub85c \uc2e4\ud589\ud558\uae30</a>' +
            '    </div>' +
            '  </article>' +
            '  <section class="related" aria-label="\ub2e4\ub978 Vibe Studio \uc571">' +
            '    <div class="related-title">\ub2e4\ub978 \uc571\ub3c4 \uc0b4\ud3b4\ubcf4\uc138\uc694</div>' +
            '    <div class="related-dock">' + relatedHtml + '</div>' +
            '  </section>' +
            '</main>' +
            '<footer class="footer" id="site-footer"></footer>';

        // ── 스크립트 순서 보장 로드 ────────────────────────────
        // layout.js 로드 완료 후 landing-common.js 로드 (순서 의존성)
        window.LAYOUT_ROOT = root;
        loadScript(root + 'js/layout.js', function () {
            loadScript(root + 'landing-common.js');
        });
    }

    // DOM 준비 후 실행
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', render);
    } else {
        render();
    }
})();
