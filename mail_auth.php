<?php
session_start();

header('Content-Type: application/json');

$action = $_POST['action'] ?? '';

// CSRF 방어 — 허가된 Origin이 아닌 외부 요청 차단
if (!empty($action)) {
    $allowed_origin = 'https://vibestudio.prisincera.com';
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin !== $allowed_origin) {
        echo json_encode(['success' => false, 'message' => '잘못된 요청입니다.']);
        exit;
    }
}

// ── PHPMailer (Gmail SMTP) ─────────────────────────────
$phpmailer_dir = __DIR__ . '/vendor/phpmailer/';
require_once $phpmailer_dir . 'Exception.php';
require_once $phpmailer_dir . 'PHPMailer.php';
require_once $phpmailer_dir . 'SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailerException;

// ── 설정 로드 ──────────────────────────────────────────
require_once __DIR__ . '/config.php';

function get_db_conn()
{
    try {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        $pdo = new PDO($dsn, DB_USER, DB_PASS);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $pdo;
    } catch (PDOException $e) {
        return null;
    }
}

/**
 * PHPMailer Gmail SMTP 방식 메일 발송
 */
function send_html_mail($to, $subject, $htmlBody, $sender = SMTP_FROM)
{
    $mail = new PHPMailer(true);
    try {
        // SMTP 설정
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = SMTP_PORT;
        $mail->CharSet    = 'UTF-8';

        // 발신/수신
        $mail->setFrom(SMTP_FROM, SMTP_FROM_NAME);
        $mail->addAddress($to);
        $mail->addReplyTo(SMTP_FROM, SMTP_FROM_NAME);

        // 메일 내용
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $htmlBody;

        $mail->send();
        return true;
    } catch (MailerException $e) {
        error_log('[VibeMail] send_html_mail error: ' . $mail->ErrorInfo);
        return false;
    }
}

/**
 * 인증 메일 HTML 템플릿
 */
function build_auth_email($code)
{
    return <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;background:#f5f5f7;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#f5f5f7;padding:40px 20px;">
    <tr><td align="center">
      <table width="100%" style="max-width:500px;background:#ffffff;border-radius:20px;overflow:hidden;box-shadow:0 10px 40px rgba(0,0,0,0.08);">
        <!-- Header -->
        <tr>
          <td style="background:linear-gradient(135deg,#6366f1,#a855f7);padding:36px 30px;text-align:center;">
            <div style="font-size:32px;margin-bottom:8px;">⚡</div>
            <h1 style="margin:0;color:#ffffff;font-size:22px;font-weight:800;letter-spacing:-0.5px;">Vibe Studio</h1>
            <p style="margin:6px 0 0;color:rgba(255,255,255,0.85);font-size:13px;font-weight:500;">이메일 인증 코드가 도착했어요!</p>
          </td>
        </tr>
        <!-- Body -->
        <tr>
          <td style="padding:36px 30px;">
            <p style="margin:0 0 20px;color:#1d1d1f;font-size:15px;line-height:1.7;">
              안녕하세요! 👋<br>
              Vibe Studio 사전예약을 위한 인증 코드입니다.
            </p>
            <!-- Code Box -->
            <div style="background:linear-gradient(135deg,#f0f0ff,#faf5ff);border:2px solid #e0e0ff;border-radius:14px;padding:24px;text-align:center;margin:0 0 24px;">
              <p style="margin:0 0 8px;font-size:12px;font-weight:700;color:#6366f1;text-transform:uppercase;letter-spacing:1px;">인증 코드</p>
              <p style="margin:0;font-size:36px;font-weight:900;letter-spacing:8px;color:#1d1d1f;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;">$code</p>
            </div>
            <p style="margin:0 0 8px;color:#86868b;font-size:13px;line-height:1.6;">
              ⏳ 이 코드는 <strong style="color:#6366f1;">10분</strong> 동안 유효합니다.
            </p>
            <p style="margin:0;color:#86868b;font-size:13px;line-height:1.6;">
              🔒 본인이 요청하지 않은 메일이라면 무시하셔도 안전합니다.
            </p>
          </td>
        </tr>
        <!-- Footer -->
        <tr>
          <td style="padding:20px 30px 28px;border-top:1px solid #f0f0f0;text-align:center;">
            <p style="margin:0;font-size:11px;color:#a1a1a6;">
              © 2026 Vibe Studio · Powered by <strong>Prisincera</strong>
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

/**
 * 등록 완료 메일 HTML 템플릿
 */
function build_welcome_email($email, $isUpdate)
{
    $badge = $isUpdate ? '🔄 정보 업데이트 완료' : '🚀 사전예약 완료';
    $greeting = $isUpdate
        ? '신청하신 정보가 안전하게 업데이트되었습니다.'
        : 'Vibe Studio의 초기 멤버가 되신 것을 진심으로 환영합니다!';
    $emoji = $isUpdate ? '✅' : '🎉';

    return <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;background:#f5f5f7;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#f5f5f7;padding:40px 20px;">
    <tr><td align="center">
      <table width="100%" style="max-width:500px;background:#ffffff;border-radius:20px;overflow:hidden;box-shadow:0 10px 40px rgba(0,0,0,0.08);">
        <!-- Header -->
        <tr>
          <td style="background:linear-gradient(135deg,#6366f1,#a855f7);padding:36px 30px;text-align:center;">
            <div style="font-size:36px;margin-bottom:10px;">$emoji</div>
            <h1 style="margin:0;color:#ffffff;font-size:22px;font-weight:800;letter-spacing:-0.5px;">$badge</h1>
          </td>
        </tr>
        <!-- Body -->
        <tr>
          <td style="padding:36px 30px;">
            <p style="margin:0 0 20px;color:#1d1d1f;font-size:15px;line-height:1.7;">
              안녕하세요! 👋<br>
              $greeting
            </p>
            <!-- Info Card -->
            <div style="background:#fafafa;border:1px solid #f0f0f0;border-radius:14px;padding:20px;margin:0 0 24px;">
              <table width="100%" cellpadding="0" cellspacing="0">
                <tr>
                  <td style="padding:6px 0;font-size:13px;color:#86868b;font-weight:600;">등록 이메일</td>
                  <td style="padding:6px 0;font-size:13px;color:#1d1d1f;font-weight:700;text-align:right;">$email</td>
                </tr>
                <tr>
                  <td colspan="2" style="border-top:1px dashed #e8e8e8;padding-top:6px;margin-top:6px;"></td>
                </tr>
                <tr>
                  <td style="padding:6px 0;font-size:13px;color:#86868b;font-weight:600;">상태</td>
                  <td style="padding:6px 0;font-size:13px;color:#10b981;font-weight:700;text-align:right;">✅ 확인 완료</td>
                </tr>
              </table>
            </div>
            <p style="margin:0 0 16px;color:#1d1d1f;font-size:14px;line-height:1.7;">
              앞으로 아래 소식을 가장 먼저 전해 드릴게요:
            </p>
            <ul style="margin:0 0 24px;padding-left:20px;color:#1d1d1f;font-size:14px;line-height:2;">
              <li>🛠 새로운 웹앱 런칭 알림</li>
              <li>📝 기획자의 바이브 코딩 이야기</li>
              <li>🎁 초기 멤버 전용 혜택</li>
            </ul>
            <p style="margin:0;color:#86868b;font-size:13px;line-height:1.6;">
              Vibe Studio와 함께 더 멋진 작업 환경을 만들어가요. 💜
            </p>
          </td>
        </tr>
        <!-- CTA -->
        <tr>
          <td style="padding:0 30px 28px;text-align:center;">
            <a href="https://vibestudio.prisincera.com" style="display:inline-block;padding:14px 36px;background:linear-gradient(135deg,#6366f1,#a855f7);color:#ffffff;font-size:14px;font-weight:700;border-radius:100px;text-decoration:none;letter-spacing:-0.2px;">Vibe Studio 방문하기 →</a>
          </td>
        </tr>
        <!-- Footer -->
        <tr>
          <td style="padding:20px 30px 28px;border-top:1px solid #f0f0f0;text-align:center;">
            <p style="margin:0;font-size:11px;color:#a1a1a6;">
              © 2026 Vibe Studio · Powered by <strong>Prisincera</strong><br>
              <span style="color:#c0c0c0;">이 메일은 사전예약 신청에 의해 발송되었습니다.</span>
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

if ($action === 'send') {
    $email = $_POST['email'] ?? '';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'message' => '올바른 이메일 주소를 입력해주세요.']);
        exit;
    }

    // Throttling: 1분 내 3회 이상 발송 시 10분 차단
    if (!isset($_SESSION['mail_send_history'])) {
        $_SESSION['mail_send_history'] = [];
    }
    if (!isset($_SESSION['blocked_until'])) {
        $_SESSION['blocked_until'] = 0;
    }

    $now = time();

    if ($now < $_SESSION['blocked_until']) {
        echo json_encode(['success' => false, 'message' => '10분 후 다시 시도하세요.']);
        exit;
    }

    $_SESSION['mail_send_history'] = array_filter($_SESSION['mail_send_history'], function ($timestamp) use ($now) {
        return $timestamp > ($now - 60);
    });

    if (count($_SESSION['mail_send_history']) >= 2) {
        $_SESSION['blocked_until'] = $now + 600;
        echo json_encode(['success' => false, 'message' => '10분 후 다시 시도하세요.']);
        exit;
    }

    // ── 이미 등록된 이메일인지 확인 ─────────────────────────
    $already_registered = false;
    try {
        $pdo = get_db_conn();
        if ($pdo) {
            $chk = $pdo->prepare('SELECT id FROM pre_registrations WHERE email = :email LIMIT 1');
            $chk->execute([':email' => $email]);
            $already_registered = ($chk->fetch() !== false);
        }
    } catch (\Throwable $e) {
        $already_registered = false;
    }

    $code = str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);

    $subject = "⚡ Vibe Studio 이메일 인증 코드";
    $htmlBody = build_auth_email($code);

    if (send_html_mail($email, $subject, $htmlBody)) {
        $_SESSION['mail_send_history'][] = $now;
        $_SESSION['auth_code']   = $code;
        $_SESSION['auth_email']  = $email;
        $_SESSION['auth_expire'] = $now + 600;
        $_SESSION['auth_time']   = date('Y-m-d H:i:s');

        // 이미 등록된 이메일인 경우: OTP 입력 UI가 비활성화되므로
        // register 액션에서 OTP 검증을 통과할 수 있도록 auth_verified 미리 설정
        if ($already_registered) {
            $_SESSION['auth_verified'] = true;
        }

        echo json_encode([
            'success'            => true,
            'message'            => '인증 메일을 발송했습니다.',
            'already_registered' => $already_registered,
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => '메일 발송에 실패했습니다.']);
    }
    exit;
}

if ($action === 'verify') {
    $code = $_POST['code'] ?? '';
    $now = time();

    if (!isset($_SESSION['auth_code']) || !isset($_SESSION['auth_expire'])) {
        echo json_encode(['success' => false, 'message' => '인증 정보가 없습니다.']);
        exit;
    }

    if ($now > $_SESSION['auth_expire']) {
        echo json_encode(['success' => false, 'message' => '인증 시간이 만료되었습니다.']);
        exit;
    }

    if ($code === $_SESSION['auth_code']) {
        $_SESSION['auth_verified'] = true;
        echo json_encode(['success' => true, 'message' => '인증 성공!']);
    } else {
        echo json_encode(['success' => false, 'message' => '인증번호가 일치하지 않습니다.']);
    }
    exit;
}

if ($action === 'register') {
    // ── Google OAuth 인증 우회 경로 ──────────────────────────
    // fan_google_verified 세션이 있으면 OTP 검증 없이 처리
    $isGoogleVerified = isset($_SESSION['fan_google_verified']) && $_SESSION['fan_google_verified'] === true;

    if (!$isGoogleVerified) {
        // 기존 이메일 OTP 인증 확인
        if (!isset($_SESSION['auth_verified']) || $_SESSION['auth_verified'] !== true) {
            echo json_encode(['success' => false, 'message' => '이메일 인증을 먼저 완료해주세요.']);
            exit;
        }
    }

    // 이메일 소스 결정
    if ($isGoogleVerified) {
        $email     = $_SESSION['fan_google_email'] ?? '';
        $googleId  = $_SESSION['fan_google_id']    ?? null;
        $auth_time = date('Y-m-d H:i:s');
        $source    = 'google';
    } else {
        $email     = $_SESSION['auth_email'];
        $googleId  = null;
        $auth_time = $_SESSION['auth_time'] ?? date('Y-m-d H:i:s');
        $source    = 'manual';
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'message' => '유효하지 않은 이메일입니다.']);
        exit;
    }

    $marketing = ($_POST['marketing'] === 'true') ? 1 : 0;
    $webapp    = ($_POST['webapp']    === 'true') ? 1 : 0;
    $content   = ($_POST['content']   === 'true') ? 1 : 0;
    $coffee    = ($_POST['coffee']    === 'true') ? 1 : 0;

    $pdo = get_db_conn();
    if (!$pdo) {
        echo json_encode(['success' => false, 'message' => '데이터베이스 연결 실패']);
        exit;
    }

    // ── google_id 컬럼 마이그레이션 (없으면 추가) ────────────
    try {
        $pdo->exec("ALTER TABLE pre_registrations ADD COLUMN google_id VARCHAR(255) NULL DEFAULT NULL AFTER coffee_chat");
    } catch (Exception $e) { /* 이미 있음 */ }
    try {
        $pdo->exec("ALTER TABLE pre_registrations ADD COLUMN reg_source ENUM('manual','google') NOT NULL DEFAULT 'manual' AFTER google_id");
    } catch (Exception $e) { /* 이미 있음 */ }

    try {
        $checkStmt = $pdo->prepare("SELECT id, webapp_apply, marketing_consent, content_subscribe, coffee_chat FROM pre_registrations WHERE email = ?");
        $checkStmt->execute([$email]);
        $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            // 기존 회원: 항목 변동 여부와 관계없이 항상 업데이트 허용
            $sets   = "auth_time=?, webapp_apply=?, marketing_consent=?, content_subscribe=?, coffee_chat=?, reg_source=?";
            $params = [$auth_time, $webapp, $marketing, $content, $coffee, $source];
            if ($googleId) { $sets .= ", google_id=?"; $params[] = $googleId; }
            $params[] = $email;
            $stmt = $pdo->prepare("UPDATE pre_registrations SET $sets WHERE email=?");
            $stmt->execute($params);
            $isUpdate = true;
        } else {
            $stmt = $pdo->prepare("INSERT INTO pre_registrations (email, auth_time, webapp_apply, marketing_consent, content_subscribe, coffee_chat, google_id, reg_source) VALUES (?,?,?,?,?,?,?,?)");
            $stmt->execute([$email, $auth_time, $webapp, $marketing, $content, $coffee, $googleId, $source]);
            $isUpdate = false;
        }

        // 완료 메일 발송
        $subject  = $isUpdate
            ? "🔄 Vibe Studio 신청 정보가 업데이트되었습니다"
            : "🎉 Vibe Studio 사전예약이 완료되었습니다!";
        $htmlBody = build_welcome_email($email, $isUpdate);
        send_html_mail($email, $subject, $htmlBody);

        // 세션 정리 — OTP 인증 관련 키만 제거
        // fan_google_* 는 제거하지 않음:
        //   Signal 구독 등 다른 페이지에서도 Google 세션이 필요하며,
        //   로그아웃은 fan_google_session_clear 액션(명시적)으로만 처리
        $clearKeys = ['auth_verified','auth_code','auth_email','auth_expire','auth_time'];
        foreach ($clearKeys as $k) unset($_SESSION[$k]);

        $resMsg = $isUpdate
            ? '신청 정보가 성공적으로 업데이트되었습니다! ✨'
            : 'Vibe Studio의 Fan이 되신 걸 환영합니다! 🎉';
        echo json_encode(['success' => true, 'message' => $resMsg, 'source' => $source]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => '저장 중 오류 발생: ' . $e->getMessage()]);
    }
    exit;
}

/* ── Signal 전용 구독 (signal_subscribe) ───────────────────────
   - Google 세션 필수
   - 기존 회원: content_subscribe = 1 만 UPDATE (나머지 커럼 유지)
   - 신규   : webapp=0, coffee=0, content=1, marketing=1
*/
if ($action === 'signal_subscribe') {
    $isGoogleVerified = !empty($_SESSION['fan_google_verified']) && $_SESSION['fan_google_verified'] === true;
    if (!$isGoogleVerified || empty($_SESSION['fan_google_email'])) {
        echo json_encode(['success' => false, 'message' => 'Google 인증이 필요합니다.']);
        exit;
    }

    $email     = $_SESSION['fan_google_email'];
    $googleId  = $_SESSION['fan_google_id'] ?? null;
    $auth_time = date('Y-m-d H:i:s');

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'message' => '유효하지 않은 이메일입니다.']);
        exit;
    }

    $pdo = get_db_conn();
    if (!$pdo) {
        echo json_encode(['success' => false, 'message' => 'DB 연결 실패']);
        exit;
    }

    // google_id 커럼 마이그레이션
    try { $pdo->exec("ALTER TABLE pre_registrations ADD COLUMN google_id VARCHAR(255) NULL DEFAULT NULL AFTER coffee_chat"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE pre_registrations ADD COLUMN reg_source ENUM('manual','google') NOT NULL DEFAULT 'manual' AFTER google_id"); } catch (Exception $e) {}

    try {
        $chk = $pdo->prepare("SELECT id FROM pre_registrations WHERE email = ? LIMIT 1");
        $chk->execute([$email]);
        $existing = $chk->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            // 기존 회원: content_subscribe만 1로 갱신, 나머지 커럼 기존값 유지
            $upd = $pdo->prepare("UPDATE pre_registrations SET content_subscribe=1, auth_time=?, reg_source='google' WHERE email=?");
            $upd->execute([$auth_time, $email]);
            $isUpdate = true;
        } else {
            // 신규: webapp=0, coffee=0, content=1, marketing=1
            $ins = $pdo->prepare("INSERT INTO pre_registrations (email, auth_time, webapp_apply, marketing_consent, content_subscribe, coffee_chat, google_id, reg_source) VALUES (?,?,0,1,1,0,?,'google')");
            $ins->execute([$email, $auth_time, $googleId]);
            $isUpdate = false;
        }

        // 세션 정리 (선택)
        // 메인 패널에서도 Google 세션을 유지해야 하므로 클리어 안 함

        $resMsg = $isUpdate ? '구독 정보가 업데이트되었습니다.' : 'Signal 구독이 완료되었습니다.';
        echo json_encode(['success' => true, 'message' => $resMsg, 'is_update' => $isUpdate]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'DB 오류: ' . $e->getMessage()]);
    }
    exit;
}

echo json_encode(['success' => false, 'message' => '잘못된 요청입니다.']);
