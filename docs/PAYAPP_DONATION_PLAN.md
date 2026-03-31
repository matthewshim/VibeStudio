# PayApp PG 연동 — 후원하기 페이지 추진계획서

> **대상 서비스**: [Vibe Studio](https://vibestudio.prisincera.com)  
> **PG사**: 페이앱 (payapp.kr)  
> **작성일**: 2026-03-25  
> **작성자**: AI 에이전트 (Antigravity)

---

## 📋 목차

1. [추진 목적 및 배경](#1-추진-목적-및-배경)
2. [페이앱 서비스 개요](#2-페이앱-서비스-개요)
3. [사전 준비 사항](#3-사전-준비-사항)
4. [기술 아키텍처](#4-기술-아키텍처)
5. [프론트엔드 설계](#5-프론트엔드-설계)
6. [백엔드 설계](#6-백엔드-설계)
7. [DB 설계](#7-db-설계)
8. [파일 구조](#8-파일-구조)
9. [단계별 구현 계획](#9-단계별-구현-계획)
10. [수수료 및 정산 정보](#10-수수료-및-정산-정보)
11. [리스크 및 제약사항](#11-리스크-및-제약사항)
12. [보안 고려사항](#12-보안-고려사항)

---

## 1. 추진 목적 및 배경

Vibe Studio는 기획자가 AI와의 바이브 코딩으로 직접 제작한 무료 웹 생산성 툴킷입니다. 현재까지 Phase 8까지 개발이 진행되어 Signal 구독 시스템, Google OAuth, 어드민 패널 등 견고한 인프라가 구축된 상태입니다.

**후원 페이지 신설 목적**:
- 서비스 운영 비용(AWS Lightsail, 도메인, API 비용) 조달
- 팬 커뮤니티와의 관계 강화 (후원자 감사 시스템)
- 수익화 첫 단계 — Vibe Studio의 지속적 성장 기반 마련

---

## 2. 페이앱 서비스 개요

### 2-1. 페이앱이란?

| 항목 | 내용 |
|------|------|
| 서비스명 | 페이앱 (PayApp) |
| 운영사 | (주)유디아이디 |
| 고객센터 | 1800-3772 |
| 특징 | 사업자 없이도 개인이 즉시 결제 수령 가능 |
| 지원 결제 수단 | 신용/체크카드, 카카오페이, 네이버페이, 애플페이, 스마일페이, 휴대폰 소액결제, 계좌이체, 가상계좌 |

### 2-2. 연동 방식 (JS API)

페이앱은 두 가지 연동 방식을 제공합니다:

| 방식 | 특징 | 우리 선택 |
|------|------|-----------|
| **JS API (lite.payapp.kr)** | 스크립트 1줄 삽입, 결제창 팝업 방식, 프론트엔드 중심 | ✅ **채택** |
| REST API | 서버-서버 호출, 복잡한 서버 구현 필요 | ❌ 복잡도 높음 |

**JS API 선택 이유**: 기존 Vibe Studio 구조(PHP + Vanilla JS)와 가장 잘 맞으며 구현이 빠르고 안정적입니다.

### 2-3. JS API 동작 흐름

```
[후원자 클릭]
    ↓
[donating.html - 금액/이름 선택]
    ↓
[PayApp JS API 호출 (lite.payapp.kr)]
    ↓
[PayApp 결제 팝업창]
    ↓
[결제 완료]
    ↓
[feedbackurl → donate_webhook.php (서버 수신)]
    ↓
[DB 저장 + 감사 메일 발송]
    ↓
[redirecturl → donate_complete.html]
```

---

## 3. 사전 준비 사항

### 3-1. 페이앱 가입 및 설정 (필수 선행)

> [!IMPORTANT]
> 개발 시작 전 반드시 페이앱 가입 및 계정 설정이 완료되어야 합니다.

**Step 1: 회원가입**
- URL: https://seller.payapp.kr/a/seller_regist
- 사업자 / 비사업자(개인) 모두 가입 가능
- 비사업자: **페이앱 라이트** 플랜 적용 (월 200만원, 건당 50만원 한도)

**Step 2: 계약 서류 제출 (정산을 위해 필수)**
- 사업자의 경우: 사업자등록증, 신분증, 통장 사본
- 개인(비사업자): 신분증, 통장 사본

**Step 3: 연동 Key 확인**
- 관리자 로그인 → 설정 탭 → **연동 Key** / **연동 Value** 복사
- ⚠️ 이 값은 외부에 절대 노출 금지 → `config.php`에 안전하게 보관

**Step 4: 결제 테스트**
- 페이앱 제공 테스트 페이지: https://www.payapp.kr/popup_pay_new/popup/sample_step01.html
- 실제 결제 테스트 후 연동 확인

### 3-2. config.php에 추가할 상수

```php
// ── PayApp PG ────────────────────────────────────────────
define('PAYAPP_USER_ID',    'your_payapp_id');       // 페이앱 로그인 아이디
define('PAYAPP_LINK_KEY',   'your_link_key');         // 연동 Key
define('PAYAPP_LINK_VALUE', 'your_link_value');       // 연동 Value
define('PAYAPP_SHOP_NAME',  'Vibe Studio');           // 결제창에 표시될 상점명
define('PAYAPP_FEEDBACK_URL', 'https://vibestudio.prisincera.com/donate_webhook.php');
define('PAYAPP_REDIRECT_URL', 'https://vibestudio.prisincera.com/donate_complete.html');
```

---

## 4. 기술 아키텍처

```
┌─────────────────────────────────────────────────────┐
│                   브라우저 (후원자)                    │
│                                                     │
│  donating.html                                      │
│  ├── 금액 선택 UI (1,000 / 3,000 / 5,000 / 직접입력) │
│  ├── 이름/메시지 입력 (선택)                           │
│  └── 후원하기 버튼 → PayApp JS API 호출               │
│                                                     │
│  lite.payapp.kr/public/api/v2/payapp-lite.js (CDN)  │
│  └── 결제 팝업창 (페이앱 호스팅)                      │
└─────────────────────────────────────────────────────┘
         ↓ 결제 완료 후 feedbackurl 호출 (POST)
┌─────────────────────────────────────────────────────┐
│              서버 (AWS Lightsail / PHP)               │
│                                                     │
│  donate_webhook.php                                  │
│  ├── PayApp 서버에서 결제 결과 POST 수신               │
│  ├── 결제 금액/상태 검증                              │
│  ├── donations 테이블 INSERT                         │
│  └── PHPMailer → 후원 감사 메일 발송                  │
│                                                     │
│  donate_webhook.php (관리자 API)                     │
│  └── admin.php 에서 후원 내역 조회                    │
└─────────────────────────────────────────────────────┘
         ↓ redirecturl로 브라우저 이동
┌─────────────────────────────────────────────────────┐
│  donate_complete.html — 결제 완료 감사 화면           │
└─────────────────────────────────────────────────────┘
```

---

## 5. 프론트엔드 설계

### 5-1. 후원 페이지 (`donating.html`)

기존 `about.html`, `contact.html`과 동일한 **글라스모피즘 디자인 시스템** 적용:
- `layout.js` 공통 헤더/푸터
- Pretendard Variable 폰트
- 다크/라이트 테마 토글
- 마우스 스포트라이트 효과
- `.fade-up` IntersectionObserver 애니메이션

**UI 구성 섹션:**

```
┌─────── Hero 섹션 ──────────────────────┐
│  ⚡ SUPPORT                            │
│  Vibe Studio를 응원해주세요            │
│  (서브카피: 커피 한 잔으로 개발을 이어가요) │
└────────────────────────────────────────┘

┌─────── 후원 금액 선택 ─────────────────┐
│  ☕ 커피 한 잔   💙 스몰 서포트         │
│    1,000원          3,000원            │
│                                        │
│  🚀 빅 서포트   ✍️ 직접 입력           │
│    5,000원          [    원]           │
└────────────────────────────────────────┘

┌─────── 후원자 정보 (선택) ─────────────┐
│  이름 또는 닉네임: [           ]       │
│  응원 메시지:     [           ]       │
└────────────────────────────────────────┘

┌─────── 후원하기 버튼 ──────────────────┐
│       [⚡ 후원하기 → PayApp]           │
└────────────────────────────────────────┘

┌─────── 후원자 명단 (누적 표시) ────────┐
│  ☕ 닉네임A · "응원합니다!"  1,000원   │
│  💙 닉네임B                 3,000원   │
│  ...                                  │
└────────────────────────────────────────┘
```

### 5-2. PayApp JS API 호출 코드

```html
<!-- 페이앱 JS SDK 삽입 -->
<script src="//lite.payapp.kr/public/api/v2/payapp-lite.js"></script>

<script>
function requestDonate() {
  const price = document.getElementById('selected-price').value;
  const donorName = document.getElementById('donor-name').value || '익명';
  const message = document.getElementById('donor-message').value || '';

  if (!price || price < 100) {
    alert('후원 금액을 선택해주세요.');
    return;
  }

  // PayApp JS API 결제 요청
  payapp.request({
    userid    : 'PAYAPP_USER_ID',        // 페이앱 아이디
    shopname  : 'Vibe Studio',           // 상점명
    goodname  : 'Vibe Studio 후원',      // 상품명
    price     : price,                   // 결제 금액
    feedbackurl: 'https://vibestudio.prisincera.com/donate_webhook.php',
    redirecturl: 'https://vibestudio.prisincera.com/donate_complete.html',
    openpaytype: 'card,kakaopay,naverpay,applepay,phone',  // 결제 수단
    var1      : donorName,               // 후원자 이름 (임의 변수)
    var2      : message,                 // 응원 메시지 (임의 변수)
    memo      : `${donorName}님의 후원`,
  });
}
</script>
```

### 5-3. 주요 PayApp API 파라미터

| 파라미터 | 필수 | 설명 |
|---------|------|------|
| `userid` | ✅ | 페이앱 로그인 아이디 |
| `shopname` | ✅ | 결제창 상단에 표시될 상점명 |
| `goodname` | ✅ | 상품명 (예: "Vibe Studio 후원") |
| `price` | ✅ | 결제 금액 (정수, 원 단위) |
| `feedbackurl` | ✅ | 결제 완료 시 서버 알림 URL |
| `redirecturl` | 권장 | 결제 후 이동할 완료 페이지 URL |
| `openpaytype` | 선택 | 결제 수단 콤마 구분 (card, kakaopay, naverpay, applepay, phone, rbank 등) |
| `var1` | 선택 | 임의 변수 1 (후원자 이름 저장용) |
| `var2` | 선택 | 임의 변수 2 (응원 메시지 저장용) |
| `memo` | 선택 | 관리자에게 표시될 메모 |

### 5-4. 완료 페이지 (`donate_complete.html`)

- 결제 완료 감사 메시지
- URL 파라미터에서 결제 정보 표시 (goodname, price 등)
- 홈으로 돌아가기 버튼
- SNS 공유 버튼 (공유로 Vibe Studio 홍보 유도)

---

## 6. 백엔드 설계

### 6-1. `donate_webhook.php` — 결제 결과 수신

> [!IMPORTANT]
> feedbackurl은 **외부(페이앱 서버)에서 POST 요청**을 받습니다. Origin 검증은 IP 기반으로 수행해야 합니다.

```php
<?php
require_once 'config.php';

// 페이앱 서버 IP 화이트리스트 (페이앱 문서 참고 후 확정)
$allowed_ips = ['211.218.26.100', '211.218.26.101']; // 예시 — 실제 IP 확인 필요

$client_ip = $_SERVER['REMOTE_ADDR'];
// IP 검증 (선택 — 페이앱 공식 IP 확인 후 적용)

// POST 데이터 수신
$pay_state  = $_POST['pay_state'] ?? '';   // 결제 상태 (1: 성공)
$order_num  = $_POST['order_num'] ?? '';   // 페이앱 주문번호
$goodname   = $_POST['goodname'] ?? '';
$price      = intval($_POST['price'] ?? 0);
$var1       = $_POST['var1'] ?? '익명';     // 후원자 이름
$var2       = $_POST['var2'] ?? '';         // 응원 메시지
$pay_type   = $_POST['pay_type'] ?? '';    // 결제 수단

if ($pay_state !== '1') {
    // 결제 실패 또는 취소 — 로그 기록 후 종료
    exit;
}

// DB에 후원 내역 저장
$pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME, DB_USER, DB_PASS);
$stmt = $pdo->prepare("
    INSERT INTO donations (order_num, donor_name, message, amount, pay_type, status, created_at)
    VALUES (?, ?, ?, ?, ?, 'completed', NOW())
");
$stmt->execute([$order_num, $var1, $var2, $price, $pay_type]);

// 감사 메일 발송 (후원자 이메일이 없으므로 관리자에게만)
// PHPMailer로 관리자 알림 메일 발송

echo "OK"; // 페이앱 서버에 수신 확인 응답
```

### 6-2. `admin.php` 확장 — 후원 내역 탭 추가

기존 어드민 패널에 **💰 후원 내역** 탭 추가:

| 기능 | 내용 |
|------|------|
| 요약 카드 | 총 후원금액, 후원 건수, 평균 후원액, 최근 7일 |
| 후원 목록 | 날짜, 후원자명, 금액, 응원 메시지, 결제 수단 |
| 기간 필터 | 오늘 / 7일 / 30일 / 전체 |
| CSV 내보내기 | 정산 검증용 |

---

## 7. DB 설계

### 7-1. `donations` 테이블 (신규 생성)

```sql
CREATE TABLE donations (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    order_num   VARCHAR(100) NOT NULL UNIQUE,  -- 페이앱 주문번호
    donor_name  VARCHAR(100) DEFAULT '익명',   -- 후원자 이름/닉네임
    message     TEXT,                           -- 응원 메시지
    amount      INT NOT NULL,                   -- 후원 금액 (원)
    pay_type    VARCHAR(30),                    -- 결제 수단 (card/kakaopay 등)
    status      VARCHAR(20) DEFAULT 'pending', -- pending / completed / refunded
    is_public   TINYINT(1) DEFAULT 1,          -- 후원자 명단 공개 여부
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_created (created_at),
    INDEX idx_status  (status)
);
```

### 7-2. 후원자 명단 공개 정책

- `is_public = 1`: 후원자 명단에 이름/금액 표시
- `is_public = 0`: 익명 처리 (기본값은 공개, 사용자 선택)
- 어드민에서 개별 공개/비공개 토글 가능

---

## 8. 파일 구조

```
/opt/bitnami/apache2/htdocs/
│
├── donating.html           ← [신규] 후원하기 메인 페이지
├── donate_complete.html    ← [신규] 결제 완료 감사 페이지
├── donate_webhook.php      ← [신규] PayApp 결제 결과 수신 (feedbackurl)
│
├── config.php              ← [수정] PayApp 키/상수 추가
├── admin.php               ← [수정] 후원 내역 탭 추가
├── js/layout.js            ← [수정] 네비게이션에 "후원" 링크 추가
├── sitemap.xml             ← [수정] donating.html URL 추가
│
└── docs/
    └── PAYAPP_DONATE_SPEC.md ← [신규] 이 계획서 서버 보관용
```

**신규 파일 4개, 수정 파일 4개** — 총 8개 파일

---

## 9. 단계별 구현 계획

### Phase A: 준비 (D-Day, ~1시간)

| 작업 | 담당 | 비고 |
|------|------|------|
| 페이앱 회원가입 | 운영자 | https://seller.payapp.kr/a/seller_regist |
| 연동 Key/Value 수령 | 운영자 | 관리자 → 설정 탭 |
| config.php에 상수 추가 | 개발 | PAYAPP_* 상수 4개 |
| 테스트 결제 확인 | 운영자+개발 | 페이앱 샘플 페이지 |

### Phase B: 백엔드 구현 (D+1, ~2시간)

| 작업 | 파일 |
|------|------|
| `donations` 테이블 생성 | DB 마이그레이션 |
| `donate_webhook.php` 구현 | 신규 파일 |
| `admin.php` 후원 탭 추가 | 기존 파일 수정 |

### Phase C: 프론트엔드 구현 (D+1~2, ~3시간)

| 작업 | 파일 |
|------|------|
| `donating.html` 디자인 + PayApp JS 연동 | 신규 파일 |
| `donate_complete.html` 감사 페이지 | 신규 파일 |
| `layout.js` 네비게이션 후원 링크 추가 | 기존 파일 수정 |

### Phase D: 검증 및 배포 (D+2, ~1시간)

| 작업 | 방법 |
|------|------|
| 실 결제 테스트 (최소 금액) | 1,000원 카드 결제 테스트 |
| feedbackurl 수신 확인 | 서버 로그 확인 |
| DB 저장 확인 | admin 후원 탭 확인 |
| 완료 페이지 리다이렉트 확인 | 브라우저 검증 |
| sitemap.xml 업데이트 | 신규 URL 추가 |

**총 예상 소요 시간: 7~8시간 (가입 포함)**

---

## 10. 수수료 및 정산 정보

### 10-1. 수수료

| 가맹점 유형 | 카드 수수료 (VAT 별도) | 간편결제 | 휴대폰 소액결제 |
|------------|----------------------|---------|----------------|
| 비사업자(페이앱 라이트) | **4.0%** | 4.0% | 4.0% |
| 일반 사업자(신규) | 3.4% | 3.4% | 4.0% |
| 소상공인(영세/중소) | 1.9% ~ 2.85% | 동일 | 3.8% |

> [!NOTE]
> **비사업자(개인)** 기준: VAT 포함 4.4% 수수료 적용  
> 예) 1,000원 결제 시 수수료 44원 → 실수령 **956원**

### 10-2. 결제 한도 (비사업자)

| 항목 | 한도 |
|------|------|
| 1회 결제 | 최대 50만원 |
| 월 총 결제 | 최대 200만원 |

### 10-3. 정산 주기

| 정산 유형 | 주기 | 비고 |
|----------|------|------|
| 기본 정산 | **D+5 영업일** | 별도 신청 불필요 |
| 3일 정산 | D+3 영업일 | 관리자에서 신청 |
| 익일 정산 | D+1 영업일 | 관리자에서 신청 |
| 정산 시간 | 오전 11:30~12:00 | 평일 기준 |

### 10-4. 추가 비용

- PG 가입비: **무료**
- 결제 수수료 외 추가 비용 없음
- 세금계산서: 매월 1~2일 전월분 자동 발행

---

## 11. 리스크 및 제약사항

### 11-1. 주요 리스크

| 리스크 | 가능성 | 대응 방안 |
|--------|--------|-----------|
| 비사업자 월 200만원 한도 초과 | 낮음 (초기) | 추후 사업자 등록 전환 |
| feedbackurl 서버 미수신 | 중간 | 결제 재시도 로직 + 로그 모니터링 |
| 페이앱 서버 장애 | 낮음 | 안내 메시지 처리, 유지보수 모드 |
| 중복 결제 처리 | 중간 | order_num UNIQUE 제약으로 중복 INSERT 방지 |

### 11-2. 제약사항

> [!WARNING]
> 아래 제약사항을 반드시 확인하세요.

1. **feedbackurl은 HTTPS** 필수 — 이미 SSL 적용되어 있으므로 문제없음
2. **feedbackurl은 서버에서만 호출** — 브라우저가 아닌 페이앱 서버가 직접 POST
3. **redirecturl은 결제창 이후 이동** — 결제 성공/실패 무관하게 이동할 수 있으므로 feedbackurl에서 실제 성공 처리 필수
4. **결제 금액 최소값** — 1회 최소 결제 가능 금액 확인 필요 (통상 100원 이상)
5. **연동 Key 보안** — `config.php`에 보관, `.htaccess`로 직접 접근 차단 (이미 구축됨)

---

## 12. 보안 고려사항

기존 Vibe Studio 보안 아키텍처([docs/DEVELOPER.md](./DEVELOPER.md) 참고)와 일관성 유지.

### 12-1. donate_webhook.php 보안

```php
// 1. 허용된 HTTP 메서드만 수신
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

// 2. 페이앱 서버 IP 화이트리스트 (선택적 적용)
// → 페이앱 공식 문서에서 발신 IP 목록 확인 후 적용

// 3. 이중 결제 방지 (order_num UNIQUE)
// → PDO 예외 처리로 중복 POST 시 gracefully 처리

// 4. 금액 최소값 검증
if ($price < 100) exit;

// 5. 입력값 새니타이즈
$donor_name = htmlspecialchars(strip_tags($var1), ENT_QUOTES, 'UTF-8');
$message    = htmlspecialchars(strip_tags($var2), ENT_QUOTES, 'UTF-8');
```

### 12-2. config.php 보안

- `PAYAPP_LINK_KEY` / `PAYAPP_LINK_VALUE` → `config.php`에만 보관
- 이미 `.htaccess`에서 `config.php` 직접 접근 403 차단 적용됨
- GitHub/공개 저장소에 절대 커밋 금지

### 12-3. XSS 방지

- 후원자 명단 표시 시 `htmlspecialchars()` 필수 적용
- 이름, 메시지 필드 길이 제한 (name: 50자, message: 200자)

---

## ✅ 최종 체크리스트

### 계정/설정
- [ ] 페이앱 회원가입 완료
- [ ] 계약 서류 제출 (정산을 위해 필수)
- [ ] 연동 Key / Value 수령
- [ ] config.php에 PAYAPP_* 상수 추가
- [ ] 테스트 결제 성공 확인

### 개발
- [x] donations 테이블 생성 (`donate_webhook.php` 자동 생성, `donation_attempts` 동시 구현)
- [x] donating.html 구현 (금액 선택 UI + PayApp JS API)
- [x] donate_webhook.php 구현 (결제 결과 수신 + verify/poll/update API)
- [x] donate_complete.html 구현 (pay_state 분기 + 폴링 + 성공 화면 + 후원자 폼)
- [ ] admin.php 후원 탭 추가
- [x] layout.js 네비게이션 링크 추가 (후원하기)

### 검증
- [ ] 실 결제 1,000원 테스트
- [ ] feedbackurl POST 수신 확인 (서버 로그)
- [ ] DB donations 테이블 INSERT 확인
- [ ] 어드민 후원 탭에서 내역 확인
- [ ] 완료 페이지 리다이렉트 확인
- [ ] 모바일에서 결제창 정상 동작 확인

### 배포
- [ ] sitemap.xml에 donating.html 추가
- [x] deploy.ps1 또는 SCP로 서버 배포
- [x] CHANGELOG.md 업데이트

---

> [!TIP]
> 페이앱 개발자 가이드 및 매뉴얼: https://www.payapp.kr  
> 결제 테스트 샘플: https://www.payapp.kr/popup_pay_new/popup/sample_step01.html  
> 서비스 신청: https://seller.payapp.kr/a/seller_regist

---

*작성일: 2026-03-25 | 작성: AI 에이전트 (Antigravity)*  
*검토 필요 사항: 페이앱 가입 후 실제 연동 Key 수령, 발신 IP 화이트리스트 확인*

---

## 현재 구현 현황 (2026-03-31 기준)

### ✅ 구현 완료
| 항목 | 상태 | 비고 |
|------|------|------|
| `donating.html` 후원 페이지 | ✅ | 글라스모피즘 UI, 금액선택, 후원자명단 |
| `donate_complete.html` | ✅ | pay_state 분기, 폴링, 파티클, 후원자 폼 |
| `donate_webhook.php` | ✅ | 웹훅 + verify/poll/update_donor_info API, get_wh_db() 싱글톤, PAYAPP_* 상수 |
| `donations` 테이블 | ✅ | 자동 생성 (webhook 실행 시) |
| `donation_attempts` 테이블 | ✅ | 취소/실패 이력 |
| `layout.js` 후원 링크 | ✅ | 네비게이션에 /donating 추가 |
| `config.php` PAYAPP 상수 | ✅ | KEY/VALUE 등 설정 완료 |
| `PAYAPP_DONATION_PLAN.md` | ✅ | 추진계획서 작성 |
| `admin.php` Support 탭 | ✅ | KPI 요약, 후원 로그, 취소 이력, 강제취소/공개토글 *(2026-03-26 추가)* |
| `apps/admin/app.js` Support UI | ✅ | PC 테이블 + 모바일 카드, 상태 필터, toKST()/payStateLabel() 전역 함수 *(2026-03-26 추가)* |
| `history.html` Phase 9~10 | ✅ | 공개 히스토리 페이지 반영 |

### ⚠️ 대기 중 (페이앱 가입 선행 필요)
| 항목 | 상태 | 비고 |
|------|------|------|
| 페이앱 회원가입 | ⏳ 대기 | 운영자 직접 진행 필요 |
| 연동 Key/Value 수령 | ⏳ 대기 | 가입 후 콘솔 설정 탭에서 확인 |
| 실 결제 1,000원 테스트 | ⏳ 대기 | Key 수령 후 실행 |
| feedbackurl 수신 검증 | ⏳ 대기 | 테스트결제와 함께 |
| sitemap.xml 업데이트 | 📦 미구현 | donating.html URL 추가 필요 |
