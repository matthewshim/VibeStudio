# Signal 파이프라인 기술 문서
> 작성일: 2026-03-25 | 분석자: Antigravity

---

## 1. ai_news 테이블 — `status` 값 종류와 정의

### 상태 흐름 (파이프라인 순서)

```
[collect.py + collect_save.php]
        → status = 'pending'       ← 수집 완료, AI 요약 대기

[summarize.php — Gemini AI 요약]
  AI 요약 성공 → status = 'summarized'  ← 요약 완료, 선발 전
  AI 요약 실패 → status = 'skipped'     ← Rate Limit(429) 또는 응답 없음으로 제외

[summarize.php — score 상위 N건 선발]
        → status = 'selected'      ← 게시 대상 선발 (기본 상위 7건)

[publish_news.php — HTML 페이지 생성]
        → status = 'sent'          ← 페이지에 게시 완료
```

### 상태값 정의표

| status | 담당 파일 | 정의 | 비고 |
|--------|----------|------|------|
| `pending` | `collect_save.php` | 수집 완료, AI 요약 **대기중** | INSERT 초기값 |
| `summarized` | `summarize.php` | Gemini AI 요약 **완료** | 선발 전 중간 상태 |
| `skipped` | `summarize.php` | API 실패 또는 요약 불가로 **제외** | HTTP 429 (Rate Limit) 주요 원인 |
| `selected` | `summarize.php` | score 기준 상위 N건 **게시 대상 선발** | `TOP_SELECT = 7` |
| `sent` | `publish_news.php` | HTML 페이지에 **게시 완료** | 이메일 발송 후 최종 상태 |

### ⚠️ 어드민 패널 상태 배지 색상 불일치 (개선 필요)

현재 `apps/admin/app.js`의 `SC` 색상 정의가 실제 DB 상태값과 불일치합니다.

```javascript
// 현재 (❌ 불완전)
const SC = { sent:'#34d399', collected:'#38bdf8', error:'#ff453a' };
// 'collected'는 DB에 존재하지 않는 값
// pending, summarized, selected, skipped → 모두 기본 회색(#86868b)으로 표시됨

// 권장 수정안 (✅)
const SC = {
    pending:    '#38bdf8',   // 파란색 — 수집 대기
    summarized: '#fb923c',   // 주황색 — AI 요약 완료
    selected:   '#a855f7',   // 보라색 — 선발
    sent:       '#34d399',   // 초록색 — 게시 완료
    skipped:    '#ff453a',   // 빨간색 — 제외
};
```

---

## 2. Signal 페이지 기사 정렬 기준

### 현재 정렬 방식

**`publish_news.php` (페이지 생성 쿼리):**
```sql
SELECT id, title, url, source_name, category, score, summary_ko
FROM ai_news
WHERE status = 'selected' AND DATE(collected_at) = :today
ORDER BY score DESC  -- score 높은 순으로 #1 배치
```

**Admin 패널 스케줄러 뷰 (`admin.php`):**
```sql
SELECT id, title, source_name, category, score, status, collected_at
FROM ai_news WHERE DATE(collected_at) = :d
ORDER BY score DESC LIMIT 20
```

> 코드상으로는 양쪽 모두 **`score DESC` (높은 점수 → 상단 노출)**이 맞습니다.

---

## 3. score 산출 기준 (`collect.py:calc_score`)

수집 시점에 기사당 score를 계산하여 DB에 저장합니다.

```python
def calc_score(item):
    score = 0.0
    score += item['source_weight'] * 2   # 소스 신뢰도 (최대 3점)
    score += max(0.0, 3.0 - hours_old/8) # 최신성 (최대 3점, 8시간마다 -1점)
    score += min(2.0, hn_points / 50.0)  # HN 포인트 (최대 2점)
    score += bonus[category]              # 카테고리 보너스 (최대 2점)
    return round(score, 2)
```

### 항목별 가중치

| 평가 항목 | 최대 점수 | 세부 내용 |
|----------|---------|---------|
| 소스 신뢰도 | **3점** | arXiv×1.5, MIT×1.4, Korea AI×1.3, HN×1.2, 기타×1.0 |
| **최신성** | **3점** | 수집 시점 기준, 8시간 경과마다 -1점 (24시간 후 0점) |
| HN 포인트 | **2점** | HN 좋아요 50점 = 1점 (최대 2점) |
| 카테고리 보너스 | **2점** | research/korea=2.0, bigtech=1.8, tools=1.5, tech=1.3, 기타=1.0 |
| **최대 합산** | **10점** | |

### 순위가 직관적으로 보이지 않는 이유

- score가 **수집 시점에 고정**되어 이후 시간 변화를 반영하지 않음
- 기사 간 점수 차이가 적어 **동점 또는 근접 점수** 다발 (예: 6.2 / 6.0 / 5.8 / 5.8)
- arXiv 논문(research)은 카테고리 보너스(+2.0)로 HN 인기 기사보다 높게 나올 수 있음

---

## 4. 파이프라인 스케줄 정보

| 항목 | 값 |
|------|-----|
| 실행 시간 | **매일 07:50 KST** (= 22:50 UTC 전날) |
| Cron 설정 | `50 22 * * * bash /opt/bitnami/.../pipeline.sh` |
| 서버 타임존 | UTC |
| TODAY 계산 | `TZ=Asia/Seoul date +%Y-%m-%d` (KST 기준) |
| 선발 기사 수 | 상위 7건 (`TOP_SELECT = 7`) |
| signal 디렉토리 | `/opt/bitnami/apache2/htdocs/signal/` (소유자: bitnami) |

---

## 5. 관련 파일 목록

| 파일 | 역할 |
|------|------|
| `digest/pipeline.sh` | 전체 파이프라인 오케스트레이터 |
| `digest/collect.py` | RSS/API 뉴스 수집 + score 산출 |
| `digest/collect_save.php` | 수집 결과 DB 저장 (INSERT IGNORE) |
| `digest/summarize.php` | Gemini AI 요약 + selected 선발 |
| `digest/publish_news.php` | Signal HTML 페이지 생성 |
| `digest/digest_mailer.php` | 구독자 이메일 발송 |
| `digest/monitor.php` | 파이프라인 완료 모니터링 |
| `admin.php` | `signal_scheduler_status` API 제공 |
| `apps/admin/app.js` | 어드민 Signal 탭 UI |
