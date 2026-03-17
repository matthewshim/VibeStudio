<?php
session_start();

header('Content-Type: application/json');

$action = $_POST['action'] ?? '';

// DB 설정
$db_host = 'localhost';
$db_name = 'vibe_db';
$db_user = 'root';
$db_pass = 'vq.HlL6QthDG';

function get_db_conn()
{
    global $db_host, $db_name, $db_user, $db_pass;
    try {
        $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $pdo;
    } catch (PDOException $e) {
        return null;
    }
}

/**
 * RFC 2047 Base64 인코딩으로 한글 메일 제목 깨짐 방지
 */
function encode_subject($subject)
{
    return '=?UTF-8?B?' . base64_encode($subject) . '?=';
}

/**
 * UTF-8 HTML 메일 발송 공통 함수
 */
function send_html_mail($to, $subject, $htmlBody, $sender = "matthew.shim@prisincera.com")
{
    $encodedSubject = encode_subject($subject);
    $headers  = "From: Vibe Studio <$sender>\r\n";
    $headers .= "Reply-To: $sender\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "X-Mailer: PHP/" . phpversion();

    return mail($to, $encodedSubject, $htmlBody, $headers);
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

    $code = str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);

    $subject = "⚡ Vibe Studio 이메일 인증 코드";
    $htmlBody = build_auth_email($code);

    if (send_html_mail($email, $subject, $htmlBody)) {
        $_SESSION['mail_send_history'][] = $now;
        $_SESSION['auth_code'] = $code;
        $_SESSION['auth_email'] = $email;
        $_SESSION['auth_expire'] = $now + 600;
        $_SESSION['auth_time'] = date('Y-m-d H:i:s');
        echo json_encode(['success' => true, 'message' => '인증 메일을 발송했습니다.']);
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
    if (!isset($_SESSION['auth_verified']) || $_SESSION['auth_verified'] !== true) {
        echo json_encode(['success' => false, 'message' => '이메일 인증을 먼저 완료해주세요.']);
        exit;
    }

    $email = $_SESSION['auth_email'];
    $auth_time = $_SESSION['auth_time'] ?? date('Y-m-d H:i:s');
    $marketing = ($_POST['marketing'] === 'true') ? 1 : 0;
    $webapp = ($_POST['webapp'] === 'true') ? 1 : 0;
    $content = ($_POST['content'] === 'true') ? 1 : 0;
    $coffee = ($_POST['coffee'] === 'true') ? 1 : 0;

    $pdo = get_db_conn();
    if (!$pdo) {
        echo json_encode(['success' => false, 'message' => '데이터베이스 연결 실패']);
        exit;
    }

    try {
        $checkStmt = $pdo->prepare("SELECT webapp_apply, marketing_consent, content_subscribe, coffee_chat FROM pre_registrations WHERE email = ?");
        $checkStmt->execute([$email]);
        $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            if (
                (int) $existing['webapp_apply'] === $webapp &&
                (int) $existing['marketing_consent'] === $marketing &&
                (int) $existing['content_subscribe'] === $content &&
                (int) $existing['coffee_chat'] === $coffee
            ) {
                echo json_encode(['success' => false, 'message' => '어라? 이미 동일한 항목으로 신청되어 있네요! 😅']);
                exit;
            }

            $stmt = $pdo->prepare("UPDATE pre_registrations SET auth_time = ?, webapp_apply = ?, marketing_consent = ?, content_subscribe = ?, coffee_chat = ? WHERE email = ?");
            $stmt->execute([$auth_time, $webapp, $marketing, $content, $coffee, $email]);
            $isUpdate = true;
        } else {
            $stmt = $pdo->prepare("INSERT INTO pre_registrations (email, auth_time, webapp_apply, marketing_consent, content_subscribe, coffee_chat) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$email, $auth_time, $webapp, $marketing, $content, $coffee]);
            $isUpdate = false;
        }

        // 완료 메일 발송 (HTML + UTF-8 인코딩 제목)
        $subject = $isUpdate
            ? "🔄 Vibe Studio 신청 정보가 업데이트되었습니다"
            : "🎉 Vibe Studio 사전예약이 완료되었습니다!";
        $htmlBody = build_welcome_email($email, $isUpdate);

        send_html_mail($email, $subject, $htmlBody);

        // 세션 초기화
        unset($_SESSION['auth_verified']);
        unset($_SESSION['auth_code']);
        unset($_SESSION['auth_email']);
        unset($_SESSION['auth_expire']);
        unset($_SESSION['auth_time']);

        $resMsg = $isUpdate ? '신청 정보가 성공적으로 업데이트되었습니다! ✨' : 'Vibe Studio의 Fan이 되신 걸 환영합니다! 🎉';
        echo json_encode(['success' => true, 'message' => $resMsg]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => '저장 중 오류 발생: ' . $e->getMessage()]);
    }
    exit;
}

echo json_encode(['success' => false, 'message' => '잘못된 요청입니다.']);
