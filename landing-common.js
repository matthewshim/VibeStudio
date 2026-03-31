/* Vibe Studio Landing Pages — Common JS */
(function(){
    // Spotlight
    document.addEventListener('mousemove',function(e){
        var s=document.getElementById('spotlight');
        if(s){s.style.left=e.clientX+'px';s.style.top=e.clientY+'px'}
    });
})();

/* Nav Drawer (layout.js의 openNavDrawer/closeNavDrawer 보완용) */
function openNavDrawer(){
    var d=document.getElementById('navDrawer');
    var o=document.getElementById('navDrawerOverlay');
    var b=document.getElementById('hamburgerBtn');
    if(d)d.classList.add('open');
    if(o)o.classList.add('open');
    if(b)b.classList.add('open');
    document.body.style.overflow='hidden';
}
function closeNavDrawer(){
    var d=document.getElementById('navDrawer');
    var o=document.getElementById('navDrawerOverlay');
    var b=document.getElementById('hamburgerBtn');
    if(d)d.classList.remove('open');
    if(o)o.classList.remove('open');
    if(b)b.classList.remove('open');
    document.body.style.overflow='';
}
document.addEventListener('keydown',function(e){if(e.key==='Escape')closeNavDrawer()});

/* ──────────────────────────────────────────────────────────
   Landing 페이지 앱 활성화 상태 체크
   - 현재 landing.html 파일명으로 앱 키를 추출
   - OFF인 경우 app_unavailable.html 로 리다이렉트
   - .related-dock-item 중 OFF 앱은 숨김 처리
   ────────────────────────────────────────────────────────── */
(function(){
    // 루트 경로 (apps/xxx/landing.html → ../../)
    var root = (typeof window.LAYOUT_ROOT === 'string') ? window.LAYOUT_ROOT : '../../';

    // 현재 파일 경로에서 앱 키 추출 (예: /apps/tangram/landing.html → 'tangram')
    var pathParts = location.pathname.split('/');
    var landingIdx = pathParts.indexOf('landing.html');
    var currentAppKey = (landingIdx > 0) ? pathParts[landingIdx - 1] : null;

    // related 아이템의 landing URL에서 앱 키 추출 헬퍼
    function keyFromLandingHref(href) {
        var m = href.match(/\/apps\/([^\/]+)\/landing\.html/);
        if (m) return m[1];
        // 상대경로 대응: ../tangram/landing.html
        var m2 = href.match(/\.\.\/([^\/]+)\/landing\.html/);
        return m2 ? m2[1] : null;
    }

    // 앱 이름 매핑
    var APP_NAMES = {
        tangram : 'Tangram',
        pachinko: 'Pachinko',
        server  : 'Server Monitor',
        sys     : 'System Info',
        pdf     : 'PDF Splitter',
        qr      : 'QR Master',
        meter   : 'Clap Meter',
        spell   : 'Spell Check',
    };

    // app_settings_get 호출 (캐시 없이)
    fetch(root + 'admin.php?action=app_settings_get', { cache: 'no-store' })
        .then(function(r){ return r.json(); })
        .then(function(data){
            if(!data.success || !data.settings) return;

            var disabledSet = new Set();
            data.settings.forEach(function(s){
                if(parseInt(s.enabled) === 0) disabledSet.add(s.app_key);
            });

            // 1) 현재 landing.html의 앱이 OFF이면 → 안내 페이지로 리다이렉트
            if(currentAppKey && disabledSet.has(currentAppKey)){
                var appName = APP_NAMES[currentAppKey] || currentAppKey;
                location.replace(
                    root + 'app_unavailable.html'
                    + '?app=' + encodeURIComponent(currentAppKey)
                    + '&name=' + encodeURIComponent(appName)
                );
                return;
            }

            // 2) 하단 related 앱 목록에서 OFF 앱 숨김
            var relatedItems = document.querySelectorAll('.related-dock-item');
            relatedItems.forEach(function(item){
                var href = item.getAttribute('href') || '';
                var key  = keyFromLandingHref(href);
                if(key && disabledSet.has(key)){
                    item.style.display = 'none';
                }
            });

            // 3) related 섹션 전체가 비어있으면 섹션 숨김
            var relatedSection = document.querySelector('.related');
            if(relatedSection){
                var visible = Array.from(relatedSection.querySelectorAll('.related-dock-item'))
                    .filter(function(el){ return el.style.display !== 'none'; });
                if(visible.length === 0) relatedSection.style.display = 'none';
            }
        })
        .catch(function(){ /* 설정 로드 실패 시 모두 표시 유지 */ });
})();
