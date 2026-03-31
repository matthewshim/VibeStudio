<?php
/**
 * google_fan_callback.php — 사전예약 팝업 전용 Google OAuth 콜백
 *
 * 플로팅 패널(SPA)에서 popup 방식으로 열린 OAuth 창의 콜백.
 * 인증 완료 후 window.opener에 postMessage로 이메일·google_id 전달하고 창을 닫습니다.
 *
 * @deploy: 사전예약 팝업 Google OAuth 콜백 신규 추가
 */

require_once __DIR__ . '/config.php';

ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure',   1);
ini_set('session.use_strict_mode', 1);
ini_set('session.cookie_samesite', 'Lax');
session_start();

/**
 * 결과를 opener에 postMessage로 전달하고 팝업을 닫는 HTML 출력
 */
function close_popup(string $status, array $payload = []): void {
    $payload['status'] = $status;
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
    $origin = SITE_URL;
    echo "<!DOCTYPE html><html><head><meta charset='UTF-8'></head><body>
<script>
(function(){
  var data = $json;
  try {
    if (window.opener) {
      window.opener.postMessage({ vibe_fan_oauth: data }, '$origin');
    }
  } catch(e) {}
  setTimeout(function(){ window.close(); }, 200);
})();
</script>
<p style='font-family:system-ui;text-align:center;padding:40px;color:#888;'>처리 중...</p>
</body></html>";
    exit;
}

// ① OAuth 시작 흐름 타임아웃 (10분)
$initiated_at = $_SESSION['fan_oauth_initiated_at'] ?? 0;
if (time() - $initiated_at > 600) {
    session_destroy();
    close_popup('error', ['code' => 'timeout']);
}

// ② CSRF state 검증
$received_state = $_GET['state'] ?? '';
$expected_state = $_SESSION['fan_oauth_state'] ?? '';

if (empty($received_state) || !hash_equals($expected_state, $received_state)) {
    session_destroy();
    close_popup('error', ['code' => 'invalid_state']);
}
unset($_SESSION['fan_oauth_state']);

// ③ 사용자 취소 처리
if (isset($_GET['error']) && $_GET['error'] === 'access_denied') {
    close_popup('cancelled');
}

// ④ Authorization Code 수신 확인
$auth_code = $_GET['code'] ?? '';
if (empty($auth_code)) {
    close_popup('error', ['code' => 'no_code']);
}

// ⑤ Authorization Code → Access Token 교환
$token_context = stream_context_create([
    'http' => [
        'method'        => 'POST',
        'header'        => "Content-Type: application/x-www-form-urlencoded\r\n",
        'content'       => http_build_query([
            'code'          => $auth_code,
            'client_id'     => GOOGLE_CLIENT_ID,
            'client_secret' => GOOGLE_CLIENT_SECRET,
            'redirect_uri'  => SITE_URL . '/google_fan_callback.php',
            'grant_type'    => 'authorization_code',
        ]),
        'timeout'       => 10,
        'ignore_errors' => true,
    ],
]);

$token_response = @file_get_contents('https://oauth2.googleapis.com/token', false, $token_context);
$token = json_decode($token_response, true);

if (empty($token['access_token'])) {
    error_log('[FanOAuth] Token exchange failed: ' . $token_response);
    close_popup('error', ['code' => 'token_failed']);
}

// ⑥ Google UserInfo API 호출
$userinfo_context = stream_context_create([
    'http' => [
        'method'        => 'GET',
        'header'        => "Authorization: Bearer " . $token['access_token'] . "\r\n",
        'timeout'       => 10,
        'ignore_errors' => true,
    ],
]);

$userinfo_response = @file_get_contents(
    'https://www.googleapis.com/oauth2/v3/userinfo',
    false,
    $userinfo_context
);
$userInfo = json_decode($userinfo_response, true);

$email    = filter_var($userInfo['email'] ?? '', FILTER_VALIDATE_EMAIL);
$googleId = $userInfo['sub'] ?? null;
$name     = $userInfo['name'] ?? '';

// ⑦ 이메일 존재 여부 분기
if (!$email) {
    close_popup('no_email');
}

// ⑧ 세션 정리 후 성공 응답
unset($_SESSION['fan_oauth_initiated_at']);
session_regenerate_id(true);

close_popup('success', [
    'email'     => $email,
    'google_id' => $googleId,
    'name'      => $name,
]);
