// ==========================================
// 🔐 Admin Application Logic
// ==========================================

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
        if (!data.success) { alert(data.message); return; }
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
    if (!id || !pass) { alert('아이디와 비밀번호를 입력해주세요.'); return; }
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
            alert(data.message);
        }
    } catch (e) {
        alert('로그인 오류가 발생했습니다.');
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
            } else alert(data.message);
        }
    } catch (e) { console.error(e); }
};

window.updateAdminList = function (list) {
    const tbody    = document.getElementById('admin-list-body');
    const cardList = document.getElementById('admin-card-list');
    if (!tbody && !cardList) return;

    if (list.length === 0) {
        if (tbody)    tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;padding:40px;color:var(--text-muted);">신청자가 없습니다.</td></tr>';
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
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td>${i+1}</td>
                <td style="font-weight:600;">${item.email}</td>
                <td><span class="admin-badge" style="background:rgba(94,92,230,0.1);color:var(--sys-primary);">${apps.join(', ') || '-'}</span></td>
                <td>${parseInt(item.marketing_consent) ? '✅' : '❌'}</td>
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
            const card = document.createElement('div');
            card.className = 'admin-card';
            card.innerHTML = `
                <div class="admin-card-row">
                    <span class="admin-card-no">#${i+1}</span>
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
            alert('저장 실패: ' + data.message);
            // 실패 시 체크박스 원상복구
            fetchAppSettings();
        }
    } catch (e) {
        alert('네트워크 오류가 발생했습니다.');
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
            fetchAdminAccounts();
        } else {
            alert('삭제 실패: ' + data.message);
        }
    } catch (e) {
        alert('네트워크 오류가 발생했습니다.');
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
        alert('비밀번호는 최소 4자 이상이어야 합니다.');
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
            fetchAdminAccounts(); // 목록 전체 새로고침
        } else {
            alert('수정 실패: ' + data.message);
        }
    } catch (e) {
        alert('네트워크 오류가 발생했습니다.');
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



