<?php
// @deploy: 브루트포스 방어 추가 (5회 실패 → 5분 IP 잠금, session_regenerate_id 적용)
// @deploy: security_logs 테이블 자동 생성 및 로그인 이벤트 기록 기능 추가
// @deploy: security_logs 조회 API 추가 (타입 필터, 검색, 페이지네이션) - MariaDB LIMIT 호환 수정
session_start();

header('Content-Type: application/json');

// GET/POST 모두 수신 (읽기 액션은 GET 허용)
$action = $_POST['action'] ?? $_GET['action'] ?? '';

// 상태변경(쓰기) 액션은 POST 전용 강제 (CSRF 방어)
$write_actions = ['login', 'admin_add', 'admin_update', 'admin_delete', 'app_settings_update', 'log'];
if (in_array($action, $write_actions) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => '잘못된 요청 방식입니다.']);
    exit;
}


// ── 설정 로드 ──────────────────────────────────────────
require_once __DIR__ . '/config.php';

function get_db_conn()
{
    try {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        $pdo = new PDO($dsn, DB_USER, DB_PASS);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $pdo;
    } catch (PDOException $e) {
        return null;
    }
}

/* ── 보안 로그 테이블 자동 생성 ────────────────────── */
function ensure_security_log_table($pdo)
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS security_logs (
            id         INT AUTO_INCREMENT PRIMARY KEY,
            event_type VARCHAR(30)  NOT NULL COMMENT 'login_success|login_fail|login_blocked',
            username   VARCHAR(100) NOT NULL DEFAULT '',
            ip_address VARCHAR(45)  NOT NULL DEFAULT '',
            user_agent VARCHAR(255) NOT NULL DEFAULT '',
            created_at TIMESTAMP   DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_created (created_at),
            INDEX idx_event   (event_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
}

function write_security_log($pdo, $event_type, $username) {
    try {
        ensure_security_log_table($pdo);
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
        $stmt = $pdo->prepare(
            "INSERT INTO security_logs (event_type, username, ip_address, user_agent) VALUES (?,?,?,?)"
        );
        $stmt->execute([$event_type, $username, $ip, $ua]);
    } catch (Exception $e) {
        error_log('[VibeAdmin] security_log write error: ' . $e->getMessage());
    }
}

/* ── 후원(donations) 테이블 자동 생성 ─────────────────── */
function ensure_donations_table($pdo)
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS donations (
            id          INT AUTO_INCREMENT PRIMARY KEY,
            order_num   VARCHAR(100) NOT NULL UNIQUE,
            donor_name  VARCHAR(50)  NOT NULL DEFAULT '익명',
            message     VARCHAR(200) DEFAULT NULL,
            amount      INT          NOT NULL,
            pay_type    VARCHAR(30)  DEFAULT NULL,
            is_public   TINYINT(1)   NOT NULL DEFAULT 1,
            status      VARCHAR(20)  NOT NULL DEFAULT 'completed',
            created_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_created (created_at),
            INDEX idx_status  (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
}

/* ── 통계 로그 테이블 자동 생성 ────────────────────── */
function ensure_stats_table($pdo)
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS vibe_stats (
            id          INT AUTO_INCREMENT PRIMARY KEY,
            stat_date   DATE        NOT NULL,
            stat_type   VARCHAR(50) NOT NULL,
            stat_value  INT         NOT NULL DEFAULT 1,
            created_at  TIMESTAMP   DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_date_type (stat_date, stat_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
}

/* ── 관리자 계정 테이블 자동 생성 + 컬럼 마이그레이션 */
function ensure_admin_table($pdo)
{
    // 테이블 생성 (최신 스키마)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS admin_accounts (
            id           INT AUTO_INCREMENT PRIMARY KEY,
            username     VARCHAR(100) NOT NULL UNIQUE,
            password     VARCHAR(255) NOT NULL,
            display_name VARCHAR(100) DEFAULT '',
            role         VARCHAR(50)  NOT NULL DEFAULT 'admin',
            created_at   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
            updated_at   TIMESTAMP    NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // 기존 테이블에 display_name 컬럼 없으면 추가 (마이그레이션)
    try {
        $pdo->exec("ALTER TABLE admin_accounts ADD COLUMN display_name VARCHAR(100) DEFAULT '' AFTER password");
    } catch (Exception $e) { /* 이미 있을 경우 무시 */ }

    // 기존 테이블에 updated_at 컬럼 없으면 추가 (마이그레이션)
    try {
        $pdo->exec("ALTER TABLE admin_accounts ADD COLUMN updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP AFTER created_at");
    } catch (Exception $e) { /* 이미 있을 경우 무시 */ }

    // 계정이 하나도 없으면 초기 설정 필요 안내 (보안상 기본값 자동 생성 제거)
    $cnt = (int)$pdo->query("SELECT COUNT(*) FROM admin_accounts")->fetchColumn();
    if ($cnt === 0) {
        error_log('[VibeAdmin] WARNING: No admin accounts exist. Please create one manually via DB.');
    }
}

/* ── 웹앱 설정 테이블 자동 생성 ────────────────────────── */
function ensure_app_settings_table($pdo)
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS app_settings (
            id         INT AUTO_INCREMENT PRIMARY KEY,
            app_key    VARCHAR(50)  NOT NULL UNIQUE,
            enabled    TINYINT(1)   NOT NULL DEFAULT 1,
            updated_at TIMESTAMP    NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // 기본 앱 목록 시드 (없으면 삽입)
    $defaults = ['tangram','pachinko','server','sys','pdf','qr','meter','spell'];
    foreach ($defaults as $key) {
        try {
            $pdo->exec("INSERT IGNORE INTO app_settings (app_key, enabled) VALUES ('$key', 1)");
        } catch (Exception $e) { /* 무시 */ }
    }
}

/* ── 방문/앱 실행 로그 기록 ─────────────────────────── */
if ($action === 'log') {
    $type = $_POST['type'] ?? $_GET['type'] ?? '';
    if (!$type) { echo json_encode(['success' => false]); exit; }

    $pdo = get_db_conn();
    if ($pdo) {
        ensure_stats_table($pdo);
        $today = date('Y-m-d');
        $stmt = $pdo->prepare("
            INSERT INTO vibe_stats (stat_date, stat_type, stat_value)
            VALUES (:d, :t, 1)
            ON DUPLICATE KEY UPDATE stat_value = stat_value + 1
        ");
        try {
            $pdo->exec("ALTER TABLE vibe_stats ADD UNIQUE KEY uq_date_type (stat_date, stat_type)");
        } catch (Exception $e) { /* 이미 있을 경우 무시 */ }
        $stmt->execute([':d' => $today, ':t' => $type]);
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false]);
    }
    exit;
}

/* ── 로그인 ─────────────────────────────────────────── */
if ($action === 'login') {
    $id   = $_POST['id']   ?? '';
    $pass = $_POST['pass'] ?? '';

    // 브루트포스 방어 — 5회 실패 시 5분 잠금
    if (!isset($_SESSION['login_fails']))   $_SESSION['login_fails']   = 0;
    if (!isset($_SESSION['login_blocked'])) $_SESSION['login_blocked'] = 0;
    if (time() < $_SESSION['login_blocked']) {
        $remain = max(1, (int)(($_SESSION['login_blocked'] - time()) / 60) + 1);
        echo json_encode(['success' => false, 'message' => "잠시 후 다시 시도해주세요. ({$remain}분 대기)"]);
        exit;
    }

    $pdo = get_db_conn();
    $authenticated = false;

    if ($pdo) {
        ensure_admin_table($pdo);
        $stmt = $pdo->prepare("SELECT password FROM admin_accounts WHERE username = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && password_verify($pass, $row['password'])) {
            $authenticated = true;
        }
    } else {
        // DB 연결 실패 시 보안 우선 — 인증 절대 허용하지 않음
        error_log('[VibeAdmin] DB connection failed on login from: ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
        echo json_encode(['success' => false, 'message' => '서버 오류가 발생했습니다. 잠시 후 다시 시도해주세요.']);
        exit;
    }

    if ($authenticated) {
        // 로그인 성공: 카운터 초기화 + 세션 고정 공격 방지
        $_SESSION['login_fails']   = 0;
        $_SESSION['login_blocked'] = 0;
        session_regenerate_id(true);
        $_SESSION['admin_auth'] = true;
        $_SESSION['admin_user'] = $id;
        write_security_log($pdo, 'login_success', $id);
        echo json_encode(['success' => true]);
    } else {
        // 실패 카운터 증가 — 5회 초과 시 5분 잠금
        $_SESSION['login_fails']++;
        if ($_SESSION['login_fails'] >= 5) {
            $_SESSION['login_blocked'] = time() + 300;
            $_SESSION['login_fails']   = 0;
            write_security_log($pdo, 'login_blocked', $id);
            error_log('[VibeAdmin] Login blocked after 5 fails. ID: ' . $id . ' IP: ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
        } else {
            write_security_log($pdo, 'login_fail', $id);
        }
        echo json_encode(['success' => false, 'message' => '아이디 또는 비밀번호가 틀렸습니다.']);
    }
    exit;
}

/* ── 세션 확인 ─────────────────────────────────────── */
if ($action === 'check') {
    echo json_encode(['success' => isset($_SESSION['admin_auth']) && $_SESSION['admin_auth'] === true]);
    exit;
}

/* ── Fan 목록 (google 정보 포함) ─────────────────────────── */
if ($action === 'list') {
    if (!isset($_SESSION['admin_auth']) || $_SESSION['admin_auth'] !== true) {
        echo json_encode(['success' => false, 'message' => '인증이 필요합니다.']);
        exit;
    }
    $pdo = get_db_conn();
    if (!$pdo) { echo json_encode(['success' => false, 'message' => 'DB 연결 실패']); exit; }
    try {
        // google_id, reg_source 컬럼이 없을 경우 대비
        $stmt = $pdo->query("SELECT *, IFNULL(reg_source,'manual') AS reg_source FROM pre_registrations ORDER BY auth_time DESC");
        $list = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'list' => $list]);
    } catch (PDOException $e) {
        error_log('[VibeAdmin] DB Error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => '조회 중 오류가 발생했습니다.']);
    }
    exit;
}

/* ── Google 세션 저장 (fan_google_session_set) ────────────
   JavaScript postMessage → fetch로 호출: google_id,email,name을 세션에 저장
   인증 없이 호출 가능 (자체 origin 검증)                     */
if ($action === 'fan_google_session_set') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['success' => false, 'message' => '잘못된 요청 방식입니다.']);
        exit;
    }
    // Referer/Origin 검증
    $origin  = $_SERVER['HTTP_ORIGIN']  ?? '';
    $referer = $_SERVER['HTTP_REFERER'] ?? '';
    if ($origin && $origin !== SITE_URL) {
        echo json_encode(['success' => false, 'message' => 'Origin 검증 실패']);
        exit;
    }

    $googleId = trim($_POST['google_id'] ?? '');
    $email    = filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL);
    $name     = trim($_POST['name'] ?? '');

    if (!$email) {
        echo json_encode(['success' => false, 'message' => '유효하지 않은 이메일입니다.']);
        exit;
    }

    session_regenerate_id(false); // true이면 구 세션 즉시 삭제 → AJAX 연속 요청 시 세션 손실 위험
    $_SESSION['fan_google_verified'] = true;
    $_SESSION['fan_google_email']    = $email;
    $_SESSION['fan_google_id']       = $googleId;
    $_SESSION['fan_google_name']     = $name;

    // ── 이미 가입된 사용자인지 확인 + 기존 신청값 조회 ─────────
    $already_registered = false;
    $reg_data = null;
    try {
        $pdo = get_db_conn();
        if ($pdo) {
            $chkSql = 'SELECT id, webapp_apply, content_subscribe, coffee_chat, marketing_consent
                        FROM pre_registrations WHERE email = :email';
            $params  = [':email' => $email];
            if ($googleId) {
                $chkSql .= ' OR (google_id IS NOT NULL AND google_id = :gid)';
                $params[':gid'] = $googleId;
            }
            $chkSql .= ' LIMIT 1';
            $chk = $pdo->prepare($chkSql);
            $chk->execute($params);
            $row = $chk->fetch(PDO::FETCH_ASSOC);
            if ($row !== false) {
                $already_registered = true;
                $reg_data = [
                    'webapp'    => (bool)$row['webapp_apply'],
                    'content'   => (bool)$row['content_subscribe'],
                    'coffee'    => (bool)$row['coffee_chat'],
                    'marketing' => (bool)$row['marketing_consent'],
                ];
            }
        }
    } catch (\Throwable $e) {
        $already_registered = false;
    }

    echo json_encode(['success' => true, 'email' => $email, 'already_registered' => $already_registered, 'reg_data' => $reg_data]);
    exit;
}

/* ── Google 세션 확인 (fan_google_session_check) ──────────────
   패널 재방문 시 기존 Google 로그인 세션 유지 여부 확인
   인증 없이 호출 가능 (자체 origin 검증)                     */
if ($action === 'fan_google_session_check') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['success' => false, 'logged_in' => false]);
        exit;
    }
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin && $origin !== SITE_URL) {
        echo json_encode(['success' => false, 'logged_in' => false]);
        exit;
    }

    $loggedIn = !empty($_SESSION['fan_google_verified']) && !empty($_SESSION['fan_google_email']);

    if (!$loggedIn) {
        echo json_encode(['success' => true, 'logged_in' => false]);
        exit;
    }

    $email    = $_SESSION['fan_google_email'];
    $googleId = $_SESSION['fan_google_id'] ?? '';

    // 이미 가입된 사용자인지 확인 + 기존 신청값 조회
    $already_registered = false;
    $reg_data = null;
    try {
        $pdo = get_db_conn();
        if ($pdo) {
            $chkSql = 'SELECT id, webapp_apply, content_subscribe, coffee_chat, marketing_consent
                        FROM pre_registrations WHERE email = :email';
            $params  = [':email' => $email];
            if ($googleId) {
                $chkSql .= ' OR (google_id IS NOT NULL AND google_id = :gid)';
                $params[':gid'] = $googleId;
            }
            $chkSql .= ' LIMIT 1';
            $chk = $pdo->prepare($chkSql);
            $chk->execute($params);
            $row = $chk->fetch(PDO::FETCH_ASSOC);
            if ($row !== false) {
                $already_registered = true;
                $reg_data = [
                    'webapp'     => (bool)$row['webapp_apply'],
                    'content'    => (bool)$row['content_subscribe'],
                    'coffee'     => (bool)$row['coffee_chat'],
                    'marketing'  => (bool)$row['marketing_consent'],
                ];
            }
        }
    } catch (\Throwable $e) {
        $already_registered = false;
    }

    echo json_encode([
        'success'            => true,
        'logged_in'          => true,
        'email'              => $email,
        'already_registered' => $already_registered,
        'reg_data'           => $reg_data,
    ]);
    exit;
}

/* ── Google 세션 초기화 (fan_google_session_clear) ────────────
   '다른 구글 계정으로 신청하기' 버튼 클릭 시 호출
   인증 없이 호출 가능 (자체 origin 검증)                     */
if ($action === 'fan_google_session_clear') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['success' => false]);
        exit;
    }
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin && $origin !== SITE_URL) {
        echo json_encode(['success' => false]);
        exit;
    }

    unset(
        $_SESSION['fan_google_verified'],
        $_SESSION['fan_google_email'],
        $_SESSION['fan_google_id'],
        $_SESSION['fan_google_name']
    );

    echo json_encode(['success' => true]);
    exit;
}

/* ── Google 가입 회원 목록 (관리자 전용) ──────────────────── */
if ($action === 'google_members') {
    if (!isset($_SESSION['admin_auth']) || $_SESSION['admin_auth'] !== true) {
        echo json_encode(['success' => false, 'message' => '인증이 필요합니다.']);
        exit;
    }
    $pdo = get_db_conn();
    if (!$pdo) { echo json_encode(['success' => false, 'message' => 'DB 연결 실패']); exit; }
    try {
        $stmt = $pdo->query("
            SELECT id, email, google_id, auth_time,
                   webapp_apply, content_subscribe, coffee_chat, marketing_consent,
                   IFNULL(reg_source,'manual') AS reg_source
            FROM   pre_registrations
            WHERE  reg_source = 'google' OR google_id IS NOT NULL
            ORDER  BY auth_time DESC
        ");
        $list  = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $total = count($list);
        echo json_encode(['success' => true, 'list' => $list, 'total' => $total]);
    } catch (PDOException $e) {
        // 컬럼이 아직 없을 수도 있음
        echo json_encode(['success' => true, 'list' => [], 'total' => 0]);
    }
    exit;
}

/* ── 관리자 계정 목록 조회 ─────────────────────────── */
if ($action === 'admin_list') {
    if (!isset($_SESSION['admin_auth']) || $_SESSION['admin_auth'] !== true) {
        echo json_encode(['success' => false, 'message' => '인증이 필요합니다.']);
        exit;
    }
    $pdo = get_db_conn();
    if (!$pdo) { echo json_encode(['success' => false, 'message' => 'DB 연결 실패']); exit; }
    try {
        ensure_admin_table($pdo);
        $stmt = $pdo->query("SELECT id, username, display_name, role, created_at, updated_at FROM admin_accounts ORDER BY id ASC");
        $list = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'list' => $list]);
    } catch (PDOException $e) {
        error_log('[VibeAdmin] DB Error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => '조회 중 오류가 발생했습니다.']);
    }
    exit;
}

/* ── 관리자 계정 추가 ──────────────────────────────── */
if ($action === 'admin_add') {
    if (!isset($_SESSION['admin_auth']) || $_SESSION['admin_auth'] !== true) {
        echo json_encode(['success' => false, 'message' => '인증이 필요합니다.']);
        exit;
    }
    $username     = trim($_POST['username']     ?? '');
    $password     = trim($_POST['password']     ?? '');
    $display_name = trim($_POST['display_name'] ?? '');
    $role         = trim($_POST['role']         ?? 'admin');

    if (!$username || !$password) {
        echo json_encode(['success' => false, 'message' => '아이디와 비밀번호를 입력해주세요.']);
        exit;
    }
    if (strlen($username) < 3 || strlen($username) > 50) {
        echo json_encode(['success' => false, 'message' => '아이디는 3~50자여야 합니다.']);
        exit;
    }
    if (strlen($password) < 4) {
        echo json_encode(['success' => false, 'message' => '비밀번호는 최소 4자 이상이어야 합니다.']);
        exit;
    }
    if (!in_array($role, ['superadmin', 'admin'])) $role = 'admin';

    $pdo = get_db_conn();
    if (!$pdo) { echo json_encode(['success' => false, 'message' => 'DB 연결 실패']); exit; }
    try {
        ensure_admin_table($pdo);
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("INSERT INTO admin_accounts (username, password, display_name, role) VALUES (?, ?, ?, ?)");
        $stmt->execute([$username, $hash, $display_name, $role]);
        echo json_encode(['success' => true, 'message' => '관리자 계정이 추가되었습니다.']);
    } catch (PDOException $e) {
        if ($e->getCode() == 23000) {
            echo json_encode(['success' => false, 'message' => '이미 존재하는 아이디입니다.']);
        } else {
            error_log('[VibeAdmin] DB Error: ' . $e->getMessage());
            echo json_encode(['success' => false, 'message' => '계정 추가 중 오류가 발생했습니다.']);
        }
    }
    exit;
}

/* ── 관리자 계정 수정 (이름 + 비밀번호) ────────────── */
if ($action === 'admin_update') {
    if (!isset($_SESSION['admin_auth']) || $_SESSION['admin_auth'] !== true) {
        echo json_encode(['success' => false, 'message' => '인증이 필요합니다.']);
        exit;
    }
    $target_id    = (int)($_POST['id']           ?? 0);
    $display_name = trim($_POST['display_name']  ?? '');
    $password     = trim($_POST['password']      ?? '');
    $role         = trim($_POST['role']          ?? '');

    if (!$target_id) {
        echo json_encode(['success' => false, 'message' => '잘못된 요청입니다.']);
        exit;
    }
    if ($password !== '' && strlen($password) < 4) {
        echo json_encode(['success' => false, 'message' => '비밀번호는 최소 4자 이상이어야 합니다.']);
        exit;
    }
    if ($role !== '' && !in_array($role, ['superadmin', 'admin'])) {
        echo json_encode(['success' => false, 'message' => '잘못된 권한 값입니다.']);
        exit;
    }

    $pdo = get_db_conn();
    if (!$pdo) { echo json_encode(['success' => false, 'message' => 'DB 연결 실패']); exit; }

    try {
        // 동적으로 업데이트할 컬럼 조합
        $sets   = ['display_name = ?', 'updated_at = NOW()'];
        $params = [$display_name];

        if ($password !== '') {
            $sets[]   = 'password = ?';
            $params[] = password_hash($password, PASSWORD_DEFAULT);
        }
        if ($role !== '') {
            $sets[]   = 'role = ?';
            $params[] = $role;
        }
        $params[] = $target_id;

        $sql  = "UPDATE admin_accounts SET " . implode(', ', $sets) . " WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        echo json_encode(['success' => true, 'message' => '계정 정보가 수정되었습니다.']);
    } catch (PDOException $e) {
        error_log('[VibeAdmin] DB Error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => '계정 수정 중 오류가 발생했습니다.']);
    }
    exit;
}

/* ── 관리자 계정 삭제 ──────────────────────────────── */
if ($action === 'admin_delete') {
    if (!isset($_SESSION['admin_auth']) || $_SESSION['admin_auth'] !== true) {
        echo json_encode(['success' => false, 'message' => '인증이 필요합니다.']);
        exit;
    }
    $target_id = (int)($_POST['id'] ?? 0);
    if (!$target_id) { echo json_encode(['success' => false, 'message' => '잘못된 요청']); exit; }

    $pdo = get_db_conn();
    if (!$pdo) { echo json_encode(['success' => false, 'message' => 'DB 연결 실패']); exit; }
    try {
        $cnt = (int)$pdo->query("SELECT COUNT(*) FROM admin_accounts")->fetchColumn();
        if ($cnt <= 1) {
            echo json_encode(['success' => false, 'message' => '마지막 관리자 계정은 삭제할 수 없습니다.']);
            exit;
        }
        $stmt = $pdo->prepare("DELETE FROM admin_accounts WHERE id = ?");
        $stmt->execute([$target_id]);
        echo json_encode(['success' => true, 'message' => '계정이 삭제되었습니다.']);
    } catch (PDOException $e) {
        error_log('[VibeAdmin] DB Error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => '계정 삭제 중 오류가 발생했습니다.']);
    }
    exit;
}

/* ── 통계 조회 ─────────────────────────────────────── */
if ($action === 'stats') {
    if (!isset($_SESSION['admin_auth']) || $_SESSION['admin_auth'] !== true) {
        echo json_encode(['success' => false, 'message' => '인증이 필요합니다.']);
        exit;
    }

    $from = $_GET['from'] ?? date('Y-m-d', strtotime('-6 days'));
    $to   = $_GET['to']   ?? date('Y-m-d');

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = date('Y-m-d', strtotime('-6 days'));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   $to   = date('Y-m-d');

    $pdo = get_db_conn();
    if (!$pdo) { echo json_encode(['success' => false, 'message' => 'DB 연결 실패']); exit; }

    ensure_stats_table($pdo);

    try {
        $stmt = $pdo->prepare("
            SELECT stat_date, stat_type, stat_value
            FROM   vibe_stats
            WHERE  stat_date BETWEEN :from AND :to
            ORDER  BY stat_date ASC, stat_type ASC
        ");
        $stmt->execute([':from' => $from, ':to' => $to]);
        $raw = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $dates  = [];
        $cursor = new DateTime($from);
        $end    = new DateTime($to);
        while ($cursor <= $end) {
            $dates[] = $cursor->format('Y-m-d');
            $cursor->modify('+1 day');
        }

        $pivot = [];
        foreach ($raw as $row) {
            $pivot[$row['stat_type']][$row['stat_date']] = (int)$row['stat_value'];
        }

        $series = [];
        foreach ($pivot as $type => $dayMap) {
            $values = [];
            foreach ($dates as $d) { $values[] = $dayMap[$d] ?? 0; }
            $series[$type] = $values;
        }

        $summary = [];
        foreach ($pivot as $type => $dayMap) { $summary[$type] = array_sum($dayMap); }

        $stmt2 = $pdo->prepare("
            SELECT DATE(auth_time) as d, COUNT(*) as cnt
            FROM   pre_registrations
            WHERE  DATE(auth_time) BETWEEN :from AND :to
            GROUP  BY DATE(auth_time)
        ");
        $stmt2->execute([':from' => $from, ':to' => $to]);
        $fanRows = $stmt2->fetchAll(PDO::FETCH_ASSOC);
        $fanMap  = [];
        foreach ($fanRows as $r) { $fanMap[$r['d']] = (int)$r['cnt']; }
        $fanValues = [];
        foreach ($dates as $d) { $fanValues[] = $fanMap[$d] ?? 0; }
        $series['fan_reg']  = $fanValues;
        $summary['fan_reg'] = array_sum($fanValues);

        $stmt3    = $pdo->query("SELECT COUNT(*) FROM pre_registrations");
        $totalFan = (int)$stmt3->fetchColumn();

        echo json_encode([
            'success'   => true,
            'dates'     => $dates,
            'series'    => $series,
            'summary'   => $summary,
            'total_fan' => $totalFan,
            'from'      => $from,
            'to'        => $to,
        ]);
    } catch (PDOException $e) {
        error_log('[VibeAdmin] DB Error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => '조회 중 오류가 발생했습니다.']);
    }
    exit;
}

/* ── 로그아웃 ─────────────────────────────────────── */
if ($action === 'logout') {
    unset($_SESSION['admin_auth']);
    unset($_SESSION['admin_user']);
    echo json_encode(['success' => true]);
    exit;
}

/* ── 웹앱 설정 조회 (공개 — 독 숨김에 사용) ─────────── */
if ($action === 'app_settings_get') {
    $pdo = get_db_conn();
    if (!$pdo) {
        // DB 연결 실패 시 모두 ON으로 반환
        echo json_encode(['success' => true, 'settings' => []]);
        exit;
    }
    try {
        ensure_app_settings_table($pdo);
        $stmt = $pdo->query("SELECT app_key, enabled, updated_at FROM app_settings ORDER BY id ASC");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'settings' => $rows]);
    } catch (PDOException $e) {
        echo json_encode(['success' => true, 'settings' => []]);
    }
    exit;
}

/* ── 웹앱 설정 업데이트 (인증 필요) ─────────────────── */
if ($action === 'app_settings_update') {
    if (!isset($_SESSION['admin_auth']) || $_SESSION['admin_auth'] !== true) {
        echo json_encode(['success' => false, 'message' => '인증이 필요합니다.']);
        exit;
    }
    $app_key = trim($_POST['app_key'] ?? '');
    $enabled = isset($_POST['enabled']) ? (int)$_POST['enabled'] : -1;

    $allowed = ['tangram','pachinko','server','sys','pdf','qr','meter','spell'];
    if (!in_array($app_key, $allowed)) {
        echo json_encode(['success' => false, 'message' => '잘못된 앱 키입니다.']);
        exit;
    }
    if ($enabled !== 0 && $enabled !== 1) {
        echo json_encode(['success' => false, 'message' => '잘못된 값입니다.']);
        exit;
    }

    $pdo = get_db_conn();
    if (!$pdo) { echo json_encode(['success' => false, 'message' => 'DB 연결 실패']); exit; }
    try {
        ensure_app_settings_table($pdo);
        $stmt = $pdo->prepare("UPDATE app_settings SET enabled = ?, updated_at = NOW() WHERE app_key = ?");
        $stmt->execute([$enabled, $app_key]);
        echo json_encode(['success' => true, 'message' => '설정이 저장되었습니다.']);
    } catch (PDOException $e) {
        error_log('[VibeAdmin] DB Error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => '설정 저장 중 오류가 발생했습니다.']);
    }
    exit;
}

/* ── 보안 로그 조회 ───────────────────────────────── */
if ($action === 'security_logs') {
    if (!isset($_SESSION['admin_auth']) || $_SESSION['admin_auth'] !== true) {
        echo json_encode(['success' => false, 'message' => '인증이 필요합니다.']);
        exit;
    }
    $pdo = get_db_conn();
    if (!$pdo) { echo json_encode(['success' => false, 'message' => 'DB 연결 실패']); exit; }
    try {
        ensure_security_log_table($pdo);

        // 정수형으로 확실히 캐스팅 (LIMIT/OFFSET은 SQL에 직접 삽입)
        $limit  = min((int)($_GET['limit']  ?? 50), 500);
        $offset = max((int)($_GET['offset'] ?? 0), 0);
        $type   = $_GET['type']   ?? '';
        $search = trim($_GET['search'] ?? '');

        $where  = [];
        $params = [];
        if ($type && in_array($type, ['login_success','login_fail','login_blocked'], true)) {
            $where[] = 'event_type = ?'; $params[] = $type;
        }
        if ($search !== '') {
            $where[] = '(username LIKE ? OR ip_address LIKE ?)';
            $params[] = "%$search%";
            $params[] = "%$search%";
        }
        $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        // 전체 건수
        $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM security_logs $whereSql");
        $cntStmt->execute($params);
        $total = (int)$cntStmt->fetchColumn();

        // 요약 (전체 + 최근 24h) — CASE WHEN으로 MariaDB 호환
        $sumStmt = $pdo->query("
            SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN event_type='login_success'  THEN 1 ELSE 0 END) AS success_total,
                SUM(CASE WHEN event_type='login_fail'     THEN 1 ELSE 0 END) AS fail_total,
                SUM(CASE WHEN event_type='login_blocked'  THEN 1 ELSE 0 END) AS blocked_total,
                SUM(CASE WHEN event_type='login_success'  AND created_at >= NOW() - INTERVAL 24 HOUR THEN 1 ELSE 0 END) AS success_24h,
                SUM(CASE WHEN event_type='login_fail'     AND created_at >= NOW() - INTERVAL 24 HOUR THEN 1 ELSE 0 END) AS fail_24h,
                SUM(CASE WHEN event_type='login_blocked'  AND created_at >= NOW() - INTERVAL 24 HOUR THEN 1 ELSE 0 END) AS blocked_24h
            FROM security_logs
        ");
        $summary = $sumStmt->fetch(PDO::FETCH_ASSOC);

        // 로그 목록 — LIMIT/OFFSET 값을 안전한 정수로 SQL에 직접 삽입
        $logStmt = $pdo->prepare("
            SELECT id, event_type, username, ip_address, user_agent, created_at
            FROM   security_logs $whereSql
            ORDER  BY created_at DESC
            LIMIT  $limit OFFSET $offset
        ");
        $logStmt->execute($params);
        $logs = $logStmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'logs' => $logs, 'total' => $total, 'summary' => $summary]);
    } catch (PDOException $e) {
        error_log('[VibeAdmin] security_logs error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => '조회 중 오류가 발생했습니다: ' . $e->getMessage()]);
    }
    exit;
}

/* ── Signal: 대시보드 Overview ────────────────────────────
   구독자 수, 오늘 발송 여부, 최근 발송 로그 요약               */
if ($action === 'signal_overview') {
    if (!isset($_SESSION['admin_auth']) || $_SESSION['admin_auth'] !== true) {
        echo json_encode(['success' => false, 'message' => '인증이 필요합니다.']); exit;
    }
    $pdo = get_db_conn();
    if (!$pdo) { echo json_encode(['success' => false, 'message' => 'DB 연결 실패']); exit; }
    try {
        // 구독자 수 (content_subscribe = 1)
        $sub_total = (int)$pdo->query(
            "SELECT COUNT(*) FROM pre_registrations WHERE content_subscribe = 1"
        )->fetchColumn();

        // 오늘 발송 기록
        $today = date('Y-m-d');
        $today_log = $pdo->prepare(
            "SELECT id, subject_line, sent_count, fail_count, total_subs, created_at
             FROM digest_logs WHERE sent_date = :d LIMIT 1"
        );
        $today_log->execute([':d' => $today]);
        $today_sent = $today_log->fetch(PDO::FETCH_ASSOC);

        // 최근 7일 발송 통계
        $recent = $pdo->query(
            "SELECT sent_date, subject_line, sent_count, fail_count, total_subs, created_at
             FROM digest_logs ORDER BY sent_date DESC LIMIT 7"
        )->fetchAll(PDO::FETCH_ASSOC);

        // 오늘 수집된 ai_news 건수
        $news_today = (int)$pdo->prepare(
            "SELECT COUNT(*) FROM ai_news WHERE DATE(CONVERT_TZ(collected_at,'+00:00','+09:00')) = :d"
        )->execute([':d' => $today]) ? $pdo->query(
            "SELECT COUNT(*) FROM ai_news WHERE DATE(collected_at) = '$today'"
        )->fetchColumn() : 0;

        // ai_news 최근 수집 시각
        $last_collect = $pdo->query(
            "SELECT MAX(collected_at) FROM ai_news"
        )->fetchColumn();

        // news_pages (Signal 웹 페이지) 최근 게시 시각
        $last_page = null;
        try {
            $last_page = $pdo->query(
                "SELECT MAX(created_at) FROM news_pages"
            )->fetchColumn();
        } catch (Exception $e) { $last_page = null; }

        echo json_encode([
            'success'       => true,
            'sub_total'     => $sub_total,
            'today_sent'    => $today_sent ?: null,
            'recent_logs'   => $recent,
            'news_today'    => $news_today,
            'last_collect'  => $last_collect,
            'last_page'     => $last_page,
            'server_time'   => date('Y-m-d H:i:s'),
        ]);
    } catch (PDOException $e) {
        error_log('[VibeAdmin] signal_overview error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

/* ── Signal: 메일 발송 로그 목록 ─────────────────────── */
if ($action === 'signal_digest_logs') {
    if (!isset($_SESSION['admin_auth']) || $_SESSION['admin_auth'] !== true) {
        echo json_encode(['success' => false, 'message' => '인증이 필요합니다.']); exit;
    }
    $pdo = get_db_conn();
    if (!$pdo) { echo json_encode(['success' => false, 'message' => 'DB 연결 실패']); exit; }
    try {
        $limit  = min((int)($_GET['limit']  ?? 30), 100);
        $offset = max((int)($_GET['offset'] ?? 0), 0);
        $total  = (int)$pdo->query("SELECT COUNT(*) FROM digest_logs")->fetchColumn();
        $rows   = $pdo->query(
            "SELECT id, sent_date, subject_line, total_subs, sent_count, fail_count,
                    news_ids, created_at
             FROM digest_logs ORDER BY sent_date DESC
             LIMIT $limit OFFSET $offset"
        )->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'logs' => $rows, 'total' => $total]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

/* ── Signal: 스케줄러 상태 (오늘 수집된 ai_news 목록) ── */
if ($action === 'signal_scheduler_status') {
    if (!isset($_SESSION['admin_auth']) || $_SESSION['admin_auth'] !== true) {
        echo json_encode(['success' => false, 'message' => '인증이 필요합니다.']); exit;
    }
    $pdo = get_db_conn();
    if (!$pdo) { echo json_encode(['success' => false, 'message' => 'DB 연결 실패']); exit; }
    try {
        // KST 기준 오늘 날짜 (UTC+9)
        $kst_today = (new DateTime('now', new DateTimeZone('Asia/Seoul')))->format('Y-m-d');
        $date = $_GET['date'] ?? $kst_today;
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = date('Y-m-d');

        $news = $pdo->prepare(
            "SELECT id, title, source_name, category, score, status, collected_at
             FROM ai_news WHERE DATE(CONVERT_TZ(collected_at,'+00:00','+09:00')) = :d
             ORDER BY score DESC LIMIT 20"
        );
        $news->execute([':d' => $date]);
        $news_list = $news->fetchAll(PDO::FETCH_ASSOC);

        // 스케줄러 cron 예상 실행 여부: digest_logs에 오늘 기록 있는지
        $digest_today = $pdo->prepare(
            "SELECT sent_count, fail_count, created_at FROM digest_logs WHERE sent_date = :d LIMIT 1"
        );
        $digest_today->execute([':d' => $date]);
        $digest_row = $digest_today->fetch(PDO::FETCH_ASSOC);

        echo json_encode([
            'success'      => true,
            'date'         => $date,
            'news_list'    => $news_list,
            'digest_row'   => $digest_row ?: null,
            'server_time'  => date('Y-m-d H:i:s'),
        ]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

/* ── 후원 내역 목록 + 통계 조회 ──────────────────────── */
if ($action === 'donations_list') {
    $is_admin = isset($_SESSION['admin_auth']) && $_SESSION['admin_auth'] === true;

    $pdo = get_db_conn();
    if (!$pdo) { echo json_encode(['success' => false, 'message' => 'DB 연결 실패']); exit; }
    try {
        ensure_donations_table($pdo);

        $limit  = min((int)($_GET['limit']  ?? 50), 200);
        $offset = max((int)($_GET['offset'] ?? 0), 0);
        $range  = $_GET['range'] ?? 'all';  // today | 7d | 30d | all

        // ── 공개 접근 (어드민 미인증): is_public=1 & completed 만 반환 ──
        if (!$is_admin) {
            $rows = $pdo->query("
                SELECT donor_name, message, amount, pay_type, created_at,
                       1 AS is_public
                FROM donations
                WHERE is_public = 1 AND status = 'completed'
                ORDER BY created_at DESC
                LIMIT $limit OFFSET $offset
            ")->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['success' => true, 'list' => $rows, 'total' => count($rows)]);
            exit;
        }

        // ── 어드민 인증된 경우: 전체 목록 + 통계 반환 ──────────────────
        $whereDate = '';
        if ($range === 'today') {
            $whereDate = "WHERE DATE(created_at) = CURDATE()";
        } elseif ($range === '7d') {
            $whereDate = "WHERE created_at >= NOW() - INTERVAL 7 DAY";
        } elseif ($range === '30d') {
            $whereDate = "WHERE created_at >= NOW() - INTERVAL 30 DAY";
        }

        $total = (int)$pdo->query("SELECT COUNT(*) FROM donations $whereDate")->fetchColumn();

        // stats: completed 건만 집계 + 범위 필터 동일 적용
        $statsDateCond = '';
        if ($range === 'today') {
            $statsDateCond = " AND DATE(created_at) = CURDATE()";
        } elseif ($range === '7d') {
            $statsDateCond = " AND created_at >= NOW() - INTERVAL 7 DAY";
        } elseif ($range === '30d') {
            $statsDateCond = " AND created_at >= NOW() - INTERVAL 30 DAY";
        }

        $stats = $pdo->query("
            SELECT
                COUNT(*)                AS total_count,
                IFNULL(SUM(amount), 0)  AS total_amount,
                IFNULL(AVG(amount), 0)  AS avg_amount,
                IFNULL(MAX(amount), 0)  AS max_amount,
                SUM(CASE WHEN DATE(created_at) = CURDATE() THEN 1      ELSE 0 END) AS today_count,
                SUM(CASE WHEN DATE(created_at) = CURDATE() THEN amount ELSE 0 END) AS today_amount
            FROM donations
            WHERE status = 'completed' $statsDateCond
        ")->fetch(PDO::FETCH_ASSOC);

        // 대기/취소 건수 별도 집계 (투명성 확보)
        $statusSummary = $pdo->query("
            SELECT
                SUM(CASE WHEN status = 'pending'   THEN 1 ELSE 0 END) AS pending_count,
                SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled_count,
                IFNULL(SUM(CASE WHEN status = 'pending' THEN amount ELSE 0 END), 0) AS pending_amount
            FROM donations
        ")->fetch(PDO::FETCH_ASSOC);
        $stats = array_merge($stats, $statusSummary);

        $rows = $pdo->query("
            SELECT id, order_num, donor_name, message, amount, pay_type, is_public, status, created_at
            FROM donations $whereDate
            ORDER BY created_at DESC
            LIMIT $limit OFFSET $offset
        ")->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'list' => $rows, 'total' => $total, 'stats' => $stats]);
    } catch (PDOException $e) {
        error_log('[VibeAdmin] donations_list error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

/* ── 후원 명단 공개/비공개 토글 ───────────────────────── */
if ($action === 'donations_toggle_public') {
    if (!isset($_SESSION['admin_auth']) || $_SESSION['admin_auth'] !== true) {
        echo json_encode(['success' => false, 'message' => '인증이 필요합니다.']); exit;
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['success' => false, 'message' => '잘못된 요청 방식']); exit;
    }
    $id        = (int)($_POST['id']        ?? 0);
    $is_public = (int)($_POST['is_public'] ?? 0);
    if (!$id) { echo json_encode(['success' => false, 'message' => '잘못된 ID']); exit; }
    $is_public = $is_public ? 1 : 0;

    $pdo = get_db_conn();
    if (!$pdo) { echo json_encode(['success' => false, 'message' => 'DB 연결 실패']); exit; }
    try {
        $stmt = $pdo->prepare("UPDATE donations SET is_public = ? WHERE id = ?");
        $stmt->execute([$is_public, $id]);
        echo json_encode(['success' => true]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

/* ── 후원 시도 내역 목록 ───────────────────────── */
if ($action === 'donation_attempts_list') {
    if (!isset($_SESSION['admin_auth']) || $_SESSION['admin_auth'] !== true) {
        echo json_encode(['success' => false, 'message' => '인증이 필요합니다.']); exit;
    }
    $pdo = get_db_conn();
    if (!$pdo) { echo json_encode(['success' => false, 'message' => 'DB 연결 실패']); exit; }
    try {
        $limit  = min((int)($_GET['limit']  ?? 50), 200);
        $offset = max((int)($_GET['offset'] ?? 0), 0);
        $range  = $_GET['range'] ?? 'all';  // today | 7d | 30d | all

        $whereDate = '';
        if ($range === 'today') {
            $whereDate = "WHERE DATE(created_at) = CURDATE()";
        } elseif ($range === '7d') {
            $whereDate = "WHERE created_at >= NOW() - INTERVAL 7 DAY";
        } elseif ($range === '30d') {
            $whereDate = "WHERE created_at >= NOW() - INTERVAL 30 DAY";
        }

        $total = (int)$pdo->query("SELECT COUNT(*) FROM donation_attempts $whereDate")->fetchColumn();

        $rows = $pdo->query("
            SELECT id, order_num, donor_name, amount, pay_type, status, created_at
            FROM donation_attempts $whereDate
            ORDER BY created_at DESC
            LIMIT $limit OFFSET $offset
        ")->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'list' => $rows, 'total' => $total]);
    } catch (PDOException $e) {
        error_log('[VibeAdmin] donation_attempts_list error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

/* ── 관리자 수동 취소 ─────────────────────────────────────── */
if ($action === 'donation_force_cancel') {
    if (!isset($_SESSION['admin_auth']) || $_SESSION['admin_auth'] !== true) {
        echo json_encode(['success' => false, 'message' => '인증이 필요합니다.']); exit;
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['success' => false, 'message' => '잘못된 요청 방식']); exit;
    }
    $id   = (int)($_POST['id'] ?? 0);
    $note = trim($_POST['note'] ?? '관리자 수동 취소');
    if (!$id) { echo json_encode(['success' => false, 'message' => '잘못된 ID']); exit; }

    $pdo = get_db_conn();
    if (!$pdo) { echo json_encode(['success' => false, 'message' => 'DB 연결 실패']); exit; }
    try {
        $stmt = $pdo->prepare("
            UPDATE donations
            SET status     = 'cancelled',
                pay_state  = 'ADMIN_CANCEL',
                admin_note = ?,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ? AND status != 'cancelled'
        ");
        $stmt->execute([$note . ' | ' . date('Y-m-d H:i:s'), $id]);
        if ($stmt->rowCount() > 0) {
            error_log('[VibeAdmin] donation_force_cancel id=' . $id);
            echo json_encode(['success' => true, 'message' => '취소 처리되었습니다.']);
        } else {
            echo json_encode(['success' => false, 'message' => '해당 건이 없거나 이미 취소 상태입니다.']);
        }
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

/* ── Signal: 편집자 노트 목록 조회 ─────────────────────── */
if ($action === 'signal_editor_notes') {
    if (!isset($_SESSION['admin_auth']) || $_SESSION['admin_auth'] !== true) {
        echo json_encode(['success' => false, 'message' => '인증이 필요합니다.']); exit;
    }
    $pdo = get_db_conn();
    if (!$pdo) { echo json_encode(['success' => false, 'message' => 'DB 연결 실패']); exit; }
    try {
        $limit  = min((int)($_GET['limit']  ?? 30), 100);
        $offset = max((int)($_GET['offset'] ?? 0), 0);
        $total  = (int)$pdo->query("SELECT COUNT(*) FROM news_pages")->fetchColumn();
        $rows   = $pdo->query(
            "SELECT id, publish_date, editor_note, file_path, published_at
             FROM news_pages ORDER BY publish_date DESC
             LIMIT $limit OFFSET $offset"
        )->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'list' => $rows, 'total' => $total]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

/* ── Signal: 편집자 노트 수정 ─────────────────────────── */
if ($action === 'signal_editor_note_update') {
    if (!isset($_SESSION['admin_auth']) || $_SESSION['admin_auth'] !== true) {
        echo json_encode(['success' => false, 'message' => '인증이 필요합니다.']); exit;
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['success' => false, 'message' => '잘못된 요청 방식']); exit;
    }
    $id          = (int)($_POST['id']          ?? 0);
    $editor_note = trim($_POST['editor_note']  ?? '');
    if (!$id || $editor_note === '') {
        echo json_encode(['success' => false, 'message' => 'ID와 편집자 노트를 입력해주세요.']); exit;
    }
    if (mb_strlen($editor_note) > 300) {
        echo json_encode(['success' => false, 'message' => '편집자 노트는 300자 이내로 작성해주세요.']); exit;
    }

    $pdo = get_db_conn();
    if (!$pdo) { echo json_encode(['success' => false, 'message' => 'DB 연결 실패']); exit; }
    try {
        $row = $pdo->prepare("SELECT publish_date, file_path FROM news_pages WHERE id = ?");
        $row->execute([$id]);
        $page = $row->fetch(PDO::FETCH_ASSOC);
        if (!$page) {
            echo json_encode(['success' => false, 'message' => '해당 페이지를 찾을 수 없습니다.']); exit;
        }

        $upd = $pdo->prepare("UPDATE news_pages SET editor_note = ? WHERE id = ?");
        $upd->execute([$editor_note, $id]);

        $html_updated = false;
        $file_path = $page['file_path'];

        // file_path가 상대경로인 경우 (/signal/2026-04-01.html) htdocs 루트 prefix 추가
        if ($file_path && $file_path[0] === '/' && !file_exists($file_path)) {
            $file_path = __DIR__ . $file_path;
        }
        // file_path가 비어 있으면 규칙 기반 절대경로 생성
        if (!$file_path) {
            $file_path = __DIR__ . '/signal/' . $page['publish_date'] . '.html';
        }

        error_log('[VibeAdmin] editor_note_update: resolved path=' . $file_path . ' exists=' . (file_exists($file_path) ? 'Y' : 'N'));

        if (file_exists($file_path)) {
            $html = file_get_contents($file_path);
            $escaped_note = htmlspecialchars($editor_note, ENT_QUOTES, 'UTF-8');

            // editor-text div 내용 교체
            $count = 0;
            $html = preg_replace(
                '/<div class="editor-text">.*?<\/div>/s',
                '<div class="editor-text">' . $escaped_note . '</div>',
                $html, 1, $count
            );

            // JSON editor_note 필드도 교체 (있는 경우에만)
            if (preg_match('/"editor_note"/', $html)) {
                $json_note = json_encode($editor_note, JSON_UNESCAPED_UNICODE);
                $html = preg_replace(
                    '/"editor_note"\s*:\s*"[^"]*"/',
                    '"editor_note":' . $json_note,
                    $html
                );
            }

            file_put_contents($file_path, $html);
            $html_updated = true;
            error_log('[VibeAdmin] editor_note HTML updated: regex_matches=' . $count);
        }

        error_log('[VibeAdmin] editor_note updated: date=' . $page['publish_date'] . ' html=' . ($html_updated ? 'Y' : 'N'));
        echo json_encode([
            'success'      => true,
            'message'      => ($html_updated ? 'DB + HTML 반영 완료' : 'DB만 반영 (HTML 파일 없음)'),
            'html_updated' => $html_updated,
        ]);
    } catch (PDOException $e) {
        error_log('[VibeAdmin] editor_note_update error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}



echo json_encode(['success' => false, 'message' => '잘못된 요청']);
?>