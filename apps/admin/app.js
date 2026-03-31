// ==========================================
// Admin Application Logic
// ==========================================

// ── 토스트 알림 ────────────────────────────────────────────
function vibeToast(msg, type) {
    // type: 'success' | 'error' | 'warn' | 'info'
    const colors = {
        success: { bg: 'rgba(52,211,153,0.15)',  border: '#34d399', icon: '✔' },
        error:   { bg: 'rgba(255,69,58,0.15)',   border: '#ff453a', icon: '✖' },
        warn:    { bg: 'rgba(255,159,10,0.15)',  border: '#ff9f0a', icon: '⚠' },
        info:    { bg: 'rgba(94,92,230,0.15)',   border: '#5e5ce6', icon: 'ℹ' },
    };
    const c = colors[type] || colors.info;

    // 컴테이너 확보
    let container = document.getElementById('vibe-toast-container');
    if (!container) {
        container = document.createElement('div');
        container.id = 'vibe-toast-container';
        container.style.cssText = [
            'position:fixed', 'top:60px', 'right:20px', 'z-index:999999',
            'display:flex', 'flex-direction:column', 'gap:8px',
            'pointer-events:none', 'max-width:320px'
        ].join(';');
        document.body.appendChild(container);
    }

    const toast = document.createElement('div');
    toast.style.cssText = [
        'display:flex', 'align-items:center', 'gap:10px',
        'padding:12px 16px',
        'background:' + c.bg,
        'border:1px solid ' + c.border,
        'border-radius:10px',
        'backdrop-filter:blur(20px)',
        '-webkit-backdrop-filter:blur(20px)',
        'box-shadow:0 4px 24px rgba(0,0,0,0.18)',
        'font-size:13px', 'font-weight:500',
        'color:var(--text-main,#1d1d1f)',
        'pointer-events:auto',
        'opacity:0', 'transform:translateX(20px)',
        'transition:opacity 0.25s,transform 0.25s'
    ].join(';');
    toast.innerHTML = '<span style="font-size:14px;flex-shrink:0;">' + c.icon + '</span><span>' + msg + '</span>';
    container.appendChild(toast);

    // 애니메이션
    requestAnimationFrame(() => {
        requestAnimationFrame(() => {
            toast.style.opacity = '1';
            toast.style.transform = 'translateX(0)';
        });
    });

    setTimeout(() => {
        toast.style.opacity = '0';
        toast.style.transform = 'translateX(20px)';
        setTimeout(() => toast.remove(), 260);
    }, 3200);
}
// ── 앱 이름 매핑 ──────────────────────────────────────
const ADMIN_APP_LABELS = {
    app_tangram: '탱그램',
    app_pachinko: '파친코',
    app_server:   '서버 모니터',
    app_sys:      '시스템정보',
    app_pdf:      'PDF',
    app_qr:       'QR',
    app_meter:    '클랩미터',
    app_spell:    '맞춤법',
};
const APP_COLORS = ['#5e5ce6','#a855f7','#34d399','#0a84ff','#ff453a','#ff9f0a','#ff375f','#a78bfa'];

// ── 탭 전환 ────────────────────────────────────────────
window.showAdminTab = function(tab) {
    document.querySelectorAll('.admin-tab').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.admin-panel-view').forEach(v => v.classList.remove('active'));
    document.getElementById(`tab-${tab}`)?.classList.add('active');
    document.getElementById(`admin-view-${tab}`)?.classList.add('active');

    if (tab === 'stats')    loadStats();
    if (tab === 'fans')     fetchAdminList();
    if (tab === 'settings') fetchAdminAccounts();
    if (tab === 'webapp')   fetchAppSettings();
    if (tab === 'seclog')   fetchSecurityLogs();
    if (tab === 'google')   fetchGoogleMembers();
    if (tab === 'signal')   initSignalTab();
    if (tab === 'support')  initSupportTab();
};

// ── 날짜 프리셋 ────────────────────────────────────────
window.setStatPreset = function(days, btn) {
    document.querySelectorAll('.stats-preset').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    const to   = new Date();
    const from = new Date(); from.setDate(from.getDate() - (days - 1));
    document.getElementById('stats-from').value = fmt(from);
    document.getElementById('stats-to').value   = fmt(to);
    loadStats();
};

function fmt(d) {
    return d.toISOString().split('T')[0];
}

// ── 통계 로드 ──────────────────────────────────────────
window.loadStats = async function() {
    const from = document.getElementById('stats-from')?.value;
    const to   = document.getElementById('stats-to')?.value;
    if (!from || !to) return;

    try {
        const res  = await fetch(`admin.php?action=stats&from=${from}&to=${to}`);
        const data = await res.json();
        if (!data.success) { vibeToast(data.message, 'error'); return; }
        renderStats(data);
    } catch (e) {
        console.error('stats fetch error', e);
    }
};

// ── 통계 렌더링 ────────────────────────────────────────
function renderStats(data) {
    const { dates, series, summary, total_fan } = data;

    // 요약 카드
    document.getElementById('sum-visit').textContent    = (summary.visit    || 0).toLocaleString();
    document.getElementById('sum-fan-reg').textContent  = (summary.fan_reg  || 0).toLocaleString();
    document.getElementById('sum-total-fan').textContent= total_fan.toLocaleString();
    const appTotal = Object.entries(summary)
        .filter(([k]) => k.startsWith('app_'))
        .reduce((s, [,v]) => s + v, 0);
    document.getElementById('sum-apps').textContent = appTotal.toLocaleString();

    document.querySelectorAll('.stats-summary-card').forEach(c => c.classList.remove('loading'));

    // 짧은 날짜 레이블
    const labels = dates.map(d => {
        const parts = d.split('-'); return `${parts[1]}/${parts[2]}`;
    });

    // 방문 추이 차트
    drawLineChart('chart-visit', labels, series.visit || dates.map(() => 0), '#5e5ce6');

    // 앱별 바 차트
    const appKeys   = Object.keys(ADMIN_APP_LABELS).filter(k => series[k]);
    const appVals   = appKeys.map(k => (series[k] || []).reduce((a,b) => a+b, 0));
    const appLabels = appKeys.map(k => ADMIN_APP_LABELS[k]);
    drawBarChart('chart-apps', appLabels, appVals, APP_COLORS);

    // 일별 상세 테이블 (최신순)
    const tbody = document.getElementById('stats-detail-body');
    tbody.innerHTML = '';
    // 최신순 정렬: 배열 역순 순회
    const len = dates.length;
    for (let i = len - 1; i >= 0; i--) {
        const d = dates[i];
        const tr = document.createElement('tr');
        const appCols = ['app_tangram','app_pachinko','app_server','app_sys','app_pdf','app_qr','app_meter','app_spell']
            .map(k => `<td>${(series[k] || [])[i] ?? '—'}</td>`).join('');
        tr.innerHTML = `
            <td style="white-space:nowrap;font-weight:600;">${d}</td>
            <td>${(series.visit   || [])[i] ?? '—'}</td>
            <td>${(series.fan_reg || [])[i] ?? '—'}</td>
            ${appCols}
        `;
        tbody.appendChild(tr);
    }

    if (window.lucide) window.lucide.createIcons();
}

// ── Canvas 라인 차트 ────────────────────────────────────
function drawLineChart(id, labels, values, color) {
    const canvas = document.getElementById(id);
    if (!canvas) return;
    const ctx = canvas.getContext('2d');
    const dpr = window.devicePixelRatio || 1;
    const w = canvas.offsetWidth, h = canvas.offsetHeight;
    canvas.width  = w * dpr; canvas.height = h * dpr;
    ctx.scale(dpr, dpr);
    ctx.clearRect(0, 0, w, h);

    if (!values.length) return;
    const pad  = { top: 16, right: 16, bottom: 30, left: 36 };
    const cw   = w - pad.left - pad.right;
    const ch   = h - pad.top  - pad.bottom;
    const maxV = Math.max(...values, 1);

    const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
    const gridC  = isDark ? 'rgba(255,255,255,0.07)' : 'rgba(0,0,0,0.06)';
    const textC  = isDark ? 'rgba(255,255,255,0.45)' : 'rgba(0,0,0,0.4)';

    const xPos = i => pad.left + (i / (labels.length - 1 || 1)) * cw;
    const yPos = v => pad.top  + ch - (v / maxV) * ch;

    // Grid lines (4개)
    ctx.font = '10px system-ui'; ctx.fillStyle = textC;
    for (let g = 0; g <= 4; g++) {
        const y = pad.top + (g / 4) * ch;
        ctx.strokeStyle = gridC; ctx.lineWidth = 1;
        ctx.beginPath(); ctx.moveTo(pad.left, y); ctx.lineTo(pad.left + cw, y); ctx.stroke();
        if (g < 4) { ctx.fillText(Math.round(maxV * (1 - g/4)), 2, y + 4); }
    }

    // Gradient fill
    const grad = ctx.createLinearGradient(0, pad.top, 0, pad.top + ch);
    grad.addColorStop(0, color + '44');
    grad.addColorStop(1, color + '00');
    ctx.beginPath();
    values.forEach((v, i) => i === 0 ? ctx.moveTo(xPos(i), yPos(v)) : ctx.lineTo(xPos(i), yPos(v)));
    ctx.lineTo(xPos(values.length - 1), pad.top + ch);
    ctx.lineTo(xPos(0), pad.top + ch);
    ctx.closePath();
    ctx.fillStyle = grad; ctx.fill();

    // Line
    ctx.beginPath();
    values.forEach((v, i) => i === 0 ? ctx.moveTo(xPos(i), yPos(v)) : ctx.lineTo(xPos(i), yPos(v)));
    ctx.strokeStyle = color; ctx.lineWidth = 2; ctx.lineJoin = 'round'; ctx.stroke();

    // Dots
    values.forEach((v, i) => {
        ctx.beginPath();
        ctx.arc(xPos(i), yPos(v), 3.5, 0, Math.PI * 2);
        ctx.fillStyle = color; ctx.fill();
        ctx.strokeStyle = isDark ? '#1a1a1a' : '#fff'; ctx.lineWidth = 1.5; ctx.stroke();
    });

    // X labels (최대 7개)
    const step = Math.max(1, Math.ceil(labels.length / 7));
    ctx.fillStyle = textC;
    labels.forEach((lbl, i) => {
        if (i % step === 0 || i === labels.length - 1) {
            ctx.fillText(lbl, xPos(i) - 14, h - 6);
        }
    });
}

// ── Canvas 바 차트 ──────────────────────────────────────
function drawBarChart(id, labels, values, colors) {
    const canvas = document.getElementById(id);
    if (!canvas) return;
    const ctx = canvas.getContext('2d');
    const dpr = window.devicePixelRatio || 1;
    const w = canvas.offsetWidth, h = canvas.offsetHeight;
    canvas.width  = w * dpr; canvas.height = h * dpr;
    ctx.scale(dpr, dpr);
    ctx.clearRect(0, 0, w, h);

    if (!values.length || Math.max(...values) === 0) {
        const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
        ctx.fillStyle = isDark ? 'rgba(255,255,255,0.3)' : 'rgba(0,0,0,0.3)';
        ctx.font = '13px system-ui'; ctx.textAlign = 'center';
        ctx.fillText('데이터 없음', w/2, h/2); return;
    }

    const pad  = { top: 16, right: 16, bottom: 36, left: 36 };
    const cw   = w - pad.left - pad.right;
    const ch   = h - pad.top  - pad.bottom;
    const maxV = Math.max(...values, 1);
    const n    = labels.length;
    const barW = Math.min((cw / n) * 0.6, 40);
    const gap  = cw / n;

    const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
    const textC  = isDark ? 'rgba(255,255,255,0.45)' : 'rgba(0,0,0,0.4)';
    const gridC  = isDark ? 'rgba(255,255,255,0.07)' : 'rgba(0,0,0,0.06)';

    // Grid
    for (let g = 0; g <= 3; g++) {
        const y = pad.top + (g / 3) * ch;
        ctx.strokeStyle = gridC; ctx.lineWidth = 1;
        ctx.beginPath(); ctx.moveTo(pad.left, y); ctx.lineTo(pad.left + cw, y); ctx.stroke();
    }

    // Bars + labels
    ctx.font = '10px system-ui'; ctx.fillStyle = textC;
    values.forEach((v, i) => {
        const x   = pad.left + i * gap + gap / 2 - barW / 2;
        const bh  = (v / maxV) * ch;
        const y   = pad.top + ch - bh;
        const r   = 5;

        // Rounded top bar
        ctx.fillStyle = colors[i % colors.length] + 'cc';
        ctx.beginPath();
        ctx.moveTo(x + r, y);
        ctx.lineTo(x + barW - r, y);
        ctx.quadraticCurveTo(x + barW, y, x + barW, y + r);
        ctx.lineTo(x + barW, y + bh);
        ctx.lineTo(x, y + bh);
        ctx.lineTo(x, y + r);
        ctx.quadraticCurveTo(x, y, x + r, y);
        ctx.fill();

        // Value on bar
        if (v > 0) {
            ctx.fillStyle = isDark ? 'rgba(255,255,255,0.8)' : 'rgba(0,0,0,0.65)';
            ctx.font = '10px system-ui'; ctx.textAlign = 'center';
            ctx.fillText(v, x + barW / 2, y - 4);
        }

        // X label
        ctx.fillStyle = textC;
        ctx.font = '10px system-ui'; ctx.textAlign = 'center';
        const lbl = labels[i] || '';
        ctx.fillText(lbl.length > 4 ? lbl.slice(0, 4) : lbl, x + barW / 2, h - 6);
    });
}

// ── 로그인 ─────────────────────────────────────────────
window.openAdminInternal = async function () {
    try {
        const res  = await fetch('admin.php?action=check');
        const data = await res.json();
        if (data.success) {
            switchApp('admin-panel', 'Admin Panel', 'var(--sys-primary)', 'settings');
            initAdminPanel();
        } else {
            switchApp('admin-login', 'Admin Login', 'var(--sys-primary)', 'lock');
        }
    } catch (err) {
        switchApp('admin-login', 'Admin Login', 'var(--sys-primary)', 'lock');
    }
};

window.tryAdminLogin = async function () {
    const id   = document.getElementById('admin-id').value;
    const pass = document.getElementById('admin-pw').value;
    if (!id || !pass) { vibeToast('아이디와 비밀번호를 입력해주세요.', 'warn'); return; }
    try {
        const res  = await fetch('admin.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `action=login&id=${encodeURIComponent(id)}&pass=${encodeURIComponent(pass)}`
        });
        const data = await res.json();
        if (data.success) {
            switchApp('admin-panel', 'Admin Panel', 'var(--sys-primary)', 'settings');
            initAdminPanel();
        } else {
            vibeToast(data.message, 'error');
        }
    } catch (e) {
        vibeToast('로그인 오류가 발생했습니다.', 'error');
    }
};

function initAdminPanel() {
    // 날짜 초기값 세팅 (최근 7일)
    const to   = new Date();
    const from = new Date(); from.setDate(from.getDate() - 6);
    const fromEl = document.getElementById('stats-from');
    const toEl   = document.getElementById('stats-to');
    if (fromEl) fromEl.value = fmt(from);
    if (toEl)   toEl.value   = fmt(to);

    // 첫 탭(통계) 로드
    showAdminTab('stats');
    if (window.lucide) window.lucide.createIcons();
}

// ── Fan 목록 ────────────────────────────────────────────
window.fetchAdminList = async function () {
    const tbody    = document.getElementById('admin-list-body');
    const cardList = document.getElementById('admin-card-list');
    const loadMsg  = '<tr><td colspan="5" style="text-align:center;padding:40px;color:var(--text-muted);">불러오는 중...</td></tr>';
    if (tbody)    tbody.innerHTML = loadMsg;
    if (cardList) cardList.innerHTML = '<div style="text-align:center;padding:40px 0;color:var(--text-muted);font-size:14px;">불러오는 중...</div>';

    try {
        const res  = await fetch('admin.php?action=list');
        const data = await res.json();
        if (data.success) {
            updateAdminList(data.list);
        } else {
            if (data.message === '인증이 필요합니다.') {
                switchApp('admin-login', 'Admin Login', 'var(--sys-primary)', 'lock');
            } else vibeToast(data.message, 'warn');
        }
    } catch (e) { console.error(e); }
};

window.updateAdminList = function (list) {
    const tbody    = document.getElementById('admin-list-body');
    const cardList = document.getElementById('admin-card-list');
    if (!tbody && !cardList) return;

    if (list.length === 0) {
        if (tbody)    tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:40px;color:var(--text-muted);">신청자가 없습니다.</td></tr>';
        if (cardList) cardList.innerHTML = '<div style="text-align:center;padding:40px 0;color:var(--text-muted);font-size:14px;">신청자가 없습니다.</div>';
        return;
    }

    // PC 테이블
    if (tbody) {
        tbody.innerHTML = '';
        list.forEach((item, i) => {
            let apps = [];
            if (parseInt(item.webapp_apply))      apps.push('웹앱');
            if (parseInt(item.content_subscribe)) apps.push('컨텐츠');
            if (parseInt(item.coffee_chat))       apps.push('커피챗');
            const isGoogle = (item.reg_source === 'google');
            const srcBadge = isGoogle
                ? `<span class="admin-badge" style="background:rgba(66,133,244,0.1);color:#4285F4;font-size:11px;">Google</span>`
                : `<span class="admin-badge" style="background:rgba(0,0,0,0.06);color:var(--text-muted);font-size:11px;">이메일</span>`;
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td>${i+1}</td>
                <td style="font-weight:600;">${item.email}</td>
                <td><span class="admin-badge" style="background:rgba(94,92,230,0.1);color:var(--sys-primary);">${apps.join(', ') || '-'}</span></td>
                <td>${parseInt(item.marketing_consent) ? '✅' : '❌'}</td>
                <td>${srcBadge}</td>
                <td style="color:var(--text-muted);font-size:12px;">${item.auth_time || '-'}</td>
            `;
            tbody.appendChild(tr);
        });
    }

    // Mobile 카드
    if (cardList) {
        cardList.innerHTML = '';
        list.forEach((item, i) => {
            let apps = [];
            if (parseInt(item.webapp_apply))      apps.push('웹앱');
            if (parseInt(item.content_subscribe)) apps.push('컨텐츠');
            if (parseInt(item.coffee_chat))       apps.push('커피챗');
            const badges   = apps.map(a => `<span class="admin-badge" style="background:rgba(94,92,230,0.12);color:var(--sys-primary);">${a}</span>`).join('') || '<span class="admin-badge" style="background:rgba(0,0,0,0.08);color:var(--text-muted);">-</span>';
            const mktBadge = parseInt(item.marketing_consent) ? '<span class="admin-badge badge-success">동의</span>' : '<span class="admin-badge badge-fail">거부</span>';
            const isGoogle  = (item.reg_source === 'google');
            const srcBadge  = isGoogle
                ? '<span class="admin-badge" style="background:rgba(66,133,244,0.1);color:#4285F4;font-size:11px;">Google</span>'
                : '<span class="admin-badge" style="background:rgba(0,0,0,0.06);color:var(--text-muted);font-size:11px;">이메일</span>';
            const card = document.createElement('div');
            card.className = 'admin-card';
            card.innerHTML = `
                <div class="admin-card-row">
                    <span class="admin-card-no">#${i+1}</span>
                    ${srcBadge}
                    <span class="admin-card-time">${item.auth_time || '-'}</span>
                </div>
                <div class="admin-card-email">${item.email}</div>
                <div class="admin-card-row">
                    <div class="admin-card-meta">${badges}</div>
                    <div class="admin-card-meta">${mktBadge}</div>
                </div>
            `;
            cardList.appendChild(card);
        });
    }

    if (window.lucide) window.lucide.createIcons();
};

// ── 로그아웃 ────────────────────────────────────────────
window.adminLogout = async function () {
    try {
        await fetch('admin.php?action=logout');
        switchApp('admin-login', 'Admin Login', 'var(--sys-primary)', 'lock');
    } catch (e) { location.reload(); }
};

// ── 웹앱 설정 목록 조회 ──────────────────────────
window.fetchAppSettings = async function () {
    const list = document.getElementById('webapp-toggle-list');
    if (list) list.innerHTML = '<div style="text-align:center;padding:40px;color:var(--text-muted);">불러오는 중...</div>';
    try {
        const res  = await fetch('admin.php?action=app_settings_get', { cache: 'no-store' });
        const data = await res.json();
        if (data.success) {
            renderAppSettings(data.settings);
        } else {
            if (list) list.innerHTML = `<div style="text-align:center;padding:40px;color:#ff453a;">${data.message}</div>`;
        }
    } catch (e) { console.error('app_settings_get error', e); }
};

// ── 웹앱 설정 렌더링 ────────────────────────────
const APP_META = {
    tangram : { label: 'Tangram',       icon: 'puzzle',      color: '#ff6b35', grad: 'linear-gradient(135deg,#ff8c5a,#ff6b35)' },
    pachinko: { label: 'Pachinko',      icon: 'gamepad-2',   color: '#ff2d55', grad: 'linear-gradient(135deg,#ff5274,#d81b60)' },
    server  : { label: 'Server Monitor',icon: 'server',      color: '#34d399', grad: 'linear-gradient(135deg,#34d399,#10b981)' },
    sys     : { label: 'System Info',   icon: 'activity',    color: '#5e5ce6', grad: 'linear-gradient(135deg,#8b89ff,#5e5ce6)' },
    pdf     : { label: 'PDF Splitter',  icon: 'file-text',   color: '#ff3b30', grad: 'linear-gradient(135deg,#ff6b63,#ff3b30)' },
    qr      : { label: 'QR Master',     icon: 'qr-code',     color: '#007aff', grad: 'linear-gradient(135deg,#40b3ff,#007aff)' },
    meter   : { label: 'Clap Meter',    icon: 'mic',         color: '#ff9500', grad: 'linear-gradient(135deg,#ffcc00,#ff9500)' },
    spell   : { label: 'Spell Check',   icon: 'languages',   color: '#a78bfa', grad: 'linear-gradient(135deg,#c4b5fd,#8b5cf6)' },
};

function renderAppSettings(settings) {
    const list = document.getElementById('webapp-toggle-list');
    if (!list) return;

    if (!settings || settings.length === 0) {
        list.innerHTML = '<div style="text-align:center;padding:40px;color:var(--text-muted);">설정 정보가 없습니다.</div>';
        return;
    }

    list.innerHTML = '';
    settings.forEach(s => {
        const meta    = APP_META[s.app_key] || { label: s.app_key, icon: 'box', color: '#888', grad: '#888' };
        const enabled = parseInt(s.enabled) === 1;
        const updAt   = s.updated_at ? s.updated_at.slice(0, 16).replace('T', ' ') : '설정 전';

        const card = document.createElement('div');
        card.className = 'webapp-toggle-card';
        card.id = `webapp-card-${s.app_key}`;
        card.innerHTML = `
            <div class="webapp-card-left">
                <div class="webapp-card-icon" style="background:${meta.grad};">
                    <i data-lucide="${meta.icon}" style="width:20px;height:20px;color:#fff;"></i>
                </div>
                <div class="webapp-card-info">
                    <div class="webapp-card-name">${meta.label}</div>
                    <div class="webapp-card-time">수정일: ${updAt}</div>
                </div>
            </div>
            <label class="webapp-toggle-switch" title="${enabled ? 'OFF로 전환' : 'ON으로 전환'}">
                <input type="checkbox" ${enabled ? 'checked' : ''}
                    onchange="toggleAppSetting('${s.app_key}', this.checked)">
                <span class="webapp-toggle-slider"></span>
            </label>
        `;
        list.appendChild(card);
    });

    if (window.lucide) window.lucide.createIcons();
}

// ── 웹앱 토글 ─────────────────────────────────────────
window.toggleAppSetting = async function (appKey, isOn) {
    const enabled = isOn ? 1 : 0;
    try {
        const res  = await fetch('admin.php', {
            method : 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body   : `action=app_settings_update&app_key=${encodeURIComponent(appKey)}&enabled=${enabled}`
        });
        const data = await res.json();
        if (data.success) {
            // 메인 페이지 독 즉시 반영 (부모 window 접근)
            if (window.refreshDockVisibility) window.refreshDockVisibility();
            // 수정일 갱신을 위해 목록 새로고침
            fetchAppSettings();
        } else {
            vibeToast('저장 실패: ' + data.message, 'error');
            fetchAppSettings();
        }
    } catch (e) {
        vibeToast('네트워크 오류가 발생했습니다.', 'error');
        fetchAppSettings();
    }
};

// ── 관리자 계정 목록 조회 ────────────────────────
window.fetchAdminAccounts = async function () {
    const tbody    = document.getElementById('acct-table-body');
    const cardList = document.getElementById('acct-card-list');
    const loading  = '<tr><td colspan="7" style="text-align:center;padding:30px;color:var(--text-muted);">불러오는 중...</td></tr>';
    if (tbody)    tbody.innerHTML = loading;
    if (cardList) cardList.innerHTML = '<div style="text-align:center;padding:30px 0;color:var(--text-muted);font-size:14px;">불러오는 중...</div>';

    try {
        const res  = await fetch('admin.php?action=admin_list');
        const data = await res.json();
        if (data.success) {
            renderAdminAccounts(data.list);
        } else {
            if (data.message === '인증이 필요합니다.') {
                switchApp('admin-login', 'Admin Login', 'var(--sys-primary)', 'lock');
            } else {
                if (tbody) tbody.innerHTML = `<tr><td colspan="7" style="text-align:center;padding:30px;color:#ff453a;">${data.message}</td></tr>`;
            }
        }
    } catch (e) { console.error('admin_list error', e); }
};

// ── 관리자 계정 목록 렌더링 ─────────────────────────────
function renderAdminAccounts(list) {
    const tbody    = document.getElementById('acct-table-body');
    const cardList = document.getElementById('acct-card-list');
    const badge    = document.getElementById('acct-count-badge');
    if (badge) badge.textContent = list.length + '명';

    // PC 테이블
    if (tbody) {
        if (list.length === 0) {
            tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:30px;color:var(--text-muted);">등록된 관리자가 없습니다.</td></tr>';
        } else {
            tbody.innerHTML = '';
            list.forEach((item, i) => {
                const roleCls   = item.role === 'superadmin' ? 'role-superadmin' : 'role-admin';
                const roleLabel = item.role === 'superadmin' ? 'Super Admin' : 'Admin';
                const createdAt = item.created_at ? item.created_at.slice(0, 10) : '-';
                const updatedAt = item.updated_at ? item.updated_at.slice(0, 16).replace('T', ' ') : '-';
                const dispName  = item.display_name || '';
                const tr = document.createElement('tr');
                tr.id = `acct-row-${item.id}`;
                tr.innerHTML = `
                    <td>${i + 1}</td>
                    <td style="font-weight:700;">${escapeHtml(item.username)}</td>
                    <td class="acct-name-cell">${dispName
                        ? escapeHtml(dispName)
                        : '<span style="color:var(--text-muted);font-size:11px;">없음</span>'}</td>
                    <td><span class="acct-role-badge ${roleCls}">${roleLabel}</span></td>
                    <td style="color:var(--text-muted);font-size:12px;white-space:nowrap;">${createdAt}</td>
                    <td style="color:var(--text-muted);font-size:12px;white-space:nowrap;">${updatedAt}</td>
                    <td>
                        <div style="display:flex;gap:5px;flex-wrap:nowrap;">
                            <button class="acct-edit-btn"
                                onclick="editAdminRow(${item.id},'${escapeHtml(dispName)}','${escapeHtml(item.role)}')"
                                title="수정">
                                <i data-lucide="pencil" style="width:11px;height:11px;"></i> 수정
                            </button>
                            <button class="acct-delete-btn"
                                onclick="deleteAdminAccount(${item.id},'${escapeHtml(item.username)}')"
                                title="삭제">
                                <i data-lucide="trash-2" style="width:11px;height:11px;"></i> 삭제
                            </button>
                        </div>
                    </td>
                `;
                tbody.appendChild(tr);
            });
        }
    }

    // Mobile 카드
    if (cardList) {
        if (list.length === 0) {
            cardList.innerHTML = '<div style="text-align:center;padding:30px 0;color:var(--text-muted);font-size:14px;">등록된 관리자가 없습니다.</div>';
        } else {
            cardList.innerHTML = '';
            list.forEach((item, i) => {
                const roleCls   = item.role === 'superadmin' ? 'role-superadmin' : 'role-admin';
                const roleLabel = item.role === 'superadmin' ? 'Super Admin' : 'Admin';
                const createdAt = item.created_at ? item.created_at.slice(0, 10) : '-';
                const updatedAt = item.updated_at ? item.updated_at.slice(0, 16).replace('T', ' ') : '-';
                const card = document.createElement('div');
                card.className = 'admin-card';
                card.id = `acct-card-${item.id}`;
                card.innerHTML = `
                    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px;">
                        <div style="display:flex;flex-direction:column;gap:5px;flex:1;min-width:0;">
                            <div style="display:flex;align-items:center;gap:8px;">
                                <span class="admin-card-no">#${i + 1}</span>
                                <span class="acct-role-badge ${roleCls}">${roleLabel}</span>
                            </div>
                            <div class="admin-card-email">${escapeHtml(item.username)}</div>
                            ${item.display_name
                                ? `<div style="font-size:12px;color:var(--text-muted);">이름: ${escapeHtml(item.display_name)}</div>`
                                : ''}
                            <div class="admin-card-time">생성: ${createdAt}${item.updated_at ? ' | 수정: ' + updatedAt : ''}</div>
                        </div>
                        <div style="display:flex;flex-direction:column;gap:5px;align-items:flex-end;">
                            <button class="acct-edit-btn"
                                onclick="editAdminRow(${item.id},'${escapeHtml(item.display_name || '')}','${escapeHtml(item.role)}')">
                                <i data-lucide="pencil" style="width:11px;height:11px;"></i> 수정
                            </button>
                            <button class="acct-delete-btn"
                                onclick="deleteAdminAccount(${item.id},'${escapeHtml(item.username)}')">
                                <i data-lucide="trash-2" style="width:11px;height:11px;"></i> 삭제
                            </button>
                        </div>
                    </div>
                `;
                cardList.appendChild(card);
            });
        }
    }

    if (window.lucide) window.lucide.createIcons();
}

// ── 관리자 계정 추가 ─────────────────────────────────────
window.addAdminAccount = async function () {
    const username    = document.getElementById('new-admin-id')?.value.trim();
    const displayName = document.getElementById('new-admin-name')?.value.trim() || '';
    const password    = document.getElementById('new-admin-pw')?.value.trim();
    const role        = document.getElementById('new-admin-role')?.value;
    const msgEl       = document.getElementById('acct-add-msg');

    function showMsg(text, type) {
        if (!msgEl) return;
        msgEl.textContent = text;
        msgEl.className   = `acct-msg ${type}`;
        msgEl.style.display = 'block';
        setTimeout(() => { msgEl.style.display = 'none'; }, 4000);
    }

    if (!username || !password) { showMsg('아이디와 비밀번호를 입력해주세요.', 'error'); return; }
    if (username.length < 3)    { showMsg('아이디는 3자 이상이어야 합니다.', 'error'); return; }
    if (password.length < 4)    { showMsg('비밀번호는 4자 이상이어야 합니다.', 'error'); return; }

    try {
        const res  = await fetch('admin.php', {
            method : 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body   : `action=admin_add&username=${encodeURIComponent(username)}&display_name=${encodeURIComponent(displayName)}&password=${encodeURIComponent(password)}&role=${encodeURIComponent(role)}`
        });
        const data = await res.json();
        if (data.success) {
            showMsg('✅ ' + data.message, 'success');
            document.getElementById('new-admin-id').value   = '';
            document.getElementById('new-admin-name').value = '';
            document.getElementById('new-admin-pw').value   = '';
            document.getElementById('new-admin-role').value = 'admin';
            fetchAdminAccounts();
        } else {
            showMsg('❌ ' + data.message, 'error');
        }
    } catch (e) {
        showMsg('❌ 네트워크 오류가 발생했습니다.', 'error');
    }
};

// ── 관리자 계정 삭제 ─────────────────────────────────────
window.deleteAdminAccount = async function (id, username) {
    if (!confirm(`'${username}' 계정을 삭제하시겠습니까?\n이 작업은 되돌릴 수 없습니다.`)) return;
    try {
        const res  = await fetch('admin.php', {
            method : 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body   : `action=admin_delete&id=${id}`
        });
        const data = await res.json();
        if (data.success) {
            vibeToast('관리자 계정이 삭제되었습니다.', 'success');
            fetchAdminAccounts();
        } else {
            vibeToast('삭제 실패: ' + data.message, 'error');
        }
    } catch (e) {
        vibeToast('네트워크 오류가 발생했습니다.', 'error');
    }
};

// ── 인라인 행 편집 모드 전환 ─────────────────────────────
window.editAdminRow = function (id, currentName, currentRole) {
    const tr = document.getElementById(`acct-row-${id}`);
    if (!tr) return;

    // 취소를 위해 원본 HTML 저장
    tr.dataset.origHtml = tr.innerHTML;

    // 권한 옵션 HTML
    const roleOpts = ['admin', 'superadmin'].map(r =>
        `<option value="${r}"${r === currentRole ? ' selected' : ''}>${r === 'superadmin' ? 'Super Admin' : 'Admin'}</option>`
    ).join('');

    // 첫 번째 셀(번호)과 두 번째 셀(아이디)을 합쳐 colspan=2로, 나머지는 편집 입력창으로 대체
    const usernameText = tr.cells[1]?.textContent.trim() || '';

    tr.innerHTML = `
        <td colspan="2" style="font-size:12px;color:var(--text-muted);padding:10px 16px;vertical-align:middle;">
            ID: <strong style="color:var(--text-main);">${escapeHtml(usernameText)}</strong>
        </td>
        <td style="padding:8px 12px;vertical-align:middle;">
            <input class="acct-input" id="edit-name-${id}" value="${escapeHtml(currentName)}"
                placeholder="표시 이름"
                style="padding:6px 10px;font-size:12px;min-width:80px;max-width:130px;">
        </td>
        <td style="padding:8px 12px;vertical-align:middle;">
            <select class="acct-input acct-select" id="edit-role-${id}"
                style="padding:6px 10px;font-size:12px;min-width:100px;">
                ${roleOpts}
            </select>
        </td>
        <td colspan="2" style="padding:8px 12px;vertical-align:middle;">
            <div class="acct-pw-wrap" style="min-width:130px;max-width:180px;">
                <input class="acct-input" id="edit-pw-${id}" type="password"
                    placeholder="새 비밀번호 (선택)" autocomplete="new-password"
                    style="padding:6px 10px;font-size:12px;padding-right:34px;">
                <button class="acct-pw-toggle" type="button"
                    onclick="toggleEditPw(${id})" title="비밀번호 표시">
                    <i data-lucide="eye" id="edit-pw-eye-${id}" style="width:13px;height:13px;"></i>
                </button>
            </div>
        </td>
        <td style="padding:8px 12px;vertical-align:middle;">
            <div style="display:flex;gap:5px;">
                <button class="acct-edit-btn"
                    style="background:rgba(52,211,153,0.12);color:#10b981;border-color:rgba(52,211,153,0.3);"
                    onclick="saveAdminRow(${id})">
                    <i data-lucide="check" style="width:11px;height:11px;"></i> 저장
                </button>
                <button class="acct-delete-btn" onclick="cancelAdminRow(${id})">
                    취소
                </button>
            </div>
        </td>
    `;
    if (window.lucide) window.lucide.createIcons();
    document.getElementById(`edit-name-${id}`)?.focus();
};

// ── 인라인 편집 저장 ─────────────────────────────────────
window.saveAdminRow = async function (id) {
    const nameEl = document.getElementById(`edit-name-${id}`);
    const roleEl = document.getElementById(`edit-role-${id}`);
    const pwEl   = document.getElementById(`edit-pw-${id}`);
    if (!nameEl || !roleEl) return;

    const displayName = nameEl.value.trim();
    const role        = roleEl.value;
    const password    = pwEl ? pwEl.value.trim() : '';

    if (password && password.length < 4) {
        vibeToast('비밀번호는 최소 4자 이상이어야 합니다.', 'warn');
        return;
    }

    try {
        const body = `action=admin_update&id=${id}&display_name=${encodeURIComponent(displayName)}&role=${encodeURIComponent(role)}&password=${encodeURIComponent(password)}`;
        const res  = await fetch('admin.php', {
            method : 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body
        });
        const data = await res.json();
        if (data.success) {
            vibeToast('수정되었습니다.', 'success');
            fetchAdminAccounts();
        } else {
            vibeToast('수정 실패: ' + data.message, 'error');
        }
    } catch (e) {
        vibeToast('네트워크 오류가 발생했습니다.', 'error');
    }
};

// ── 인라인 편집 취소 ─────────────────────────────────────
window.cancelAdminRow = function (id) {
    const tr = document.getElementById(`acct-row-${id}`);
    if (!tr || !tr.dataset.origHtml) return;
    tr.innerHTML = tr.dataset.origHtml;
    delete tr.dataset.origHtml;
    if (window.lucide) window.lucide.createIcons();
};

// ── 편집 행 비밀번호 토글 ────────────────────────────────
window.toggleEditPw = function (id) {
    const input = document.getElementById(`edit-pw-${id}`);
    const icon  = document.getElementById(`edit-pw-eye-${id}`);
    if (!input || !icon) return;
    const isText = input.type === 'text';
    input.type = isText ? 'password' : 'text';
    icon.setAttribute('data-lucide', isText ? 'eye' : 'eye-off');
    if (window.lucide) window.lucide.createIcons();
};

// ── 추가 폼 비밀번호 토글 ────────────────────────────────
window.toggleNewPw = function (btn) {
    const input = document.getElementById('new-admin-pw');
    if (!input) return;
    const isText = input.type === 'text';
    input.type = isText ? 'password' : 'text';
    const icon = btn.querySelector('i');
    if (icon) icon.setAttribute('data-lucide', isText ? 'eye' : 'eye-off');
    if (window.lucide) window.lucide.createIcons();
};

// ── HTML 이스케이프 유틸 ─────────────────────────────────
function escapeHtml(str) {
    return String(str)
        .replace(/&/g,  '&amp;')
        .replace(/</g,  '&lt;')
        .replace(/>/g,  '&gt;')
        .replace(/"/g,  '&quot;')
        .replace(/'/g,  '&#39;');
}


// ==========================================
// 🔒 보안 로그
// ==========================================

let _seclogState = { type: '', search: '', page: 0, perPage: 50, total: 0, debounceTimer: null };

// ── 탭 전환 시 로드 ──────────────────────────────────────
window.fetchSecurityLogs = async function () {
    const tbody    = document.getElementById('seclog-table-body');
    const cardList = document.getElementById('seclog-card-list');
    if (tbody)    tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:40px;color:var(--text-muted);">불러오는 중...</td></tr>';
    if (cardList) cardList.innerHTML = '';

    const { type, search, page, perPage } = _seclogState;
    const params = new URLSearchParams({ action: 'security_logs', limit: perPage, offset: page * perPage });
    if (type)   params.append('type', type);
    if (search) params.append('search', search);

    try {
        const res  = await fetch(`admin.php?${params.toString()}`);
        const data = await res.json();
        if (!data.success) {
            if (data.message === '인증이 필요합니다.') {
                switchApp('admin-login', 'Admin Login', 'var(--sys-primary)', 'lock');
            } else if (tbody) {
                tbody.innerHTML = `<tr><td colspan="6" style="text-align:center;padding:40px;color:#ff453a;">${data.message}</td></tr>`;
            }
            return;
        }
        _seclogState.total = data.total;
        renderSeclogSummary(data.summary);
        renderSeclogLogs(data.logs);
        renderSeclogPagination();
        renderSeclogCount(data.total);
    } catch (e) {
        if (tbody) tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:40px;color:#ff453a;">네트워크 오류</td></tr>';
        console.error('security_logs fetch error', e);
    }
    if (window.lucide) window.lucide.createIcons();
};

// ── 요약 카드 렌더 ────────────────────────────────────────
function renderSeclogSummary(s) {
    if (!s) return;
    const n    = v => (parseInt(v) || 0).toLocaleString();
    const setEl = (id, val) => { const el = document.getElementById(id); if (el) el.textContent = val; };
    setEl('seclog-success-24h',   n(s.success_24h));
    setEl('seclog-success-total', `합계 ${n(s.success_total)}`);
    setEl('seclog-fail-24h',      n(s.fail_24h));
    setEl('seclog-fail-total',    `합계 ${n(s.fail_total)}`);
    setEl('seclog-blocked-24h',   n(s.blocked_24h));
    setEl('seclog-blocked-total', `합계 ${n(s.blocked_total)}`);

    const failCard    = document.getElementById('seclog-card-fail');
    const blockedCard = document.getElementById('seclog-card-blocked');
    if (failCard)    failCard.style.borderColor    = parseInt(s.fail_24h)    > 10 ? 'rgba(255,159,10,0.5)' : '';
    if (blockedCard) blockedCard.style.borderColor = parseInt(s.blocked_24h) > 0  ? 'rgba(255,69,58,0.5)'  : '';
}

// ── 로그 목록 렌더 ────────────────────────────────────────
function renderSeclogLogs(logs) {
    const tbody    = document.getElementById('seclog-table-body');
    const cardList = document.getElementById('seclog-card-list');
    const EVENT_META = {
        login_success: { label: '로그인 성공', color: '#34d399', bg: 'rgba(52,211,153,0.12)',  icon: 'check-circle'   },
        login_fail:    { label: '로그인 실패', color: '#ff9f0a', bg: 'rgba(255,159,10,0.12)', icon: 'alert-triangle' },
        login_blocked: { label: 'IP 잠금',     color: '#ff453a', bg: 'rgba(255,69,58,0.12)',  icon: 'shield-x'       },
    };
    const offset = _seclogState.page * _seclogState.perPage;

    if (!logs || logs.length === 0) {
        if (tbody)    tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:40px;color:var(--text-muted);">로그가 없습니다.</td></tr>';
        if (cardList) cardList.innerHTML = '';
        return;
    }

    if (tbody) {
        tbody.innerHTML = '';
        logs.forEach((log, i) => {
            const meta = EVENT_META[log.event_type] || { label: log.event_type, color:'#888', bg:'rgba(128,128,128,0.1)', icon:'info' };
            const ua   = log.user_agent ? log.user_agent.substring(0, 60) + (log.user_agent.length > 60 ? '…' : '') : '-';
            const tr   = document.createElement('tr');
            tr.innerHTML = `
                <td style="color:var(--text-muted);font-size:11px;">${offset + i + 1}</td>
                <td><span style="display:inline-flex;align-items:center;gap:4px;padding:3px 8px;border-radius:20px;font-size:11px;font-weight:600;background:${meta.bg};color:${meta.color};">
                    <i data-lucide="${meta.icon}" style="width:11px;height:11px;"></i>${meta.label}</span></td>
                <td style="font-weight:600;">${escapeHtml(log.username) || '<span style="color:var(--text-muted);font-style:italic;">알 수 없음</span>'}</td>
                <td style="font-size:12px;font-family:monospace;">${escapeHtml(log.ip_address)}</td>
                <td style="font-size:11px;color:var(--text-muted);max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="${escapeHtml(log.user_agent)}">${escapeHtml(ua)}</td>
                <td style="font-size:11px;color:var(--text-muted);white-space:nowrap;">${log.created_at ? log.created_at.replace('T',' ').slice(0,19) : '-'}</td>`;
            tbody.appendChild(tr);
        });
    }

    if (cardList) {
        cardList.innerHTML = '';
        logs.forEach((log, i) => {
            const meta = EVENT_META[log.event_type] || { label: log.event_type, color:'#888', bg:'rgba(128,128,128,0.1)', icon:'info' };
            const card = document.createElement('div');
            card.className = 'admin-card';
            card.innerHTML = `
                <div style="display:flex;flex-direction:column;gap:5px;">
                    <div style="display:flex;align-items:center;gap:7px;">
                        <span class="admin-card-no">#${offset + i + 1}</span>
                        <span style="padding:2px 8px;border-radius:20px;font-size:11px;font-weight:600;background:${meta.bg};color:${meta.color};">${meta.label}</span>
                    </div>
                    <div class="admin-card-email">${escapeHtml(log.username) || '<em style="color:var(--text-muted);">알 수 없음</em>'}</div>
                    <div style="font-size:12px;font-family:monospace;color:var(--text-muted);">${escapeHtml(log.ip_address)}</div>
                    <div class="admin-card-time">${log.created_at ? log.created_at.replace('T',' ').slice(0,19) : '-'}</div>
                </div>`;
            cardList.appendChild(card);
        });
    }
    if (window.lucide) window.lucide.createIcons();
}

// ── 건수 표시 ──────────────────────────────────────────────
function renderSeclogCount(total) {
    const el = document.getElementById('seclog-count-info');
    if (!el) return;
    if (total === 0) { el.textContent = '조회된 로그가 없습니다.'; return; }
    const { page, perPage } = _seclogState;
    const from = page * perPage + 1;
    const to   = Math.min((page + 1) * perPage, total);
    el.textContent = `전체 ${total.toLocaleString()}건 중 ${from}–${to}번째 표시`;
}

// ── 페이지네이션 ────────────────────────────────────────────
function renderSeclogPagination() {
    const el = document.getElementById('seclog-pagination');
    if (!el) return;
    const { page, perPage, total } = _seclogState;
    const pages = Math.ceil(total / perPage);
    if (pages <= 1) { el.innerHTML = ''; return; }
    let html = '<div class="seclog-page-btns">';
    if (page > 0)
        html += `<button class="seclog-page-btn" onclick="gotoSeclogPage(${page - 1})"><i data-lucide="chevron-left" style="width:13px;height:13px;"></i></button>`;
    const start = Math.max(0, page - 2), end = Math.min(pages, start + 5);
    for (let p = start; p < end; p++)
        html += `<button class="seclog-page-btn${p === page ? ' active' : ''}" onclick="gotoSeclogPage(${p})">${p + 1}</button>`;
    if (page < pages - 1)
        html += `<button class="seclog-page-btn" onclick="gotoSeclogPage(${page + 1})"><i data-lucide="chevron-right" style="width:13px;height:13px;"></i></button>`;
    html += '</div>';
    el.innerHTML = html;
    if (window.lucide) window.lucide.createIcons();
}

window.gotoSeclogPage = function(p) {
    _seclogState.page = p;
    fetchSecurityLogs();
};

window.setSeclogType = function(type, btn) {
    document.querySelectorAll('.seclog-type-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    _seclogState.type = type;
    _seclogState.page = 0;
    fetchSecurityLogs();
};

window.onSeclogSearch = function(val) {
    clearTimeout(_seclogState.debounceTimer);
    _seclogState.debounceTimer = setTimeout(() => {
        _seclogState.search = val.trim();
        _seclogState.page   = 0;
        fetchSecurityLogs();
    }, 300);
};


// ==========================================
// 👥 Google 멤버 목록
// ==========================================

window.fetchGoogleMembers = async function () {
    const tbody    = document.getElementById('google-member-table-body');
    const cardList = document.getElementById('google-member-card-list');
    const countEl  = document.getElementById('google-member-count');
    if (tbody)    tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:40px;color:var(--text-muted);">\ubd88\ub7ec\uc624\ub294 \uc911...</td></tr>';
    if (cardList) cardList.innerHTML = '';

    try {
        const res  = await fetch('admin.php?action=google_members');
        const data = await res.json();
        if (!data.success) {
            if (data.message === '\uc778\uc99d\uc774 \ud544\uc694\ud569\ub2c8\ub2e4.') {
                switchApp('admin-login', 'Admin Login', 'var(--sys-primary)', 'lock');
            } else if (tbody) {
                tbody.innerHTML = `<tr><td colspan="6" style="text-align:center;padding:40px;color:#ff453a;">${data.message}</td></tr>`;
            }
            return;
        }
        if (countEl) countEl.textContent = (data.total || 0).toLocaleString() + '\uba85';
        renderGoogleMembers(data.list);
    } catch (e) { console.error('google_members error', e); }
};

function renderGoogleMembers(list) {
    const tbody    = document.getElementById('google-member-table-body');
    const cardList = document.getElementById('google-member-card-list');

    if (!list || list.length === 0) {
        if (tbody)    tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:40px;color:var(--text-muted);">Google \ub85c\uadf8\uc778 \ud68c\uc6d0\uc774 \uc5c6\uc2b5\ub2c8\ub2e4.</td></tr>';
        if (cardList) cardList.innerHTML = '<div style="text-align:center;padding:40px 0;color:var(--text-muted);font-size:14px;">Google \ub85c\uadf8\uc778 \ud68c\uc6d0\uc774 \uc5c6\uc2b5\ub2c8\ub2e4.</div>';
        return;
    }

    if (tbody) {
        tbody.innerHTML = '';
        list.forEach((item, i) => {
            let apps = [];
            if (parseInt(item.webapp_apply))      apps.push('\uc6f9\uc571');
            if (parseInt(item.content_subscribe)) apps.push('\ucf58\ud150\uce20');
            if (parseInt(item.coffee_chat))       apps.push('\ucee4\ud53c\ucc57');
            // Google ID 일부 마스킹 (\ubcf4\uc548)
            const gid    = item.google_id ? item.google_id.substring(0, 8) + '…' : '-';
            const tr     = document.createElement('tr');
            tr.innerHTML = `
                <td>${i+1}</td>
                <td style="font-weight:600;">${escapeHtml(item.email)}</td>
                <td style="font-size:11px;font-family:monospace;color:var(--text-muted);">${escapeHtml(gid)}</td>
                <td><span class="admin-badge" style="background:rgba(94,92,230,0.1);color:var(--sys-primary);">${apps.join(', ') || '-'}</span></td>
                <td>${parseInt(item.marketing_consent) ? '\u2705' : '\u274c'}</td>
                <td style="color:var(--text-muted);font-size:12px;">${item.auth_time || '-'}</td>
            `;
            tbody.appendChild(tr);
        });
    }

    if (cardList) {
        cardList.innerHTML = '';
        list.forEach((item, i) => {
            let apps = [];
            if (parseInt(item.webapp_apply))      apps.push('\uc6f9\uc571');
            if (parseInt(item.content_subscribe)) apps.push('\ucf58\ud150\uce20');
            if (parseInt(item.coffee_chat))       apps.push('\ucee4\ud53c\ucc57');
            const badges   = apps.map(a => `<span class="admin-badge" style="background:rgba(94,92,230,0.12);color:var(--sys-primary);">${a}</span>`).join('') || '<span class="admin-badge">-</span>';
            const mktBadge = parseInt(item.marketing_consent) ? '<span class="admin-badge badge-success">\ub3d9\uc758</span>' : '<span class="admin-badge badge-fail">\uac70\ubd80</span>';
            const card = document.createElement('div');
            card.className = 'admin-card';
            card.innerHTML = `
                <div class="admin-card-row">
                    <span class="admin-card-no">#${i+1}</span>
                    <span class="admin-badge" style="background:rgba(66,133,244,0.1);color:#4285F4;font-size:11px;">Google</span>
                    <span class="admin-card-time">${item.auth_time || '-'}</span>
                </div>
                <div class="admin-card-email">${escapeHtml(item.email)}</div>
                <div style="font-size:11px;font-family:monospace;color:var(--text-muted);margin-bottom:6px;">ID: ${escapeHtml(item.google_id ? item.google_id.substring(0,8)+'\u2026' : '-')}</div>
                <div class="admin-card-row">
                    <div class="admin-card-meta">${badges}</div>
                    <div class="admin-card-meta">${mktBadge}</div>
                </div>
            `;
            cardList.appendChild(card);
        });
    }

    if (window.lucide) window.lucide.createIcons();
}


// ==========================================
// Signal 서비스 관리
// ==========================================

let _signalLogState = { page: 0, perPage: 20, total: 0 };

function initSignalTab() {
    const dateEl = document.getElementById('signal-sched-date');
    if (dateEl && !dateEl.value) dateEl.value = fmt(new Date());
    fetchSignalOverview();
    fetchSignalScheduler();
    fetchSignalDigestLogs();
}

// KPI Overview
window.fetchSignalOverview = async function() {
    try {
        const res  = await fetch('admin.php?action=signal_overview');
        const data = await res.json();
        if (!data.success) {
            if (data.message === '인증이 필요합니다.') switchApp('admin-login','Admin Login','var(--sys-primary)','lock');
            return;
        }
        const setEl = (id, val) => { const el = document.getElementById(id); if (el) el.textContent = val; };

        setEl('signal-sub-total', (data.sub_total || 0).toLocaleString() + '명');

        const sentEl = document.getElementById('signal-today-sent');
        const subLbl = document.getElementById('signal-today-sub');
        const card   = document.getElementById('signal-today-card');
        if (data.today_sent) {
            if (sentEl) sentEl.textContent = data.today_sent.sent_count + '명 성공';
            if (subLbl) subLbl.textContent = '실패 ' + data.today_sent.fail_count + '명 | ' + (data.today_sent.created_at||'').slice(0,16);
            if (card)   card.style.borderColor = 'rgba(52,211,153,0.4)';
        } else {
            if (sentEl) sentEl.textContent = '미발송';
            if (subLbl) subLbl.textContent  = '오늘 아직 발송되지 않음';
            if (card)   card.style.borderColor = 'rgba(255,159,10,0.4)';
        }

        setEl('signal-news-today', (data.news_today || 0) + '건');
        setEl('signal-last-collect', data.last_collect ? '수집 ' + data.last_collect.slice(0,16) : '수집 기록 없음');

        const isToday = data.last_page && data.last_page.slice(0,10) === fmt(new Date());
        setEl('signal-page-status', data.last_page ? (isToday ? '오늘 게시✓' : '게시됨') : '미게시');
        setEl('signal-last-page', data.last_page ? '최근 ' + data.last_page.slice(0,16) : '기록 없음');

        if (window.lucide) window.lucide.createIcons();
    } catch(e) { console.error('signal_overview error', e); }
};

// 스케줄러: 날짜별 수집 기사 목록
window.fetchSignalScheduler = async function() {
    const dateEl = document.getElementById('signal-sched-date');
    const date   = dateEl ? dateEl.value : fmt(new Date());
    const body   = document.getElementById('signal-scheduler-body');
    if (body) body.innerHTML = '<div style="text-align:center;padding:24px;color:var(--text-muted);font-size:13px;">로드 중...</div>';
    try {
        const res  = await fetch('admin.php?action=signal_scheduler_status&date=' + date);
        const data = await res.json();
        if (!data.success || !body) return;

        const CAT_KO = { research:'연구·논문', bigtech:'빅테크', tools:'도구·제품', industry:'산업·비즈', korea:'국내 AI', tips:'실용 팁' };
        const SC = { sent:'#34d399', collected:'#38bdf8', error:'#ff453a' };
        const digestOk = data.digest_row;

        let html = '<div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px;">';
        html += '<span style="padding:4px 12px;border-radius:20px;font-size:12px;font-weight:600;background:' + (data.news_list.length > 0 ? 'rgba(56,189,248,0.12);color:#38bdf8' : 'rgba(161,161,170,0.10);color:#71717a') + '">파이프라인 1·4: 수집 ' + data.news_list.length + '건</span>';
        html += '<span style="padding:4px 12px;border-radius:20px;font-size:12px;font-weight:600;background:' + (digestOk ? 'rgba(52,211,153,0.12);color:#34d399' : 'rgba(255,159,10,0.10);color:#ff9f0a') + '">파이프라인 4: 메일 ' + (digestOk ? '발송완료 (' + digestOk.sent_count + '명)' : '미발송') + '</span>';
        html += '</div>';

        if (data.news_list.length === 0) {
            html += '<div style="text-align:center;padding:20px;color:var(--text-muted);font-size:13px;">수집된 기사가 없습니다.</div>';
        } else {
            html += '<div class="admin-table-container"><table class="admin-table"><thead><tr><th style="width:32px;">#</th><th>제목</th><th style="width:80px;">카테고리</th><th style="width:60px;">점수</th><th style="width:80px;">상태</th><th style="width:130px;">수집시각</th></tr></thead><tbody>';
            data.news_list.forEach(function(n, i) {
                const sc = SC[n.status] || '#86868b';
                html += '<tr><td style="color:var(--text-muted);font-size:11px;">' + (i+1) + '</td>';
                html += '<td style="font-size:12px;font-weight:600;max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="' + escapeHtml(n.title) + '">' + escapeHtml(n.title) + '</td>';
                html += '<td><span class="admin-badge" style="background:rgba(94,92,230,0.1);color:var(--sys-primary);">' + (CAT_KO[n.category]||n.category) + '</span></td>';
                html += '<td style="font-weight:700;color:#fb923c;">' + parseFloat(n.score||0).toFixed(1) + '</td>';
                html += '<td><span style="padding:2px 8px;border-radius:20px;font-size:11px;font-weight:600;background:' + sc + '22;color:' + sc + ';">' + (n.status||'-') + '</span></td>';
                html += '<td style="font-size:11px;color:var(--text-muted);">' + (n.collected_at||'').slice(0,16) + '</td></tr>';
            });
            html += '</tbody></table></div>';
        }
        body.innerHTML = html;
        if (window.lucide) window.lucide.createIcons();
    } catch(e) { console.error('signal_scheduler error', e); }
};

// 메일 발송 이력
window.fetchSignalDigestLogs = async function() {
    const tbody    = document.getElementById('signal-log-body');
    const cardList = document.getElementById('signal-log-card-list');
    const countEl  = document.getElementById('signal-log-count');
    if (tbody) tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:24px;color:var(--text-muted);">로드 중...</td></tr>';
    const { page, perPage } = _signalLogState;
    try {
        const res  = await fetch('admin.php?action=signal_digest_logs&limit=' + perPage + '&offset=' + (page * perPage));
        const data = await res.json();
        if (!data.success) return;
        _signalLogState.total = data.total;
        if (countEl) countEl.textContent = '(전체 ' + data.total + '건)';

        if (!data.logs || data.logs.length === 0) {
            if (tbody) tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:24px;color:var(--text-muted);">발송 이력이 없습니다.</td></tr>';
            if (cardList) cardList.innerHTML = '';
            return;
        }

        if (tbody) {
            tbody.innerHTML = '';
            data.logs.forEach(function(log) {
                const succRate  = log.total_subs > 0 ? Math.round(log.sent_count / log.total_subs * 100) : 0;
                const failColor = parseInt(log.fail_count) > 0 ? '#ff453a' : 'var(--text-muted)';
                const tr = document.createElement('tr');
                tr.innerHTML =
                    '<td style="font-weight:700;white-space:nowrap;">' + log.sent_date + '</td>' +
                    '<td style="font-size:12px;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="' + escapeHtml(log.subject_line) + '">' + escapeHtml(log.subject_line) + '</td>' +
                    '<td style="text-align:center;">' + log.total_subs + '</td>' +
                    '<td style="text-align:center;font-weight:700;color:#34d399;">' + log.sent_count + '<span style="font-size:10px;color:var(--text-muted);font-weight:400;"> (' + succRate + '%)</span></td>' +
                    '<td style="text-align:center;font-weight:600;color:' + failColor + ';">' + log.fail_count + '</td>' +
                    '<td style="font-size:11px;color:var(--text-muted);white-space:nowrap;">' + (log.created_at||'').slice(0,16) + '</td>';
                tbody.appendChild(tr);
            });
        }

        if (cardList) {
            cardList.innerHTML = '';
            data.logs.forEach(function(log) {
                const failColor = parseInt(log.fail_count) > 0 ? '#ff453a' : 'var(--text-muted)';
                const card = document.createElement('div');
                card.className = 'admin-card';
                card.innerHTML =
                    '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">' +
                        '<span style="font-weight:700;font-size:14px;">' + log.sent_date + '</span>' +
                        '<span style="font-size:11px;color:var(--text-muted);">' + (log.created_at||'').slice(0,16) + '</span>' +
                    '</div>' +
                    '<div style="font-size:12px;color:var(--text-muted);margin-bottom:8px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' + escapeHtml(log.subject_line) + '</div>' +
                    '<div style="display:flex;gap:10px;font-size:12px;">' +
                        '<span>구독자 <strong>' + log.total_subs + '</strong></span>' +
                        '<span style="color:#34d399;">성공 <strong>' + log.sent_count + '</strong></span>' +
                        '<span style="color:' + failColor + '">실패 <strong>' + log.fail_count + '</strong></span>' +
                    '</div>';
                cardList.appendChild(card);
            });
        }
        renderSignalLogPagination();
        if (window.lucide) window.lucide.createIcons();
    } catch(e) { console.error('signal_digest_logs error', e); }
};

function renderSignalLogPagination() {
    const el = document.getElementById('signal-log-pagination');
    if (!el) return;
    const { page, perPage, total } = _signalLogState;
    const pages = Math.ceil(total / perPage);
    if (pages <= 1) { el.innerHTML = ''; return; }
    let html = '';
    if (page > 0) html += '<button class="seclog-page-btn" onclick="gotoSignalLogPage(' + (page-1) + ')"><i data-lucide="chevron-left" style="width:13px;height:13px;"></i></button>';
    const start = Math.max(0, page-2), end = Math.min(pages, start+5);
    for (let p = start; p < end; p++)
        html += '<button class="seclog-page-btn' + (p===page?' active':'') + '" onclick="gotoSignalLogPage(' + p + ')">' + (p+1) + '</button>';
    if (page < pages-1) html += '<button class="seclog-page-btn" onclick="gotoSignalLogPage(' + (page+1) + ')"><i data-lucide="chevron-right" style="width:13px;height:13px;"></i></button>';
    el.innerHTML = html;
    if (window.lucide) window.lucide.createIcons();
}

window.gotoSignalLogPage = function(p) {
    _signalLogState.page = p;
    fetchSignalDigestLogs();
};

// ══════════════════════════════════════════════════
// ♥ Support 후원 관리 탭
// ══════════════════════════════════════════════════
let _supportStatusFilter = '';

// UTC(DB) → KST(+9h) 변환 헬퍼 — 전역 함수로 두어 renderDonationFilter 등에서 공유
function toKST(utcStr) {
    if (!utcStr) return '-';
    const d   = new Date(utcStr.replace(' ', 'T') + 'Z'); // UTC로 파싱
    const kst = new Date(d.getTime() + 9 * 3600 * 1000);  // +9h
    return kst.toISOString().slice(0, 16).replace('T', ' ');
}

// PayApp pay_state 레이블 맵 — donate_webhook.php PAYAPP_CANCEL 상수와 동기·유지
const PAY_STATE_LABELS = {
    1:'결제요청', 4:'결제완료',
    8:'요청취소', 9:'승인취소', 16:'요청취소(기타)', 31:'요청실패',
    32:'요청취소', 64:'승인취소', 70:'부분취소', 71:'부분취소완료'
};
function payStateLabel(s) {
    const n = parseInt(s);
    return PAY_STATE_LABELS[n] ? `${s} (${PAY_STATE_LABELS[n]})` : String(s);
}

window.initSupportTab = function() {
    fetchDonations();
    fetchAttempts();
};

window.setSupportStatus = function(status, btn) {
    _supportStatusFilter = status;
    document.querySelectorAll('[data-status]').forEach(b => b.classList.remove('active'));
    if (btn) btn.classList.add('active');
    renderDonationFilter();
};

// ── 후원 로그 조회 ──────────────────────────────
window.fetchDonations = async function() {
    const range   = document.getElementById('support-range-select')?.value || 'all';
    const tbody   = document.getElementById('support-log-body');
    const cardList= document.getElementById('support-log-card-list');
    if (tbody)    tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:30px;color:var(--text-muted);">로드 중...</td></tr>';
    if (cardList) cardList.innerHTML = '';

    try {
        const res  = await fetch(`admin.php?action=donations_list&range=${range}&limit=200`);
        const data = await res.json();
        if (!data.success) {
            if (data.message === '인증이 필요합니다.') { switchApp('admin-login','Admin Login','var(--sys-primary)','lock'); return; }
            vibeToast(data.message, 'error'); return;
        }

        // KPI 카드 렌더링 (stats는 어드민 응답에만 포함)
        const s = data.stats || {};
        const fmt = n => Number(n||0).toLocaleString();
        const setKpi = (id, val) => { const el = document.getElementById(id); if (el) el.textContent = val; };
        setKpi('kpi-total-amount', fmt(s.total_amount) + '원');
        setKpi('kpi-completed',    fmt(s.total_count) + '건');
        setKpi('kpi-today-amount', fmt(s.today_amount) + '원');

        // 대기 건수 서브노트 표시 (pending 금액은 총 후원금에서 제외됨을 명확히)
        const pendingNoteEl = document.getElementById('kpi-pending-note');
        const pendingCntEl  = document.getElementById('kpi-pending-count-note');
        const pendingCnt    = parseInt(s.pending_count || 0);
        const pendingAmt    = parseInt(s.pending_amount || 0);
        if (pendingNoteEl) {
            pendingNoteEl.textContent = pendingCnt > 0
                ? `⏳ 대기 ${fmt(pendingAmt)}원 미포함`
                : '';
        }
        if (pendingCntEl) {
            pendingCntEl.textContent = pendingCnt > 0
                ? `⏳ 대기 ${pendingCnt}건 별도`
                : '';
        }

        // 공개 명단 수 계산
        const publicCnt = (data.list || []).filter(d => parseInt(d.is_public) === 1 && d.status === 'completed').length;
        setKpi('kpi-public-cnt', publicCnt + '명');

        // 목록 저장 후 필터 렌더링
        window._donationList = data.list || [];
        const count = document.getElementById('support-log-count');
        if (count) count.textContent = `전체 ${window._donationList.length}건`;
        renderDonationFilter();
    } catch(e) { console.error('fetchDonations error', e); }
};

function renderDonationFilter() {
    const list    = (window._donationList || []).filter(d => !_supportStatusFilter || d.status === _supportStatusFilter);
    const tbody   = document.getElementById('support-log-body');
    const cardList= document.getElementById('support-log-card-list');

    if (!list.length) {
        if (tbody)    tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:30px;color:var(--text-muted);">후원 내역이 없습니다.</td></tr>';
        if (cardList) cardList.innerHTML = '<div style="text-align:center;padding:30px;color:var(--text-muted);font-size:13px;">후원 내역이 없습니다.</div>';
        return;
    }

    const statusBadge = s => {
        const map = { completed:'완료', pending:'대기', cancelled:'취소' };
        const cls = { completed:'status-completed', pending:'status-pending', cancelled:'status-cancelled' };
        return `<span class="donation-status-badge ${cls[s]||''}"> ${map[s]||s}</span>`;
    };
    const publicBtn = (id, isPublic) => {
        const cls  = isPublic ? 'is-public' : 'is-private';
        const label= isPublic ? '✓ 공개중' : '비공개';
        return `<button class="donor-public-btn ${cls}" onclick="toggleDonorPublic(${id},${isPublic?0:1},this)">${label}</button>`;
    };

    const cancelBtn = (id) => {
        return `<button class="donor-public-btn is-private" onclick="forceCancelDonation(${id})" title="관리자 수동 취소">
            ✕ 강제취소
        </button>`;
    };

    // PC 테이블
    if (tbody) {
        tbody.innerHTML = '';
        list.forEach((d, i) => {
            const tr = document.createElement('tr');
            const msg = d.message ? escapeHtml(d.message) : '<span style="color:var(--text-muted);font-size:11px;">-</span>';
            const isPub = parseInt(d.is_public) === 1;
            tr.id = `donation-row-${d.id}`;
            tr.innerHTML = `
                <td>${i+1}</td>
                <td style="font-weight:700;">${escapeHtml(d.donor_name||'익명')}</td>
                <td style="font-size:12px;max-width:180px;word-break:break-all;">${msg}</td>
                <td style="font-weight:700;color:var(--sys-primary);">${Number(d.amount).toLocaleString()}원</td>
                <td>${statusBadge(d.status)}</td>
                <td>${d.status === 'completed' ? publicBtn(d.id, isPub) : (d.status === 'pending' ? cancelBtn(d.id) : '<span style="color:var(--text-muted);font-size:11px;">-</span>')}</td>
                <td style="color:var(--text-muted);font-size:12px;white-space:nowrap;">${toKST(d.created_at)} KST</td>
            `;
            tbody.appendChild(tr);
        });
    }

    // 모바일 카드
    if (cardList) {
        cardList.innerHTML = '';
        list.forEach((d, i) => {
            const isPub = parseInt(d.is_public) === 1;
            const card  = document.createElement('div');
            card.className = 'admin-card';
            card.innerHTML = `
                <div class="admin-card-row">
                    <span class="admin-card-no">#${i+1}</span>
                    ${statusBadge(d.status)}
                    <span class="admin-card-time">${toKST(d.created_at)} KST</span>
                </div>
                <div class="admin-card-email">${escapeHtml(d.donor_name||'익명')}</div>
                ${d.message ? `<div style="font-size:12px;color:var(--text-muted);">${escapeHtml(d.message)}</div>` : ''}
                <div class="admin-card-row">
                    <span style="font-weight:700;color:var(--sys-primary);">${Number(d.amount).toLocaleString()}원</span>
                    ${d.status === 'completed' ? publicBtn(d.id, isPub) : ''}
                </div>
            `;
            cardList.appendChild(card);
        });
    }

    if (window.lucide) window.lucide.createIcons();
}

// ── 공개/비공개 토글 ────────────────────────────
window.toggleDonorPublic = async function(id, newVal, btn) {
    try {
        const res  = await fetch('admin.php', {
            method : 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body   : `action=donations_toggle_public&id=${id}&is_public=${newVal}`
        });
        const data = await res.json();
        if (data.success) {
            // 로컬 목록 업데이트 후 재렌더
            if (window._donationList) {
                const item = window._donationList.find(d => d.id == id);
                if (item) item.is_public = newVal;
            }
            // KPI 공개 명단 수 갱신
            const publicCnt = (window._donationList||[]).filter(d => parseInt(d.is_public)===1 && d.status==='completed').length;
            const el = document.getElementById('kpi-public-cnt');
            if (el) el.textContent = publicCnt + '명';
            renderDonationFilter();
            vibeToast(newVal ? '명단 공개로 전환했습니다.' : '명단 비공개로 전환했습니다.', 'success');
        } else {
            vibeToast('변경 실패: ' + data.message, 'error');
        }
    } catch(e) {
        vibeToast('네트워크 오류가 발생했습니다.', 'error');
    }
};

// ── donation_attempts 조회 ──────────────────────
window.fetchAttempts = async function() {
    const tbody = document.getElementById('support-attempt-body');
    if (tbody) tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;padding:20px;color:var(--text-muted);">로드 중...</td></tr>';
    try {
        const res  = await fetch('admin.php?action=donation_attempts_list&limit=50');
        const data = await res.json();
        if (!data.success) return;

        const cnt = document.getElementById('support-attempt-count');
        if (cnt) cnt.textContent = `최근 ${(data.list||[]).length}건`;

        const statusBadge = s => {
            const cls = s==='cancelled'?'status-cancelled':s==='completed'?'status-completed':'status-pending';
            return `<span class="donation-status-badge ${cls}">${s}</span>`;
        };

        if (!tbody) return;
        if (!data.list || !data.list.length) {
            tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;padding:20px;color:var(--text-muted);">취소/실패 이력이 없습니다.</td></tr>';
            return;
        }
        tbody.innerHTML = '';
        data.list.forEach((d, i) => {
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td>${i+1}</td>
                <td style="font-size:12px;">${payStateLabel(d.pay_state)}</td>
                <td>${statusBadge(d.status)}</td>
                <td style="font-weight:700;">${Number(d.amount||0).toLocaleString()}원</td>
                <td style="color:var(--text-muted);font-size:12px;">${toKST(d.created_at)} KST</td>
            `;
            tbody.appendChild(tr);
        });
        if (window.lucide) window.lucide.createIcons();
    } catch(e) { console.error('fetchAttempts error', e); }
};

// ── 관리자 수동 취소 ────────────────────────────────────────
window.forceCancelDonation = async function(id) {
    if (!confirm(`ID ${id} 후원 건을 수동 취소 처리하시겠습니까?\nPG사에서 이미 취소된 경우에만 사용하세요.`)) return;
    try {
        const res  = await fetch('admin.php', {
            method : 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body   : `action=donation_force_cancel&id=${id}&note=관리자 수동 취소(PG 직접 취소 확인)`
        });
        const data = await res.json();
        if (data.success) {
            // 로컬 목록 업데이트
            if (window._donationList) {
                const item = window._donationList.find(d => d.id == id);
                if (item) item.status = 'cancelled';
            }
            renderDonationFilter();
            fetchDonations(); // KPI 갱신
            vibeToast('취소 처리되었습니다.', 'success');
        } else {
            vibeToast('처리 실패: ' + data.message, 'error');
        }
    } catch(e) {
        vibeToast('네트워크 오류가 발생했습니다.', 'error');
    }
};
