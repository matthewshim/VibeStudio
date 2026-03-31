#!/bin/bash
# =============================================================
# Signal AI Digest — Full Pipeline Runner
# /opt/bitnami/apache2/htdocs/digest/pipeline.sh
#
# Usage: bash pipeline.sh [--force]
#   --force : 오늘 이미 실행했더라도 강제 재실행
#
# Exit codes:
#   0 = 성공
#   1 = collect 실패
#   2 = save 실패
#   3 = summarize 실패
#   4 = publish 실패
# =============================================================

PHP=/opt/bitnami/php/bin/php
PY3=/usr/bin/python3
HTDOCS=/opt/bitnami/apache2/htdocs
DIGEST=${HTDOCS}/digest
LOCK_FILE=/tmp/signal_pipeline.lock
LOG_FILE=/tmp/signal_pipeline.log
TODAY=$(TZ=Asia/Seoul date +%Y-%m-%d)
FORCE=0

# --force 플래그 처리
for arg in "$@"; do
    [ "$arg" = "--force" ] && FORCE=1
done

# ── 로깅 함수 ──────────────────────────────────────────────
log() {
    TS=$(date '+%Y-%m-%d %H:%M:%S')
    echo "[${TS}] $1" | tee -a "${LOG_FILE}"
}

# ── 중복 실행 방지 (Lock 파일) ─────────────────────────────
if [ -f "${LOCK_FILE}" ]; then
    LOCK_DATE=$(cat "${LOCK_FILE}" 2>/dev/null)
    if [ "${LOCK_DATE}" = "${TODAY}" ] && [ "${FORCE}" -eq 0 ]; then
        log "⚠ 오늘(${TODAY}) 이미 실행됨. 종료. (강제 재실행: --force)"
        exit 0
    fi
fi
echo "${TODAY}" > "${LOCK_FILE}"

# ── 시작 ───────────────────────────────────────────────────
log "=============================================="
log "Signal 파이프라인 시작 | ${TODAY}"
log "=============================================="

START_TS=$(date +%s)

# ═══════════════════════════════════════════════
# PHASE 1-A: collect.py — 뉴스 수집
# ═══════════════════════════════════════════════
log "[1/6] collect.py 실행..."
${PY3} ${DIGEST}/collect.py >> "${LOG_FILE}" 2>&1
EXIT_CODE=$?

if [ ${EXIT_CODE} -ne 0 ]; then
    log "✗ collect.py 실패 (exit ${EXIT_CODE})"
    rm -f "${LOCK_FILE}"
    exit 1
fi

if [ ! -f "/tmp/collected_news.json" ]; then
    log "✗ /tmp/collected_news.json 파일 없음"
    rm -f "${LOCK_FILE}"
    exit 1
fi

COLLECTED=$(python3 -c "import json,sys; d=json.load(open('/tmp/collected_news.json')); print(d.get('count',0))" 2>/dev/null)
log "✓ 수집 완료: ${COLLECTED}건"

# ═══════════════════════════════════════════════
# PHASE 1-B: collect_save.php — DB 저장
# ═══════════════════════════════════════════════
log "[2/6] collect_save.php 실행..."
${PHP} ${DIGEST}/collect_save.php >> "${LOG_FILE}" 2>&1
EXIT_CODE=$?

if [ ${EXIT_CODE} -ne 0 ]; then
    log "✗ collect_save.php 실패 (exit ${EXIT_CODE})"
    rm -f "${LOCK_FILE}"
    exit 2
fi

log "✓ DB 저장 완료"

# ═══════════════════════════════════════════════
# PHASE 2: summarize.php — Gemini AI 요약
# (Rate limit 방지: 5초 간격, 최대 ~2분 소요)
# ═══════════════════════════════════════════════
log "[3/6] summarize.php 실행... (2~3분 소요)"
${PHP} ${DIGEST}/summarize.php >> "${LOG_FILE}" 2>&1
EXIT_CODE=$?

if [ ${EXIT_CODE} -ne 0 ]; then
    log "✗ summarize.php 실패 (exit ${EXIT_CODE})"
    rm -f "${LOCK_FILE}"
    exit 3
fi

# selected 건수 확인 (KST 날짜 기준 — collected_at은 UTC 저장, CONVERT_TZ로 KST 변환)
SELECTED=$(${PHP} -r "
define('BASE_PATH', '${HTDOCS}');
require_once BASE_PATH.'/config.php';
\$pdo = new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset='.DB_CHARSET, DB_USER, DB_PASS);
\$r = \$pdo->query(\"SELECT COUNT(*) FROM ai_news WHERE status='selected' AND DATE(CONVERT_TZ(collected_at,'+00:00','+09:00'))='${TODAY}'\");
echo \$r->fetchColumn();
" 2>/dev/null)

log "✓ 요약 완료: ${SELECTED}건 selected"

if [ "${SELECTED:-0}" -lt 1 ]; then
    log "⚠ selected 기사 없음 (${SELECTED}건). 게시 생략."
    rm -f "${LOCK_FILE}"
    exit 0
fi

# ═══════════════════════════════════════════════
# PHASE 3: publish_news.php — HTML 페이지 생성
# ═══════════════════════════════════════════════
log "[4/6] publish_news.php 실행..."
${PHP} ${DIGEST}/publish_news.php >> "${LOG_FILE}" 2>&1
EXIT_CODE=$?

if [ ${EXIT_CODE} -ne 0 ]; then
    log "✗ publish_news.php 실패 (exit ${EXIT_CODE})"
    rm -f "${LOCK_FILE}"
    exit 4
fi

# ═══════════════════════════════════════════════
# PHASE 4-A: digest_mailer.php — 구독자 이메일 발송
# ═══════════════════════════════════════════════
log "[5/6] digest_mailer.php 실행..."
${PHP} ${DIGEST}/digest_mailer.php >> "${LOG_FILE}" 2>&1
EXIT_CODE=$?

if [ ${EXIT_CODE} -ne 0 ]; then
    log "✗ digest_mailer.php 실패 (exit ${EXIT_CODE})"
    # 발송 실패는 치명적이지 않음 - 파이프라인 계속
else
    log "✓ 이메일 발송 완료"
fi

# ═══════════════════════════════════════════════
# PHASE 4-B: monitor.php — 발송 모니터링
# ═══════════════════════════════════════════════
log "[6/6] monitor.php 실행..."
${PHP} ${DIGEST}/monitor.php >> "${LOG_FILE}" 2>&1
log "✓ 모니터링 완료"

# ── 완료 ───────────────────────────────────────
END_TS=$(date +%s)
ELAPSED=$(( END_TS - START_TS ))

log "=============================================="
log "✅ Signal 파이프라인 완료!"
log "   날짜: ${TODAY}"
log "   게시 URL: https://vibestudio.prisincera.com/signal/${TODAY}"
log "   소요 시간: ${ELAPSED}초"
log "============================================="

# Lock 파일 유지 (오늘 중복 방지용)
exit 0
