<?php
session_start();

header('Content-Type: application/json');

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// DB 설정
$db_host = 'localhost';
$db_name = 'vibe_db';
$db_user = 'root';
$db_pass = 'vq.HlL6QthDG';

function get_db_conn()
{
    global $db_host, $db_name, $db_user, $db_pass;
    try {
        $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $pdo;
    } catch (PDOException $e) {
        return null;
    }
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

    // 계정이 하나도 없으면 기본 admin 계정 생성
    $cnt = (int)$pdo->query("SELECT COUNT(*) FROM admin_accounts")->fetchColumn();
    if ($cnt === 0) {
        $hash = password_hash('150323', PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("INSERT INTO admin_accounts (username, password, display_name, role) VALUES (?, ?, '시스템관리자', 'superadmin')");
        $stmt->execute(['admin', $hash]);
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
        if ($id === 'admin' && $pass === '150323') {
            $authenticated = true;
        }
    }

    if ($authenticated) {
        $_SESSION['admin_auth'] = true;
        $_SESSION['admin_user'] = $id;
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => '아이디 또는 비밀번호가 틀렸습니다.']);
    }
    exit;
}

/* ── 세션 확인 ─────────────────────────────────────── */
if ($action === 'check') {
    echo json_encode(['success' => isset($_SESSION['admin_auth']) && $_SESSION['admin_auth'] === true]);
    exit;
}

/* ── Fan 목록 ─────────────────────────────────────── */
if ($action === 'list') {
    if (!isset($_SESSION['admin_auth']) || $_SESSION['admin_auth'] !== true) {
        echo json_encode(['success' => false, 'message' => '인증이 필요합니다.']);
        exit;
    }
    $pdo = get_db_conn();
    if (!$pdo) { echo json_encode(['success' => false, 'message' => 'DB 연결 실패']); exit; }
    try {
        $stmt = $pdo->query("SELECT * FROM pre_registrations ORDER BY auth_time DESC");
        $list = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'list' => $list]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => '조회 실패: ' . $e->getMessage()]);
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
        echo json_encode(['success' => false, 'message' => '조회 실패: ' . $e->getMessage()]);
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
            echo json_encode(['success' => false, 'message' => '추가 실패: ' . $e->getMessage()]);
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
        echo json_encode(['success' => false, 'message' => '수정 실패: ' . $e->getMessage()]);
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
        echo json_encode(['success' => false, 'message' => '삭제 실패: ' . $e->getMessage()]);
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
        echo json_encode(['success' => false, 'message' => '조회 실패: ' . $e->getMessage()]);
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
        echo json_encode(['success' => false, 'message' => '저장 실패: ' . $e->getMessage()]);
    }
    exit;
}

echo json_encode(['success' => false, 'message' => '잘못된 요청']);
?>