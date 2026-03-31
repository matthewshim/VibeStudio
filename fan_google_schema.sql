-- ==========================================
-- Fan 사전예약 DB 스키마 업데이트
-- Google OAuth 통합을 위한 컬럼 추가
-- 배포 시 1회 실행 (idempotent)
-- ==========================================

-- pre_registrations 테이블이 없다면 생성
CREATE TABLE IF NOT EXISTS pre_registrations (
    id                 INT AUTO_INCREMENT PRIMARY KEY,
    email              VARCHAR(255) NOT NULL UNIQUE,
    auth_time          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    webapp_apply       TINYINT(1)   NOT NULL DEFAULT 0,
    marketing_consent  TINYINT(1)   NOT NULL DEFAULT 0,
    content_subscribe  TINYINT(1)   NOT NULL DEFAULT 0,
    coffee_chat        TINYINT(1)   NOT NULL DEFAULT 0,
    INDEX idx_email    (email),
    INDEX idx_authtime (auth_time)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- google_id 컬럼 추가 (없을 경우에만)
ALTER TABLE pre_registrations
    ADD COLUMN IF NOT EXISTS google_id VARCHAR(255) NULL DEFAULT NULL AFTER coffee_chat;

-- reg_source 컬럼 추가 (없을 경우에만)
ALTER TABLE pre_registrations
    ADD COLUMN IF NOT EXISTS reg_source ENUM('manual','google') NOT NULL DEFAULT 'manual' AFTER google_id;

-- 인덱스 추가 (중복 무시)
ALTER TABLE pre_registrations
    ADD INDEX IF NOT EXISTS idx_reg_source (reg_source);
