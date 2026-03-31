# 🔐 Signal — Google 로그인 연동 & 이메일 수집 정책 기획서

> **문서명**: Signal 구독 신청 — Google OAuth 2.0 연동 및 이메일 수집 세부 정책  
> **작성일**: 2026-03-20  
> **연계 문서**: `AI_DIGEST_PLAN.md` (Signal 서비스 구축 기획서), `AI_DIGEST_STRATEGY.md`  
> **스택**: PHP 8 · Google OAuth 2.0 · Google API PHP Client · MariaDB

> [!IMPORTANT]
> 구독 신청 시 이메일 주소 수집은 **서비스 필수 정보**이므로, 구글 로그인 후 이메일이 제공되지 않는 경우에 대한 **명확한 폴백 정책**이 필요합니다.  
> 한국 **개인정보보호법(PIPA)** 준수를 위한 동의 정책을 함께 수립합니다.

---

## 목차

1. [Google OAuth 2.0 개요 및 이메일 제공 원리](#1-google-oauth-20-개요-및-이메일-제공-원리)
2. [이메일 제공 시나리오 분류](#2-이메일-제공-시나리오-분류)
3. [세부 정책 수립](#3-세부-정책-수립)
4. [개인정보보호법 준수 동의 정책](#4-개인정보보호법-준수-동의-정책)
5. [구독 신청 UX Flow 설계](#5-구독-신청-ux-flow-설계)
6. [기술 구현 계획 (PHP)](#6-기술-구현-계획-php)
7. [데이터베이스 스키마 확장](#7-데이터베이스-스키마-확장)
8. [보안 체크리스트](#8-보안-체크리스트)
9. [개발 로드맵 (Phase)](#9-개발-로드맵-phase)

---

## 1. Google OAuth 2.0 개요 및 이메일 제공 원리

### 1.1 Google OAuth 2.0 인증 흐름

```
사용자
  │ [Google로 로그인] 클릭
  ▼
Google 인증 서버
  │ 동의 화면: "이 앱이 다음에 접근합니다"
  │   ✅ 이메일 주소 보기
  │   ✅ 기본 프로필 정보
  ▼
사용자 동의 → Google이 Authorization Code 발급
  │
  ▼
서버 (google_oauth.php)
  │ Authorization Code → Access Token 교환
  │ Access Token → Google UserInfo API 호출
  ▼
이메일·이름·프로필 사진 수신
```

### 1.2 요청하는 OAuth Scope

```php
$scopes = [
    'openid',   // 기본 인증 식별자
    'email',    // 이메일 주소 요청 ← 핵심
    'profile',  // 이름, 프로필 사진 (선택적)
];
```

### 1.3 이메일이 "제공되지 않을 수 있는" 경우

> [!NOTE]
> Google OAuth에서 `email` scope를 요청하고 사용자가 동의를 완료하면 **대부분 이메일이 반환**됩니다.  
> 그러나 다음 예외 상황에서 이메일이 누락되거나 사용 불가할 수 있습니다.

| 케이스 | 원인 | 발생 빈도 |
|--------|------|----------|
| **이메일 미인증 계정** | Google 계정에 인증된 이메일이 없음 | 매우 드묾 |
| **OAuth 흐름 중단** | 사용자가 동의 화면에서 취소 | 발생 가능 |
| **API 응답 오류** | Google 서버 오류 또는 네트워크 문제 | 드묾 |
| **서드파티 계정 연동** | 타사 SSO를 통한 Google 계정 (일부 제한) | 드묾 |
| **개인정보 보호 강화 설정** | 특정 Workspace 계정의 관리자 정책 | 드묾 |

---

## 2. 이메일 제공 시나리오 분류

### 시나리오 A — ✅ 이메일 정상 제공 (95%+ 대부분의 케이스)

```
Google OAuth 완료
      │
      ▼
email 값 수신 (예: user@gmail.com)
      │
      ▼
자동으로 구독 신청 플로우 진입
(이메일 입력 생략, OTP 생략 → UX 간소화)
      │
      ▼
개인정보 수집·이용 동의 (필수)
      │
      ▼
관심 분야 선택 (선택 사항)
      │
      ▼
구독 완료 → 웰컴 이메일 발송
```

**처리 전략**: 이메일 자동 입력, OTP 인증 단계 **생략**하여 최고의 UX 제공

---

### 시나리오 B — ⚠️ 이메일 미제공 (예외 상황)

```
Google OAuth 완료 또는 중단
      │
      ▼
email 값 없음 또는 null
      │
      ▼
안내 메시지 노출:
"구글 계정에서 이메일 정보를 가져오지 못했습니다.
이메일 주소를 직접 입력해주세요."
      │
      ▼
이메일 직접 입력 + OTP 인증 (기존 방식)
      │
      ▼
구독 완료
```

**처리 전략**: 기존 OTP 방식으로 graceful fallback, 사용자 경험 단절 없음

---

### 시나리오 C — ❌ 사용자가 OAuth 취소

```
Google 동의 화면에서 [취소] 클릭
      │
      ▼
error=access_denied 파라미터 수신
      │
      ▼
안내 메시지:
"구글 로그인을 취소하셨습니다.
이메일을 직접 입력하시거나 다시 시도해주세요."
      │
      ▼
구독 신청 폼으로 복귀 (이메일 직접 입력)
```

**처리 전략**: 원래 구독 신청 폼으로 자연스럽게 복귀, 에러 느낌 최소화

---

## 3. 세부 정책 수립

### 3.1 이메일 수집 방법 우선순위

```
1순위: Google OAuth → 이메일 자동 수집 (사용자 입력 불필요)
2순위: 이메일 직접 입력 + OTP 인증 (폴백)
```

### 3.2 이메일 중복 처리 정책

| 상황 | 처리 방식 |
|------|----------|
| 이미 구독 중인 이메일로 Google 로그인 | "이미 구독 중입니다" 안내 + 구독 관리 링크 |
| 구독 취소된 이메일로 재구독 | 재구독 처리 (기존 데이터 유지, status 복원) |
| 바운스(반송) 이력 있는 이메일 | 안내 메시지 후 다른 이메일 입력 유도 |

### 3.3 Google 계정 연동 범위 제한 정책

> [!WARNING]
> Signal은 **이메일 주소만** 수집 목적으로 Google OAuth를 사용합니다.

```
수집하는 정보:
  ✅ 이메일 주소 (email)
  ✅ Google 계정 식별자 (sub) — 재구독 식별 목적
  ⬜ 이름 (name) — 사용하지 않음 (웰컴 이메일 개인화 목적으로만 사용 시 선택)
  ❌ 프로필 사진 — 수집하지 않음
  ❌ 연락처, 캘린더, 드라이브 등 — 절대 요청하지 않음
```

---

## 4. 개인정보보호법 준수 동의 정책

### 4.1 한국 개인정보보호법(PIPA) 필수 요건

Signal 구독 신청 시 다음을 반드시 명시해야 합니다:

| 항목 | 내용 |
|------|------|
| **수집 항목** | 이메일 주소 (필수), Google 계정 식별자 (선택) |
| **수집·이용 목적** | AI 뉴스 알림 이메일 발송, 구독 관리 |
| **보유 기간** | 구독 취소 시까지, 취소 후 6개월 후 파기 |
| **제3자 제공** | 제공하지 않음 |
| **처리 위탁** | Gmail SMTP (Google) — 이메일 발송 목적 |

### 4.2 동의 UI 설계

```
┌─────────────────────────────────────────────────┐
│  [Google로 Signal 구독 신청]                     │
│                                                  │
│  구글 계정의 이메일 주소로 구독을 신청합니다.    │
│                                                  │
│  ☑ [필수] 개인정보 수집·이용 동의               │
│     · 수집 항목: 이메일 주소                    │
│     · 이용 목적: Signal 뉴스레터 발송           │
│     · 보유 기간: 구독 취소 후 6개월             │
│     [전문 보기 ▼]                               │
│                                                  │
│  ☐ [선택] 마케팅 정보 수신 동의                  │
│     Vibe Studio의 새로운 서비스 소식 수신        │
│                                                  │
│  [Google로 계속하기]   [이메일로 직접 입력]      │
└─────────────────────────────────────────────────┘
```

### 4.3 동의 기록 저장 정책

```sql
-- 동의 기록은 법적 증거로 활용될 수 있으므로 반드시 저장
consent_at         TIMESTAMP  -- 동의 일시
consent_ip         VARCHAR(45) -- 동의 시 IP (IPv6 포함)
consent_ua         TEXT        -- 동의 시 User-Agent
marketing_consent  TINYINT(1)  -- 마케팅 수신 동의 여부
```

---

## 5. 구독 신청 UX Flow 설계

### 5.1 전체 플로우 (Google OAuth 기준)

```
[Signal 구독 신청 버튼 클릭]
        │
        ▼
┌─────────────────────┐
│  동의 체크박스 화면  │  ← 필수 동의 미완료 시 버튼 비활성
│  ☑ 개인정보 필수   │
│  ☐ 마케팅 선택     │
└─────────────────────┘
        │ 필수 동의 ☑
        ▼
[Google로 계속하기] 클릭
        │
        ▼
Google 인증 서버 (팝업 또는 리다이렉트)
        │
        ├─── ✅ 이메일 수신 ────────────────────────────────┐
        │                                                   │
        │                               관심 분야 선택 (선택사항)
        │                                        │
        │                               구독 완료 처리
        │                                        │
        │                               웰컴 이메일 발송
        │
        ├─── ⚠️ 이메일 미수신 ──── 이메일 직접 입력 → OTP 인증 → 완료
        │
        └─── ❌ 사용자 취소 ─────── 구독 폼으로 복귀 (안내 메시지)
```

### 5.2 이메일 직접 입력 폴백 UI

```
┌─────────────────────────────────────────────────┐
│  ⚠️ 구글 계정에서 이메일을 가져오지 못했습니다. │
│                                                  │
│  이메일 주소를 직접 입력해주세요.               │
│  ┌──────────────────────────────────────────┐   │
│  │ your@email.com                           │   │
│  └──────────────────────────────────────────┘   │
│                                                  │
│  [인증 메일 받기]                                │
│                                                  │
│  또는  [Google로 다시 시도]                      │
└─────────────────────────────────────────────────┘
```

---

## 6. 기술 구현 계획 (PHP)

### 6.1 필요 라이브러리

```bash
# Google API PHP Client 설치 (Composer 사용)
composer require google/apiclient:^2.0

# 또는 서버에 직접 설치
# /opt/bitnami/apache2/htdocs/vendor/
```

> [!TIP]
> 기존 서버에 Composer가 없다면 Google API PHP Client의 단일 파일 버전을 사용하거나,  
> `google-api-php-client-services`를 수동 설치할 수 있습니다.

### 6.2 Google Cloud Console 설정

```
필수 설정:
  1. Google Cloud Console → APIs & Services → Credentials
  2. OAuth 2.0 클라이언트 ID 생성 (웹 애플리케이션)
  3. 승인된 리디렉션 URI 등록:
     https://vibestudio.prisincera.com/signal/google_callback.php
  4. OAuth 동의 화면 설정:
     - 앱 이름: Signal by Vibe Studio
     - 사용자 지원 이메일: signal@prisincera.com
     - 승인된 도메인: prisincera.com
     - 범위(Scopes): email, openid
```

### 6.3 신규 파일 구조

```
htdocs/
│
├─ signal/                         ← 기존 콘텐츠 허브
│   └─ YYYY-MM-DD.html
│
├─ google_oauth.php                ← ★ 신규 — OAuth 시작점 (Google로 리다이렉트)
├─ google_callback.php             ← ★ 신규 — OAuth 콜백 처리
├─ signal_subscribe.php            ← ★ 신규 — 구독 처리 통합 API
│
└─ config.php                      ← 기존 — 아래 키 추가 필요
    GOOGLE_CLIENT_ID
    GOOGLE_CLIENT_SECRET
    GOOGLE_REDIRECT_URI
```

### 6.4 `config.php` 추가 설정

```php
// Google OAuth
define('GOOGLE_CLIENT_ID',     'your-client-id.apps.googleusercontent.com');
define('GOOGLE_CLIENT_SECRET', 'your-client-secret');
define('GOOGLE_REDIRECT_URI',  'https://vibestudio.prisincera.com/google_callback.php');
```

### 6.5 `google_oauth.php` — OAuth 시작

```php
<?php
require_once 'config.php';
session_start();

// CSRF 방지용 state 토큰 생성
$state = bin2hex(random_bytes(16));
$_SESSION['oauth_state'] = $state;

// 동의 정보 세션 저장 (콜백에서 검증)
$_SESSION['consent_agreed'] = isset($_POST['consent']) ? 1 : 0;
$_SESSION['marketing_agreed'] = isset($_POST['marketing']) ? 1 : 0;

if (!$_SESSION['consent_agreed']) {
    header('Location: /index.html#signal-subscribe?error=consent_required');
    exit;
}

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
```

### 6.6 `google_callback.php` — 콜백 처리 (핵심)

```php
<?php
require_once 'config.php';
session_start();

// ① CSRF 검증
if (!isset($_GET['state']) || $_GET['state'] !== $_SESSION['oauth_state']) {
    die(json_encode(['error' => 'invalid_state']));
}
unset($_SESSION['oauth_state']);

// ② 사용자 취소 처리 (시나리오 C)
if (isset($_GET['error']) && $_GET['error'] === 'access_denied') {
    header('Location: /index.html#signal-subscribe?error=cancelled');
    exit;
}

// ③ Authorization Code → Access Token 교환
$tokenResponse = file_get_contents('https://oauth2.googleapis.com/token', false,
    stream_context_create(['http' => [
        'method'  => 'POST',
        'header'  => 'Content-Type: application/x-www-form-urlencoded',
        'content' => http_build_query([
            'code'          => $_GET['code'],
            'client_id'     => GOOGLE_CLIENT_ID,
            'client_secret' => GOOGLE_CLIENT_SECRET,
            'redirect_uri'  => GOOGLE_REDIRECT_URI,
            'grant_type'    => 'authorization_code',
        ]),
    ]])
);
$token = json_decode($tokenResponse, true);

if (empty($token['access_token'])) {
    // API 오류 → 폴백 (시나리오 B)
    header('Location: /index.html#signal-subscribe?error=token_failed');
    exit;
}

// ④ 사용자 정보 조회
$userInfo = json_decode(file_get_contents(
    'https://www.googleapis.com/oauth2/v3/userinfo',
    false,
    stream_context_create(['http' => [
        'header' => 'Authorization: Bearer ' . $token['access_token'],
    ]])
), true);

$email   = $userInfo['email'] ?? null;
$googleId = $userInfo['sub']  ?? null; // Google 계정 고유 ID

// ⑤ 이메일 존재 여부 분기
if (empty($email)) {
    // 시나리오 B: 이메일 미수신 → 폴백
    header('Location: /index.html#signal-subscribe?error=no_email&source=google');
    exit;
}

// ⑥ 이메일 형식 검증
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    header('Location: /index.html#signal-subscribe?error=invalid_email');
    exit;
}

// ⑦ 구독 처리 (signal_subscribe.php로 위임)
$_SESSION['google_email']      = $email;
$_SESSION['google_id']         = $googleId;
$_SESSION['consent_ip']        = $_SERVER['REMOTE_ADDR'];
$_SESSION['consent_ua']        = $_SERVER['HTTP_USER_AGENT'];
$_SESSION['marketing_consent'] = $_SESSION['marketing_agreed'] ?? 0;

header('Location: /signal_subscribe.php?source=google');
exit;
```

### 6.7 `signal_subscribe.php` — 구독 처리 통합

```php
<?php
require_once 'config.php';
session_start();

$source = $_GET['source'] ?? 'manual'; // 'google' or 'manual'

if ($source === 'google') {
    // Google OAuth 경유
    $email      = $_SESSION['google_email'] ?? null;
    $googleId   = $_SESSION['google_id'] ?? null;
    $consentIp  = $_SESSION['consent_ip'] ?? null;
    $consentUa  = $_SESSION['consent_ua'] ?? null;
    $marketing  = $_SESSION['marketing_consent'] ?? 0;

    if (!$email) {
        header('Location: /index.html#signal-subscribe?error=session_expired');
        exit;
    }
} else {
    // 기존 이메일 직접 입력 방식 (OTP 검증 후 호출)
    $email     = $_POST['email'] ?? null;
    $googleId  = null;
    $consentIp = $_SERVER['REMOTE_ADDR'];
    $consentUa = $_SERVER['HTTP_USER_AGENT'];
    $marketing = (int)($_POST['marketing'] ?? 0);
}

// 중복 구독 확인
$pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME, DB_USER, DB_PASS);
$stmt = $pdo->prepare("SELECT id, status FROM digest_subs WHERE email = ?");
$stmt->execute([$email]);
$existing = $stmt->fetch(PDO::FETCH_ASSOC);

if ($existing) {
    if ($existing['status'] === 'active') {
        // 이미 구독 중
        header('Location: /index.html#signal-subscribe?error=already_subscribed');
        exit;
    } else {
        // 재구독 처리
        $stmt = $pdo->prepare("
            UPDATE digest_subs
            SET status = 'active',
                subscribed_at = NOW(),
                google_id = ?,
                consent_at = NOW(),
                consent_ip = ?,
                marketing_consent = ?
            WHERE email = ?
        ");
        $stmt->execute([$googleId, $consentIp, $marketing, $email]);
    }
} else {
    // 신규 구독
    $token        = bin2hex(random_bytes(32)); // 구독 취소용 토큰
    $referralCode = strtoupper(substr(bin2hex(random_bytes(5)), 0, 8));

    $stmt = $pdo->prepare("
        INSERT INTO digest_subs
        (email, token, referral_code, google_id,
         consent_at, consent_ip, consent_ua, marketing_consent, source)
        VALUES (?, ?, ?, ?, NOW(), ?, ?, ?, ?)
    ");
    $stmt->execute([
        $email, $token, $referralCode, $googleId,
        $consentIp, $consentUa, $marketing, $source
    ]);
}

// 세션 정리
unset($_SESSION['google_email'], $_SESSION['google_id'],
      $_SESSION['consent_ip'], $_SESSION['consent_ua'],
      $_SESSION['marketing_consent'], $_SESSION['marketing_agreed']);

// 웰컴 이메일 발송 (별도 함수)
sendWelcomeEmail($email);

// 완료 페이지
header('Location: /index.html#signal-subscribe?success=1&source=' . $source);
exit;
```

---

## 7. 데이터베이스 스키마 확장

### 7.1 `digest_subs` 테이블 — 컬럼 추가

```sql
ALTER TABLE digest_subs
  ADD COLUMN google_id        VARCHAR(100) DEFAULT NULL
              COMMENT 'Google 계정 고유 식별자(sub)',
  ADD COLUMN source           ENUM('google','manual') DEFAULT 'manual'
              COMMENT '구독 신청 경로',
  ADD COLUMN consent_at       DATETIME DEFAULT NULL
              COMMENT '개인정보 동의 일시',
  ADD COLUMN consent_ip       VARCHAR(45) DEFAULT NULL
              COMMENT '동의 시 IP 주소',
  ADD COLUMN consent_ua       TEXT DEFAULT NULL
              COMMENT '동의 시 User-Agent',
  ADD COLUMN marketing_consent TINYINT(1) DEFAULT 0
              COMMENT '마케팅 수신 동의 여부',
  ADD UNIQUE INDEX idx_google_id (google_id);
```

### 7.2 스키마 변경 후 전체 `digest_subs` 구조

```sql
CREATE TABLE digest_subs (
    id                INT AUTO_INCREMENT PRIMARY KEY,
    email             VARCHAR(255)  NOT NULL UNIQUE,
    token             VARCHAR(64)   NOT NULL UNIQUE,   -- 구독 취소 토큰
    referral_code     VARCHAR(20)   NOT NULL UNIQUE,   -- 추천 코드
    referred_by       VARCHAR(20)   DEFAULT NULL,      -- 추천인 코드
    referral_count    INT           DEFAULT 0,
    categories        VARCHAR(200)  DEFAULT 'all',
    frequency         ENUM('daily','weekly') DEFAULT 'daily',
    status            ENUM('active','unsubscribed','bounced') DEFAULT 'active',
    source            ENUM('google','manual') DEFAULT 'manual',     -- ★ 신규
    google_id         VARCHAR(100)  DEFAULT NULL,                    -- ★ 신규
    consent_at        DATETIME      DEFAULT NULL,                    -- ★ 신규
    consent_ip        VARCHAR(45)   DEFAULT NULL,                    -- ★ 신규
    consent_ua        TEXT          DEFAULT NULL,                    -- ★ 신규
    marketing_consent TINYINT(1)    DEFAULT 0,                       -- ★ 신규
    subscribed_at     TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    unsubscribed_at   DATETIME      DEFAULT NULL,
    last_sent_at      DATETIME      DEFAULT NULL,
    last_open_at      DATETIME      DEFAULT NULL,
    nps_score         TINYINT       DEFAULT NULL,
    nps_updated_at    DATETIME      DEFAULT NULL,
    INDEX idx_status    (status),
    INDEX idx_referral  (referral_code),
    UNIQUE INDEX idx_google_id (google_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

---

## 8. 보안 체크리스트

### 8.1 OAuth 보안

| 항목 | 구현 방법 | 상태 |
|------|----------|------|
| **CSRF 방지** | `state` 파라미터 사용 (랜덤 토큰) | 📋 필수 |
| **HTTPS 전용** | OAuth URI 반드시 `https://` | 📋 필수 |
| **Access Token 저장 금지** | 토큰은 메모리에서만 사용, DB 저장 금지 | 📋 필수 |
| **Redirect URI 검증** | Google Console에 허용 URI만 등록 | 📋 필수 |
| **Client Secret 보호** | `config.php` → `.htaccess`로 접근 차단 | 📋 필수 |

### 8.2 개인정보 보안

| 항목 | 구현 방법 | 상태 |
|------|----------|------|
| **이메일 암호화 저장** | AES-256 또는 해시 (검색 필요 시 별도 처리) | 📋 검토 |
| **Google ID 저장 최소화** | 재구독 식별 목적만, 불필요 시 삭제 | 📋 필수 |
| **동의 기록 보관** | 법적 요건: 구독 취소 후 5년 보관 권장 | 📋 필수 |
| **IP 로깅** | PIPA 준수, 접근로그 별도 관리 | 📋 필수 |
| **정보 최소 수집** | email + google_id만 수집, 이름·사진 미저장 | ✅ 기본 원칙 |

### 8.3 세션 보안

```php
// PHP 세션 설정 (config.php 또는 세션 시작 전)
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure', 1);   // HTTPS 전용
ini_set('session.use_strict_mode', 1);
ini_set('session.cookie_samesite', 'Lax');
session_regenerate_id(true);           // OAuth 완료 후 세션 ID 재생성
```

---

## 9. 개발 로드맵 (Phase)

### Phase G-1 — Google Cloud 설정 (1일)
- [ ] Google Cloud Console 프로젝트 생성
- [ ] OAuth 2.0 클라이언트 ID 발급
- [ ] 동의 화면 구성 (앱 이름, 아이콘, 정책 URL)
- [ ] `config.php`에 `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET` 추가
- [ ] `.htaccess`로 `config.php` 웹 접근 차단 확인

### Phase G-2 — PHP 구현 (3~4일)
- [ ] `google_oauth.php` 구현 (OAuth 시작 + CSRF state 발급)
- [ ] `google_callback.php` 구현 (콜백 처리 + 3가지 시나리오 분기)
- [ ] `signal_subscribe.php` 구현 (Google / 수동 통합 처리)
- [ ] DB `digest_subs` 테이블 `ALTER` 실행
- [ ] 기존 `digest_api.php` OTP 방식과 통합

### Phase G-3 — 프론트엔드 (2일)
- [ ] `index.html` 구독 섹션 UI 업데이트
  - [ ] [Google로 Signal 구독] 버튼 추가
  - [ ] 개인정보 동의 체크박스 (필수/선택)
  - [ ] 동의 전문 표시 (토글/모달)
  - [ ] 폴백 이메일 입력 폼 (에러 파라미터 기반 조건부 표시)
- [ ] 완료/오류 상태 메시지 UI

### Phase G-4 — 테스트 및 배포 (2일)
- [ ] 시나리오 A (정상 제공) 테스트
- [ ] 시나리오 B (미제공 → 폴백) 테스트
- [ ] 시나리오 C (사용자 취소) 테스트
- [ ] 중복 구독 방지 테스트
- [ ] 재구독 처리 테스트
- [ ] 동의 기록 DB 저장 확인
- [ ] CSRF 방지 동작 확인
- [ ] 서버 배포 및 Google Console 프로덕션 URI 등록

---

## 부록 — 개인정보처리방침 필수 고지 문구 (예시)

> 서비스 내 개인정보처리방침 페이지 또는 구독 동의 전문에 반드시 포함할 내용

```
[개인정보 수집·이용 동의]

서비스명: Signal by Vibe Studio
운영자: PriSincera (matthew.shim@prisincera.com)

1. 수집 항목
   - 필수: 이메일 주소
   - 자동 수집 (Google 로그인 시): Google 계정 식별자

2. 수집·이용 목적
   - Signal AI 뉴스 알림 이메일 발송
   - 구독 상태 관리 및 수신 거부 처리

3. 보유 및 이용 기간
   - 구독 취소 시까지
   - 취소 후 관계 법령에 따라 최대 5년 보관 후 파기

4. 동의 거부 권리
   귀하는 동의를 거부할 권리가 있으나, 거부 시 Signal 구독 서비스를 이용하실 수 없습니다.

[선택] 마케팅 정보 수신 동의
   - Vibe Studio 신규 서비스 출시, 이벤트 안내
   - 보유 기간: 동의 철회 시까지
   - 거부 시에도 Signal 구독에는 영향 없음
```

---

*작성: Vibe Studio Dev Team · 2026-03-20*  
*연계 문서: `AI_DIGEST_PLAN.md` (Signal 구축 기획서) · `AI_DIGEST_STRATEGY.md` (전략 기획안)*

---

## ✅ 실제 구현 완료 (2026-03-24)

> [!NOTE]
> 위 기획 내용과 실제 구현 방식이 일부 다릅니다. 기존 Fan 사전예약 인프라를 재사용하여 더 빠르게 구현되었습니다.

### 구현 방식 비교

| 항목 | 기획안 | 실제 구현 |
|------|--------|----------|
| OAuth 파일 | `google_oauth.php`, `google_callback.php` 신규 생성 | 기존 `google_fan_oauth.php` 재사용 |
| 인증 방식 | 리다이렉트 방식 | **팝업 + postMessage** 방식 |
| 구독 API | `signal_subscribe.php` 신규 | `mail_auth.php?action=signal_subscribe` 액션 추가 |
| DB 테이블 | `digest_subs` | 기존 `pre_registrations` (`content_subscribe=1` 컬럼 활용) |
| 세션 관리 | `google_email` 세션 | `fan_google_verified`, `fan_google_email` 세션 |

### 실제 플로우

```
[Signal 구독하기] 클릭
    ↓
구독 모달 오픈
    ↓ — 자동 세션 체크 (fan_google_session_check)
    ├── 세션 유효 → 즉시 signal_subscribe → 완료 화면 ✅
    └── 세션 없음 → Google 로그인 버튼 표시
                        ↓
                   google_fan_oauth.php 팝업
                        ↓
                   postMessage 수신
                        ↓
                   fan_google_session_set (세션 저장)
                        ↓
                   signal_subscribe (content=1만 갱신)
                        ↓
                   완료 화면 ✅
```

### 전역 세션 유지 정책

- `fan_google_*` 세션은 **브라우저 종료 또는 명시적 로그아웃까지** 유지
- `action=register` (메인 사전예약) 완료 시에도 Google 세션 **유지** (OTP 키만 삭제)
- `action=fan_google_session_clear` 호출 시에만 Google 세션 삭제
- 어디서든 Google 로그인하면 Signal 페이지, 메인 패널 모두에서 세션 공유

*구현 완료: 2026-03-24 — FAN_REGISTRATION_SPEC.md, CHANGELOG.md 함께 업데이트*
