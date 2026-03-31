<?php
/**
 * summarize.php — Gemini API로 AI 뉴스 한국어 요약 + 카테고리 분류
 * @deploy: Signal Phase 2
 * Cron: 55 22 * * * /opt/bitnami/php/bin/php /opt/bitnami/apache2/htdocs/digest/summarize.php
 */

date_default_timezone_set('Asia/Seoul'); // KST 기준으로 통일
define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/config.php';

$LOG_FILE      = '/tmp/signal_summarize.log';
$MAX_ARTICLES  = 30;   // 소스 12개 대응 — 상위 30건 요약 (API 한도 6% 이하)
$TOP_SELECT    = 7;
$API_DELAY_SEC = 5;   // 12 RPM — 무료 한도(15 RPM) 이하

// ── 로깅 ─────────────────────────────────────────────────
function log_msg(string $msg): void {
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
    echo $line;
    file_put_contents($GLOBALS['LOG_FILE'], $line, FILE_APPEND);
}

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

$TODAY_KST = date('Y-m-d'); // KST 오늘 날짜
log_msg("==============================================");
log_msg("summarize.php | " . $TODAY_KST . " | 모델: " . GEMINI_MODEL_ALT . " (primary)");

// ── 1. pending 기사 조회 (KST 날짜 기준) ─────────────────
$stmt = $pdo->prepare(
    "SELECT id, title, url, source_name, category, score
     FROM ai_news
     WHERE status = 'pending'
       AND DATE(CONVERT_TZ(collected_at,'+00:00','+09:00')) = :kst_today
     ORDER BY score DESC
     LIMIT :lim"
);
$stmt->bindValue(':kst_today', $TODAY_KST);
$stmt->bindValue(':lim', $MAX_ARTICLES, PDO::PARAM_INT);
$stmt->execute();
$articles = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($articles)) {
    log_msg("✓ pending 기사 없음 — 종료");
    exit(0);
}
log_msg("요약 대상: " . count($articles) . "건");

// ── 2. Gemini API 호출 ────────────────────────────────────
function call_gemini(string $prompt, ?string $model = null): ?string {
    $model = $model ?? GEMINI_MODEL_ALT;   // primary: gemini-2.5-flash
    $url   = GEMINI_API_URL . $model . ':generateContent?key=' . GEMINI_API_KEY;

    $body = json_encode([
        'contents'         => [['parts' => [['text' => $prompt]]]],
        'generationConfig' => [
            'temperature'     => 0.2,
            'maxOutputTokens' => 1200,
            'stopSequences'   => ['}'],   // JSON 닫는 괄호에서 멈춤
        ],
    ]);

    $ctx = stream_context_create(['http' => [
        'method'        => 'POST',
        'header'        => "Content-Type: application/json\r\n",
        'content'       => $body,
        'timeout'       => 25,
        'ignore_errors' => true,
    ]]);

    $raw    = @file_get_contents($url, false, $ctx);
    $status = isset($http_response_header[0]) ? (int)substr($http_response_header[0], 9, 3) : 0;

    if ($status === 200 && $raw) {
        $d = json_decode($raw, true);
        $text = $d['candidates'][0]['content']['parts'][0]['text'] ?? null;
        // stopSequences removes }, so add it back
        return $text ? trim($text) . '}' : null;
    }

    if ($status === 429 && $model !== GEMINI_MODEL) {
        log_msg("  ⚠ Rate Limit — " . GEMINI_MODEL . " 폴백");
        sleep(10);
        return call_gemini($prompt, GEMINI_MODEL);
    }

    log_msg("  ✗ Gemini 오류: HTTP {$status}");
    return null;
}

// ── 3. 프롬프트 ───────────────────────────────────────────
function make_prompt(array $a): string {
    $title = addslashes($a['title']);
    $src   = $a['source_name'];
    // 매우 간결한 응답 유도 — 토큰 절약, JSON 완결 보장
    return "AI news summarizer. Respond ONLY with this exact JSON (no newlines, no markdown):\n"
         . "{\"summary_ko\":\"<2 Korean sentences max>\",\"category\":\"<one of: research|bigtech|tools|industry|korea|tips>\"}\n\n"
         . "Title: {$title}\nSource: {$src}";
}

// ── 4. 응답 파싱 ──────────────────────────────────────────
function parse_gemini_json(string $text): ?array {
    // 코드블록 제거
    $text = preg_replace('/^```(?:json)?\s*/m', '', $text);
    $text = preg_replace('/^```\s*$/m', '', $text);

    // 실제 줄바꿈 → 공백 (invalid JSON 방지)
    $text = str_replace(["\r\n", "\r", "\n"], ' ', $text);
    $text = trim($text);

    // JSON 블록 경계 추출
    $start = strpos($text, '{');
    $end   = strrpos($text, '}');
    if ($start === false || $end === false || $end <= $start) return null;

    $json = substr($text, $start, $end - $start + 1);
    $d    = json_decode($json, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        log_msg("  [dbg] json_err: " . json_last_error_msg());
        log_msg("  [dbg] json: " . mb_substr($json, 0, 150));
    }

    if (is_array($d) && !empty($d['summary_ko']) && !empty($d['category'])) {
        return ['summary_ko' => $d['summary_ko'], 'category' => $d['category']];
    }
    return null;
}

// ── 5. 요약 실행 ──────────────────────────────────────────
$summarized = 0;
$failed     = 0;
$valid_cats = ['research','bigtech','tools','industry','korea','tips'];

$upd  = $pdo->prepare("UPDATE ai_news SET summary_ko=:s, category=:c, status='summarized' WHERE id=:id");
$skip = $pdo->prepare("UPDATE ai_news SET status='skipped' WHERE id=:id");

foreach ($articles as $a) {
    log_msg("\n[#{$a['id']}] " . mb_substr($a['title'], 0, 55) . "...");

    $result = call_gemini(make_prompt($a));

    if (!$result) {
        log_msg("  ✗ 응답 없음");
        $skip->execute([':id' => $a['id']]);
        $failed++;
        sleep($API_DELAY_SEC);
        continue;
    }

    log_msg("  → " . mb_substr($result, 0, 140));

    $p = parse_gemini_json($result);
    if (!$p) {
        log_msg("  ✗ 파싱 실패");
        $skip->execute([':id' => $a['id']]);
        $failed++;
        sleep($API_DELAY_SEC);
        continue;
    }

    $summary_ko = mb_substr(trim($p['summary_ko']), 0, 1000);
    $category   = in_array($p['category'], $valid_cats) ? $p['category'] : $a['category'];

    $upd->execute([':s' => $summary_ko, ':c' => $category, ':id' => $a['id']]);
    log_msg("  ✓ [{$category}] " . mb_substr($summary_ko, 0, 60) . "...");
    $summarized++;
    sleep($API_DELAY_SEC);
}

log_msg("\n요약: 성공 {$summarized} / 실패 {$failed}");

// ── 6. 상위 7건 → selected (KST 날짜 기준) ──────────────
$sel = $pdo->prepare(
    "UPDATE ai_news SET status='selected'
     WHERE status='summarized'
       AND DATE(CONVERT_TZ(collected_at,'+00:00','+09:00'))=:kst_today
     ORDER BY score DESC LIMIT :lim"
);
$sel->bindValue(':kst_today', $TODAY_KST);
$sel->bindValue(':lim', $TOP_SELECT, PDO::PARAM_INT);
$sel->execute();
log_msg("✓ selected: " . $sel->rowCount() . "건");
log_msg("==============================================");
