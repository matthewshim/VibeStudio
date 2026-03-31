# ⚡ Signal — 서비스 구축 기획서

> **프로젝트명**: Signal by Vibe Studio  
> **연계 서비스**: Vibe Studio (vibestudio.prisincera.com)  
> **작성일**: 2026-03-20 · **최종 업데이트**: 2026-03-20 (전략 기획안 반영)  
> **스택**: PHP 8 · MariaDB · Apache · Python (Cron) · Gmail SMTP (PHPMailer)  
> **전략 연계 문서**: `AI_DIGEST_STRATEGY.md` (대고객 만족 구독 서비스 전략 기획안)

> [!IMPORTANT]
> 이 문서는 `AI_DIGEST_STRATEGY.md`의 전략 방향을 기반으로 작성된 **기술 구현 명세서**입니다.  
> 전략 기획안이 변경될 경우 이 문서도 함께 업데이트해야 합니다.

---

## 목차

1. [개발 원칙 (편집 철학 기반)](#0-개발-원칙-편집-철학-기반)
2. [서비스 개요](#1-서비스-개요)
3. [핵심 사용자 여정 (UX Flow)](#2-핵심-사용자-여정-ux-flow)
4. [시스템 아키텍처](#3-시스템-아키텍처)
5. [데이터 수집 전략](#4-데이터-수집-전략)
6. [콘텐츠 가공 (요약·분류)](#5-콘텐츠-가공-요약분류)
7. [이메일 템플릿 설계](#6-이메일-템플릿-설계)
8. [구독 관리 시스템](#7-구독-관리-시스템)
9. [데이터베이스 스키마](#8-데이터베이스-스키마)
10. [신규 파일 구조](#9-신규-파일-구조)
11. [관리자 패널 연동 계획](#10-관리자-패널-연동-계획)
12. [개발 로드맵 (Phase)](#11-개발-로드맵-phase)
13. [예상 이슈 & 해결 방향](#12-예상-이슈--해결-방향)
14. [검토 필요 항목 (TO-DO Review)](#13-검토-필요-항목-to-do-review)

---

## 0. 개발 원칙 (편집 철학 기반)

> 전략 기획안의 **편집 철학**을 기술 구현 원칙으로 전환한 내용입니다.

| 편집 철학 | 기술 구현 원칙 |
|----------|---------------|
| 독자의 시간을 존중한다 | 이메일 렌더링 속도 최적화, 인라인 CSS, 이미지 최소화 |
| 정직하게 요약한다 | AI 요약 시 원문 왜곡 방지 프롬프트 설계, 발송 전 헤드라인 수동 검토 로직 |
| 사람의 손이 닿은 콘텐츠 | 어드민 패널 "편집장 한마디" 입력 필드 필수 구현 |
| 구독자를 팬으로 만든다 | 이메일 답장 수신 주소 실제 운영, NPS 월간 조사 API 구현 |
| 지속 가능하게 성장한다 | 발송 실패 자동 복구, 모니터링 알람, 단계적 기능 확장 |

---

## 1. 서비스 개요

### 1.1 한 줄 정의

> **매일 아침 AI 뉴스를 자동 수집·요약해 Vibe Studio에 게시하고, 구독자에게 알림 이메일을 발송해 사이트로 유입시키는 콘텐츠 허브 서비스**

### 1.2 서비스 컨셉

| 구분 | 내용 |
|------|------|
| **서비스명** | Signal by Vibe Studio |
| **콘텐츠 본체** | `vibestudio.prisincera.com/signal/YYYY-MM-DD` 웹 페이지 |
| **이메일 역할** | 알림 채널 (요약 헤드라인 3선 + 웹 링크) |
| **게시·발송 시간** | 매일 오전 8:00 KST 자동 게시 → 즉시 이메일 알림 |
| **언어** | 한국어 (원문 출처 표기) |
| **대상** | AI에 관심 있는 개발자·기획자·비즈니스 실무자 |

### 1.3 차별점

- 🌐 **웹 콘텐츠 허브**: `/signal/` 페이지가 본체 — SEO 누적, 비구독자도 검색으로 방문
- 📬 **이메일은 알림**: 짧고 강렬한 헤드라인 → 클릭 유도 → Vibe Studio 트래픽 확보
- 🔗 **서비스 연결**: 방문자가 자연스럽게 다른 Vibe Studio 앱·서비스 탐색
- 📌 **큐레이션 필터**: 중요도·신뢰성 기준 상위 콘텐츠 자동 선별

---

## 2. 핵심 사용자 여정 (UX Flow)

### 2.1 전체 플로우 — 콘텐츠 허브 모델

```
① 매일 UTC 22:50 (KST 07:50)
   collect.py → AI 뉴스 수집·요약

② KST 08:00
   publish_news.php → /signal/YYYY-MM-DD 웹 페이지 자동 생성·게시

③ KST 08:01
   digest_mailer.php → 구독자 알림 이메일 발송
   [이메일 내용]
     헤드라인 3선 (제목 + 1줄)
     [오늘 Signal 전체 보기 →] (핵심 CTA)

④ 구독자 클릭 → vibestudio.prisincera.com/signal/YYYY-MM-DD
   전체 내용 열람 + 사이드바에서 다른 앱·서비스 탐색

⑤ 비구독자 구글 검색 → /signal/ 페이지 방문 (SEO)
   하단 [Signal 알림 받기] → 구독 전환
```

### 2.2 구독 신청 플로우 (30초 완결)

```
Step 1. 이메일 입력 (한 줄)
Step 2. OTP 인증 (6자리, 기존 mail_auth.php 재사용)
Step 3. 관심 분야 선택 (선택 사항)
         ☐ 최신 연구·논문  ☐ AI 도구·제품  ☐ 국내 AI 동향
Step 4. 완료 → 웰컴 이메일 발송
         "🤖 내일 아침 8시, Signal 알림이 시작됩니다"
         → 웰컴 이메일에 /signal/ 링크 포함
```

### 2.3 구독 관리

```
[구독 취소] 이메일 하단 링크 → unsubscribe.php
  └─ 취소 직전: "발송 주기를 줄여드릴까요?" 리텐션 팝업
[NPS 조사] 월 1회, 이메일 내 별점 클릭
  └─ digest_api.php?action=nps_submit
```

---

## 3. 시스템 아키텍처

```
┌──────────────────────────────────────────────────────────────────┐
│                    AWS Lightsail (13.125.67.96)                  │
│                                                                  │
│ ┌─────────────┐   ┌───────────────┐   ┌─────────────────────┐   │
│ │ collect.py  │──▶│  MariaDB      │◀──│    admin.php        │   │
│ │(Cron 07:50) │   │  vibe_db      │   │  (어드민 API)       │   │
│ └─────────────┘   │  ai_news      │   └─────────────────────┘   │
│        │          │  digest_subs  │   ┌─────────────────────┐   │
│        ▼          │  digest_logs  │◀──│   digest_api.php    │   │
│ ┌─────────────┐   │  ai_sources   │   │  (구독/취소/NPS)    │   │
│ │summarize.php│──▶│  news_pages   │◀──┘                         │
│ │(Gemini API) │   └───────────────┘                             │
│ └─────────────┘          │                                       │
│        │                 ▼                                       │
│        ▼         ┌───────────────┐                              │
│ ┌─────────────┐  │publish_news   │──▶ /signal/YYYY-MM-DD (웹)     │
│ │digest_mailer│  │   .php        │    (SEO 누적, 사이드바 노출)  │
│ │   .php      │  │(Cron 08:00)   │                              │
│ │(Cron 08:01) │  └───────────────┘                              │
│ └──────┬──────┘                                                  │
│        │ Gmail SMTP (PHPMailer)                                  │
└────────┼─────────────────────────────────────────────────────────┘
         ▼
  구독자 알림 이메일
  (헤드라인 3선 + [Signal 보기 →])
         │ 클릭
         ▼
  vibestudio.prisincera.com/signal/YYYY-MM-DD
  (전체 내용 + 사이드바 서비스 노출)

  ↑ 비구독자 구글 검색으로도 직접 유입 (SEO)
```

---

## 4. 데이터 수집 전략

### 4.1 수집 소스 (우선순위 순)

| 소스 | 방식 | 신뢰도 | 비용 |
|------|------|--------|------|
| Hacker News AI 태그 (hn.algolia.com/api) | REST API (무료) | ⭐⭐⭐⭐ | 무료 |
| arXiv AI/ML Papers | RSS Feed | ⭐⭐⭐⭐⭐ | 무료 |
| The Verge AI | RSS Feed | ⭐⭐⭐⭐ | 무료 |
| MIT Technology Review | RSS Feed | ⭐⭐⭐⭐⭐ | 무료 |
| VentureBeat AI | RSS Feed | ⭐⭐⭐⭐ | 무료 |
| Google AI Blog | RSS Feed | ⭐⭐⭐⭐⭐ | 무료 |
| OpenAI Blog | RSS Feed | ⭐⭐⭐⭐⭐ | 무료 |
| Reddit r/MachineLearning | RSS Feed | ⭐⭐⭐ | 무료 |

### 4.2 수집 스크립트 (`collect.py`)

```
동작 흐름:
  1. 각 소스별 RSS/API 호출
  2. 중복 URL 필터링 (DB 기존 데이터와 비교)
  3. 기본 메타데이터 추출 (title, url, published_at, source)
  4. ai_news 테이블에 raw 데이터 저장 (status = 'pending')
  5. 수집 완료 로그 기록
```

**실행 환경**:
- Python 3.x (서버 설치 여부 확인 필요 → 미설치 시 PHP SimplePie 대체)
- 라이브러리: `feedparser`, `requests`, `pymysql`
- Cron: `50 22 * * * /usr/bin/python3 /opt/bitnami/apache2/htdocs/digest/collect.py`
  *(서버 시간 UTC 기준, KST 07:50 = UTC 22:50 전날)*

### 4.3 중요도 점수 산정 (Scoring)

```
score =
  (HN 포인트 × 0.4) + (댓글 수 × 0.2) + (소스 신뢰도 × 0.3) + (최신성 × 0.1)

→ score 상위 7개 기사만 당일 Digest에 포함
```

---

## 5. 콘텐츠 가공 (요약·분류)

### 5.1 요약 방식 선택지

| 방식 | 장점 | 단점 | 추천 시나리오 |
|------|------|------|--------------|
| **Gemini API (무료)** | 무료 티어 충분, 한국어 우수 | Rate limit 존재 | 초기 운영 ✅ **추천** |
| **OpenAI GPT-4o-mini** | 품질 안정적 | API 비용 발생 (월 ~$5~20) | 구독자 100명 이상 |
| **자체 규칙 기반 추출** | 비용 0원 | 품질 낮음 | 백업/폴백 |

> **초기 전략**: Gemini API 무료 티어 활용, 월 한도 초과 시 GPT-4o-mini 전환

### 5.2 가공 결과물 구조

```json
{
  "date": "2026-03-20",
  "headline": "오늘의 AI 핵심 뉴스",
  "items": [
    {
      "rank": 1,
      "category": "🔬 연구",
      "title": "GPT-5, 추론 능력 획기적 개선",
      "summary": "OpenAI가 GPT-5를 공개했습니다. 기존 대비 수학·코딩 추론에서 40% 향상...",
      "source": "OpenAI Blog",
      "url": "https://openai.com/blog/...",
      "read_time": "3분"
    }
  ],
  "paper_of_day": { ... },
  "tip_of_day": "VS Code + Continue.dev로 로컬 AI 코딩 보조 설정하기"
}
```

### 5.3 카테고리 분류

| 아이콘 | 카테고리 | 설명 |
|--------|----------|------|
| 🔬 | 연구·논문 | arXiv, 학술 발표 |
| 🏢 | 빅테크 | OpenAI, Google, Meta, Apple |
| 🛠️ | 도구·제품 | 새로운 AI 도구, SaaS |
| 📊 | 산업·비즈니스 | 투자, M&A, 규제 |
| 🇰🇷 | 국내 AI | 한국 AI 관련 뉴스 |
| 💡 | 실용 팁 | 개발자·기획자 활용법 |

---

## 6. 이메일 템플릿 설계 — 알림 채널 역할

> **핵심 원칙**: 이메일은 짧게, 웹으로 데려오는 것이 목적

### 6.1 이메일 구조 (콘텐츠 허브 알림형)

```
┌──────────────────────────────────────┐
│  [헤더]                              │
│  🤖 Signal · 2026년 3월 20일        │
│  by Vibe Studio                      │
├──────────────────────────────────────┤
│  [오늘의 헤드라인] (1줄)             │
│  🔥 "오늘 가장 중요한 AI 소식은..." │
│  ↳ 편집장의 한마디 (1줄)            │
├──────────────────────────────────────┤
│  [핵심 뉴스 3선] — 제목+1줄만       │
│  1. 🏢 GPT-5 출시 — 추론 40% 향상  │
│     [자세히 보기 →]                  │
│  2. 🔬 새 논문: ...                  │
│     [자세히 보기 →]                  │
│  3. 🛠️ 새 AI 도구 출시...           │
│     [자세히 보기 →]                  │
├──────────────────────────────────────┤
│  ★ 핵심 CTA (크고 선명하게)         │
│  [오늘 Signal 전체 보기 →]          │
│  vibestudio.prisincera.com/signal/...  │
├──────────────────────────────────────┤
│  [공유]  "도움됐다면 동료에게"       │
│  [트위터] [링크드인]                 │
├──────────────────────────────────────┤
│  [풋터]                              │
│  © 2026 Vibe Studio                  │
│  [구독 취소]  NPS: ⭐⭐⭐⭐⭐       │
└──────────────────────────────────────┘
```

> 목표: 스크롤 없이 한 화면에 완결

### 6.2 이메일 제목 라인 전략

| 패턴 | 예시 | 적용 조건 |
|------|------|----------|
| 업데이트 알림 | `🤖 오늘의 Signal 업데이트 (핵심 7선)` | 기본형 |
| 질문형 | `GPT-5가 진짜 나왔다고요? Signal에서 확인 🤔` | 빅뉴스 |
| 긴급/단독 | `[단독] Google 오늘 발표 — Vibe Studio Signal` | 단독이슈 |
| 감성형 | `☕ 오늘 아침 꼭 알아야 할 AI 이야기` | 스토리성 |

### 6.3 웹 페이지 `/signal/YYYY-MM-DD` 구조

```
[헤더] Signal · 날짜
[본문]
  🔥 오늘의 헤드라인 + 편집장 한마디
  📰 핵심 뉴스 5~7선 (각 소제목+3줄요약+출처)
  📄 오늘의 논문 (5줄 상세)
  💡 바로 쓰는 AI 팁
  🗓️ 이번 주 AI 이벤트
[사이드바] Vibe Studio 다른 앱·서비스 ← 신규 론칭 시 자동 활용
[하단] 구독 CTA (비구독자 방문 시)
```

### 6.4 디자인 방향

- 기존 Vibe Studio 브랜딩 (`#6366f1` → `#a855f7`) 일관 적용
- 이메일: inline CSS, 모바일 우선 (max-width: 600px)
- 웹 페이지: Vibe Studio 디자인 시스템 연동
- `List-Unsubscribe` 헤더 필수 (스팸 방지)

---

## 7. 구독 관리 시스템

### 7.1 구독 신청 플로우

```
사용자 이메일 입력
    └─ digest_api.php?action=subscribe_send
           └─ OTP 이메일 발송 (기존 mail_auth.php 방식 재사용)
                  └─ digest_api.php?action=subscribe_verify
                         └─ 카테고리 선택 (체크박스, 선택 사항)
                                └─ digest_subs 테이블에 INSERT
                                       └─ 웰컴 이메일 발송
```

### 7.2 구독 취소 플로우

```
이메일 하단 [구독 취소] 링크 클릭
    └─ unsubscribe.php?token={unique_token}
           ├─ 토큰 검증
           ├─ digest_subs.status = 'unsubscribed' 업데이트
           └─ 취소 완료 페이지 (Vibe Studio 스타일 HTML)
```

> ⚠️ **스팸 방지 필수**: 원클릭 구독 취소, List-Unsubscribe 헤더 추가, 재구독 가능 구조

### 7.3 구독자 설정 (향후 확장)

| 설정 | 옵션 |
|------|------|
| 수신 주기 | 매일 / 주 3회 / 주 1회 |
| 관심 카테고리 | 연구 / 빅테크 / 도구 / 국내 AI |
| 언어 | 한국어 (기본) |

---

## 8. 데이터베이스 스키마

### 8.1 `ai_news` — 수집된 원본 뉴스

```sql
CREATE TABLE ai_news (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    title        VARCHAR(500) NOT NULL,
    url          VARCHAR(1000) NOT NULL UNIQUE,
    source       VARCHAR(100),
    category     VARCHAR(50),
    score        FLOAT DEFAULT 0,
    summary_ko   TEXT,
    published_at DATETIME,
    collected_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    status       ENUM('pending','summarized','selected','sent','skipped')
                 DEFAULT 'pending',
    INDEX idx_status (status),
    INDEX idx_collected (collected_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### 8.2 `digest_subs` — 구독자 (전략안 §7 Referral 반영)

```sql
CREATE TABLE digest_subs (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    email           VARCHAR(255) NOT NULL UNIQUE,
    token           VARCHAR(64)  NOT NULL UNIQUE,    -- 구독 취소용 토큰
    referral_code   VARCHAR(20)  NOT NULL UNIQUE,    -- 내 추천 코드 (Referral 프로그램)
    referred_by     VARCHAR(20)  DEFAULT NULL,       -- 추천인 코드 (누가 초대했는지)
    referral_count  INT DEFAULT 0,                   -- 내가 초대한 구독자 수
    categories      VARCHAR(200) DEFAULT 'all',      -- 관심 카테고리 (CSV)
    frequency       ENUM('daily','weekly') DEFAULT 'daily',
    status          ENUM('active','unsubscribed','bounced') DEFAULT 'active',
    subscribed_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    unsubscribed_at DATETIME DEFAULT NULL,
    last_sent_at    DATETIME DEFAULT NULL,
    last_open_at    DATETIME DEFAULT NULL,           -- 마지막 이메일 오픈 시각 (리텐션 관리)
    nps_score       TINYINT DEFAULT NULL,            -- 최근 NPS 점수 (1~5)
    nps_updated_at  DATETIME DEFAULT NULL,
    INDEX idx_status (status),
    INDEX idx_referral (referral_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### 8.3 `digest_logs` — 발송 로그 (전략안 §10 KPI 트래킹 반영)

```sql
CREATE TABLE digest_logs (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    sent_date     DATE NOT NULL,
    edition_type  ENUM('daily','weekly','monthly','special') DEFAULT 'daily',
    subject_line  VARCHAR(300),                 -- 실제 발송된 제목 (A/B 테스트용)
    total_subs    INT DEFAULT 0,
    sent_count    INT DEFAULT 0,
    fail_count    INT DEFAULT 0,
    open_count    INT DEFAULT 0,                -- 오픈 수 (KPI: 35%↑ 목표)
    click_count   INT DEFAULT 0,               -- 링크 클릭 수 (KPI: CTR 10%↑ 목표)
    news_ids      TEXT,                         -- 포함된 ai_news ID 목록 (JSON)
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_date (sent_date),
    INDEX idx_type (edition_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### 8.4 `ai_sources` — 수집 소스 관리

```sql
CREATE TABLE ai_sources (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(100) NOT NULL,
    url             VARCHAR(500) NOT NULL,
    type            ENUM('rss','api','web') DEFAULT 'rss',
    category        VARCHAR(50),
    enabled         TINYINT(1) DEFAULT 1,
    weight          FLOAT DEFAULT 1.0,
    last_fetched_at DATETIME DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 초기 소스 데이터
INSERT INTO ai_sources (name, url, type, category, weight) VALUES
('Hacker News AI', 'https://hn.algolia.com/api/v1/search?tags=story&query=AI', 'api', 'tech', 1.2),
('arXiv CS.AI', 'https://export.arxiv.org/rss/cs.AI', 'rss', 'research', 1.5),
('OpenAI Blog', 'https://openai.com/blog/rss', 'rss', 'bigtech', 1.5),
('Google AI Blog', 'https://blog.google/technology/ai/rss', 'rss', 'bigtech', 1.4),
('MIT Tech Review AI', 'https://www.technologyreview.com/feed', 'rss', 'research', 1.3),
('VentureBeat AI', 'https://venturebeat.com/category/ai/feed', 'rss', 'industry', 1.1),
('The Verge AI', 'https://www.theverge.com/ai-artificial-intelligence/rss/index.xml', 'rss', 'industry', 1.0),
('Reddit ML', 'https://www.reddit.com/r/MachineLearning/.rss', 'rss', 'community', 0.8);
```

---

## 9. 신규 파일 구조

```
htdocs/
│
├─ signal/                                ← ★ 신규 — Signal 웹 콘텐츠 허브
│   └─ YYYY-MM-DD.html                  ← 매일 자동 생성되는 일별 Signal 페이지
│       (예: 2026-03-20.html)
│
├─ digest/                              ← ★ 신규 — 자동화 스크립트
│   ├─ collect.py                       ← 뉴스 수집 (Cron UTC 22:50 = KST 07:50)
│   ├─ summarize.php                    ← AI 요약 가공 (Gemini API → GPT 폴백)
│   ├─ publish_news.php                 ← ★ 신규 — /signal/ 웹 페이지 자동 생성
│   │                                       (Cron UTC 23:00 = KST 08:00)
│   │                                       사이드바 Vibe Studio 앱 목록 자동 주입
│   ├─ digest_mailer.php                ← 알림 이메일 발송 (Cron UTC 23:01)
│   │                                       헤드라인 3선 + [Signal 전체 보기 →]
│   ├─ build_template.php              ← HTML 이메일 템플릿 (알림형, 짧게)
│   └─ monitor.php                      ← 발송 +10분 모니터링 알람
│
├─ digest_api.php                       ← ★ 신규 — 구독/취소/NPS REST API
├─ unsubscribe.php                      ← ★ 신규 — 구독 취소 + 리텐션 팝업
│
├─ apps/digest/                         ← ★ 신규 어드민 앱
│   ├─ app.html
│   ├─ app.css
│   └─ app.js
│
└─ config.php                           ← 기존 — GEMINI_API_KEY 추가
```

---

## 10. 관리자 패널 연동 계획

기존 Admin 패널 (`apps/admin/`)에 **Digest 탭** 추가:

| 탭 | 내용 | 전략 연계 |
|----|------|----------|
| 📰 뉴스 현황 | 오늘 수집 기사, 점수, 요약 상태, 편집장 한마디 입력 | 편집 철학 §3 |
| 📧 발송 현황 | 날짜별 발송 로그, **오픈율·CTR** KPI 대시보드 | KPI §10.1·10.2 |
| 👥 구독자 관리 | 구독자 목록, 상태, Referral 현황, NPS 분포 | 리텐션 §9 |
| ⚙️ 소스 관리 | RSS 소스 활성화/비활성화, 가중치 조정 | 수집 §4 |
| 📨 특별 발송 | 주간/월간/특집 즉시 발송 트리거 | 콘텐츠 §6.3 |
| 💰 수익화 현황 | 스폰서 관리, 프리미엄 구독자 현황 (Phase A↑) | 수익화 §8.2 |

### `admin.php` 추가 엔드포인트

```
GET  admin.php?action=digest_news          → 오늘 수집 뉴스 목록
GET  admin.php?action=digest_logs          → 발송 로그 + 오픈율/CTR
GET  admin.php?action=digest_subs          → 구독자 목록 + Referral + NPS
GET  admin.php?action=digest_sources       → 소스 목록
GET  admin.php?action=digest_kpi           → KPI 현황 (오픈율, CTR, 구독자 증가율)
POST admin.php action=digest_send_now      → 즉시 발송 (일간/주간/특별 구분)
POST admin.php action=digest_editor_note   → 편집장 한마디 저장
POST admin.php action=digest_source_toggle → 소스 활성화 토글
```

---

## 11. 개발 로드맵 (Phase)

> 전략 기획안 §12 분기별 로드맵과 연계된 기술 구현 일정입니다.

### Phase 1 — 기반 구축 (4월 1~2주)
- [ ] DB 스키마 생성 (4개 테이블 + `news_pages` 테이블 추가)
- [ ] `collect.py` 구현 (RSS 8개 소스, 중복 제거, 점수 산정)
- [ ] Cron Job 등록 (UTC 22:50 수집 / 23:00 게시 / 23:01 발송 / 23:10 모니터)
- [ ] `config.php`에 `GEMINI_API_KEY` 추가
- [ ] 수동 실행 테스트 (내부 팀원 5명)

### Phase 2 — AI 요약·가공 (4월 3주)
- [ ] `summarize.php` (Gemini API → GPT-4o-mini 폴백)
- [ ] 카테고리 자동 분류 (🔬/🏢/🛠️/📊/🇰🇷/💡)
- [ ] `ai_news` 상태 흐름 (`pending → summarized → selected`)
- [ ] 프롬프트 튜닝 (원문 왜곡 없이 3줄 원칙)

### Phase 3 — 웹 게시 시스템 (4월 4주) ← 신규
- [ ] `publish_news.php` — `/signal/YYYY-MM-DD.html` 자동 생성
- [ ] 웹 페이지 레이아웃: 본문 + 사이드바(Vibe Studio 앱 목록) + 구독 CTA
- [ ] SEO 최적화 (title, meta description, OG 태그 자동 삽입)
- [ ] `/signal/` 인덱스 페이지 (최근 7일 목록)
- [ ] `sitemap.xml` 자동 업데이트

### Phase 4 — 이메일 알림 발송 (5월 1주)
- [ ] `build_template.php` — 알림형 이메일 (헤드라인 3선 + 메인 CTA)
- [ ] 제목 라인 4패턴 자동 선택
- [ ] `digest_mailer.php` — PHPMailer (List-Unsubscribe 헤더 포함)
- [ ] `digest_logs` 기록 (CTR 트래킹 UTM 포함)
- [ ] `monitor.php` — 발송 +10분 모니터링
- [ ] 웹+이메일 전체 흐름 테스트 검증

### Phase 5 — 구독 시스템 (5월 2주 → 소프트 런칭)
- [ ] `digest_api.php` — subscribe / unsubscribe / nps_submit / referral_check
- [ ] `unsubscribe.php` — 리텐션 팝업 포함
- [ ] `index.html` 구독 UI (30초 완결)
- [ ] `/signal/` 페이지 하단 구독 CTA (비구독자 전환)
- [ ] 웰컴 이메일 (/signal/ 링크 포함)

### Phase 6 — 어드민 연동 (5월 3주)
- [ ] `admin.php` Digest 엔드포인트 8개
- [ ] `apps/digest/` 어드민 앱 (6개 탭)
- [ ] KPI 대시보드 (오픈율 / 웹 CTR / /signal/ 방문수 / NPS)
- [ ] 편집장 한마디 입력 UI + 즉시 발송

### Phase 7 — 공개 런칭 & SEO (6월)
- [ ] SNS 공개 발표 (/signal/ URL 공유)
- [ ] Referral 프로그램 오픈
- [ ] 주간 리포트 (금요일판) Cron 등록
- [ ] SEO 색인 시작 확인 (Google Search Console)
- [ ] 구독자 100명 달성 목표

### Phase 8 — 수익화 (10월↑, 구독자 500명 달성 시)
- [ ] /signal/ 페이지 사이드바 스폰서 광고 슬롯 추가
- [ ] 이메일 하단 네이티브 광고 섹션
- [ ] 프리미엄 티어 (구독자 1,000명↑)
- [ ] B2B 팀 구독 관리 (구독자 2,000명↑)

---

## 12. 예상 이슈 & 해결 방향

| 이슈 | 해결 방향 |
|------|----------|
| **Python 미설치** | `python3 --version` 확인 → 미설치 시 PHP `SimplePie` 또는 `cURL + DOMDocument`로 대체 |
| **Gemini Rate Limit** | 일일 요청 한도 초과 시 GPT-4o-mini 자동 전환 |
| **Gmail SMTP 일 500건 한도** | 초기엔 충분, 구독자 400명↑ 시 Mailgun/SendGrid 전환 검토 |
| **스팸 분류 방지** | SPF/DKIM/DMARC DNS 레코드 확인, `List-Unsubscribe` 헤더 필수 추가 |
| **RSS 파싱 실패** | 소스별 try-catch, 연속 3회 실패 시 `ai_sources.enabled = 0` 자동 처리 |
| **중복 기사** | URL UNIQUE 제약으로 1차 차단, 제목 유사도(Levenshtein) 2차 필터 |
| **요약 품질 저하** | 최소 본문 길이 기준(200자↑) 미달 기사 스킵, 프롬프트 버전 관리 |

---

## 13. 검토 필요 항목 (TO-DO Review)

> 기획 확정 전 함께 논의가 필요한 항목들입니다.

### ✅ 확정 사항
- 기존 PHPMailer + Gmail SMTP 재사용
- 기존 OTP 인증 방식 (`mail_auth.php`) 재사용
- MariaDB `vibe_db` 확장 (신규 테이블 4개 추가)
- Vibe Studio 브랜딩 일관 적용

### ❓ 검토 필요 항목

| # | 항목 | 선택지 | 비고 |
|---|------|--------|------|
| 1 | **AI 요약 API** | Gemini 무료 vs GPT 유료 | 초기엔 Gemini 추천 |
| 2 | **발송 한도 대응** | Gmail SMTP 유지 vs Mailgun 전환 | 구독자 규모에 따라 결정 |
| 3 | **구독 UI 위치** | `index.html` 섹션 추가 vs `/digest` 별도 페이지 | UX 방향 검토 필요 |
| 4 | **수집 주기** | 1일 1회 vs 2회 (오전·오후) | 서버 부하 vs 최신성 |
| 5 | **수집 언어** | Python `feedparser` vs PHP `SimplePie` | 서버 Python 설치 여부 먼저 확인 |
| 6 | **국내 AI 소스** | 아이뉴스24, 동아사이언스 RSS 추가 여부 | 별도 조사 필요 |
| 7 | **오픈율 트래킹** | 픽셀 추적 구현 여부 | 프라이버시 이슈 검토 필요 |
| 8 | **무료 vs 유료** | 현재는 완전 무료 | 향후 프리미엄 티어 가능성 |

---

## 14. 두 문서 간 연계 관계

```
AI_DIGEST_STRATEGY.md          AI_DIGEST_PLAN.md
(대고객 전략 기획안)    ────▶  (서비스 구축 기획서)

§3 페르소나           ────▶  §2 UX Flow (구독 신청 30초 완결)
§5 CX 설계           ────▶  §6 이메일 템플릿 (제목 패턴 4종)
§6 콘텐츠 전략        ────▶  §6 이메일 구조 (특별 콘텐츠 4종)
§7 성장 전략          ────▶  §8 DB (referral_code 컬럼)
§9 리텐션 전략        ────▶  §7 구독 취소 (리텐션 팝업)
§9.4 NPS 조사        ────▶  §8 DB (nps_score) + §9 신규 파일
§10 KPI              ────▶  §8 digest_logs (open/click 트래킹)
§12 분기별 로드맵     ────▶  §11 Phase 1~7 월 단위 일정
```

> [!NOTE]
> 전략 기획안이 변경되면 위 연계 항목을 기준으로 이 문서도 함께 업데이트하세요.

---

*작성: Vibe Studio Dev Team · 2026-03-20*  
*최종 업데이트: 2026-03-20 — AI_DIGEST_STRATEGY.md 전략 기획안 반영 완료*
