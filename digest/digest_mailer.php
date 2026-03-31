<?php
/**
 * digest_mailer.php — Signal 데일리 뉴스 이메일 발송
 * @deploy: 헤더 아이콘 이모지→인라인 SVG 교체 (이메일 클라이언트 호환)
 * @deploy: "+ 더 보기" 간략 목록 섹션 제거
 * @deploy: 전체 뉴스 기사(최대 7건)에 Top3 카드 디자인 일괄 적용
 *
 * 파이프라인 4단계: news_pages 오늘 기사 → 구독자 이메일 발송 → digest_logs 기록
 * Cron: 1 23 * * * /opt/bitnami/php/bin/php /opt/bitnami/apache2/htdocs/digest/digest_mailer.php
 */

date_default_timezone_set('Asia/Seoul'); // KST 기준 날짜 통일
define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/config.php';

// ── PHPMailer ─────────────────────────────────────────────
$phpmailer_dir = BASE_PATH . '/vendor/phpmailer/';
require_once $phpmailer_dir . 'Exception.php';
require_once $phpmailer_dir . 'PHPMailer.php';
require_once $phpmailer_dir . 'SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailerException;

$LOG_FILE = '/tmp/signal_mail.log';
// CLI 날짜 오버라이드: php digest_mailer.php 2026-03-28 [--force]
$_date_arg = null;
foreach ($argv ?? [] as $_a) { if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $_a)) { $_date_arg = $_a; break; } }
$_dt      = new DateTime($_date_arg ?? 'now', new DateTimeZone('Asia/Seoul'));
$TODAY    = $_dt->format('Y-m-d');
$TODAY_KO = $_dt->format('Y년 n월 j일');

// CLI 옵션: --test=이메일주소 로 단일 테스트 발송
$test_email = null;
foreach ($argv ?? [] as $arg) {
    if (str_starts_with($arg, '--test=')) {
        $test_email = substr($arg, 7);
    }
}

function log_msg(string $msg): void {
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
    echo $line;
    file_put_contents($GLOBALS['LOG_FILE'], $line, FILE_APPEND);
}

log_msg("==============================================");
log_msg("digest_mailer.php 시작 | {$TODAY}");
if ($test_email) log_msg("🧪 테스트 모드: {$test_email}");

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

// ── 중복 발송 방지 ────────────────────────────────────────
if (!$test_email) {
    $dup = $pdo->prepare("SELECT id FROM digest_logs WHERE sent_date = :d LIMIT 1");
    $dup->execute([':d' => $TODAY]);
    if ($dup->fetch()) {
        log_msg("⚠ 오늘({$TODAY}) 이미 발송 완료 — 종료");
        log_msg("  → 강제 재발송: php digest_mailer.php --force");
        if (!in_array('--force', $argv ?? [])) exit(0);
    }
}

// ── 오늘 발행된 뉴스 기사 조회 (KST 날짜 기준) ─────────────────
$news_stmt = $pdo->prepare(
    "SELECT n.id, n.title, n.url, n.source_name, n.category, n.score, n.summary_ko
     FROM ai_news n
     WHERE n.status = 'sent'
       AND DATE(CONVERT_TZ(n.collected_at,'+00:00','+09:00')) = :today
     ORDER BY n.score DESC
     LIMIT 7"
);
$news_stmt->execute([':today' => $TODAY]);
$news = $news_stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($news)) {
    log_msg("✗ 오늘 발송할 기사 없음 — publish_news.php를 먼저 실행하세요");
    exit(1);
}

log_msg("✓ 발송 기사: " . count($news) . "건");

// ── 구독자 조회 ───────────────────────────────────────────
if ($test_email) {
    $subscribers = [['email' => $test_email]];
} else {
    $sub_stmt = $pdo->query(
        "SELECT email FROM pre_registrations
         WHERE content_subscribe = 1
         ORDER BY id ASC"
    );
    $subscribers = $sub_stmt->fetchAll(PDO::FETCH_ASSOC);
}

$total_subs = count($subscribers);
log_msg("✓ 구독자: {$total_subs}명");

if ($total_subs === 0) {
    log_msg("⚠ 구독자 없음 — 종료");
    exit(0);
}

// ── 카테고리 한국어 매핑 ─────────────────────────────────
function cat_label(string $cat): string {
    $map = [
        'research' => '연구·논문', 'bigtech' => '빅테크',
        'tools'    => '도구·제품', 'industry' => '산업·비즈니스',
        'korea'    => '국내 AI',   'tips'     => '실용 팁',
    ];
    return $map[$cat] ?? '뉴스';
}

function cat_color(string $cat): string {
    $map = [
        'research' => '#818cf8', 'bigtech' => '#38bdf8',
        'tools'    => '#34d399', 'industry' => '#fbbf24',
        'korea'    => '#f87171', 'tips'     => '#a855f7',
    ];
    return $map[$cat] ?? '#86868b';
}

// ── 이메일 HTML 빌드 ─────────────────────────────────────
function build_signal_email(array $news, string $date_ko, string $to_email, int $cnt, string $page_date = ''): string {
    $site_url   = SITE_URL;
    $today_date = $page_date ?: date('Y-m-d');
    $page_url   = "{$site_url}/signal/{$today_date}";

    // 뉴스 카드 (전체 동일 디자인 적용)
    $cards = '';
    foreach ($news as $i => $item) {
        $rank    = $i + 1;
        $title   = htmlspecialchars($item['title']);
        $summary = htmlspecialchars(mb_substr($item['summary_ko'], 0, 120));
        $source  = htmlspecialchars($item['source_name']);
        $label   = cat_label($item['category']);
        $color   = cat_color($item['category']);
        // UTM 파라미터 삽입
        $utm_url = $item['url'] . '?utm_source=signal&utm_medium=email&utm_campaign=daily&utm_date=' . $today_date;
        $utm_url = htmlspecialchars($utm_url);

        // Top3는 강조 배경, 나머지는 일반 배경
        $card_bg     = ($rank <= 3) ? '#f8f8fc' : '#ffffff';
        $card_border = ($rank <= 3) ? '#e8e8f0' : '#f0f0f4';
        $badge_bg    = ($rank <= 3) ? 'rgba(251,146,60,.1)'  : 'rgba(161,161,170,.08)';
        $badge_bd    = ($rank <= 3) ? 'rgba(251,146,60,.25)' : 'rgba(161,161,170,.2)';
        $badge_color = ($rank <= 3) ? '#fb923c' : '#71717a';

        $cards .= "
    <!-- 뉴스 카드 #{$rank} -->
    <tr>
      <td style=\"padding:0 30px 16px;\">
        <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" style=\"background:{$card_bg};border:1px solid {$card_border};border-radius:14px;overflow:hidden;\">
          <tr>
            <td style=\"padding:20px 22px;\">
              <span style=\"display:inline-block;background:{$badge_bg};border:1px solid {$badge_bd};color:{$badge_color};font-size:10px;font-weight:800;letter-spacing:.08em;padding:2px 9px;border-radius:100px;text-transform:uppercase;margin-bottom:8px;\">#{$rank} · {$label}</span>
              <p style=\"margin:0 0 8px;font-size:15px;font-weight:800;color:#18181b;line-height:1.4;letter-spacing:-.3px;\">{$title}</p>
              <p style=\"margin:0 0 12px;font-size:13px;color:#52525b;line-height:1.7;\">{$summary}</p>
              <a href=\"{$utm_url}\" style=\"display:inline-block;font-size:12px;font-weight:700;color:#fb923c;text-decoration:none;\">원문 읽기 →</a>
              <span style=\"font-size:11px;color:#a1a1aa;margin-left:10px;\">{$source}</span>
            </td>
          </tr>
        </table>
      </td>
    </tr>";
    }

    $extra = ''; // + 더 보기 섹션 제거

    // 구독 취소 링크 (수신 거부 의무)
    $unsub_url = "{$site_url}/signal?unsub=" . urlencode($to_email);

    return <<<HTML
<!DOCTYPE html>
<html lang="ko">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"></head>
<body style="margin:0;padding:0;background:#f5f5f7;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f5f5f7;padding:32px 16px;">
<tr><td align="center">
<table width="100%" style="max-width:560px;background:#ffffff;border-radius:20px;overflow:hidden;box-shadow:0 10px 40px rgba(0,0,0,.06);">

  <!-- 헤더 -->
  <tr>
    <td style="background:#111827;padding:36px 30px 32px;text-align:center;">
      <table width="100%" cellpadding="0" cellspacing="0">
        <!-- 앱 아이콘 -->
        <tr>
          <td align="center" style="padding-bottom:16px;">
            <img src="https://vibestudio.prisincera.com/img/signal-app-icon.png"
                 width="64" height="64" border="0" alt="Signal"
                 style="display:block;margin:0 auto;border-radius:16px;" />
          </td>
        </tr>
        <!-- 브랜드명 -->
        <tr>
          <td align="center" style="padding-bottom:4px;">
            <p style="margin:0;color:rgba(255,255,255,.45);font-size:10px;font-weight:700;letter-spacing:.18em;text-transform:uppercase;">VIBE STUDIO</p>
          </td>
        </tr>
        <!-- 서비스명 -->
        <tr>
          <td align="center" style="padding-bottom:6px;">
            <h1 style="margin:0;color:#ffffff;font-size:26px;font-weight:900;letter-spacing:-.5px;">Signal</h1>
          </td>
        </tr>
        <!-- 서브타이틀 -->
        <tr>
          <td align="center" style="padding-bottom:10px;">
            <p style="margin:0;color:rgba(255,255,255,.4);font-size:11px;font-weight:500;letter-spacing:.04em;">AI News Curation</p>
          </td>
        </tr>
        <!-- 구분선 -->
        <tr>
          <td align="center" style="padding-bottom:10px;">
            <table cellpadding="0" cellspacing="0" border="0" align="center">
              <tr><td width="32" height="1" style="background:rgba(255,255,255,.12);font-size:0;line-height:0;">&nbsp;</td></tr>
            </table>
          </td>
        </tr>
        <!-- 날짜 -->
        <tr>
          <td align="center">
            <p style="margin:0;color:rgba(255,255,255,.55);font-size:13px;font-weight:400;">{$date_ko} · 오늘의 AI 핵심 뉴스</p>
          </td>
        </tr>
      </table>
    </td>
  </tr>

  <!-- 인트로 -->
  <tr>
    <td style="padding:26px 30px 20px;">
      <p style="margin:0;font-size:14px;color:#52525b;line-height:1.7;">
        안녕하세요! Gemini AI가 오늘 선별한 AI 핵심 뉴스 <strong style="color:#fb923c;">{$cnt}건</strong>을 전해드립니다.
      </p>
    </td>
  </tr>

  <!-- 뉴스 카드 -->
{$cards}

  <!-- 전체 보기 CTA -->
  <tr>
    <td style="padding:8px 30px 28px;text-align:center;">
      <a href="{$page_url}?utm_source=signal&utm_medium=email&utm_campaign=daily_cta"
         style="display:inline-block;padding:13px 32px;background:linear-gradient(135deg,#fb923c,#f97316);color:#ffffff;font-size:14px;font-weight:700;border-radius:100px;text-decoration:none;letter-spacing:-.2px;box-shadow:0 4px 16px rgba(251,146,60,.3);">
        Signal 전체 보기 →
      </a>
    </td>
  </tr>

  <!-- 구분선 -->
  <tr><td style="height:1px;background:#f0f0f4;"></td></tr>

  <!-- 푸터 -->
  <tr>
    <td style="padding:20px 30px 26px;text-align:center;">
      <p style="margin:0 0 6px;font-size:11px;color:#a1a1aa;">
        © 2026 Vibe Studio · Powered by <strong>Prisincera</strong>
      </p>
      <p style="margin:0;font-size:10px;color:#c0c0c8;">
        이 메일은 Signal 구독 신청에 의해 발송되었습니다.
        <a href="{$unsub_url}" style="color:#a1a1aa;text-decoration:underline;">수신 거부</a>
      </p>
    </td>
  </tr>

</table>
</td></tr>
</table>
</body>
</html>
HTML;
}

// ── PHPMailer 발송 함수 ───────────────────────────────────
function send_signal_mail(string $to, string $subject, string $html): bool {
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

        $mail->setFrom(SMTP_FROM, 'Vibe Studio Signal');
        $mail->addAddress($to);
        $mail->addReplyTo(SMTP_FROM, 'Vibe Studio Signal');

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $html;

        $mail->send();
        return true;
    } catch (MailerException $e) {
        error_log('[Signal Mailer] ' . $mail->ErrorInfo);
        return false;
    }
}

// ── 발송 실행 ─────────────────────────────────────────────
$cnt        = count($news);
$news_ids   = json_encode(array_column($news, 'id'));
$subject    = "⚡ Signal · {$TODAY_KO} — 오늘의 AI 핵심 {$cnt}선";

$sent_count = 0;
$fail_count = 0;

foreach ($subscribers as $idx => $sub) {
    $email = $sub['email'];
    $html  = build_signal_email($news, $TODAY_KO, $email, $cnt, $TODAY);

    // 과부하 방지: 5건마다 0.5초 대기
    if ($idx > 0 && $idx % 5 === 0) usleep(500000);

    if (send_signal_mail($email, $subject, $html)) {
        $sent_count++;
        log_msg("✓ [{$sent_count}/{$total_subs}] {$email}");
    } else {
        $fail_count++;
        log_msg("✗ 발송 실패: {$email}");
    }
}

log_msg("── 발송 완료: 성공 {$sent_count} / 실패 {$fail_count} / 전체 {$total_subs} ──");

// ── digest_logs 기록 ──────────────────────────────────────
if (!$test_email) {
    try {
        $log_stmt = $pdo->prepare(
            "INSERT INTO digest_logs (sent_date, subject_line, total_subs, sent_count, fail_count, news_ids)
             VALUES (:date, :subj, :total, :sent, :fail, :ids)
             ON DUPLICATE KEY UPDATE
               subject_line=VALUES(subject_line), total_subs=VALUES(total_subs),
               sent_count=VALUES(sent_count), fail_count=VALUES(fail_count), news_ids=VALUES(news_ids)"
        );
        $log_stmt->execute([
            ':date'  => $TODAY,
            ':subj'  => $subject,
            ':total' => $total_subs,
            ':sent'  => $sent_count,
            ':fail'  => $fail_count,
            ':ids'   => $news_ids,
        ]);
        log_msg("✓ digest_logs 기록 완료");
    } catch (PDOException $e) {
        log_msg("⚠ digest_logs 기록 실패: " . $e->getMessage());
    }
}

log_msg("==============================================");
