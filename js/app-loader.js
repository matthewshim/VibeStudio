/**
 * Vibe Studio App Loader
 * Handles dynamic loading of web apps within the macOS-like interface.
 */

const loadedApps = new Set();
let currentApp = null;
window.appCleanups = {}; // Store cleanup functions for each app

/**
 * Loads an app's assets (HTML, CSS, JS) if not already loaded.
 * @param {string} appName - The directory name of the app.
 * @returns {Promise<void>}
 */
async function loadApp(appName) {
    const v = Date.now();

    try {
        // 1. HTML은 항상 서버에서 최신으로 fetch (캐시 완전 무시)
        const response = await fetch(`apps/${appName}/app.html?v=${v}`, { cache: 'no-store' });
        if (!response.ok) throw new Error(`HTML not found for ${appName}`);
        const html = await response.text();

        const container = document.getElementById(`app-${appName}`);
        if (container) {
            container.innerHTML = html;
        } else if (appName === 'floating') {
            const div = document.createElement('div');
            div.innerHTML = html;
            document.body.appendChild(div);
        }

        // 2. CSS: 최초 1회만 로드 (중복 <link> 방지)
        if (!loadedApps.has(appName)) {
            document.querySelectorAll(`link[data-app="${appName}"]`).forEach(el => el.remove());
            const link = document.createElement('link');
            link.rel = 'stylesheet';
            link.href = `apps/${appName}/app.css?v=${v}`;
            link.setAttribute('data-app', appName);
            document.head.appendChild(link);

            // 3. JS: 최초 1회만 로드 (중복 script 방지)
            await new Promise((resolve, reject) => {
                const script = document.createElement('script');
                script.src = `apps/${appName}/app.js?v=${v}`;
                script.onload = resolve;
                script.onerror = reject;
                document.body.appendChild(script);
            });

            loadedApps.add(appName);
        }

        // Lucide 아이콘 갱신
        if (window.lucide) {
            window.lucide.createIcons();
        }
    } catch (error) {
        console.error(`Failed to load app: ${appName}`, error);
    }
}

/**
 * Enhanced Switch App function that ensures the app is loaded first.
 */
async function switchApp(appName, titleText, color, iconName) {
    // 1. Map virtual app names to physical names
    let physicalApp = appName;
    if (appName.startsWith('admin-')) physicalApp = 'admin';

    // 2. 다른 앱으로 전환 시 이전 앱 언로드 → 항상 최신 파일 로드 보장
    if (currentApp && currentApp !== physicalApp) {
        if (typeof window.appCleanups[currentApp] === 'function') {
            try { window.appCleanups[currentApp](); } catch(e) {}
        }
        const prevContainer = document.getElementById(`app-${currentApp}`);
        if (prevContainer) prevContainer.innerHTML = '';
        loadedApps.delete(currentApp);
    }

    currentApp = physicalApp;
    window.currentApp = physicalApp; // 공유 팝오버 딥링크용 전역 노출

    // 3. Show window immediately
    const win = document.getElementById('macWindow');
    if (!win) return;
    win.classList.remove('hidden');
    document.getElementById('desktopBranding')?.classList.add('dimmed');
    document.getElementById('orbitLayer')?.classList.add('dimmed');

    // 모바일: 앱 활성화 시 독 슬라이드 아웃
    if (window.innerWidth <= 768) {
        document.body.classList.add('mobile-app-active');
    }

    // 4. Ensure app is loaded (항상 최신 버전)
    if (!loadedApps.has(physicalApp)) {
        const container = document.getElementById(`app-${physicalApp}`);
        if (container) container.innerHTML = '<div style="display:flex;align-items:center;justify-content:center;height:100%;"><div class="mac-loader"></div></div>';
        await loadApp(physicalApp);
    }

    // 4. Update UI
    document.querySelectorAll('.app-view').forEach(v => v.classList.remove('active'));
    document.querySelectorAll('.dock-item').forEach(i => i.classList.remove('active'));

    // If it's a sub-app (like admin-panel), the physicalApp container is what we activate
    const targetApp = document.getElementById(`app-${physicalApp}`);
    if (targetApp) targetApp.classList.add('active');

    const dockItem = document.querySelector(`.dock-item[onclick*="'${appName}'"]`);
    if (dockItem) dockItem.classList.add('active');

    const titleEl = document.getElementById('windowTitleText');
    if (titleEl) titleEl.innerText = titleText;
    
    const iconEl = document.getElementById('windowIcon');
    if (iconEl) iconEl.setAttribute('data-lucide', iconName);
    
    win.style.borderColor = color.startsWith('var') ? color : `rgba(${hexToRgb(color)}, 0.3)`;
    
    if (window.lucide) window.lucide.createIcons();

    // 5. App specific initializations or sub-view toggles
    if (appName === 'admin-panel' || appName === 'admin-login') {
        document.querySelectorAll('.admin-sub-view').forEach(v => v.style.display = 'none');
        const subView = document.getElementById(appName);
        if (subView) subView.style.display = 'block';
    }

    if (physicalApp === 'qr' && typeof initQrScanner === 'function') initQrScanner();
    if (physicalApp === 'tangram' && typeof initTangramApp === 'function') initTangramApp();
    if (physicalApp === 'meter' && typeof resizeMeterCanvas === 'function') setTimeout(resizeMeterCanvas, 150);
    if (physicalApp === 'sys' && typeof updateSystemInfo === 'function') updateSystemInfo();
    if (physicalApp === 'server' && typeof fetchServerStats === 'function') fetchServerStats();
    if (physicalApp === 'spell' && typeof initSpellCheck === 'function') initSpellCheck();

    // 6. 앱 실행 통계 기록 (admin 제외)
    if (physicalApp !== 'admin' && physicalApp !== 'floating') {
        fetch(`admin.php?action=log&type=app_${physicalApp}`, { method: 'POST' }).catch(() => {});
    }
}

function hexToRgb(hex) {
    if (hex.startsWith('var')) return '139, 92, 246'; // Fallback
    hex = hex.replace('#', '');
    if (hex.length === 3) hex = hex.split('').map(c => c + c).join('');
    const r = parseInt(hex.substring(0, 2), 16);
    const g = parseInt(hex.substring(2, 4), 16);
    const b = parseInt(hex.substring(4, 6), 16);
    return `${r}, ${g}, ${b}`;
}

// Global Exports
window.switchApp = switchApp;
window.loadApp = loadApp;

window.openAdmin = async function() {
    await loadApp('admin');
    if (typeof window.openAdminInternal === 'function') {
        window.openAdminInternal();
    }
};

window.closeApp = function() {
    if (!currentApp) return;
    
    console.log(`Cleaning up app: ${currentApp}`);
    
    // 1. Call app-specific cleanup if registered
    if (typeof window.appCleanups[currentApp] === 'function') {
        try {
            window.appCleanups[currentApp]();
        } catch (e) {
            console.error(`Error during cleanup for ${currentApp}:`, e);
        }
    }
    
    // 2. Optionally clear the innerHTML to ensure "process termination" feel
    const container = document.getElementById(`app-${currentApp}`);
    if (container) {
        container.innerHTML = "";
    }
    
    // 3. Remove from loaded set so it reloads fresh next time
    loadedApps.delete(currentApp);
    currentApp = null;
    window.currentApp = null;
};

// Initial Load
document.addEventListener('DOMContentLoaded', () => {
    loadApp('floating');
    // 방문 기록
    fetch('admin.php?action=log&type=visit', { method: 'POST' }).catch(() => {});
});
