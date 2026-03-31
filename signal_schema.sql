-- ============================================================
-- Signal 구독 테이블 스키마 (digest_subs)
-- SIGNAL_GOOGLE_AUTH.md §7 기반
-- 실행: 서버 MariaDB에 1회만 실행할 것
-- ============================================================

-- 1. digest_subs 테이블이 없으면 신규 생성 (safe: 있으면 무시)
CREATE TABLE IF NOT EXISTS digest_subs (
    id                INT AUTO_INCREMENT PRIMARY KEY,
    email             VARCHAR(255)  NOT NULL UNIQUE,
    token             VARCHAR(64)   NOT NULL UNIQUE,
    referral_code     VARCHAR(20)   NOT NULL UNIQUE,
    referred_by       VARCHAR(20)   DEFAULT NULL,
    referral_count    INT           DEFAULT 0,
    categories        VARCHAR(200)  DEFAULT 'all',
    frequency         ENUM('daily','weekly') DEFAULT 'daily',
    status            ENUM('active','unsubscribed','bounced') DEFAULT 'active',
    source            ENUM('google','manual') DEFAULT 'manual',
    google_id         VARCHAR(100)  DEFAULT NULL,
    consent_at        DATETIME      DEFAULT NULL,
    consent_ip        VARCHAR(45)   DEFAULT NULL,
    consent_ua        TEXT          DEFAULT NULL,
    marketing_consent TINYINT(1)    DEFAULT 0,
    subscribed_at     TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    unsubscribed_at   DATETIME      DEFAULT NULL,
    last_sent_at      DATETIME      DEFAULT NULL,
    last_open_at      DATETIME      DEFAULT NULL,
    nps_score         TINYINT       DEFAULT NULL,
    nps_updated_at    DATETIME      DEFAULT NULL,
    INDEX idx_status   (status),
    INDEX idx_referral (referral_code),
    UNIQUE INDEX idx_google_id (google_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. 테이블이 이미 있는 경우 — 컬럼 개별 추가 (IF NOT EXISTS는 MariaDB 10.x 이상 지원)
--    없는 컬럼만 추가하므로 재실행해도 안전
ALTER TABLE digest_subs
    ADD COLUMN IF NOT EXISTS source
        ENUM('google','manual') DEFAULT 'manual'
        COMMENT '구독 신청 경로',
    ADD COLUMN IF NOT EXISTS google_id
        VARCHAR(100) DEFAULT NULL
        COMMENT 'Google 계정 고유 식별자(sub)',
    ADD COLUMN IF NOT EXISTS consent_at
        DATETIME DEFAULT NULL
        COMMENT '개인정보 동의 일시',
    ADD COLUMN IF NOT EXISTS consent_ip
        VARCHAR(45) DEFAULT NULL
        COMMENT '동의 시 IP 주소',
    ADD COLUMN IF NOT EXISTS consent_ua
        TEXT DEFAULT NULL
        COMMENT '동의 시 User-Agent',
    ADD COLUMN IF NOT EXISTS marketing_consent
        TINYINT(1) DEFAULT 0
        COMMENT '마케팅 수신 동의 여부';

-- 3. google_id 유니크 인덱스 (중복 실행 방지)
CREATE UNIQUE INDEX IF NOT EXISTS idx_google_id ON digest_subs (google_id);
