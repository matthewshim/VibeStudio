<?php
/**
 * google_fan_oauth.php — 사전예약 팝업 전용 Google OAuth 시작점
 *
 * 플로팅 패널 JS에서 window.open()으로 호출됩니다.
 * CSRF state 발급 후 Google 인증 서버로 리다이렉트.
 *
 * @deploy: 사전예약 팝업 Google OAuth 시작점 신규 추가
 */

require_once __DIR__ . '/config.php';

ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure',   1);
ini_set('session.use_strict_mode', 1);
ini_set('session.cookie_samesite', 'Lax');
session_start();

// 팝업 referrer/origin 검증 (완전히 빈 referrer는 팝업 방식에서 허용)
$referer = $_SERVER['HTTP_REFERER'] ?? '';
if ($referer && !str_starts_with($referer, SITE_URL)) {
    http_response_code(403);
    exit('Forbidden');
}

// CSRF state 발급
$state = bin2hex(random_bytes(16));
session_regenerate_id(true);

$_SESSION['fan_oauth_state']        = $state;
$_SESSION['fan_oauth_initiated_at'] = time();

$redirect_uri = SITE_URL . '/google_fan_callback.php';

$params = http_build_query([
    'client_id'     => GOOGLE_CLIENT_ID,
    'redirect_uri'  => $redirect_uri,
    'response_type' => 'code',
    'scope'         => 'openid email profile',
    'state'         => $state,
    'access_type'   => 'online',
    'prompt'        => 'select_account',
]);

header('Location: https://accounts.google.com/o/oauth2/v2/auth?' . $params);
exit;
