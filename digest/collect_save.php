<?php
/**
 * collect_save.php — /tmp/collected_news.json을 읽어 DB에 저장
 * @deploy: Signal Phase 1 — 수집된 뉴스 JSON → ai_news 테이블 INSERT
 *
 * 실행: /opt/bitnami/php/bin/php collect_save.php
 * Cron: 53 22 * * * /opt/bitnami/php/bin/php /opt/bitnami/apache2/htdocs/digest/collect_save.php
 */

define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/config.php';

$JSON_FILE = '/tmp/collected_news.json';
$LOG_FILE  = '/tmp/signal_save.log';

function log_msg(string $msg): void {
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
    echo $line;
    file_put_contents($GLOBALS['LOG_FILE'], $line, FILE_APPEND);
}

// ── JSON 파일 확인 ────────────────────────────────────────
if (!file_exists($JSON_FILE)) {
    log_msg("✗ JSON 파일 없음: {$JSON_FILE} — collect.py가 먼저 실행되어야 합니다.");
    exit(1);
}

$raw  = file_get_contents($JSON_FILE);
$data = json_decode($raw, true);

if (!$data || empty($data['items'])) {
    log_msg("✗ JSON 파싱 실패 또는 items 없음.");
    exit(1);
}

log_msg("==============================================");
log_msg("collect_save.php 시작");
log_msg("수집 시각: " . ($data['collected_at'] ?? '?'));
log_msg("수집 건수: " . $data['count']);

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

// ── INSERT (URL 중복 시 SKIP) ─────────────────────────────
$sql = "INSERT IGNORE INTO ai_news
            (title, url, source_id, source_name, category, score, hn_points, published_at, status)
        VALUES
            (:title, :url, :source_id, :source_name, :category, :score, :hn_points, :published_at, 'pending')";

$stmt = $pdo->prepare($sql);

$inserted = 0;
$skipped  = 0;

foreach ($data['items'] as $item) {
    try {
        $ok = $stmt->execute([
            ':title'       => mb_substr($item['title'] ?? '', 0, 500),
            ':url'         => mb_substr($item['url'] ?? '', 0, 1000),
            ':source_id'   => (int)($item['source_id'] ?? 0) ?: null,
            ':source_name' => $item['source_name'] ?? '',
            ':category'    => $item['category'] ?? 'tech',
            ':score'       => (float)($item['score'] ?? 0),
            ':hn_points'   => (int)($item['hn_points'] ?? 0),
            ':published_at'=> $item['published_at'] ?? date('Y-m-d H:i:s'),
        ]);

        if ($stmt->rowCount() > 0) {
            $inserted++;
        } else {
            $skipped++;  // URL 중복 (IGNORE)
        }
    } catch (PDOException $e) {
        log_msg("  ✗ INSERT 실패: " . $e->getMessage() . " | URL: " . ($item['url'] ?? ''));
    }
}

// ── ai_sources 마지막 수집 시각 업데이트 ──────────────────
$pdo->exec("UPDATE ai_sources SET last_fetched_at = NOW() WHERE enabled = 1");

log_msg("✓ INSERT 완료 — 신규: {$inserted}건 / 중복 스킵: {$skipped}건");
log_msg("==============================================");

// ── JSON 파일 삭제 (처리 완료 후 정리) ───────────────────
@unlink($JSON_FILE);
log_msg("✓ /tmp/collected_news.json 삭제 완료");
