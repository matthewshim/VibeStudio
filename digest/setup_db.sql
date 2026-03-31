-- Signal — DB 테이블 생성 스크립트
-- 실행: mysql -u root -p vibe_db < setup_db.sql

-- ── 1. 뉴스 소스 관리 ─────────────────────────────────────
CREATE TABLE IF NOT EXISTS ai_sources (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(100) NOT NULL,
    url             VARCHAR(500) NOT NULL,
    type            ENUM('rss','api','web') DEFAULT 'rss',
    category        VARCHAR(50),
    enabled         TINYINT(1) DEFAULT 1,
    weight          FLOAT DEFAULT 1.0,
    last_fetched_at DATETIME DEFAULT NULL,
    fail_count      INT DEFAULT 0,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 초기 소스 데이터 (4개 우선)
INSERT IGNORE INTO ai_sources (name, url, type, category, weight) VALUES
('arXiv cs.AI',   'https://export.arxiv.org/rss/cs.AI',                                                              'rss', 'research', 1.5),
('Hacker News AI','https://hn.algolia.com/api/v1/search?tags=story&query=AI&numericFilters=points%3E10&hitsPerPage=20','api', 'tech',     1.2),
('OpenAI Blog',   'https://openai.com/blog/rss',                                                                       'rss', 'bigtech',  1.5),
('Google AI Blog','https://blog.google/technology/ai/rss',                                                            'rss', 'bigtech',  1.4),
('VentureBeat AI','https://venturebeat.com/category/ai/feed',                                                         'rss', 'industry', 1.1),
('MIT Tech Review','https://www.technologyreview.com/feed',                                                           'rss', 'research', 1.3),
('The Verge AI',  'https://www.theverge.com/ai-artificial-intelligence/rss/index.xml',                                'rss', 'industry', 1.0);

-- ── 2. 수집된 원본 뉴스 ───────────────────────────────────
CREATE TABLE IF NOT EXISTS ai_news (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    title        VARCHAR(500) NOT NULL,
    url          VARCHAR(1000) NOT NULL UNIQUE,
    source_id    INT DEFAULT NULL,
    source_name  VARCHAR(100),
    category     VARCHAR(50),
    score        FLOAT DEFAULT 0,
    hn_points    INT DEFAULT 0,
    summary_ko   TEXT,
    published_at DATETIME,
    collected_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    status       ENUM('pending','summarized','selected','sent','skipped') DEFAULT 'pending',
    INDEX idx_status     (status),
    INDEX idx_collected  (collected_at),
    INDEX idx_score      (score DESC),
    FOREIGN KEY (source_id) REFERENCES ai_sources(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── 3. 발행된 Signal 페이지 ───────────────────────────────
CREATE TABLE IF NOT EXISTS news_pages (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    publish_date DATE NOT NULL UNIQUE,
    file_path    VARCHAR(300),
    news_ids     TEXT,           -- 포함된 ai_news ID (JSON 배열)
    editor_note  TEXT,           -- 편집장 한마디
    published_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_date (publish_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── 4. 발송 로그 ──────────────────────────────────────────
CREATE TABLE IF NOT EXISTS digest_logs (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    sent_date    DATE NOT NULL,
    subject_line VARCHAR(300),
    total_subs   INT DEFAULT 0,
    sent_count   INT DEFAULT 0,
    fail_count   INT DEFAULT 0,
    news_ids     TEXT,
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_date (sent_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── 5. 구독자 (추후 구독 시스템 구현 시 활성화) ───────────
CREATE TABLE IF NOT EXISTS digest_subs (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    email           VARCHAR(255) NOT NULL UNIQUE,
    token           VARCHAR(64)  NOT NULL UNIQUE,
    status          ENUM('active','unsubscribed','bounced') DEFAULT 'active',
    subscribed_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_sent_at    DATETIME DEFAULT NULL,
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
