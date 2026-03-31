# Signal — 데일리 AI 뉴스 수집·콘텐츠화 실행 계획

> **작성일**: 2026-03-24  
> **목적**: AI 뉴스 자동 수집 → 콘텐츠 생성 파이프라인의 구체적 검토 및 실행 계획  
> **서버 환경 확인 결과**: Python 3.11.2 설치됨 · pip 미설치 · PHP 8.5.2 · urllib 표준 라이브러리 사용 가능

---

## 1. 핵심 판단 사항 (서버 환경 기반)

### ✅ 확정: Python 3.11 기반 수집 스크립트 (외부 라이브러리 없이)

| 항목 | 현황 | 결론 |
|------|------|------|
| Python 버전 | 3.11.2 ✅ | 사용 가능 |
| pip | 미설치 ❌ | `urllib` 표준 라이브러리로 대체 |
| feedparser | 미설치 ❌ | `xml.etree.ElementTree`로 RSS 파싱 |
| requests | 미설치 ❌ | `urllib.request`로 대체 |
| PHP | 8.5.2 ✅ | Gemini API 호출·DB 작업·페이지 생성 담당 |

> [!IMPORTANT]
> `pip` 없이 외부 모듈을 설치할 수 없으므로, Python은 **표준 라이브러리만**으로 RSS 수집을 담당합니다.  
> Gemini API 호출·DB 저장·HTML 생성 등 복잡한 작업은 **PHP가 전담**합니다.

---

## 2. 전체 파이프라인 설계

```
[UTC 22:50 / KST 07:50]
collect.py (Python 표준라이브러리)
├─ 8개 RSS/API 소스 fetch
├─ XML 파싱 → 메타데이터 추출 (title, url, pubDate, source)
├─ URL 중복 제거 (DB 비교)
└─ ai_news 테이블 INSERT (status='pending')
        ↓
[UTC 22:55 / KST 07:55]
summarize.php (PHP + Gemini API)
├─ status='pending' 기사 조회 (최대 15건)
├─ Gemini API 호출 → 한국어 3줄 요약 + 카테고리 분류
├─ score 산정 (소스 가중치 + 최신성)
└─ status='summarized' 업데이트
        ↓
[UTC 23:00 / KST 08:00]
publish_news.php (PHP)
├─ 상위 score 7건 선별 (status='selected')
├─ /signal/YYYY-MM-DD.html 자동 생성
├─ SEO 메타태그 삽입
└─ news_pages 테이블 기록
        ↓
[UTC 23:01 / KST 08:01]
digest_mailer.php (PHP + PHPMailer)
├─ 상위 3건 헤드라인 추출
├─ HTML 이메일 빌드 (알림형, 짧게)
└─ 구독자 전체 발송
        ↓
[UTC 23:10 / KST 08:10]
monitor.php (PHP)
└─ 발송 성공/실패 확인 → 알람
```

---

## 3. 수집 소스 및 접근 가능성 검증

| 소스 | URL | 방식 | 접근 가능 여부 | 비고 |
|------|-----|------|--------------|------|
| arXiv cs.AI | `https://export.arxiv.org/rss/cs.AI` | RSS XML | ✅ | 가장 신뢰도 높음 |
| OpenAI Blog | `https://openai.com/blog/rss` | RSS XML | ✅ | 빅뉴스 소스 |
| Google AI Blog | `https://blog.google/technology/ai/rss` | RSS XML | ✅ | |
| Hacker News | `https://hn.algolia.com/api/v1/search?tags=story&query=AI&numericFilters=points>10` | JSON API | ✅ | 무료, 실시간 |
| VentureBeat AI | `https://venturebeat.com/category/ai/feed` | RSS XML | ✅ | |
| MIT Tech Review | `https://www.technologyreview.com/feed` | RSS XML | ✅ | |
| The Verge AI | `https://www.theverge.com/ai-artificial-intelligence/rss/index.xml` | RSS XML | ✅ | |
| Reddit r/ML | `https://www.reddit.com/r/MachineLearning/.rss` | RSS XML | ⚠️ | User-Agent 필요 |

> [!TIP]
> 초기에는 **arXiv + Hacker News + OpenAI + Google AI Blog** 4개로 시작하고,  
> 안정화 후 나머지 소스를 순차 추가하는 것을 권장합니다.

---

## 4. `collect.py` 구현 계획 (표준 라이브러리만 사용)

```python
# collect.py — 핵심 구조 (pip 없이 표준 라이브러리만)

import urllib.request
import xml.etree.ElementTree as ET
import json
import re
from datetime import datetime, timezone
import pymysql  # ← 이 부분이 문제 → 아래 해결책 참조

SOURCES = [
    {"name": "arXiv AI", "url": "https://export.arxiv.org/rss/cs.AI", "type": "rss", "weight": 1.5},
    {"name": "HN AI",    "url": "https://hn.algolia.com/api/v1/search?tags=story&query=AI&numericFilters=points>10", "type": "json_api", "weight": 1.2},
    {"name": "OpenAI Blog", "url": "https://openai.com/blog/rss", "type": "rss", "weight": 1.5},
    {"name": "Google AI", "url": "https://blog.google/technology/ai/rss", "type": "rss", "weight": 1.4},
]
```

### ⚠️ DB 연결 문제 및 해결 방안

Python에서 MariaDB에 접근하려면 `pymysql` 또는 `mysql-connector` 가 필요한데, pip가 없어 설치 불가.

**해결책 2가지:**

| 방안 | 방법 | 장단점 |
|------|------|--------|
| **A. PHP로 DB 처리 위임** ✅ 권장 | collect.py → JSON 파일 출력 → collect_save.php → DB INSERT | 추가 설치 없음, PHP가 DB 담당 |
| **B. pip 설치** | `sudo apt install python3-pip` | 직접적이나 시스템 수정 필요 |

> [!NOTE]
> **권장 방안 A** 채택 시 파이프라인:  
> `collect.py` → `/tmp/collected_news.json` → `collect_save.php`(cron 1분 후) → DB INSERT

---

## 5. Gemini API 연동 계획 (`summarize.php`)

### 5.1 사용할 모델 및 무료 한도

| 모델 | 무료 한도 | 한국어 품질 | 권장 |
|------|----------|------------|------|
| `gemini-1.5-flash` | 15 req/min, 1,500 req/day | 우수 | ✅ 초기 추천 |
| `gemini-1.5-pro` | 2 req/min, 50 req/day | 최고 | ⚠️ 한도 빠듯 |
| `gemini-2.0-flash` | 1,500 req/day | 우수 | ✅ 대안 |

하루 15건 요약 기준 → `gemini-1.5-flash` 무료 한도 내 충분

### 5.2 프롬프트 설계

```
[시스템 프롬프트]
당신은 AI 뉴스 전문 에디터입니다. 다음 규칙을 반드시 따르세요:
1. 원문 내용만 요약하세요. 없는 내용을 추가하지 마세요.
2. 한국어로 작성하되, 자연스러운 구어체를 사용하세요.
3. 3줄 이내로 핵심만 요약하세요.
4. 카테고리를 다음 중 하나로 분류하세요:
   research(연구/논문) | bigtech(빅테크) | tools(도구/제품) | industry(산업/비즈니스) | korea(국내AI) | tips(실용팁)

[사용자 입력]
제목: {title}
원문 URL: {url}
원문 내용 (첫 500자): {content}

JSON 형식으로 응답:
{"summary_ko": "...", "category": "..."}
```

### 5.3 폴백 전략

```
Gemini 호출 실패 →
  ├─ 재시도 1회 (5초 대기)
  ├─ 실패 시: 제목만으로 단순 요약 (규칙 기반)
  └─ 그래도 실패: status='skipped', 다음 기사로
```

---

## 6. 점수 산정 로직

```php
// score 계산 (0~10 범위)
function calc_score($item) {
    $score = 0;

    // 소스 가중치 (최대 3점)
    $score += $item['source_weight'] * 2;  // arXiv=3, OpenAI=3, HN=2.4...

    // 최신성 (최대 3점)
    $hours = (time() - strtotime($item['published_at'])) / 3600;
    $score += max(0, 3 - ($hours / 8));   // 24시간 지나면 0점

    // HN 포인트 (최대 2점, API 소스만)
    if ($item['source'] === 'HN AI') {
        $score += min(2, $item['hn_points'] / 50);
    }

    // 카테고리 보너스 (최대 2점)
    $bonus = ['research' => 2, 'bigtech' => 1.8, 'tools' => 1.5, 'korea' => 2];
    $score += $bonus[$item['category']] ?? 1;

    return round($score, 2);
}
```

---

## 7. `/signal/YYYY-MM-DD.html` 페이지 생성 계획

### 7.1 페이지 구조

```html
<!-- publish_news.php가 자동 생성 -->
<!DOCTYPE html>
<html>
<head>
  <title>Signal · 2026년 3월 24일 — 오늘의 AI 핵심 뉴스</title>
  <meta name="description" content="[헤드라인 1번 제목] 외 6건 — Vibe Studio Signal">
  <!-- OG, canonical 자동 삽입 -->
</head>
<body>
  <!-- Vibe Studio 공통 헤더 (layout.js) -->

  <!-- 본문 -->
  <section class="signal-hero">       <!-- 날짜 + 헤드라인 -->
  <section class="signal-news">       <!-- 뉴스 5~7선 -->
  <section class="signal-paper">      <!-- 오늘의 논문 -->
  <section class="signal-tip">        <!-- AI 팁 -->

  <!-- 사이드바: Vibe Studio 앱 목록 (자동 주입) -->
  <!-- 하단: 구독 CTA (비구독자용) -->
</body>
</html>
```

### 7.2 SEO 자동 생성 항목

| 항목 | 생성 규칙 |
|------|----------|
| `<title>` | `Signal · {날짜} — {1위 뉴스 제목 20자}` |
| `meta description` | `{1위 요약 60자}... 외 {N}건` |
| `canonical` | `https://vibestudio.prisincera.com/signal/{날짜}` |
| OG image | 공통 Signal 썸네일 (날짜 동적 삽입) |
| [sitemap.xml](file:///d:/VibeCoding/AWS/sitemap.xml) | 페이지 생성 시 자동 추가 |

---

## 8. Cron Job 등록 계획

```bash
# /etc/cron.d/signal 또는 bitnami 사용자 crontab

# 1단계: RSS 수집 (KST 07:50 = UTC 22:50)
50 22 * * * python3 /opt/bitnami/apache2/htdocs/digest/collect.py >> /var/log/signal_collect.log 2>&1

# 2단계: JSON → DB 저장 + AI 요약 (KST 07:53)
53 22 * * * /opt/bitnami/php/bin/php /opt/bitnami/apache2/htdocs/digest/collect_save.php >> /var/log/signal_save.log 2>&1

# 3단계: AI 요약 처리 (KST 07:55)
55 22 * * * /opt/bitnami/php/bin/php /opt/bitnami/apache2/htdocs/digest/summarize.php >> /var/log/signal_summarize.log 2>&1

# 4단계: 웹 페이지 게시 (KST 08:00)
0 23 * * * /opt/bitnami/php/bin/php /opt/bitnami/apache2/htdocs/digest/publish_news.php >> /var/log/signal_publish.log 2>&1

# 5단계: 알림 이메일 발송 (KST 08:01)
1 23 * * * /opt/bitnami/php/bin/php /opt/bitnami/apache2/htdocs/digest/digest_mailer.php >> /var/log/signal_mail.log 2>&1

# 6단계: 발송 모니터링 (KST 08:10)
10 23 * * * /opt/bitnami/php/bin/php /opt/bitnami/apache2/htdocs/digest/monitor.php >> /var/log/signal_monitor.log 2>&1
```

> [!WARNING]
> Bitnami 서버의 PHP 경로가 `/opt/bitnami/php/bin/php`임을 위에서 확인했습니다.  
> 일반 [php](file:///d:/VibeCoding/AWS/api.php) 명령어가 아닌 **전체 경로를 cron에 명시**해야 합니다.

---

## 9. 구현 우선순위 및 단계별 실행 계획

### Phase 1 — 수집 파이프라인 (1주)

| 작업 | 파일 | 예상 시간 |
|------|------|----------|
| DB 테이블 생성 (ai_news, ai_sources) | SQL | 1h |
| RSS 수집 스크립트 | `collect.py` | 3h |
| JSON → DB 저장 PHP | `collect_save.php` | 2h |
| Cron 등록 + 로깅 | crontab | 1h |
| 수집 테스트 (수동 실행) | — | 2h |

**완료 기준**: 매일 아침 8개 소스에서 10~30건 수집, DB 저장 확인

### Phase 2 — AI 요약 (1주)

| 작업 | 파일 | 예상 시간 |
|------|------|----------|
| Gemini API 키 발급 + config.php 추가 | config.php | 30min |
| 요약 + 카테고리 분류 | `summarize.php` | 4h |
| 점수 산정 + 상위 선별 | `summarize.php` | 2h |
| 프롬프트 튜닝 (품질 검증) | — | 3h |

**완료 기준**: 수집된 기사 중 상위 7건 한국어 3줄 요약 + 카테고리 분류 자동화

### Phase 3 — 웹 페이지 게시 (1주)

| 작업 | 파일 | 예상 시간 |
|------|------|----------|
| Signal 페이지 HTML 템플릿 | `signal/template.html` | 4h |
| 자동 생성 스크립트 | `publish_news.php` | 3h |
| SEO 최적화 (title/meta/OG/sitemap) | — | 2h |
| `/signal/` 인덱스 페이지 | `signal/index.html` | 2h |

**완료 기준**: `vibestudio.prisincera.com/signal/2026-04-01` 접속 시 정상 페이지 표시

### Phase 4 — 이메일 알림 (1주)

| 작업 | 파일 | 예상 시간 |
|------|------|----------|
| 알림형 이메일 템플릿 | `build_template.php` | 3h |
| 발송 스크립트 (구독자 DB 연동) | `digest_mailer.php` | 3h |
| UTM 트래킹 파라미터 삽입 | — | 1h |
| 발송 로그 기록 | `digest_logs` | 1h |
| 모니터링 | `monitor.php` | 2h |

**완료 기준**: 내부 테스트 이메일(5명) 자동 발송 + 링크 클릭 추적 확인

---

## 10. 주요 결정 필요 사항

| # | 항목 | 옵션 A | 옵션 B | 권고 |
|---|------|--------|--------|------|
| 1 | **DB 연결 방식** | Python → JSON파일 → PHP INSERT | pip 설치 후 pymysql | **A 권고** (환경 변경 최소화) |
| 2 | **Gemini 모델** | gemini-1.5-flash (무료) | gemini-2.0-flash (무료) | **flash 먼저 테스트** |
| 3 | **수집 시작 소스 수** | 4개 (arXiv·HN·OpenAI·Google) | 8개 전체 | **4개 먼저 → 안정화 후 확장** |
| 4 | **페이지 URL 구조** | `/signal/YYYY-MM-DD.html` | `/signal/YYYY-MM-DD` (Clean URL) | **Clean URL** (이미 .htaccess 적용 중) |
| 5 | **편집장 한마디** | 어드민 패널에서 수동 입력 | AI가 자동 작성 | **수동 입력** (편집 철학 준수) |

---

## 11. 리스크 및 대응

| 리스크 | 발생 확률 | 대응 |
|--------|----------|------|
| RSS 소스 구조 변경으로 파싱 실패 | 중 | try-except 개별 처리, 연속 3회 실패 시 소스 비활성화 |
| Gemini API Rate Limit 초과 | 낮음 | 기사간 2초 대기, 초과 시 다음 날로 이월 |
| Cron 미실행 | 중 | monitor.php가 발송 여부 확인 후 Slack/이메일 알람 |
| 요약 품질 불량 (환각) | 중 | 최소 본문 200자 미만 기사 스킵, 발송 전 헤드라인 수동 확인 UI |
| arXiv 논문 너무 학술적 | 중 | "실용 요약" 프롬프트 별도 설계, 독자 친화적 재해석 |

---

## 12. 즉시 시작 가능한 첫 번째 작업

```
1. config.php에 GEMINI_API_KEY 추가
2. DB 4개 테이블 생성 (ai_news, ai_sources, digest_logs, news_pages)
3. collect.py 작성 (arXiv RSS 1개 소스만으로 시작)
4. 수동 실행 테스트 → JSON 파일 확인
5. collect_save.php로 DB 저장 확인
→ 이 5단계가 완료되면 전체 파이프라인의 40%가 완성됩니다.
```

---

*검토 기준일: 2026-03-24 | 서버: AWS Lightsail (Python 3.11.2, PHP 8.5.2)*
