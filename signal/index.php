<?php
/**
 * signal/index.php — Signal 서비스 메인 페이지
 * DB에서 발행된 Signal 목록을 조회해 자동으로 표시
 */

define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/config.php';

// ── DB에서 발행 목록 조회 ──────────────────────────────────
try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET,
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $stmt = $pdo->query(
        "SELECT publish_date, news_ids, og_image, editor_note
         FROM news_pages
         ORDER BY publish_date DESC
         LIMIT 60"
    );
    $pages = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $pages = [];
}

// ── 날짜 → 한국어 형식 ────────────────────────────────────
function date_ko(string $d): string {
    $ts = strtotime($d);
    return date('Y년 n월 j일', $ts) . ' (' . ['일','월','화','수','목','금','토'][date('w', $ts)] . ')';
}

function count_news(string $json): int {
    $arr = json_decode($json, true);
    return is_array($arr) ? count($arr) : 0;
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Signal — 매일 아침 AI 뉴스 | Vibe Studio</title>
    <meta name="description" content="Vibe Studio가 매일 선별·요약한 AI 뉴스. 읽고 싶으면 웹에서, 놓치고 싶지 않으면 Signal로.">
    <link rel="canonical" href="https://vibestudio.prisincera.com/signal">
    <meta property="og:title" content="Signal — 매일 아침 AI 뉴스 | Vibe Studio">
    <meta property="og:description" content="AI 세계의 노이즈를 걷어내고 오늘 꼭 알아야 할 것만 골라드립니다.">
    <meta property="og:url" content="https://vibestudio.prisincera.com/signal">
    <link rel="preconnect" href="https://unpkg.com">
    <link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
    <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'%3E%3Cdefs%3E%3ClinearGradient id='g' x1='0%25' y1='0%25' x2='100%25' y2='100%25'%3E%3Cstop offset='0%25' stop-color='%23a855f7'/%3E%3Cstop offset='100%25' stop-color='%235e5ce6'/%3E%3C/linearGradient%3E%3C/defs%3E%3Crect width='100' height='100' rx='25' fill='url(%23g)'/%3E%3Ctext x='50' y='74' font-size='65' font-family='-apple-system,sans-serif' font-weight='900' fill='white' text-anchor='middle'%3EV%3C/text%3E%3C/svg%3E">
    <script>(function(){var t=localStorage.getItem('vibe-theme')||'dark';document.documentElement.setAttribute('data-theme',t);})();</script>
    <link href="https://cdn.jsdelivr.net/gh/orioncactus/pretendard@v1.3.8/dist/web/variable/pretendardvariable.css" rel="stylesheet">
    <script src="https://unpkg.com/lucide@0.344.0/dist/umd/lucide.min.js"></script>
    <style>
        :root {
            --bg:#09090b; --bg2:#111114;
            --text-main:#f4f4f5; --text-sub:rgba(255,255,255,.65); --text-muted:rgba(255,255,255,.45);
            --glass-bg:rgba(24,24,27,.72); --glass-border:rgba(255,255,255,.09);
            --glass-shadow:0 20px 50px rgba(0,0,0,.6);
            --accent:#818cf8; --accent2:#38bdf8;
        }
        [data-theme="light"] {
            --bg:#f8f8fc; --bg2:#f0f0f8;
            --text-main:#18181b; --text-sub:#52525b; --text-muted:#71717a;
            --glass-bg:rgba(255,255,255,.8); --glass-border:rgba(0,0,0,.08);
            --glass-shadow:0 20px 50px rgba(0,0,0,.12);
            --accent:#5e5ce6; --accent2:#0284c7;
        }
        *,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
        html{scroll-behavior:smooth;}
        body{font-family:'Pretendard Variable',-apple-system,BlinkMacSystemFont,system-ui,sans-serif;background:var(--bg);color:var(--text-main);line-height:1.6;overflow-x:hidden;min-height:100vh;}
        /* Grid BG */
        body::before{content:'';position:fixed;inset:0;background-image:linear-gradient(rgba(255,255,255,.025) 1px,transparent 1px),linear-gradient(90deg,rgba(255,255,255,.025) 1px,transparent 1px);background-size:48px 48px;pointer-events:none;z-index:0;}
        [data-theme="light"] body::before{background-image:linear-gradient(rgba(0,0,0,.04) 1px,transparent 1px),linear-gradient(90deg,rgba(0,0,0,.04) 1px,transparent 1px);}
        /* Spotlight */
        #spotlight{position:fixed;width:700px;height:700px;border-radius:50%;background:radial-gradient(circle,rgba(129,140,248,.12) 0%,rgba(56,189,248,.05) 40%,transparent 70%);pointer-events:none;transform:translate(-50%,-50%);z-index:0;transition:opacity .4s;}
        /* Layout */
        .wrapper{position:relative;z-index:1;max-width:1100px;margin:0 auto;padding:0 24px;}
        /* Nav Drawer (공통) */
        .nav-drawer{position:fixed;top:0;left:0;width:min(320px,85vw);height:100dvh;background:var(--glass-bg);backdrop-filter:blur(40px) saturate(180%);-webkit-backdrop-filter:blur(40px) saturate(180%);border-right:1px solid var(--glass-border);box-shadow:6px 0 32px rgba(0,0,0,.28);z-index:20000;transform:translateX(-100%);transition:transform .32s cubic-bezier(.16,1,.3,1);display:flex;flex-direction:column;overflow:hidden;}
        .nav-drawer.open{transform:translateX(0);}
        .nav-drawer-header{display:flex;align-items:center;justify-content:space-between;padding:18px 20px;border-bottom:1px solid var(--glass-border);flex-shrink:0;}
        .nav-drawer-brand{display:flex;align-items:center;gap:9px;font-weight:800;font-size:15px;color:var(--text-main);text-decoration:none;}
        .nav-drawer-brand .lightning{width:28px;height:28px;background:linear-gradient(135deg,#a855f7,#5e5ce6);border-radius:8px;display:flex;align-items:center;justify-content:center;}
        .nav-drawer-brand .lightning svg{width:15px;height:15px;fill:#fff;stroke:none;}
        .nav-drawer-close{width:32px;height:32px;border:none;background:transparent;border-radius:8px;cursor:pointer;display:flex;align-items:center;justify-content:center;color:var(--text-muted);transition:background .2s,color .2s;}
        .nav-drawer-close:hover{background:var(--glass-border);color:var(--text-main);}
        .nav-drawer-body{flex:1;padding:16px 12px;display:flex;flex-direction:column;gap:4px;overflow-y:auto;}
        .nav-drawer-item{display:flex;align-items:center;gap:12px;padding:12px 14px;border-radius:12px;font-size:15px;font-weight:700;color:var(--text-main);text-decoration:none;border:none;background:transparent;cursor:pointer;width:100%;text-align:left;transition:background .18s,transform .15s;}
        .nav-drawer-item:hover{background:rgba(255,255,255,.07);transform:translateX(3px);}
        .nav-drawer-item.active{background:rgba(168,85,247,.15);color:#c084fc;}
        .nav-drawer-item-icon{width:36px;height:36px;border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
        .nav-drawer-footer{padding:18px 20px;border-top:1px solid var(--glass-border);flex-shrink:0;}
        .nav-drawer-footer-brand{display:flex;align-items:center;gap:6px;font-weight:800;font-size:12px;color:var(--text-main);margin-bottom:6px;}
        .nav-drawer-footer-copy{font-size:10px;color:var(--text-muted);opacity:.6;}
        .nav-drawer-overlay{position:fixed;inset:0;background:rgba(0,0,0,.48);backdrop-filter:blur(4px);z-index:19999;opacity:0;pointer-events:none;transition:opacity .32s;}
        .nav-drawer-overlay.open{opacity:1;pointer-events:auto;}
        /* Fade-up */
        .fade-up{opacity:0;transform:translateY(36px);transition:opacity .75s cubic-bezier(.2,.8,.2,1),transform .75s cubic-bezier(.2,.8,.2,1);}
        .fade-up.visible{opacity:1;transform:translateY(0);}
        /* Glass Card */
        .glass{background:var(--glass-bg);backdrop-filter:blur(20px) saturate(160%);-webkit-backdrop-filter:blur(20px) saturate(160%);border:1px solid var(--glass-border);border-radius:20px;box-shadow:var(--glass-shadow);overflow:hidden;transition:border-color .25s,box-shadow .25s,transform .12s;}

        /* ── Hero ── */
        .hero{padding:140px 0 70px;text-align:center;}
        .hero-badge{display:inline-flex;align-items:center;gap:6px;background:rgba(129,140,248,.12);border:1px solid rgba(129,140,248,.3);color:var(--accent);font-size:12px;font-weight:700;letter-spacing:.5px;padding:5px 14px;border-radius:100px;margin-bottom:28px;text-transform:uppercase;}
        .hero-badge i{width:13px;height:13px;}
        .hero h1{font-size:clamp(2.4rem,6vw,3.8rem);font-weight:900;letter-spacing:-.04em;line-height:1.2;margin-bottom:20px;background:linear-gradient(145deg,#fff 30%,#a5b4fc 70%,#38bdf8 100%);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;word-break:keep-all;}
        [data-theme="light"] .hero h1{background:linear-gradient(145deg,#18181b 30%,#5e5ce6 75%,#0284c7 100%);-webkit-background-clip:text;background-clip:text;}
        .hero-sub{font-size:1.1rem;color:var(--text-sub);max-width:580px;margin:0 auto 48px;word-break:keep-all;}

        /* ── Features ── */
        .features{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:16px;margin-bottom:100px;}
        .feature-card{padding:28px;border-radius:20px;text-align:left;}
        .feature-icon{width:44px;height:44px;border-radius:12px;display:flex;align-items:center;justify-content:center;margin-bottom:16px;}
        .feature-icon i{width:22px;height:22px;}
        .feature-card h3{font-size:1rem;font-weight:700;margin-bottom:8px;}
        .feature-card p{font-size:.88rem;color:var(--text-sub);line-height:1.7;}

        /* ── Section Title ── */
        .section-title{font-size:clamp(1.6rem,4vw,2.2rem);font-weight:900;letter-spacing:-.03em;margin-bottom:8px;}
        .section-sub{font-size:.95rem;color:var(--text-sub);margin-bottom:40px;}

        /* ── Archive Grid ── */
        .archive-section{margin-bottom:100px;}
        .archive-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:16px;}
        .archive-card{border-radius:16px;text-decoration:none;display:block;transition:border-color .2s,transform .15s,box-shadow .2s;overflow:hidden;}
        .archive-card:hover{border-color:rgba(129,140,248,.5)!important;transform:translateY(-2px);box-shadow:0 12px 30px rgba(129,140,248,.1);}
        .archive-thumb{width:100%;height:160px;background-size:cover;background-position:center;background-color:rgba(129,140,248,.06);position:relative;}
        .archive-thumb-placeholder{width:100%;height:160px;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,rgba(129,140,248,.08),rgba(56,189,248,.05));font-size:2.5rem;}
        .archive-card-body{padding:18px 20px;}
        .archive-card-date{font-size:.78rem;color:var(--text-muted);margin-bottom:5px;font-weight:600;}
        .archive-card-title{font-size:.98rem;font-weight:700;color:var(--text-main);margin-bottom:6px;}
        .archive-card-meta{font-size:.76rem;color:var(--text-muted);display:flex;align-items:center;gap:6px;}
        .archive-badge{background:rgba(129,140,248,.12);color:var(--accent);font-size:.72rem;font-weight:700;padding:2px 8px;border-radius:20px;}
        .archive-empty{text-align:center;padding:60px 20px;color:var(--text-muted);}

        /* ── Hero Subscribe Button ── */
        .cta-btn{display:inline-flex;align-items:center;gap:8px;background:linear-gradient(135deg,#818cf8,#5e5ce6);color:#fff;font-weight:700;font-size:.95rem;padding:13px 28px;border-radius:30px;text-decoration:none;transition:opacity .2s,transform .2s;box-shadow:0 8px 24px rgba(129,140,248,.3);}
        .cta-btn:hover{opacity:.9;transform:translateY(-2px);}

        /* ── Footer ── */
        .site-footer{border-top:1px solid var(--glass-border);padding:28px 24px;}
        @media(max-width:640px){.features{grid-template-columns:repeat(2,1fr);gap:10px;}.feature-card{padding:16px;}.feature-card h3{font-size:.88rem;}.feature-card p{font-size:.78rem;}.feature-icon{width:36px;height:36px;margin-bottom:10px;}.archive-grid{grid-template-columns:1fr;}}

        /* ── Signal 구독 모달 ── */
        .sig-modal-overlay{
            display:none;position:fixed;inset:0;z-index:9000;
            background:rgba(0,0,0,.55);backdrop-filter:blur(8px);
            -webkit-backdrop-filter:blur(8px);
            align-items:center;justify-content:center;
        }
        .sig-modal-overlay.open{display:flex;}
        .sig-modal{
            background:var(--glass-bg);border:1px solid var(--glass-border);
            border-radius:22px;padding:36px 32px 28px;
            max-width:400px;width:90%;box-shadow:0 24px 60px rgba(0,0,0,.5);
            text-align:center;animation:sigModalIn .28s cubic-bezier(.34,1.56,.64,1);
            position:relative;overflow:visible;
        }
        @keyframes sigModalIn{from{opacity:0;transform:scale(.88) translateY(16px)}to{opacity:1;transform:none}}
        /* X 버튼 — 모달 외곽선 바깥 우상단 */
        .sig-modal-close{
            position:absolute;top:-14px;right:-14px;
            background:var(--glass-bg);border:1px solid var(--glass-border);
            cursor:pointer;
            color:var(--text-muted);width:30px;height:30px;
            border-radius:50%;display:flex;align-items:center;justify-content:center;
            transition:background .15s,color .15s,border-color .15s;
            box-shadow:0 2px 8px rgba(0,0,0,.3);
        }
        .sig-modal-close:hover{background:rgba(255,255,255,.12);color:var(--text-main);border-color:rgba(255,255,255,.2);}
        [data-theme="light"] .sig-modal-close{background:#fff;box-shadow:0 2px 8px rgba(0,0,0,.15);}
        [data-theme="light"] .sig-modal-close:hover{background:#f3f4f6;}
        .sig-modal-wrap{}
        .sig-modal-icon{
            width:64px;height:64px;border-radius:18px;margin:0 auto 18px;
            background:linear-gradient(135deg,#fb923c,#f97316);
            display:flex;align-items:center;justify-content:center;
            box-shadow:0 8px 22px rgba(251,146,60,.35);
        }
        .sig-modal-title{
            font-size:1.25rem;font-weight:900;letter-spacing:-.03em;
            color:var(--text-main);margin-bottom:8px;
        }
        .sig-modal-desc{
            font-size:.88rem;color:var(--text-sub);line-height:1.65;
            margin:0 0 24px;word-break:keep-all;
        }
        /* Google 버튼 (floating app 동일 스타일) */
        .sig-google-btn{
            display:flex;align-items:center;justify-content:center;gap:10px;
            width:100%;padding:13px 18px;border-radius:12px;
            background:#fff;border:1.5px solid #ddd;
            font-size:.92rem;font-weight:700;color:#3c4043;
            cursor:pointer;transition:box-shadow .2s,transform .15s;
            box-shadow:0 2px 10px rgba(0,0,0,.1);
        }
        .sig-google-btn:hover{box-shadow:0 4px 16px rgba(0,0,0,.16);transform:translateY(-1px);}
        .sig-google-btn:disabled{opacity:.6;cursor:not-allowed;transform:none;}
        .sig-modal-note{
            font-size:.75rem;color:var(--text-muted);
            margin-top:14px;line-height:1.55;
        }
        /* 닫기 보조 버튼 */
        .sig-close-btn{
            display:flex;align-items:center;justify-content:center;
            width:100%;padding:11px 18px;border-radius:11px;
            background:transparent;border:1.5px solid var(--glass-border);
            font-size:.9rem;font-weight:700;color:var(--text-main);
            cursor:pointer;transition:background .18s,border-color .18s;
        }
        .sig-close-btn:hover{background:rgba(255,255,255,.07);border-color:rgba(255,255,255,.2);}
        [data-theme="light"] .sig-close-btn:hover{background:rgba(0,0,0,.04);border-color:rgba(0,0,0,.18);}
        .sig-result{display:none;flex-direction:column;align-items:center;gap:12px;padding:8px 0;}
        .sig-result.show{display:flex;}
        .sig-result-icon{
            width:56px;height:56px;border-radius:50%;
            display:flex;align-items:center;justify-content:center;
            font-size:1.8rem;
        }
        .sig-result-icon.success{background:rgba(52,211,153,.15);}
        .sig-result-icon.error{background:rgba(255,69,58,.12);}
        .sig-result-msg{font-size:.95rem;font-weight:700;color:var(--text-main);}
        .sig-result-sub{font-size:.82rem;color:var(--text-sub);}
    </style>
</head>
<body>

<div id="spotlight"></div>

<!-- ── Nav Drawer ── -->
<div class="nav-drawer" id="navDrawer">
    <div class="nav-drawer-header">
        <a href="/" class="nav-drawer-brand">
            <div class="lightning"><svg viewBox="0 0 24 24"><path d="M13 2L3 14H12L11 22L21 10H12L13 2Z"/></svg></div>
            Vibe Studio
        </a>
        <button class="nav-drawer-close" onclick="closeNavDrawer()" aria-label="닫기">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M18 6 6 18M6 6l12 12"/></svg>
        </button>
    </div>
    <div class="nav-drawer-body">
        <a href="/" class="nav-drawer-item">
            <span class="nav-drawer-item-icon" style="background:rgba(129,140,248,.15)"><i data-lucide="home" style="color:#818cf8"></i></span>Home
        </a>
        <a href="/history" class="nav-drawer-item">
            <span class="nav-drawer-item-icon" style="background:rgba(168,85,247,.15)"><i data-lucide="clock" style="color:#a855f7"></i></span>History
        </a>
        <a href="/signal" class="nav-drawer-item active" style="color:#fb923c;">
            <span class="nav-drawer-item-icon" style="background:rgba(251,146,60,.15)"><i data-lucide="zap" style="color:#fb923c"></i></span>Signal
        </a>
        <a href="/about" class="nav-drawer-item" style="color:#34d399;">
            <span class="nav-drawer-item-icon" style="background:rgba(52,211,153,.15)"><i data-lucide="layout-grid" style="color:#34d399"></i></span>Apps
        </a>
        <a href="/contact" class="nav-drawer-item">
            <span class="nav-drawer-item-icon" style="background:rgba(52,211,153,.15)"><i data-lucide="mail" style="color:#34d399"></i></span>Contact
        </a>
    </div>
    <div class="nav-drawer-footer">
        <div class="nav-drawer-footer-brand"><i data-lucide="zap" style="width:12px;height:12px;"></i> Vibe Studio</div>
        <div class="nav-drawer-footer-copy">© 2026 PriSincera. All rights reserved.</div>
    </div>
</div>
<div class="nav-drawer-overlay" id="navOverlay" onclick="closeNavDrawer()"></div>

<!-- ── Top Nav (공통 layout.js 주입) ── -->
<header class="top-nav" id="site-nav"></header>

<!-- ── Hero ── -->
<section class="hero">
    <div class="wrapper">
        <div class="hero-badge fade-up"><i data-lucide="zap"></i> Signal by Vibe Studio</div>
        <h1 class="fade-up">AI 세계의 노이즈를 걷어내고<br>오늘 꼭 알아야 할 것만</h1>
        <p class="hero-sub fade-up">매일 아침 8시, Vibe Studio가 직접 선별·요약한 AI 뉴스를 웹에 게시합니다.<br>놓치고 싶지 않다면 이메일 알림을 받아보세요.</p>
        <div class="fade-up" style="margin-top:28px;">
            <button class="cta-btn" id="signalSubscribeBtn" onclick="openSignalSubscribeModal()"><i data-lucide="zap"></i> Signal 구독하기</button>
        </div>
    </div>
</section>

<!-- ── Features ── -->
<section style="padding-bottom:80px;">
    <div class="wrapper">
        <div class="features fade-up">
            <div class="feature-card glass">
                <div class="feature-icon" style="background:rgba(129,140,248,.15)"><i data-lucide="filter" style="color:#818cf8"></i></div>
                <h3>큐레이션 필터</h3>
                <p>매일 수백 개의 AI 뉴스 중 신뢰도·중요도 기반으로 최대 8건을 자동 선별합니다. 노이즈 없이 핵심만.</p>
            </div>
            <div class="feature-card glass">
                <div class="feature-icon" style="background:rgba(56,189,248,.15)"><i data-lucide="book-open" style="color:#38bdf8"></i></div>
                <h3>한국어 요약</h3>
                <p>Gemini AI가 원문을 한국어로 2~3문장 요약합니다. 영어 기사도 빠르게 파악할 수 있습니다.</p>
            </div>
            <div class="feature-card glass">
                <div class="feature-icon" style="background:rgba(251,146,60,.15)"><i data-lucide="archive" style="color:#fb923c"></i></div>
                <h3>누적 아카이브</h3>
                <p>매일 새 페이지가 쌓입니다. 인터넷 검색으로 누구나 방문할 수 있고, 날짜별로 모든 내용을 확인할 수 있습니다.</p>
            </div>
            <div class="feature-card glass">
                <div class="feature-icon" style="background:rgba(52,211,153,.15)"><i data-lucide="mail" style="color:#34d399"></i></div>
                <h3>이메일 알림</h3>
                <p>구독하면 매일 오전 8시 헤드라인 알림을 받습니다. 짧고 강렬하게, 클릭으로 전체 내용 확인.</p>
            </div>
        </div>
    </div>
</section>

<!-- ── Archive ── -->
<section class="archive-section">
    <div class="wrapper">
        <h2 class="section-title fade-up" style="text-align:center;">Signal 아카이브</h2>
        <p class="section-sub fade-up" style="text-align:center;">오늘까지 총 <strong><?= count($pages) ?>개</strong>의 Signal이 발행되었습니다</p>

        <?php if (empty($pages)): ?>
        <div class="archive-empty fade-up">
            <p>아직 발행된 Signal이 없습니다. 내일 첫 번째 Signal을 만나보세요! ⚡</p>
        </div>
        <?php else: ?>
        <div class="archive-grid">
            <?php foreach ($pages as $i => $p):
                $cnt    = count_news($p['news_ids'] ?? '[]');
                $dateKo = date_ko($p['publish_date']);
                $url    = '/signal/' . $p['publish_date']; // Clean URL
                $note   = mb_strimwidth($p['editor_note'] ?? '오늘의 AI 핵심 뉴스', 0, 80, '...');
                $delay  = $i * 50;
            ?>
            <a href="<?= htmlspecialchars($url) ?>" class="archive-card glass fade-up" style="transition-delay:<?= $delay ?>ms;">
                <?php if (!empty($p['og_image'])): ?>
                <div class="archive-thumb" style="background-image:url('<?= htmlspecialchars($p['og_image']) ?>')"></div>
                <?php else: ?>
                <div class="archive-thumb-placeholder">⚡</div>
                <?php endif; ?>
                <div class="archive-card-body">
                    <div class="archive-card-date"><?= $dateKo ?></div>
                    <div class="archive-card-title"><?= htmlspecialchars($note) ?></div>
                    <div class="archive-card-meta">
                        <span class="archive-badge">📰 <?= $cnt ?>건 선별</span>
                        <span>Vibe Studio Signal</span>
                    </div>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</section>


<!-- ── Signal 구독 모달 ── -->
<div class="sig-modal-overlay" id="sigModalOverlay" onclick="_sigOutsideClose(event)">
    <div class="sig-modal" id="sigModal">
        <div class="sig-modal-wrap">
            <button class="sig-modal-close" onclick="closeSignalSubscribeModal()" aria-label="닫기">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>

            <!-- 입력 뷰 -->
            <div id="sigInputView">
                <div class="sig-modal-icon">
                    <svg viewBox="0 0 24 24" fill="white" style="width:28px;height:28px"><path d="M13 2L3 14H12L11 22L21 10H12L13 2Z"/></svg>
                </div>
                <h2 class="sig-modal-title">Signal 구독</h2>
                <p class="sig-modal-desc">
                    매일 아침 8시, AI 핵심 뉴스를<br>
                    이메일로 받아보세요.<br>
                    <span style="font-size:.8rem;opacity:.7;">Google 계정으로 10초 만에 완료됩니다.</span>
                </p>

                <button class="sig-google-btn" id="sigGoogleBtn" onclick="startSignalGoogleLogin()">
                    <svg width="18" height="18" viewBox="0 0 48 48">
                        <path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/>
                        <path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/>
                        <path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/>
                        <path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.18 1.48-4.97 2.31-8.16 2.31-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/>
                    </svg>
                    <span id="sigGoogleBtnText">Google로 구독하기</span>
                </button>

                <p class="sig-modal-note">이메일 주소만 수집되며, 언제든 구독 해지 가능합니다.</p>
            </div>

            <!-- 결과 뷰 -->
            <div class="sig-result" id="sigResultView">
                <div class="sig-result-icon success" id="sigResultIcon">✅</div>
                <div class="sig-result-msg" id="sigResultMsg">구독 완료!</div>
                <div class="sig-result-sub" id="sigResultSub">내일 아침 8시 첫 번째 Signal을 받아보세요.</div>
                <div style="display:flex;flex-direction:column;gap:8px;margin-top:8px;width:100%;">
                <button class="sig-close-btn" onclick="closeSignalSubscribeModal()">닫기</button>
                    <button id="sigSwitchBtn" onclick="_sigSwitchAccount()" style="background:none;border:none;cursor:pointer;font-size:.78rem;color:var(--text-muted);display:flex;align-items:center;justify-content:center;gap:5px;padding:6px;border-radius:8px;transition:color .15s,background .15s;">
                        <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M23 4v6h-6"/><path d="M1 20v-6h6"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
                        다른 구글 계정으로 신청하기
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ── Footer ── -->
<footer class="site-footer" id="site-footer"></footer>

<script>window.LAYOUT_ROOT = '/';</script>
<script src="/js/layout.js"></script>
<script>
    // Spotlight
    document.addEventListener('mousemove', function(e) {
        var s = document.getElementById('spotlight');
        if (s) { s.style.left = e.clientX + 'px'; s.style.top = e.clientY + 'px'; }
    });
    // Nav Drawer
    function openNavDrawer() {
        document.getElementById('navDrawer').classList.add('open');
        document.getElementById('navOverlay').classList.add('open');
        document.body.style.overflow = 'hidden';
    }
    function closeNavDrawer() {
        document.getElementById('navDrawer').classList.remove('open');
        document.getElementById('navOverlay').classList.remove('open');
        document.body.style.overflow = '';
    }
    // Theme
    function toggleTheme() {
        var t = document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
        document.documentElement.setAttribute('data-theme', t);
        localStorage.setItem('vibe-theme', t);
        var icon = document.getElementById('themeIcon');
        if (icon) icon.setAttribute('data-lucide', t === 'dark' ? 'sun' : 'moon');
        if (window.lucide) lucide.createIcons();
    }
    // Fade-up Observer
    var observer = new IntersectionObserver(function(entries) {
        entries.forEach(function(e) { if (e.isIntersecting) { e.target.classList.add('visible'); observer.unobserve(e.target); } });
    }, { threshold: 0.1 });
    document.querySelectorAll('.fade-up').forEach(function(el) { observer.observe(el); });

    if (window.lucide) lucide.createIcons();

    /* ── Signal 구독 모달 ── */
    let _sigFlowState = 'idle';

    window.openSignalSubscribeModal = async function() {
        document.getElementById('sigModalOverlay').classList.add('open');
        document.body.style.overflow = 'hidden';
        _sigReset();

        // ── 서버 Google 세션 자동 체크 ──
        // 어디서든 Google 로그인 했으면 즉시 구독 처리
        try {
            const sr = await fetch('/admin.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=fan_google_session_check'
            });
            const sj = await sr.json();
            if (sj.success && sj.logged_in) {
                // 세션 유효 → 즉시 Signal 구독 등록
                await _sigRegisterContent(sj.email);
            }
            // 세션 없으면 Google 로그인 화면 유지 (그대로)
        } catch(e) {
            // 체크 실패 시 Google 로그인 화면 유지
        }
    };

    window.closeSignalSubscribeModal = function() {
        document.getElementById('sigModalOverlay').classList.remove('open');
        document.body.style.overflow = '';
    };
    window._sigOutsideClose = function(e) {
        if (e.target === document.getElementById('sigModalOverlay')) closeSignalSubscribeModal();
    };

    // 다른 구글 계정으로 신청하기 — 세션 clear → Google 로그인 화면 복귀
    window._sigSwitchAccount = async function() {
        try {
            await fetch('/admin.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=fan_google_session_clear'
            });
        } catch(e) { /* 무시 */ }
        _sigReset();
    };

    function _sigReset() {
        _sigFlowState = 'idle';
        const btn  = document.getElementById('sigGoogleBtn');
        const text = document.getElementById('sigGoogleBtnText');
        if (btn)  btn.disabled = false;
        if (text) text.textContent = 'Google로 구독하기';
        document.getElementById('sigInputView').style.display = '';
        document.getElementById('sigResultView').classList.remove('show');
    }

    function _sigShowResult(ok, msg, sub) {
        document.getElementById('sigInputView').style.display = 'none';
        const rv   = document.getElementById('sigResultView');
        const icon = document.getElementById('sigResultIcon');
        rv.classList.add('show');
        icon.textContent = ok ? '✅' : '❌';
        icon.className   = 'sig-result-icon ' + (ok ? 'success' : 'error');
        document.getElementById('sigResultMsg').textContent = msg;
        document.getElementById('sigResultSub').textContent = sub;
        // 실패 시 계정 전환 버튼 숨김
        const sw = document.getElementById('sigSwitchBtn');
        if (sw) sw.style.display = ok ? '' : 'none';
    }

    // ── Signal 구독 최종 등록 (content=true 고정) ────────────
    async function _sigRegisterContent(email) {
        try {
            const rr = await fetch('/mail_auth.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=signal_subscribe'
            });
            const rj = await rr.json();
            if (rj.success) {
                _sigFlowState = 'idle';
                const isUpdate = rj.message && rj.message.includes('업데이트');
                _sigShowResult(
                    true,
                    isUpdate ? '구독 정보가 업데이트되었습니다! ✨' : 'Signal 구독 완료! 🎉',
                    `${email} 으로 매일 아침 8시 AI 뉴스를 보내드릴게요.`
                );
            } else {
                throw new Error(rj.message);
            }
        } catch(e) {
            _sigFlowState = 'idle';
            const btn  = document.getElementById('sigGoogleBtn');
            const text = document.getElementById('sigGoogleBtnText');
            if (btn)  btn.disabled = false;
            if (text) text.textContent = 'Google로 구독하기';
            _sigShowResult(false, '등록 실패', e.message || '잠시 후 다시 시도해주세요.');
        }
    }

    window.startSignalGoogleLogin = function() {
        const btn  = document.getElementById('sigGoogleBtn');
        const text = document.getElementById('sigGoogleBtnText');
        btn.disabled = true;
        text.textContent = 'Google 연결 중...';
        _sigFlowState = 'google_pending';

        const pw = 500, ph = 620;
        const pl = Math.max(0, (screen.width  - pw) / 2);
        const pt = Math.max(0, (screen.height - ph) / 2);
        const popup = window.open(
            '/google_fan_oauth.php',
            'vibeSignalOAuth',
            `width=${pw},height=${ph},left=${pl},top=${pt},scrollbars=yes,resizable=yes`
        );
        if (!popup || popup.closed) {
            btn.disabled = false;
            text.textContent = 'Google로 구독하기';
            _sigFlowState = 'idle';
            alert('팝업이 차단되었습니다. 브라우저에서 팝업을 허용해주세요.');
            return;
        }
        const chk = setInterval(() => {
            if (popup.closed && _sigFlowState === 'google_pending') {
                clearInterval(chk);
                btn.disabled = false;
                text.textContent = 'Google로 구독하기';
                _sigFlowState = 'idle';
            }
        }, 800);
    };

    /* postMessage 수신 (google_fan_oauth.php → 부모) */
    window.addEventListener('message', async function(event) {
        if (event.origin !== window.location.origin) return;
        const d = event.data?.vibe_fan_oauth;
        if (!d) return;
        if (_sigFlowState !== 'google_pending') return;

        const btn  = document.getElementById('sigGoogleBtn');
        const text = document.getElementById('sigGoogleBtnText');

        if (d.status === 'success') {
            const { email, google_id, name } = d;
            // 1) 서버 세션 저장
            try {
                const sr = await fetch('/admin.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: `action=fan_google_session_set&email=${encodeURIComponent(email)}&google_id=${encodeURIComponent(google_id||'')}&name=${encodeURIComponent(name||'')}`
                });
                const sj = await sr.json();
                if (!sj.success) throw new Error(sj.message);
            } catch(e) {
                _sigFlowState = 'idle';
                if (btn) btn.disabled = false;
                if (text) text.textContent = 'Google로 구독하기';
                _sigShowResult(false, '인증 처리 오류', '잠시 후 다시 시도해주세요.');
                return;
            }
            // 2) Signal 구독 등록
            await _sigRegisterContent(email);

        } else if (d.status === 'cancelled') {
            _sigFlowState = 'idle';
            if (btn) btn.disabled = false;
            if (text) text.textContent = 'Google로 구독하기';
        } else {
            _sigFlowState = 'idle';
            if (btn) btn.disabled = false;
            if (text) text.textContent = 'Google로 구독하기';
            _sigShowResult(false, '구글 인증 실패', '다른 계정으로 다시 시도해주세요.');
        }
    });
</script>
</body>
</html>
