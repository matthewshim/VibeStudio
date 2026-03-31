<?php
/**
 * monitor.php — Signal 파이프라인 발송 모니터링
 *
 * publish_news.php 실행 여부 + digest_mailer.php 발송 성공률 확인.
 * 발송 0건이거나 실패율 50% 초과 시 관리자 이메일 알람.
 * Cron: 10 23 * * * /opt/bitnami/php/bin/php /opt/bitnami/apache2/htdocs/digest/monitor.php
 */

define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/config.php';

$phpmailer_dir = BASE_PATH . '/vendor/phpmailer/';
require_once $phpmailer_dir . 'Exception.php';
require_once $phpmailer_dir . 'PHPMailer.php';
require_once $phpmailer_dir . 'SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailerException;

$LOG_FILE = '/tmp/signal_monitor.log';
$TODAY    = date('Y-m-d');
$TODAY_KO = date('Y년 n월 j일');
$ADMIN    = SMTP_FROM;

function log_msg(string $msg): void {
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
    echo $line;
    file_put_contents($GLOBALS['LOG_FILE'], $line, FILE_APPEND);
}

function send_alert(string $subject, string $body): void {
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = SMTP_PORT;
        $mail->CharSet    = 'UTF-8';

        $mail->setFrom(SMTP_FROM, 'Signal Monitor');
        $mail->addAddress($GLOBALS['ADMIN']);
        $mail->isHTML(false);
        $mail->Subject = $subject;
        $mail->Body    = $body;
        $mail->send();
        log_msg("✓ 알람 발송: {$subject}");
    } catch (MailerException $e) {
        log_msg("✗ 알람 발송 실패: " . $mail->ErrorInfo);
    }
}

log_msg("==============================================");
log_msg("monitor.php 시작 | {$TODAY}");

// ── DB 연결 ───────────────────────────────────────────────
try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET,
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    $msg = "✗ DB 연결 실패: " . $e->getMessage();
    log_msg($msg);
    send_alert("[Signal 긴급] DB 연결 실패 {$TODAY}", $msg);
    exit(1);
}

// ── 1. 오늘 웹 페이지 게시 여부 확인 ────────────────────
$page_stmt = $pdo->prepare("SELECT id, news_ids FROM news_pages WHERE publish_date = :d");
$page_stmt->execute([':d' => $TODAY]);
$page = $page_stmt->fetch(PDO::FETCH_ASSOC);

if (!$page) {
    $msg = "오늘({$TODAY}) Signal 페이지가 생성되지 않았습니다.\npublish_news.php 실행 여부를 확인하세요.";
    log_msg("⚠ " . $msg);
    send_alert("[Signal 경고] 오늘 페이지 미생성 {$TODAY}", $msg);
} else {
    $news_count = count(json_decode($page['news_ids'] ?? '[]', true));
    log_msg("✓ 페이지 생성 확인: {$news_count}건");
}

// ── 2. 오늘 발송 로그 확인 ───────────────────────────────
$log_stmt = $pdo->prepare("SELECT * FROM digest_logs WHERE sent_date = :d");
$log_stmt->execute([':d' => $TODAY]);
$log = $log_stmt->fetch(PDO::FETCH_ASSOC);

if (!$log) {
    $msg = "오늘({$TODAY}) Signal 이메일이 발송되지 않았습니다.\ndigest_mailer.php 실행 여부를 확인하세요.";
    log_msg("⚠ " . $msg);
    send_alert("[Signal 경고] 이메일 미발송 {$TODAY}", $msg);
} else {
    $total      = (int)$log['total_subs'];
    $sent       = (int)$log['sent_count'];
    $fail       = (int)$log['fail_count'];
    $fail_rate  = $total > 0 ? round($fail / $total * 100, 1) : 0;
    $success_rate = $total > 0 ? round($sent / $total * 100, 1) : 0;

    log_msg("✓ 발송 결과: 성공 {$sent} / 실패 {$fail} / 전체 {$total} (성공률 {$success_rate}%)");

    // 발송 0건 또는 실패율 50% 초과 → 알람
    if ($sent === 0) {
        $msg = "오늘({$TODAY}) Signal 이메일 발송 성공 건수가 0입니다.\n발송 스크립트 오류를 확인하세요.\n\n발송 기록:\n전체: {$total}명\n성공: {$sent}\n실패: {$fail}";
        log_msg("✗ " . $msg);
        send_alert("[Signal 긴급] 발송 0건 {$TODAY}", $msg);
    } elseif ($fail_rate > 50) {
        $msg = "오늘({$TODAY}) Signal 이메일 실패율이 {$fail_rate}%입니다.\n\n발송 기록:\n전체: {$total}명\n성공: {$sent}\n실패: {$fail}\n실패율: {$fail_rate}%\n\nSMTP 설정 또는 Gmail 한도를 확인하세요.";
        log_msg("⚠ " . $msg);
        send_alert("[Signal 경고] 실패율 {$fail_rate}% {$TODAY}", $msg);
    } else {
        log_msg("✓ 모니터링 정상 — 발송 성공률 {$success_rate}%");
    }
}

// ── 3. 수집 상태 요약 (참고용) ───────────────────────────
$collect_stmt = $pdo->query(
    "SELECT status, COUNT(*) as cnt FROM ai_news WHERE DATE(collected_at) = '{$TODAY}' GROUP BY status"
);
$collect_stats = $collect_stmt->fetchAll(PDO::FETCH_KEY_PAIR);

$stat_str = '';
foreach ($collect_stats as $status => $cnt) {
    $stat_str .= "  {$status}: {$cnt}건\n";
}
log_msg("✓ 오늘 수집 현황:\n{$stat_str}");

log_msg("==============================================");
