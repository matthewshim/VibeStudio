<?php
/**
 * signal_subscribe.php — Signal 구독 처리 통합 API
 *
 * Google OAuth 경유 (source=google) 및 기존 이메일 직접 입력 방식 통합.
 * - Google 경유: 세션에서 이메일·동의 정보를 읽어 처리
 * - 수동 경유: POST 파라미터 + OTP 검증 (mail_auth.php 흐름 이후 호출)
 *
 * 완료 후: index.html#signal-subscribe?success=1&source={google|manual} 로 리다이렉트
 *
 * @deploy: Signal 구독 처리 통합 핸들러 신규 추가
 */

require_once __DIR__ . '/config.php';

// ── PHPMailer ─────────────────────────────────────────
$phpmailer_dir = __DIR__ . '/vendor/phpmailer/';
require_once $phpmailer_dir . 'Exception.php';
require_once $phpmailer_dir . 'PHPMailer.php';
require_once $phpmailer_dir . 'SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailerException;

// ── 세션 보안 설정 ─────────────────────────────────────
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure',   1);
ini_set('session.use_strict_mode', 1);
ini_set('session.cookie_samesite', 'Lax');
session_start();

// ── 공통 리다이렉트 헬퍼 ─────────────────────────────
function redirect_signal(string $param): void {
    header('Location: ' . SITE_URL . '/#signal-subscribe?' . $param);
    exit;
}

// ── DB 연결 ────────────────────────────────────────────
function get_signal_db(): PDO {
    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    return $pdo;
}

// ── SMTP 메일 발송 ─────────────────────────────────────
function signal_send_mail(string $to, string $subject, string $htmlBody): bool {
    $phpmailer_dir = __DIR__ . '/vendor/phpmailer/';
    // PHPMailer는 이미 require_once 됨
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

        $mail->setFrom(SMTP_FROM, 'Signal by Vibe Studio');
        $mail->addAddress($to);
        $mail->addReplyTo(SMTP_FROM, 'Signal by Vibe Studio');

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $htmlBody;

        $mail->send();
        return true;
    } catch (MailerException $e) {
        error_log('[SignalSubscribe] Mail error: ' . $mail->ErrorInfo);
        return false;
    }
}

// ── 웰컴 이메일 HTML 템플릿 ───────────────────────────
function build_signal_welcome_email(string $email, bool $isResubscribe): string {
    $title   = $isResubscribe ? '👋 Signal 재구독을 환영해요!' : '🎉 Signal 구독을 시작했습니다!';
    $heading = $isResubscribe ? '다시 돌아오셨군요!' : '구독해주셔서 감사해요!';
    $body    = $isResubscribe
        ? 'Signal AI 뉴스레터 수신이 다시 시작됩니다.<br>최신 AI 소식을 가장 먼저 전해드릴게요.'
        : 'Signal은 매일 주요 AI 뉴스를 큐레이션해서 전달해 드립니다.<br>놓치지 마세요!';
    $unsubscribe_url = SITE_URL . '/#signal-subscribe';

    return <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f0f0ff;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#f0f0ff;padding:40px 20px;">
    <tr><td align="center">
      <table width="100%" style="max-width:520px;background:#ffffff;border-radius:24px;overflow:hidden;box-shadow:0 12px 48px rgba(99,102,241,0.12);">
        <!-- Header -->
        <tr>
          <td style="background:linear-gradient(135deg,#6366f1 0%,#8b5cf6 50%,#a855f7 100%);padding:40px 32px;text-align:center;">
            <div style="font-size:14px;font-weight:700;letter-spacing:3px;color:rgba(255,255,255,0.7);text-transform:uppercase;margin-bottom:12px;">SIGNAL</div>
            <h1 style="margin:0;color:#ffffff;font-size:26px;font-weight:800;letter-spacing:-0.5px;">$title</h1>
            <p style="margin:10px 0 0;color:rgba(255,255,255,0.85);font-size:14px;">by Vibe Studio</p>
          </td>
        </tr>
        <!-- Body -->
        <tr>
          <td style="padding:36px 32px;">
            <p style="margin:0 0 16px;color:#1d1d1f;font-size:16px;font-weight:700;">$heading</p>
            <p style="margin:0 0 24px;color:#555;font-size:14px;line-height:1.8;">$body</p>
            <!-- Info Card -->
            <div style="background:#f8f7ff;border:1px solid #e8e6ff;border-radius:14px;padding:20px 24px;margin-bottom:24px;">
              <table width="100%" cellpadding="0" cellspacing="0">
                <tr>
                  <td style="font-size:12px;color:#8b8baa;font-weight:600;padding:4px 0;">구독 이메일</td>
                  <td style="font-size:13px;color:#1d1d1f;font-weight:700;text-align:right;padding:4px 0;">$email</td>
                </tr>
                <tr>
                  <td colspan="2" style="border-top:1px dashed #e0deff;padding:4px 0;"></td>
                </tr>
                <tr>
                  <td style="font-size:12px;color:#8b8baa;font-weight:600;padding:4px 0;">수신 콘텐츠</td>
                  <td style="font-size:13px;color:#6366f1;font-weight:700;text-align:right;padding:4px 0;">매일 AI 뉴스 다이제스트</td>
                </tr>
              </table>
            </div>
            <p style="margin:0;color:#86868b;font-size:12px;line-height:1.7;">
              구독을 원하지 않으시면 언제든지 <a href="$unsubscribe_url" style="color:#6366f1;">구독 취소</a>하실 수 있습니다.
            </p>
          </td>
        </tr>
        <!-- CTA -->
        <tr>
          <td style="padding:0 32px 32px;text-align:center;">
            <a href="https://vibestudio.prisincera.com/signal/" style="display:inline-block;padding:14px 40px;background:linear-gradient(135deg,#6366f1,#a855f7);color:#ffffff;font-size:14px;font-weight:700;border-radius:100px;text-decoration:none;letter-spacing:-0.2px;">Signal 콘텐츠 보기 →</a>
          </td>
        </tr>
        <!-- Footer -->
        <tr>
          <td style="padding:20px 32px 28px;border-top:1px solid #f0f0f0;text-align:center;">
            <p style="margin:0;font-size:11px;color:#a1a1a6;">
              © 2026 Signal by Vibe Studio · Powered by <strong>Prisincera</strong><br>
              <span style="color:#c0c0c0;">이 메일은 Signal 구독 신청에 의해 발송되었습니다.</span>
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

// ═══════════════════════════════════════════════════════
//  메인 처리 로직
// ═══════════════════════════════════════════════════════

$source = $_GET['source'] ?? 'manual';

if ($source === 'google') {
    // ── Google OAuth 경유 ─────────────────────────────
    $email     = $_SESSION['google_email'] ?? null;
    $googleId  = $_SESSION['google_id']    ?? null;
    $consentIp = $_SESSION['consent_ip']   ?? $_SERVER['REMOTE_ADDR'];
    $consentUa = $_SESSION['consent_ua']   ?? '';
    $marketing = (int)($_SESSION['marketing_consent'] ?? 0);

    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        redirect_signal('error=session_expired');
    }

} else {
    // ── 수동 이메일 입력 경유 (OTP 검증 완료 후) ─────
    // OTP 검증은 mail_auth.php?action=verify 에서 완료된 뒤 호출될 것을 전제
    if (!isset($_SESSION['auth_verified']) || $_SESSION['auth_verified'] !== true) {
        redirect_signal('error=not_verified');
    }

    $email     = filter_var($_SESSION['auth_email'] ?? '', FILTER_VALIDATE_EMAIL);
    $googleId  = null;
    $consentIp = $_SERVER['REMOTE_ADDR'];
    $consentUa = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500);
    $marketing = (int)($_POST['marketing'] ?? 0);

    if (!$email) {
        redirect_signal('error=invalid_email');
    }
}

// ── DB 처리 ──────────────────────────────────────────
try {
    $pdo = get_signal_db();

    // 기존 구독 여부 확인
    $stmt = $pdo->prepare("SELECT id, status FROM digest_subs WHERE email = ?");
    $stmt->execute([$email]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        if ($existing['status'] === 'active') {
            // 이미 구독 중
            redirect_signal('error=already_subscribed&email=' . urlencode($email));
        }

        // 재구독 처리 (status 복원, 동의 기록 갱신)
        $stmt = $pdo->prepare("
            UPDATE digest_subs
            SET status            = 'active',
                subscribed_at     = NOW(),
                source            = ?,
                google_id         = ?,
                consent_at        = NOW(),
                consent_ip        = ?,
                consent_ua        = ?,
                marketing_consent = ?,
                unsubscribed_at   = NULL
            WHERE email = ?
        ");
        $stmt->execute([$source, $googleId, $consentIp, $consentUa, $marketing, $email]);
        $isResubscribe = true;

    } else {
        // 신규 구독
        $token        = bin2hex(random_bytes(32));  // 구독 취소용 토큰
        $referralCode = strtoupper(substr(bin2hex(random_bytes(5)), 0, 8));

        $stmt = $pdo->prepare("
            INSERT INTO digest_subs
            (email, token, referral_code, source, google_id,
             consent_at, consent_ip, consent_ua, marketing_consent)
            VALUES (?, ?, ?, ?, ?, NOW(), ?, ?, ?)
        ");
        $stmt->execute([
            $email, $token, $referralCode, $source, $googleId,
            $consentIp, $consentUa, $marketing,
        ]);
        $isResubscribe = false;
    }

} catch (PDOException $e) {
    error_log('[SignalSubscribe] DB error: ' . $e->getMessage());
    redirect_signal('error=db_error');
}

// ── 세션 정리 ─────────────────────────────────────────
$session_keys = [
    'google_email', 'google_id', 'consent_ip', 'consent_ua',
    'marketing_consent', 'marketing_agreed', 'oauth_initiated_at',
    'auth_verified', 'auth_code', 'auth_email', 'auth_expire', 'auth_time',
];
foreach ($session_keys as $key) {
    unset($_SESSION[$key]);
}

// ── 웰컴 이메일 발송 ──────────────────────────────────
$subject  = $isResubscribe
    ? '👋 Signal 재구독을 환영해요!'
    : '🎉 Signal 구독이 완료되었습니다!';
$htmlBody = build_signal_welcome_email($email, $isResubscribe);
signal_send_mail($email, $subject, $htmlBody);  // 실패해도 구독 처리는 완료

// ── 완료 리다이렉트 ───────────────────────────────────
$flag = $isResubscribe ? '&resub=1' : '';
redirect_signal('success=1&source=' . $source . $flag);
