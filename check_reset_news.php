<?php
date_default_timezone_set('Asia/Seoul');
define('BASE_PATH', '/opt/bitnami/apache2/htdocs');
require_once BASE_PATH . '/config.php';

$pdo = new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset='.DB_CHARSET, DB_USER, DB_PASS);

$ok = true;

echo "========================================\n";
echo " Signal 파이프라인 최종 사전 검증 체크\n";
echo " 기준 시나리오: 2026-03-25 22:50 UTC\n";
echo "               = 2026-03-26 07:50 KST\n";
echo "========================================\n\n";

// 1. Lock 파일
$lock = trim(file_get_contents('/tmp/signal_pipeline.lock') ?: '없음');
$kst_tomorrow = '2026-03-26';
$lock_ok = ($lock !== $kst_tomorrow);
echo "[1] Lock 파일: '{$lock}' ";
echo $lock_ok ? "✅ (내일자 {$kst_tomorrow}와 다름 → 파이프라인 실행됨)\n" : "❌ FAIL (이미 내일자로 잠김)\n";
if (!$lock_ok) $ok = false;

// 2. signal 디렉토리 쓰기 권한
$sig_dir = BASE_PATH . '/signal';
$writable = is_writable($sig_dir);
echo "[2] signal/ 쓰기권한: " . ($writable ? "✅ OK\n" : "❌ FAIL\n");
if (!$writable) $ok = false;

// 3. 내일 HTML 파일 존재 여부
$tomorrow_html = $sig_dir . '/2026-03-26.html';
$html_ok = !file_exists($tomorrow_html);
echo "[3] 2026-03-26.html 존재: " . (!$html_ok ? "⚠️  이미 존재 (재생성 안 됨)" : "✅ 없음 (신규 생성 예정)") . "\n";

// 4. digest_logs 내일자 중복 여부
$r = $pdo->prepare("SELECT COUNT(*) FROM digest_logs WHERE sent_date = :d");
$r->execute([':d' => '2026-03-26']);
$dup = $r->fetchColumn();
$email_ok = ($dup == 0);
echo "[4] digest_logs 2026-03-26 중복: " . ($email_ok ? "✅ 없음 (이메일 발송됨)\n" : "❌ 이미 존재 (발송 스킵됨)\n");
if (!$email_ok) $ok = false;

// 5. CONVERT_TZ 동작 검증
$r2 = $pdo->query("SELECT DATE(CONVERT_TZ('2026-03-25 22:50:00','+00:00','+09:00'))");
$kst = $r2->fetchColumn();
$tz_ok = ($kst === '2026-03-26');
echo "[5] CONVERT_TZ 변환: '2026-03-25 22:50 UTC' → '{$kst}' " . ($tz_ok ? "✅\n" : "❌\n");
if (!$tz_ok) $ok = false;

// 6. 파이프라인 스크립트 CONVERT_TZ 적용 확인
$sum = file_get_contents(BASE_PATH . '/digest/summarize.php');
$pub = file_get_contents(BASE_PATH . '/digest/publish_news.php');
$mail = file_get_contents(BASE_PATH . '/digest/digest_mailer.php');
$pipe = file_get_contents(BASE_PATH . '/digest/pipeline.sh');
$kst_applied = strpos($sum, 'Asia/Seoul') && strpos($pub, 'Asia/Seoul') && strpos($mail, 'Asia/Seoul');
$conv_applied = strpos($sum, 'CONVERT_TZ') && strpos($pub, 'CONVERT_TZ') && strpos($mail, 'CONVERT_TZ') && strpos($pipe, 'CONVERT_TZ');
echo "[6] KST 타임존 적용(3파일): " . ($kst_applied ? "✅ OK\n" : "❌ 미적용\n");
echo "[7] CONVERT_TZ 적용(4파일): " . ($conv_applied ? "✅ OK\n" : "❌ 미적용\n");
if (!$kst_applied || !$conv_applied) $ok = false;

// 7. cron 스케줄 확인
$cron = shell_exec('crontab -l 2>/dev/null');
$cron_ok = strpos($cron, '50 22 * * *') !== false;
echo "[8] Cron 스케줄 (50 22 * * *): " . ($cron_ok ? "✅ 정상\n" : "❌ 없음\n");
if (!$cron_ok) $ok = false;

// 8. 오늘 KST 기준 DB 현황 (내일 기사와 겹치지 않음 확인)
$r3 = $pdo->query("SELECT DATE(CONVERT_TZ(collected_at,'+00:00','+09:00')) as kst_dt, status, COUNT(*) c FROM ai_news GROUP BY kst_dt, status ORDER BY kst_dt DESC LIMIT 8");
echo "\n[DB 현황 — KST 날짜 기준]\n";
foreach ($r3->fetchAll(PDO::FETCH_ASSOC) as $row) {
    echo "  KST:{$row['kst_dt']} | {$row['status']}: {$row['c']}건\n";
}

echo "\n========================================\n";
echo $ok ? "✅ 전체 검증 통과 — 내일 정상 작동 예상\n" : "❌ 일부 항목 실패 — 확인 필요\n";
echo "========================================\n";
