<?php
/**
 * batch_fix_editor_notes.php — 편집자 노트 일괄 재생성 스크립트
 * 
 * 3/28(토) 이후 fallback 텍스트가 적용된 모든 날짜의 편집자 노트를
 * Gemini API로 재생성하여 DB + HTML 파일에 적용합니다.
 *
 * 실행: php batch_fix_editor_notes.php
 * 서버: sudo /opt/bitnami/php/bin/php /opt/bitnami/apache2/htdocs/digest/batch_fix_editor_notes.php
 */

date_default_timezone_set('Asia/Seoul');

// 서버 환경 감지
if (file_exists('/opt/bitnami/apache2/htdocs/config.php')) {
    define('BASE_PATH', '/opt/bitnami/apache2/htdocs');
} else {
    define('BASE_PATH', dirname(__DIR__));
}
require_once BASE_PATH . '/config.php';

$LOG_FILE   = '/tmp/batch_editor_note.log';
$FALLBACK   = '오늘도 AI 세계에서 꼭 알아야 할 소식을 골랐습니다. 천천히 읽어보세요.';
$API_DELAY  = 5; // Gemini rate limit 방지

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

log_msg("==============================================");
log_msg("편집자 노트 일괄 수정 시작");
log_msg("==============================================");

// ── 3/28 이후 fallback 텍스트가 적용된 날짜 조회 ──────────
$stmt = $pdo->prepare(
    "SELECT publish_date, editor_note, news_ids
     FROM news_pages
     WHERE publish_date >= '2026-03-28'
       AND (editor_note = :fallback OR editor_note IS NULL OR editor_note = '')
     ORDER BY publish_date ASC"
);
$stmt->execute([':fallback' => $FALLBACK]);
$targets = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($targets)) {
    log_msg("✓ 수정 대상 없음 — 모든 편집자 노트 정상");
    exit(0);
}

log_msg("수정 대상: " . count($targets) . "건");
foreach ($targets as $t) {
    log_msg("  · {$t['publish_date']}: " . mb_substr($t['editor_note'] ?? '(없음)', 0, 40));
}

// ── Gemini API 호출 함수 ──────────────────────────────────
function call_gemini_for_note(array $news, ?string $model = null): string {
    global $FALLBACK;

    $api_key = GEMINI_API_KEY;
    $model   = $model ?? (defined('GEMINI_MODEL_ALT') ? GEMINI_MODEL_ALT : 'gemini-2.5-flash');

    $context = '';
    foreach (array_slice($news, 0, 3) as $i => $item) {
        $context .= ($i+1) . ". " . $item['title'] . "\n   " . mb_substr($item['summary_ko'] ?? '', 0, 80) . "\n";
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
    $resp   = @file_get_contents($url, false, $ctx);
    $status = isset($http_response_header[0]) ? (int)substr($http_response_header[0], 9, 3) : 0;
    log_msg("  Gemini HTTP: {$status}");

    if ($status === 200 && $resp) {
        $json = json_decode($resp, true);
        $text = trim($json['candidates'][0]['content']['parts'][0]['text'] ?? '');
        if ($text) return $text;
    }

    // 폴백 모델 시도
    $fb_model = defined('GEMINI_MODEL') ? GEMINI_MODEL : 'gemini-2.0-flash';
    if ($model !== $fb_model) {
        log_msg("  ⚠ {$model} 실패 — {$fb_model} 폴백");
        sleep(3);
        return call_gemini_for_note($news, $fb_model);
    }

    return $FALLBACK;
}

// ── 각 날짜별 처리 ────────────────────────────────────────
$success = 0;
$failed  = 0;

foreach ($targets as $target) {
    $date = $target['publish_date'];
    log_msg("\n── {$date} 처리 중 ──");

    // 해당 날짜의 sent 기사 조회 (score 상위 3건)
    $news_stmt = $pdo->prepare(
        "SELECT title, summary_ko FROM ai_news
         WHERE status='sent'
           AND DATE(CONVERT_TZ(collected_at,'+00:00','+09:00')) = :d
         ORDER BY score DESC LIMIT 3"
    );
    $news_stmt->execute([':d' => $date]);
    $news = $news_stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($news)) {
        // sent가 없으면 selected도 확인
        $news_stmt = $pdo->prepare(
            "SELECT title, summary_ko FROM ai_news
             WHERE status IN ('sent','selected')
               AND DATE(CONVERT_TZ(collected_at,'+00:00','+09:00')) = :d
             ORDER BY score DESC LIMIT 3"
        );
        $news_stmt->execute([':d' => $date]);
        $news = $news_stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    if (empty($news)) {
        log_msg("  ⚠ {$date} 기사 없음 — 건너뜀");
        $failed++;
        continue;
    }

    log_msg("  기사 " . count($news) . "건으로 생성 시도");

    // Gemini 호출
    $note = call_gemini_for_note($news);

    if ($note === $FALLBACK) {
        log_msg("  ✗ {$date} — Gemini 실패, fallback 유지");
        $failed++;
        sleep($API_DELAY);
        continue;
    }

    log_msg("  ✓ 새 노트: " . mb_substr($note, 0, 60) . "...");

    // DB 업데이트
    $upd = $pdo->prepare("UPDATE news_pages SET editor_note = :note WHERE publish_date = :d");
    $upd->execute([':note' => $note, ':d' => $date]);
    log_msg("  ✓ DB 업데이트 완료");

    // HTML 파일 업데이트
    $html_file = BASE_PATH . "/signal/{$date}.html";
    if (file_exists($html_file)) {
        $html = file_get_contents($html_file);
        
        // editor-text div 안의 내용을 새 노트로 교체
        $old_pattern = '/<div class="editor-text">[^<]*<\/div>/';
        $new_content = '<div class="editor-text">' . htmlspecialchars($note) . '</div>';
        
        $updated = preg_replace($old_pattern, $new_content, $html, 1, $count);
        
        if ($count > 0) {
            file_put_contents($html_file, $updated);
            log_msg("  ✓ HTML 파일 업데이트 완료: {$html_file}");
        } else {
            log_msg("  ⚠ HTML에서 editor-text 패턴을 찾지 못함");
        }
    } else {
        log_msg("  ⚠ HTML 파일 없음: {$html_file}");
    }

    $success++;
    sleep($API_DELAY); // Rate limit 방지
}

log_msg("\n==============================================");
log_msg("일괄 수정 완료: 성공 {$success} / 실패 {$failed} / 총 " . count($targets));
log_msg("==============================================");
