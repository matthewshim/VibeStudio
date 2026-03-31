<?php
/**
 * google_callback.php — Google OAuth 2.0 콜백 처리 (핵심)
 *
 * Google 인증 서버에서 리다이렉트되어 호출됩니다.
 * 시나리오 A (이메일 정상 수신) → signal_subscribe.php로 위임
 * 시나리오 B (이메일 미수신)    → 폴백 이메일 입력 안내
 * 시나리오 C (사용자 취소)      → 구독 폼으로 복귀
 *
 * @deploy: Signal Google OAuth 콜백 핸들러 신규 추가
 */

require_once __DIR__ . '/config.php';

// ── 세션 보안 설정 ─────────────────────────────────────
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure',   1);
ini_set('session.use_strict_mode', 1);
ini_set('session.cookie_samesite', 'Lax');
session_start();

// ── 공통 리다이렉트 헬퍼 ─────────────────────────────
function redirect_with_error(string $error): void {
    header('Location: ' . SITE_URL . '/#signal-subscribe?error=' . $error);
    exit;
}

// ── ① OAuth 시작 흐름 타임아웃 (10분) ────────────────
$initiated_at = $_SESSION['oauth_initiated_at'] ?? 0;
if (time() - $initiated_at > 600) {
    session_destroy();
    redirect_with_error('timeout');
}

// ── ② CSRF state 검증 ─────────────────────────────────
$received_state = $_GET['state'] ?? '';
$expected_state = $_SESSION['oauth_state'] ?? '';

if (empty($received_state) || !hash_equals($expected_state, $received_state)) {
    // state 불일치 → CSRF 공격 또는 세션 만료
    session_destroy();
    redirect_with_error('invalid_state');
}
unset($_SESSION['oauth_state']);

// ── ③ 사용자 취소 처리 (시나리오 C) ──────────────────
if (isset($_GET['error']) && $_GET['error'] === 'access_denied') {
    redirect_with_error('cancelled');
}

// ── ④ Authorization Code 수신 확인 ──────────────────
$auth_code = $_GET['code'] ?? '';
if (empty($auth_code)) {
    redirect_with_error('no_code');
}

// ── ⑤ Authorization Code → Access Token 교환 ────────
$token_context = stream_context_create([
    'http' => [
        'method'  => 'POST',
        'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
        'content' => http_build_query([
            'code'          => $auth_code,
            'client_id'     => GOOGLE_CLIENT_ID,
            'client_secret' => GOOGLE_CLIENT_SECRET,
            'redirect_uri'  => GOOGLE_REDIRECT_URI,
            'grant_type'    => 'authorization_code',
        ]),
        'timeout' => 10,
        'ignore_errors' => true,
    ],
]);

$token_response = @file_get_contents('https://oauth2.googleapis.com/token', false, $token_context);

if ($token_response === false) {
    error_log('[SignalOAuth] Token exchange request failed (network error)');
    redirect_with_error('network_error');
}

$token = json_decode($token_response, true);

if (empty($token['access_token'])) {
    error_log('[SignalOAuth] Token exchange failed: ' . $token_response);
    redirect_with_error('token_failed');
}

// ── ⑥ Google UserInfo API 호출 (이메일 수신) ─────────
$userinfo_context = stream_context_create([
    'http' => [
        'method' => 'GET',
        'header' => "Authorization: Bearer " . $token['access_token'] . "\r\n",
        'timeout' => 10,
        'ignore_errors' => true,
    ],
]);

$userinfo_response = @file_get_contents(
    'https://www.googleapis.com/oauth2/v3/userinfo',
    false,
    $userinfo_context
);

if ($userinfo_response === false) {
    error_log('[SignalOAuth] UserInfo request failed (network error)');
    redirect_with_error('network_error');
}

$userInfo = json_decode($userinfo_response, true);

$email    = filter_var($userInfo['email'] ?? '', FILTER_VALIDATE_EMAIL);
$googleId = $userInfo['sub'] ?? null;  // Google 계정 고유 식별자

// ── ⑦ 이메일 존재 여부 분기 (시나리오 A / B) ─────────
if (!$email) {
    // 시나리오 B: 이메일 미수신 → 폴백 (직접 입력 유도)
    redirect_with_error('no_email&source=google');
}

// ── ⑧ 세션 재생성 후 구독 처리 위임 ─────────────────
// Access Token은 세션에 저장하지 않음 (보안 원칙)
session_regenerate_id(true);

$_SESSION['google_email']       = $email;
$_SESSION['google_id']          = $googleId;
$_SESSION['consent_ip']         = $_SERVER['REMOTE_ADDR'];
$_SESSION['consent_ua']         = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500);
$_SESSION['marketing_consent']  = (int)($_SESSION['marketing_agreed'] ?? 0);

// google_oauth.php에서 저장한 중간값 정리
unset($_SESSION['marketing_agreed'], $_SESSION['oauth_initiated_at']);

header('Location: ' . SITE_URL . '/signal_subscribe.php?source=google');
exit;
