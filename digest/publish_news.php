<?php
/**
 * publish_news.php — 오늘의 Signal 웹 페이지 자동 생성 (Vibe Studio 디자인 개선)
 * Cron: 0 23 * * * /opt/bitnami/php/bin/php /opt/bitnami/apache2/htdocs/digest/publish_news.php
 */

date_default_timezone_set('Asia/Seoul'); // KST 기준 날짜 통일
define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/config.php';

$LOG_FILE    = '/tmp/signal_publish.log';
// CLI 날짜 오버라이드: php publish_news.php 2026-03-28 [--force]
$_date_arg   = null;
foreach ($argv ?? [] as $_a) { if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $_a)) { $_date_arg = $_a; break; } }
$_dt         = new DateTime($_date_arg ?? 'now', new DateTimeZone('Asia/Seoul'));
$TODAY       = $_dt->format('Y-m-d');
$TODAY_KO    = $_dt->format('Y년 n월 j일');
$WEEKDAY     = ['일','월','화','수','목','금','토'][(int)$_dt->format('w')];
$OUTPUT_DIR  = BASE_PATH . '/signal';
$OUTPUT_FILE = $OUTPUT_DIR . '/' . $TODAY . '.html';

function log_msg(string $msg): void {
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
    echo $line;
    file_put_contents($GLOBALS['LOG_FILE'], $line, FILE_APPEND);
}

log_msg("==============================================");
log_msg("publish_news.php 시작 | {$TODAY}");

// ── DB 연결 ───────────────────────────────────────────────
try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET,
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    log_msg("✗ DB 연결 실패: " . $e->getMessage());
    exit(1);
}

// ── selected 기사 조회 (KST 날짜 기준) ──────────────────
$stmt = $pdo->prepare(
    "SELECT id, title, url, source_name, category, score, summary_ko
     FROM ai_news
     WHERE status = 'selected'
       AND DATE(CONVERT_TZ(collected_at,'+00:00','+09:00')) = :today
     ORDER BY score DESC"
);
$stmt->execute([':today' => $TODAY]);
$news = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($news)) {
    log_msg("✗ selected 기사 없음 — 종료");
    exit(1);
}

// ── 당일 재실행 방지 가드 ────────────────────────────────────
// HTML이 이미 존재하면 DB 불일치를 막기 위해 재실행을 차단합니다.
// 강제 재생성이 필요하면: php publish_news.php --force
$force = in_array('--force', $argv ?? []);
if (!$force && file_exists($OUTPUT_FILE)) {
    log_msg("⚠ 오늘({$TODAY}) HTML이 이미 존재합니다. 재실행을 건너뜁니다.");
    log_msg("  → 강제 재생성: php publish_news.php --force");
    exit(0);
}

log_msg("게시 기사: " . count($news) . "건");

// ── signal/ 디렉토리 생성 ─────────────────────────────────
if (!is_dir($OUTPUT_DIR)) { mkdir($OUTPUT_DIR, 0755, true); }

// ── OG 이미지 수집 ────────────────────────────────────────────
function get_og_image(string $url, int $timeout = 10): ?string {
    $ctx = stream_context_create(['http' => [
        'timeout'         => $timeout,
        'user_agent'      => 'Signal-Bot/1.0 (vibestudio.prisincera.com)',
        'follow_location' => true,
        'max_redirects'   => 3,
        'ignore_errors'   => true,
    ]]);
    $html = @file_get_contents($url, false, $ctx);
    if (!$html) return null;
    // content="..." property="og:image" 혹은 반대 순서
    $found = null;
    foreach ([
        '/<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)["\']/',
        '/<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:image["\']/',
        '/<meta[^>]+name=["\']og:image["\'][^>]+content=["\']([^"\']+)["\']/',
    ] as $pat) {
        if (preg_match($pat, $html, $m)) { $found = trim($m[1]); break; }
    }
    if (!$found) return null;
    // 상대경로 → 절대경로 변환
    if (strpos($found, 'http') === 0) return $found;
    $parsed = parse_url($url);
    $base   = $parsed['scheme'] . '://' . $parsed['host'];
    return $base . '/' . ltrim($found, '/');
}

// ── 카테고리 설정 ─────────────────────────────────────────
function cat_config(string $cat): array {
    $map = [
        'research' => ['icon'=>'flask-conical', 'label'=>'연구·논문',    'color'=>'#818cf8', 'bg'=>'rgba(129,140,248,.12)'],
        'bigtech'  => ['icon'=>'building-2',    'label'=>'빅테크',        'color'=>'#38bdf8', 'bg'=>'rgba(56,189,248,.12)'],
        'tools'    => ['icon'=>'wrench',         'label'=>'도구·제품',     'color'=>'#34d399', 'bg'=>'rgba(52,211,153,.12)'],
        'industry' => ['icon'=>'bar-chart-2',   'label'=>'산업·비즈니스', 'color'=>'#fbbf24', 'bg'=>'rgba(251,191,36,.12)'],
        'korea'    => ['icon'=>'map-pin',        'label'=>'국내 AI',       'color'=>'#f87171', 'bg'=>'rgba(248,113,113,.12)'],
        'tips'     => ['icon'=>'lightbulb',      'label'=>'실용 팁',       'color'=>'#a855f7', 'bg'=>'rgba(168,85,247,.12)'],
    ];
    return $map[$cat] ?? ['icon'=>'newspaper', 'label'=>'뉴스', 'color'=>'#6b7280', 'bg'=>'rgba(107,114,128,.12)'];
}

// ── 편집자 노트 Gemini 자동 생성 ─────────────────────────────
function generate_editor_note(array $news, ?string $model = null): string {
    $fallback = "오늘도 AI 세계에서 꼭 알아야 할 소식을 골랐습니다. 천천히 읽어보세요.";
    $api_key  = defined('GEMINI_API_KEY') ? GEMINI_API_KEY : '';
    if (!$api_key) { log_msg('⚠ GEMINI_API_KEY 미설정'); return $fallback; }

    // gemini-2.5-flash를 기본으로 사용 (summarize.php와 동일)
    $model = $model ?? (defined('GEMINI_MODEL_ALT') ? GEMINI_MODEL_ALT : 'gemini-2.5-flash');

    $context = '';
    foreach (array_slice($news, 0, 3) as $i => $item) {
        $context .= ($i+1) . ". " . $item['title'] . "\n   " . mb_substr($item['summary_ko'], 0, 80) . "\n";
    }
    $prompt = "오늘의 주요 AI 뉴스 3건:\n{$context}\n"
            . "위 뉴스를 읽고 에디터의 목소리로 오늘의 AI 흐름을 1~2문장 자연스럽게 소개해주세요.\n"
            . "따뜻하고 지적인 톤으로, '오늘은 ~', '이번 주는', '최근' 등 자연스러운 어두로 시작하세요. 텍스트만 반환하세요.";

    $payload = json_encode([
        'contents'         => [['parts' => [['text' => $prompt]]]],
        'generationConfig' => ['maxOutputTokens' => 120, 'temperature' => 0.75],
    ]);
    $url = GEMINI_API_URL . $model . ':generateContent?key=' . $api_key;
    log_msg("  Gemini 모델: {$model}");
    $ctx = stream_context_create(['http' => [
        'method'        => 'POST',
        'header'        => "Content-Type: application/json\r\n",
        'content'       => $payload,
        'timeout'       => 20,
        'ignore_errors' => true,
    ]]);
    $resp = @file_get_contents($url, false, $ctx);
    $status = isset($http_response_header[0]) ? (int)substr($http_response_header[0], 9, 3) : 0;
    log_msg("  Gemini HTTP: {$status}");

    if ($status === 200 && $resp) {
        $json = json_decode($resp, true);
        $text = trim($json['candidates'][0]['content']['parts'][0]['text'] ?? '');
        if ($text) return $text;
        log_msg('⚠ Gemini 응답에 텍스트 없음');
    }

    // 폴백: 다른 모델로 재시도 (1회)
    $fallback_model = defined('GEMINI_MODEL') ? GEMINI_MODEL : 'gemini-2.0-flash';
    if ($model !== $fallback_model) {
        log_msg("⚠ {$model} 실패 (HTTP {$status}) — {$fallback_model} 폴백 시도");
        sleep(3);
        return generate_editor_note($news, $fallback_model);
    }

    log_msg('✗ 편집자 노트 Gemini 생성 실패 — 기본 텍스트 사용');
    return $fallback;
}

// ── 헤드라인·메타 ─────────────────────────────────────────
$headline    = mb_substr($news[0]['title'], 0, 60);
$meta_desc   = mb_substr($news[0]['summary_ko'], 0, 100) . "... 외 " . (count($news)-1) . "건";
log_msg("편집자 노트 생성 중...");
$editor_note = generate_editor_note($news);
log_msg("✓ 편집자 노트: " . mb_substr($editor_note, 0, 50));


// ── 뉴스 카드 HTML ────────────────────────────────────────
$cards_html = '';
foreach ($news as $i => $item) {
    $cfg   = cat_config($item['category']);
    $rank  = $i + 1;
    $title   = htmlspecialchars($item['title']);
    $summary = htmlspecialchars($item['summary_ko']);
    $source  = htmlspecialchars($item['source_name']);
    $url     = htmlspecialchars($item['url']);
    $delay   = $i * 80;

    $cards_html .= "
    <article class=\"news-card glass fade-up\" style=\"transition-delay:{$delay}ms\" id=\"news-{$rank}\">
      <div class=\"news-meta\">
        <span class=\"news-cat\" style=\"background:{$cfg['bg']};color:{$cfg['color']}\">
          <i data-lucide=\"{$cfg['icon']}\" style=\"width:11px;height:11px\"></i> {$cfg['label']}
        </span>
        <span class=\"news-src\">{$source}</span>
        <span class=\"news-rank\">#{$rank}</span>
      </div>
      <h2 class=\"news-title\">
        <a href=\"{$url}\" target=\"_blank\" rel=\"noopener\">{$title}</a>
      </h2>
      <p class=\"news-summary\">{$summary}</p>
      <a class=\"news-link\" href=\"{$url}\" target=\"_blank\" rel=\"noopener\">
        <i data-lucide=\"external-link\" style=\"width:13px;height:13px\"></i> 원문 읽기
      </a>
    </article>";
}

$news_ids     = json_encode(array_column($news, 'id'));
$cnt          = count($news);
$site_url     = rtrim(SITE_URL, '/');

// ── OG 이미지 수집 (기사 순서대로 시도, 최초 성공 시 사용) ────────────
$og_image_url = null;
foreach ($news as $_i => $_item) {
    log_msg("OG 이미지 수집 [" . ($_i+1) . "/" . count($news) . "]: " . $_item['url']);
    $_og = get_og_image($_item['url']);
    if ($_og) {
        $og_image_url = $_og;
        log_msg("✓ OG Image: {$og_image_url}");
        break;
    }
    log_msg("  → 없음, 다음 기사 시도");
}
if (!$og_image_url) log_msg("⚠ 모든 기사에서 OG Image 없음");

$og_image_meta = $og_image_url
    ? '<meta property="og:image" content="' . htmlspecialchars($og_image_url) . '">'
    : '';

// ── sitemap.xml 자동 업데이트 함수 ──────────────────────────
function update_sitemap(string $date, string $site_url): void {
    $path = BASE_PATH . '/sitemap.xml';
    if (!file_exists($path) || !is_writable($path)) return;
    $xml = file_get_contents($path);
    $entry_url = $site_url . '/signal/' . $date;
    if (strpos($xml, $entry_url) !== false) return; // 중복 방지
    $entry = "
  <!-- Signal {$date} -->
  <url>
    <loc>{$entry_url}</loc>
    <lastmod>{$date}</lastmod>
    <changefreq>never</changefreq>
    <priority>0.8</priority>
  </url>";
    $xml = str_replace('</urlset>', $entry . "\n</urlset>", $xml);
    file_put_contents($path, $xml);
}

// ── HTML 템플릿 ───────────────────────────────────────────
$html = <<<HTML
<!DOCTYPE html>
<html lang="ko">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Signal · {$TODAY_KO} ({$WEEKDAY}) — 오늘의 AI 뉴스 | Vibe Studio</title>
  <meta name="description" content="{$meta_desc}">
  <meta property="og:title" content="Signal · {$TODAY_KO} — 오늘의 AI 핵심 뉴스">
  <meta property="og:description" content="{$meta_desc}">
  <meta property="og:type" content="article">
  <meta property="og:url" content="{$site_url}/signal/{$TODAY}">
  {$og_image_meta}
  <link rel="canonical" href="{$site_url}/signal/{$TODAY}">
  <link rel="preconnect" href="https://unpkg.com">
  <link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
  <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'%3E%3Cdefs%3E%3ClinearGradient id='g' x1='0%25' y1='0%25' x2='100%25' y2='100%25'%3E%3Cstop offset='0%25' stop-color='%23a855f7'/%3E%3Cstop offset='100%25' stop-color='%23d946ef'/%3E%3C/linearGradient%3E%3C/defs%3E%3Crect width='100' height='100' rx='25' fill='url(%23g)'/%3E%3Ctext x='50' y='74' font-size='65' font-family='-apple-system,sans-serif' font-weight='900' fill='white' text-anchor='middle'%3E%3C/text%3E%3Cpath%20fill-rule%3D%27evenodd%27%20fill%3D%27white%27%20d%3D%27M50%2C32%20C55%2C25%2065%2C22%2076%2C25%20C87%2C28%2092%2C38%2091%2C47%20C90%2C56%2083%2C64%2071%2C66%20C61%2C67.5%2054%2C61%2050%2C57%20C46%2C61%2039%2C67.5%2029%2C66%20C17%2C64%2010%2C56%209%2C47%20C8%2C38%2013%2C28%2024%2C25%20C35%2C22%2045%2C25%2050%2C32%20Z%20M29.5%2C38%20C35%2C35%2041%2C39%2041%2C47%20C41%2C55%2035%2C59%2029.5%2C57.5%20C24%2C56%2018%2C51.5%2018%2C47%20C18%2C42.5%2024%2C39.5%2029.5%2C38%20Z%20M70.5%2C38%20C76%2C39.5%2082%2C42.5%2082%2C47%20C82%2C51.5%2076%2C56%2070.5%2C57.5%20C65%2C59%2059%2C55%2059%2C47%20C59%2C39%2065%2C35%2070.5%2C38%20Z%27%2F%3E%3C%2Fsvg%3E">
  <script>(function(){var t=localStorage.getItem('vibe-theme')||'dark';document.documentElement.setAttribute('data-theme',t);})();</script>
  <link href="https://cdn.jsdelivr.net/gh/orioncactus/pretendard@v1.3.8/dist/web/variable/pretendardvariable.css" rel="stylesheet">
  <script src="https://unpkg.com/lucide@0.344.0/dist/umd/lucide.min.js"></script>
  <style>
    :root{--bg:#09090b;--bg2:#111114;--text-main:#f4f4f5;--text-sub:rgba(255,255,255,.65);--text-muted:rgba(255,255,255,.42);--glass-bg:rgba(24,24,27,.72);--glass-border:rgba(255,255,255,.09);--glass-shadow:0 20px 50px rgba(0,0,0,.6);--accent:#818cf8;--accent2:#38bdf8;}
    [data-theme="light"]{--bg:#f8f8fc;--bg2:#f0f0f8;--text-main:#18181b;--text-sub:#52525b;--text-muted:#71717a;--glass-bg:rgba(255,255,255,.8);--glass-border:rgba(0,0,0,.08);--glass-shadow:0 20px 50px rgba(0,0,0,.12);--accent:#5e5ce6;--accent2:#0284c7;}
    *,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
    html{scroll-behavior:smooth;}
    body{font-family:'Pretendard Variable',-apple-system,BlinkMacSystemFont,system-ui,sans-serif;background:var(--bg);color:var(--text-main);line-height:1.6;overflow-x:hidden;min-height:100vh;}
    body::before{content:'';position:fixed;inset:0;background-image:linear-gradient(rgba(255,255,255,.025) 1px,transparent 1px),linear-gradient(90deg,rgba(255,255,255,.025) 1px,transparent 1px);background-size:48px 48px;pointer-events:none;z-index:0;}
    [data-theme="light"] body::before{background-image:linear-gradient(rgba(0,0,0,.04) 1px,transparent 1px),linear-gradient(90deg,rgba(0,0,0,.04) 1px,transparent 1px);}
    #spotlight{position:fixed;width:600px;height:600px;border-radius:50%;background:radial-gradient(circle,rgba(129,140,248,.1) 0%,rgba(56,189,248,.04) 40%,transparent 70%);pointer-events:none;transform:translate(-50%,-50%);z-index:0;transition:opacity .4s;}
    .wrapper{position:relative;z-index:1;max-width:800px;margin:0 auto;padding:0 24px;}
    .glass{background:var(--glass-bg);backdrop-filter:blur(20px) saturate(160%);-webkit-backdrop-filter:blur(20px) saturate(160%);border:1px solid var(--glass-border);border-radius:20px;box-shadow:var(--glass-shadow);}
    .fade-up{opacity:0;transform:translateY(28px);transition:opacity .7s cubic-bezier(.2,.8,.2,1),transform .7s cubic-bezier(.2,.8,.2,1);}
    .fade-up.visible{opacity:1;transform:translateY(0);}
    /* Nav Drawer */
    .nav-drawer{position:fixed;top:0;left:0;width:min(320px,85vw);height:100dvh;background:var(--glass-bg);backdrop-filter:blur(40px) saturate(180%);-webkit-backdrop-filter:blur(40px) saturate(180%);border-right:1px solid var(--glass-border);box-shadow:6px 0 32px rgba(0,0,0,.28);z-index:20000;transform:translateX(-100%);transition:transform .32s cubic-bezier(.16,1,.3,1);display:flex;flex-direction:column;overflow:hidden;}
    .nav-drawer.open{transform:translateX(0);}
    .nav-drawer-header{display:flex;align-items:center;justify-content:space-between;padding:18px 20px;border-bottom:1px solid var(--glass-border);flex-shrink:0;}
    .nav-drawer-brand{display:flex;align-items:center;gap:9px;font-weight:800;font-size:15px;color:var(--text-main);text-decoration:none;}
    .nav-drawer-brand .lightning{width:28px;height:28px;background:linear-gradient(135deg,#a855f7,#d946ef);border-radius:8px;display:flex;align-items:center;justify-content:center;}
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
    /* Hero */
    .signal-hero{padding:120px 0 48px;text-align:center;}
    .signal-badge{display:inline-flex;align-items:center;gap:6px;background:rgba(129,140,248,.12);border:1px solid rgba(129,140,248,.25);color:var(--accent);font-size:12px;font-weight:700;letter-spacing:.5px;padding:5px 14px;border-radius:100px;margin-bottom:20px;text-transform:uppercase;}
    .signal-badge i{width:12px;height:12px;}
    .signal-date-label{font-size:.9rem;color:var(--text-muted);margin-bottom:12px;display:flex;align-items:center;justify-content:center;gap:6px;}
    .signal-hero h1{font-size:clamp(1.8rem,5vw,2.6rem);font-weight:900;letter-spacing:-.04em;line-height:1.25;margin-bottom:16px;background:linear-gradient(145deg,#fff 30%,#a5b4fc 70%,#38bdf8 100%);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;}
    [data-theme="light"] .signal-hero h1{background:linear-gradient(145deg,#18181b 30%,#5e5ce6 75%,#0284c7 100%);-webkit-background-clip:text;background-clip:text;}
    /* Editor Note */
    .editor-note{background:var(--glass-bg);border:1px solid var(--glass-border);border-left:3px solid var(--accent);border-radius:0 12px 12px 0;padding:14px 18px;margin:0 auto 32px;max-width:640px;text-align:left;backdrop-filter:blur(12px);}
    .editor-label{font-size:.72rem;color:var(--accent);font-weight:700;margin-bottom:4px;text-transform:uppercase;letter-spacing:.05em;display:flex;align-items:center;gap:5px;}
    .editor-label i{width:11px;height:11px;}
    .editor-text{font-size:.88rem;color:var(--text-sub);line-height:1.6;}
    .news-count-badge{display:inline-flex;align-items:center;gap:6px;background:rgba(52,211,153,.1);border:1px solid rgba(52,211,153,.2);color:#34d399;font-size:.8rem;font-weight:600;padding:4px 12px;border-radius:100px;margin-bottom:48px;}
    /* News Cards */
    .news-list{padding-bottom:80px;}
    .news-card{padding:24px 28px;margin-bottom:14px;border-radius:18px;transition:border-color .2s,transform .15s,box-shadow .2s;}
    .news-card:hover{border-color:rgba(129,140,248,.4)!important;transform:translateY(-2px);box-shadow:0 16px 40px rgba(129,140,248,.1);}
    .news-meta{display:flex;align-items:center;gap:8px;margin-bottom:10px;flex-wrap:wrap;}
    .news-cat{font-size:.74rem;font-weight:700;padding:3px 10px;border-radius:20px;display:inline-flex;align-items:center;gap:4px;white-space:nowrap;}
    .news-src{font-size:.78rem;color:var(--text-muted);}
    .news-rank{font-size:.72rem;color:var(--text-muted);margin-left:auto;font-weight:600;opacity:.6;}
    .news-title{font-size:1.05rem;font-weight:700;line-height:1.45;margin-bottom:10px;letter-spacing:-.01em;}
    .news-title a{color:var(--text-main);text-decoration:none;transition:color .15s;}
    .news-title a:hover{color:var(--accent);}
    .news-summary{font-size:.88rem;color:var(--text-sub);line-height:1.78;margin-bottom:14px;}
    .news-link{display:inline-flex;align-items:center;gap:5px;font-size:.8rem;color:var(--accent);text-decoration:none;font-weight:600;transition:opacity .15s;}
    .news-link:hover{opacity:.7;}
    /* Subscribe */
    .subscribe-section{margin-bottom:80px;}
    .subscribe-box{padding:44px 32px;border-radius:22px;background:linear-gradient(135deg,rgba(129,140,248,.1),rgba(56,189,248,.06));border:1px solid rgba(129,140,248,.18);text-align:center;}
    .subscribe-box h3{font-size:1.3rem;font-weight:800;letter-spacing:-.03em;margin-bottom:10px;}
    .subscribe-box p{font-size:.88rem;color:var(--text-sub);max-width:440px;margin:0 auto 24px;}
    .cta-btn{display:inline-flex;align-items:center;gap:8px;background:linear-gradient(135deg,#818cf8,#5e5ce6);color:#fff;font-weight:700;font-size:.9rem;padding:12px 26px;border-radius:30px;text-decoration:none;transition:opacity .2s,transform .2s;box-shadow:0 6px 20px rgba(129,140,248,.3);}
    .cta-btn:hover{opacity:.9;transform:translateY(-2px);}
    .archive-link{display:inline-flex;align-items:center;gap:5px;margin-top:14px;font-size:.82rem;color:var(--text-muted);text-decoration:none;transition:color .15s;}
    .archive-link:hover{color:var(--accent);}
    .site-footer{border-top:1px solid var(--glass-border);padding:28px 24px;}
    @media(max-width:600px){.news-card{padding:18px 16px;}.signal-hero{padding:100px 0 36px;}}
  </style>
</head>
<body>

<div id="spotlight"></div>

<!-- Nav Drawer -->
<div class="nav-drawer" id="navDrawer">
  <div class="nav-drawer-header">
    <a href="/" class="nav-drawer-brand">
      <div class="lightning"><svg viewBox="0 0 24 24"><path d="M13 2L3 14H12L11 22L21 10H12L13 2Z"/></svg></div>
      Vibe Studio
    </a>
    <button class="nav-drawer-close" onclick="closeNavDrawer()">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M18 6 6 18M6 6l12 12"/></svg>
    </button>
  </div>
  <div class="nav-drawer-body">
    <a href="/" class="nav-drawer-item"><span class="nav-drawer-item-icon" style="background:rgba(129,140,248,.15)"><i data-lucide="home" style="color:#818cf8"></i></span>Home</a>
    <a href="/about" class="nav-drawer-item"><span class="nav-drawer-item-icon" style="background:rgba(56,189,248,.15)"><i data-lucide="info" style="color:#38bdf8"></i></span>About</a>
    <a href="/history" class="nav-drawer-item"><span class="nav-drawer-item-icon" style="background:rgba(168,85,247,.15)"><i data-lucide="clock" style="color:#a855f7"></i></span>History</a>
    <a href="/signal" class="nav-drawer-item active"><span class="nav-drawer-item-icon" style="background:rgba(251,146,60,.15)"><i data-lucide="zap" style="color:#fb923c"></i></span>Signal</a>
    <a href="/contact" class="nav-drawer-item"><span class="nav-drawer-item-icon" style="background:rgba(52,211,153,.15)"><i data-lucide="mail" style="color:#34d399"></i></span>Contact</a>
  </div>
  <div class="nav-drawer-footer">
    <div class="nav-drawer-footer-brand"><i data-lucide="zap" style="width:12px;height:12px;"></i> Vibe Studio</div>
    <div class="nav-drawer-footer-copy">© 2026 PriSincera. All rights reserved.</div>
  </div>
</div>
<div class="nav-drawer-overlay" id="navOverlay" onclick="closeNavDrawer()"></div>

<header class="top-nav" id="site-nav"></header>

<!-- Hero -->
<section class="signal-hero">
  <div class="wrapper">
    <div class="signal-badge fade-up"><i data-lucide="zap"></i> Signal by Vibe Studio</div>
    <div class="signal-date-label fade-up"><i data-lucide="calendar" style="width:14px;height:14px"></i> {$TODAY_KO} ({$WEEKDAY})</div>
    <h1 class="fade-up">오늘의 AI 핵심 뉴스</h1>
    <div class="editor-note fade-up">
      <div class="editor-label"><i data-lucide="pen-line"></i> 편집자 노트</div>
      <div class="editor-text">{$editor_note}</div>
    </div>
    <div class="news-count-badge fade-up">
      <i data-lucide="newspaper" style="width:13px;height:13px"></i> 총 {$cnt}건의 뉴스를 선별했습니다
    </div>
  </div>
</section>

<!-- News List -->
<main class="news-list">
  <div class="wrapper">
{$cards_html}
  </div>
</main>

<!-- Subscribe CTA -->
<section class="subscribe-section">
  <div class="wrapper">
    <div class="subscribe-box fade-up">
      <h3>📬 매일 아침 8시, Signal 알림 받기</h3>
      <p>오늘같은 AI 뉴스를 이메일로 먼저 받아보세요. 언제든 취소 가능합니다.</p>
      <a href="{$site_url}/signal" class="cta-btn"><i data-lucide="zap"></i> Signal 구독하기</a>
      <br>
      <a href="{$site_url}/signal" class="archive-link"><i data-lucide="archive" style="width:13px;height:13px"></i> Signal 아카이브 보기</a>
    </div>
  </div>
</section>

<footer class="site-footer" id="site-footer"></footer>

<script>window.LAYOUT_ROOT = '/';</script>
<script src="/js/layout.js"></script>
<script>
  document.addEventListener('mousemove', function(e){var s=document.getElementById('spotlight');if(s){s.style.left=e.clientX+'px';s.style.top=e.clientY+'px';}});
  function openNavDrawer(){document.getElementById('navDrawer').classList.add('open');document.getElementById('navOverlay').classList.add('open');document.body.style.overflow='hidden';}
  function closeNavDrawer(){document.getElementById('navDrawer').classList.remove('open');document.getElementById('navOverlay').classList.remove('open');document.body.style.overflow='';}
  function toggleTheme(){var t=document.documentElement.getAttribute('data-theme')==='dark'?'light':'dark';document.documentElement.setAttribute('data-theme',t);localStorage.setItem('vibe-theme',t);var icon=document.getElementById('themeIcon');if(icon)icon.setAttribute('data-lucide',t==='dark'?'sun':'moon');if(window.lucide)lucide.createIcons();}
  var observer=new IntersectionObserver(function(entries){entries.forEach(function(e){if(e.isIntersecting){e.target.classList.add('visible');observer.unobserve(e.target);}});},{threshold:0.08});
  document.querySelectorAll('.fade-up').forEach(function(el){observer.observe(el);});
  if(window.lucide)lucide.createIcons();
</script>
</body>
</html>
HTML;

// ── 파일 저장 ─────────────────────────────────────────────
file_put_contents($OUTPUT_FILE, $html);
// Admin Panel(Apache/daemon)에서 편집자 노트 수정 시 write 가능하도록 퍼미션 설정
@chmod($OUTPUT_FILE, 0664);
log_msg("✓ 페이지 생성: {$OUTPUT_FILE}");

// ── DB 기록 ───────────────────────────────────────────────
$ins = $pdo->prepare(
    "INSERT INTO news_pages (publish_date, file_path, news_ids, editor_note, og_image)
     VALUES (:date, :path, :ids, :note, :og)
     ON DUPLICATE KEY UPDATE file_path=VALUES(file_path), news_ids=VALUES(news_ids), editor_note=VALUES(editor_note), og_image=VALUES(og_image), published_at=NOW()"
);
$ins->execute([':date'=>$TODAY, ':path'=>'/signal/'.$TODAY.'.html', ':ids'=>$news_ids, ':note'=>$editor_note, ':og'=>$og_image_url]);

// ── ai_news → sent ────────────────────────────────────────
$ids_str = implode(',', array_map('intval', array_column($news, 'id')));
$pdo->exec("UPDATE ai_news SET status='sent' WHERE id IN ({$ids_str})");

log_msg("✓ DB 기록 완료");
log_msg("✓ URL: " . SITE_URL . "/signal/{$TODAY}");

// ── sitemap.xml 업데이트 ────────────────────────────────────────
update_sitemap($TODAY, $site_url);
log_msg("✓ sitemap.xml 업데이트 완료");
log_msg("==============================================");
