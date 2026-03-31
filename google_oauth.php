<?php
/**
 * google_oauth.php — Google OAuth 2.0 시작점
 *
 * Signal 구독 신청 시 [Google로 계속하기] 버튼이 이 파일로 POST 요청을 보냅니다.
 * CSRF state 토큰을 발급하고, 동의 정보를 세션에 저장한 뒤
 * Google 인증 서버로 리다이렉트합니다.
 *
 * @deploy: Signal Google OAuth 시작점 신규 추가
 */

require_once __DIR__ . '/config.php';

// ── 세션 보안 설정 ─────────────────────────────────────
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure',   1);   // HTTPS 전용
ini_set('session.use_strict_mode', 1);
ini_set('session.cookie_samesite', 'Lax');
session_start();

// ── Origin 검증 (CSRF 1차 방어) ───────────────────────
$allowed_origin = SITE_URL;  // 'https://vibestudio.prisincera.com'
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$referer = $_SERVER['HTTP_REFERER'] ?? '';

$origin_ok   = ($origin === $allowed_origin);
$referer_ok  = str_starts_with($referer, $allowed_origin);

if (!$origin_ok && !$referer_ok) {
    // 직접 URL 접근이나 외부 도메인 요청 차단
    header('Location: ' . SITE_URL . '/?error=invalid_request');
    exit;
}

// ── 동의 여부 확인 ─────────────────────────────────────
$consent_agreed  = isset($_POST['consent'])   ? 1 : 0;
$marketing_agreed = isset($_POST['marketing']) ? 1 : 0;

if (!$consent_agreed) {
    // 필수 동의 미완료 — 개인정보 동의 없이는 OAuth 시작 불가
    header('Location: ' . SITE_URL . '/#signal-subscribe?error=consent_required');
    exit;
}

// ── CSRF state 토큰 발급 ──────────────────────────────
$state = bin2hex(random_bytes(16));

// 세션 재생성 (세션 고정 공격 방지)
session_regenerate_id(true);

$_SESSION['oauth_state']       = $state;
$_SESSION['consent_agreed']    = $consent_agreed;
$_SESSION['marketing_agreed']  = $marketing_agreed;
$_SESSION['oauth_initiated_at'] = time();  // 타임아웃 검증용 (10분)

// ── Google 인증 URL 생성 ──────────────────────────────
$params = http_build_query([
    'client_id'     => GOOGLE_CLIENT_ID,
    'redirect_uri'  => GOOGLE_REDIRECT_URI,
    'response_type' => 'code',
    'scope'         => 'openid email',
    'state'         => $state,
    'access_type'   => 'online',
    'prompt'        => 'select_account', // 매번 계정 선택 화면 표시
]);

header('Location: https://accounts.google.com/o/oauth2/v2/auth?' . $params);
exit;
