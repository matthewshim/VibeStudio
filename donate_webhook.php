<?php
/**
 * donate_webhook.php — PayApp 결제 결과 웹훅 수신 + 결제 검증/업데이트 API
 *
 * [GET]  ?action=verify&order_num=XXX         : 결제 서버 검증 (donate_complete.html)
 * [POST] action=update_donor_info             : 후원자 명단 정보 등록 (결제 완료 화면 폼)
 * [POST] (no action / PayApp webhook)         : PayApp feedbackurl 웹훅 수신
 *
 * 기본 흐름:
 *   ① PayApp 웹훅 → is_public=0 으로 저장 (비공개 기본값)
 *   ② 결제 완료 화면에서 사용자가 이름/메시지/공개 여부 입력 후 제출
 *   ③ update_donor_info 호출 → is_public 업데이트 → 이때부터 명단에 노출
 */

require_once __DIR__ . '/config.php';

// ── PayApp pay_state 상수 (PHP 전역) ────────────────────────────────
// donate_complete.html JS와 동일한 값을 코드 전체에서 참조
define('PAYAPP_COMPLETED', ['4']);
define('PAYAPP_PENDING',   ['1']);
define('PAYAPP_CANCEL',    ['8', '9', '16', '31', '32', '64', '70', '71']);

// ── DB 연결 헬퍼 (싱글턴) ─────────────────────────────────────
// 동일 요청 내 여러 곳에서 PDO를 재사용함—GET/POST 분기마다 exit하므로 크로스 요청 누수 없음
function get_wh_db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO(
            'mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset='.DB_CHARSET,
            DB_USER, DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
             PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
    }
    return $pdo;
}

// ── GET: 결제 검증 조회 (?action=verify&order_num=XXX) ───────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'verify') {
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: ' . rtrim(SITE_URL, '/'));

    $order_num = trim($_GET['order_num'] ?? '');
    if (!$order_num) {
        echo json_encode(['success' => false, 'message' => 'order_num 필요']);
        exit;
    }

    try {
        $pdo  = get_wh_db();
        $stmt = $pdo->prepare(
            'SELECT id, donor_name, amount, pay_type, status, pay_state, created_at
             FROM donations WHERE order_num = :o LIMIT 1'
        );
        $stmt->execute([':o' => $order_num]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        echo json_encode([
            'success'  => true,
            'verified' => (bool)$row,
            'data'     => $row ? [
                'donor_name' => $row['donor_name'],
                'amount'     => (int)$row['amount'],
                'pay_type'   => $row['pay_type'],
                'status'     => $row['status'],
                'pay_state'  => $row['pay_state'],
                'created_at' => $row['created_at'],
            ] : null,
        ]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'verified' => false, 'message' => 'DB 오류']);
    }
    exit;
}

// ── GET: 결제 폴링 (?action=poll&price=XXX&since=TIMESTAMP) ─────────────
// donate_complete.html 진행중 화면에서 3초마다 호출
//
// 두 가지 조회 경로:
//   A. mul_no(PayApp 주문번호)가 URL에 있는 경우 → 직접 조회 (우선순위 높음)
//   B. price + since(결제 시작 timestamp, ms) → 시간대+금액으로 최근 건 조회
//
// NOTE: PayApp Lite SDK는 var1을 feedbackurl로 전달하지 않음(실제 로그 확인)
//       redirecturl에도 pay_state 없이 오는 경우 있으므로 이 폴링이 핵심 경로임
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'poll') {
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: ' . rtrim(SITE_URL, '/'));
    header('Cache-Control: no-store, no-cache, must-revalidate');

    try {
        $pdo = get_wh_db();

        $row = null;

        // ── 경로 A: mul_no 직접 조회 (redirecturl에 mul_no가 있을 때) ──
        $mul_no = trim($_GET['mul_no'] ?? '');
        if ($mul_no && strlen($mul_no) <= 30 && ctype_digit($mul_no)) {
            $stmt = $pdo->prepare(
                'SELECT order_num, amount, pay_type, pay_state, status
                 FROM donations
                 WHERE order_num = :m AND created_at >= NOW() - INTERVAL 30 MINUTE
                 LIMIT 1'
            );
            $stmt->execute([':m' => $mul_no]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        // ── 경로 B: price + since 기반 조회 (mul_no 없는 경우 fallback) ──
        // donating.html에서 결제 시작 시 sessionStorage에 price, start timestamp 저장
        // donate_complete.html이 이 값으로 폴링 → 해당 시간대의 내 결제 건 검색
        if (!$row) {
            $price = (int)($_GET['price'] ?? 0);
            $since = (int)($_GET['since'] ?? 0);  // ms timestamp

            if ($price >= 1000 && $since > 0) {
                // since 기준 -30초 ~ +5분 범위에서 동일 금액 결제 건 검색
                $since_dt    = date('Y-m-d H:i:s', intval($since / 1000) - 30);
                $deadline_dt = date('Y-m-d H:i:s', intval($since / 1000) + 300);

                $stmt = $pdo->prepare(
                    'SELECT order_num, amount, pay_type, pay_state, status
                     FROM donations
                     WHERE amount = :p
                       AND created_at BETWEEN :s AND :e
                     ORDER BY created_at DESC
                     LIMIT 1'
                );
                $stmt->execute([':p' => $price, ':s' => $since_dt, ':e' => $deadline_dt]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
            }
        }

        if ($row) {
            echo json_encode([
                'found'     => true,
                'completed' => $row['status'] === 'completed',
                'order_num' => $row['order_num'],
                'amount'    => (int)$row['amount'],
                'pay_type'  => $row['pay_type'],
                'pay_state' => $row['pay_state'],
                'status'    => $row['status'],
            ]);
        } else {
            echo json_encode(['found' => false]);
        }
    } catch (PDOException $e) {
        echo json_encode(['found' => false]);
    }
    exit;
}

// ── POST: 후원자 명단 정보 업데이트 (donate_complete.html 폼 제출) ───
// 실제 결제가 완료되어 webhook이 DB에 저장한 뒤에만 업데이트 가능
// → order_num이 donations 테이블에 존재해야 허용 (가짜 등록 방지)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_donor_info') {
    header('Content-Type: application/json; charset=utf-8');

    $order_num  = trim($_POST['order_num']  ?? '');
    $donor_name = mb_substr(strip_tags(trim($_POST['donor_name'] ?? '익명')), 0, 50);
    $message    = mb_substr(strip_tags(trim($_POST['message']    ?? '')), 0, 200);
    $is_public  = ($_POST['is_public'] ?? '0') === '1' ? 1 : 0;

    if (!$order_num) {
        echo json_encode(['success' => false, 'message' => 'order_num 필요']);
        exit;
    }

    try {
        $pdo = get_wh_db();

        // 주문번호가 DB에 있는지 검증 (webhook이 먼저 저장해야 레코드 생김)
        $chk = $pdo->prepare('SELECT id FROM donations WHERE order_num = :o LIMIT 1');
        $chk->execute([':o' => $order_num]);
        if (!$chk->fetch()) {
            echo json_encode([
                'success' => false,
                'message' => '유효한 결제 내역이 없습니다. (웹훅 미수신 또는 유효하지 않은 주문번호)',
            ]);
            exit;
        }

        // 후원자 정보 업데이트 (이름, 메시지, 공개 여부)
        $pdo->prepare(
            'UPDATE donations SET donor_name=:n, message=:m, is_public=:p WHERE order_num=:o'
        )->execute([':n' => $donor_name, ':m' => $message ?: null, ':p' => $is_public, ':o' => $order_num]);

        error_log('[Donate][UPDATE] order=' . $order_num . ' | name=' . $donor_name . ' | public=' . $is_public);
        echo json_encode(['success' => true]);
    } catch (PDOException $e) {
        error_log('[Donate][UPDATE_ERR] ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'DB 오류']);
    }
    exit;
}

// ── POST: PayApp 웹훅 수신 (feedbackurl) ─────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method Not Allowed');
}

// ── 원본 페이로드 캡처 ────────────────────────────────────────────────
$raw_payload = json_encode($_POST, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$client_ip   = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
$webhook_at  = date('Y-m-d H:i:s');

error_log('[Donate][WEBHOOK] ' . $webhook_at . ' | IP:' . $client_ip);
error_log('[Donate][POST] keys=' . implode(',', array_keys($_POST)));
error_log('[Donate][POST] data=' . $raw_payload);

// ── PayApp 파라미터 파싱 ──────────────────────────────────────────────
// 매뉴얼 기준:
//   pay_state: 4=결제완료, 8/16/31=요청취소, 9/64=승인취소, 10=결제대기
//   pay_state=1 은 JS API + 공통통보URL 사용 시 "결제요청" 상태 (완료 아님)
//   mul_no: PayApp 결제요청번호 (주요 식별자)
$pay_state  = trim($_POST['pay_state']  ?? '');
$mul_no     = trim($_POST['mul_no']     ?? '');  // PayApp 결제요청번호
$order_num  = $mul_no ?: trim(
    $_POST['orderid']   ??
    $_POST['order_num'] ??
    $_POST['orderNum']  ??
    $_POST['order_id']  ??
    ''
);
$goodname   = trim($_POST['goodname']   ?? '');
$price      = (int)($_POST['price']     ?? 0);
$pay_type   = trim($_POST['pay_type']   ?? '');
$pay_total  = (int)($_POST['pay_total'] ?? $price);
$recvname   = trim($_POST['recvname']   ?? '');
$reqdate    = trim($_POST['reqdate']    ?? '');
$var1       = trim($_POST['var1']       ?? '');  // 임의 사용 변수 1
$var2       = trim($_POST['var2']       ?? '');  // 임의 사용 변수 2
$memo       = trim($_POST['memo']       ?? '');

// ── 결제 상태 처리 ────────────────────────────────────────────────────
// PayApp feedbackurl pay_state 값 (매뉴얼 기준):
//   1  = 결제요청 — JS API 연동 시 결제창이 열릴 때 최초 NOTI
//         → redirecturl도 pay_state=1을 받아 성공 UI 표시
//         → mul_no와 price가 이미 있으므로 DB에 'pending' 상태로 저장
//   4  = 결제완료 — 실제 카드 승인/결제 완료
//         → DB 레코드를 'completed'로 갱신 (또는 신규 저장)
//   8,16,31 = 요청취소, 9,64 = 승인취소, 70,71 = 부분취소
$completed_states = PAYAPP_COMPLETED;
$pending_states   = PAYAPP_PENDING;
$cancel_states    = PAYAPP_CANCEL;

if (in_array($pay_state, $cancel_states, true)) {
    error_log('[Donate][CANCEL] pay_state=' . $pay_state . ' | order=' . $order_num);
    _log_donation_attempt($order_num, $pay_state, $price, $pay_type, $var1, $var2,
                          $client_ip, $raw_payload, 'cancelled');

    // donations 테이블도 cancelled 로 갱신 (order_num 매칭 시)
    if ($order_num) {
        try {
            $pdo_c = get_wh_db();

            // ① 직접 order_num 매칭
            $stmt_c = $pdo_c->prepare(
                "UPDATE donations
                 SET status = 'cancelled', pay_state = ?, updated_at = CURRENT_TIMESTAMP
                 WHERE order_num = ? AND status != 'cancelled'"
            );
            $stmt_c->execute([$pay_state, $order_num]);
            $affected = $stmt_c->rowCount();

            if ($affected > 0) {
                error_log('[Donate][CANCEL_UPDATED] donations.status=cancelled | order=' . $order_num . ' | pay_state=' . $pay_state);
            } else {
                // ② 폴백: AUTO_ 임시번호 건을 금액+시간(±10분)으로 역매칭
                // (초기 테스트 시 mul_no 누락으로 AUTO_ 저장된 건들)
                if ($price > 0) {
                    $stmt_fb = $pdo_c->prepare(
                        "UPDATE donations
                         SET status     = 'cancelled',
                             pay_state  = ?,
                             admin_note = CONCAT(IFNULL(admin_note,''), ' | AUTO_fallback: real_order=', ?),
                             updated_at = CURRENT_TIMESTAMP
                         WHERE order_num LIKE 'AUTO_%'
                           AND amount  = ?
                           AND status != 'cancelled'
                           AND ABS(TIMESTAMPDIFF(MINUTE, created_at, NOW())) <= 10
                         ORDER BY created_at DESC
                         LIMIT 1"
                    );
                    $stmt_fb->execute([$pay_state, $order_num, $price]);
                    $fb = $stmt_fb->rowCount();
                    if ($fb > 0) {
                        error_log('[Donate][CANCEL_AUTO_FALLBACK] AUTO_ 건 취소 매칭 | real_order=' . $order_num . ' | pay_state=' . $pay_state . ' | amount=' . $price);
                    } else {
                        error_log('[Donate][CANCEL_NOOP] 매칭 donations 없음 | order=' . $order_num);
                    }
                } else {
                    error_log('[Donate][CANCEL_NOOP] 해당 order_num 없거나 이미 cancelled | order=' . $order_num);
                }
            }
        } catch (PDOException $e) {
            error_log('[Donate][CANCEL_DB_ERR] ' . $e->getMessage());
        }
    }

    echo 'SUCCESS';   // PayApp에는 항상 SUCCESS 응답
    exit;
}

if (!in_array($pay_state, $completed_states, true) &&
    !in_array($pay_state, $pending_states, true)) {
    error_log('[Donate][SKIP] 알 수 없는 pay_state=' . $pay_state . ' | order=' . $order_num);
    echo 'SUCCESS';
    exit;
}

// pay_state=1(요청) 또는 pay_state=4(완료) 처리
$is_completed = in_array($pay_state, $completed_states, true);
error_log('[Donate][' . ($is_completed ? 'COMPLETED' : 'REQUEST') . '] pay_state=' . $pay_state . ' | order=' . $order_num . ' | price=' . $price);

// ── order_num 보정 ────────────────────────────────────────────────────
if (!$order_num) {
    error_log('[Donate][WARN] order_num 없음 — 임시 ID 생성');
    $order_num = 'AUTO_' . date('YmdHis') . '_' . substr(md5(uniqid('', true)), 0, 8);
}

// ── 입력값 새니타이즈 ─────────────────────────────────────────────────
// var1 → clt_token(세션 토큰)으로 사용. donor_name은 '익명' 기본값
// (후원 완료 화면에서 사용자가 직접 이름/메시지 입력)
$clt_token  = preg_replace('/[^a-zA-Z0-9_\-]/', '', $var1);
$donor_name = '익명';
$message    = '';
$pay_type   = preg_replace('/[^a-zA-Z0-9_]/', '', $pay_type);

// ── DB 저장 ───────────────────────────────────────────────────────────
try {
    $pdo = get_wh_db();

    // 테이블 생성
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS donations (
            id              INT AUTO_INCREMENT PRIMARY KEY,
            order_num       VARCHAR(100) NOT NULL UNIQUE COMMENT 'PayApp 주문번호',
            donor_name      VARCHAR(50)  NOT NULL DEFAULT '익명'  COMMENT '후원자명',
            message         VARCHAR(200) DEFAULT NULL              COMMENT '응원 메시지',
            amount          INT          NOT NULL                  COMMENT '후원 금액(원)',
            pay_type        VARCHAR(30)  DEFAULT NULL              COMMENT '결제수단',
            pay_state       VARCHAR(10)  DEFAULT NULL              COMMENT 'PayApp pay_state',
            webhook_ip      VARCHAR(45)  DEFAULT NULL              COMMENT 'PayApp 웹훅 발신 IP',
            raw_payload     TEXT         DEFAULT NULL              COMMENT 'PayApp 원본 POST(JSON)',
            is_public       TINYINT(1)   NOT NULL DEFAULT 0        COMMENT '명단 공개 여부 (결제완료화면에서 사용자가 직접 등록해야 1)',
            status          VARCHAR(20)  NOT NULL DEFAULT 'completed' COMMENT 'completed|cancelled|refunded',
            refunded_at     DATETIME     DEFAULT NULL              COMMENT '환불 처리 시각',
            admin_note      VARCHAR(300) DEFAULT NULL              COMMENT '어드민 메모',
            created_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
            updated_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_created  (created_at),
            INDEX idx_status   (status),
            INDEX idx_public   (is_public),
            INDEX idx_pay_type (pay_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='후원 결제 내역';
    ");

    // 컬럼 마이그레이션 (기존 테이블 대응)
    $columns = $pdo->query("SHOW COLUMNS FROM donations")->fetchAll(PDO::FETCH_COLUMN);
    $migrations = [
        'pay_state'   => "ALTER TABLE donations ADD COLUMN pay_state  VARCHAR(10) DEFAULT NULL COMMENT 'PayApp pay_state' AFTER pay_type",
        'webhook_ip'  => "ALTER TABLE donations ADD COLUMN webhook_ip VARCHAR(45) DEFAULT NULL COMMENT 'PayApp 웹훅 발신 IP' AFTER pay_state",
        'raw_payload' => "ALTER TABLE donations ADD COLUMN raw_payload TEXT DEFAULT NULL COMMENT 'PayApp 원본 POST(JSON)' AFTER webhook_ip",
        'refunded_at' => "ALTER TABLE donations ADD COLUMN refunded_at DATETIME DEFAULT NULL COMMENT '환불 처리 시각' AFTER is_public",
        'admin_note'  => "ALTER TABLE donations ADD COLUMN admin_note VARCHAR(300) DEFAULT NULL COMMENT '어드민 메모' AFTER refunded_at",
        'updated_at'  => "ALTER TABLE donations ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at",
        'clt_token'   => "ALTER TABLE donations ADD COLUMN clt_token VARCHAR(80) DEFAULT NULL COMMENT '클라이언트 세션 토큰(var1) — 폴링용' AFTER order_num, ADD INDEX idx_clt_token (clt_token)",
    ];
    foreach ($migrations as $col => $sql) {
        if (!in_array($col, $columns)) {
            $pdo->exec($sql);
            error_log('[Donate][MIGRATION] 컬럼 추가: ' . $col);
        }
    }

    if ($is_completed) {
        // ── pay_state=4: 결제완료 → INSERT 또는 pending→completed 갱신
        $stmt = $pdo->prepare("
            INSERT INTO donations
                (order_num, clt_token, donor_name, amount, pay_type, pay_state,
                 webhook_ip, raw_payload, is_public, status)
            VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, 0, 'completed')
            ON DUPLICATE KEY UPDATE
                pay_state  = VALUES(pay_state),
                pay_type   = COALESCE(NULLIF(VALUES(pay_type),''), pay_type),
                raw_payload= VALUES(raw_payload),
                status     = 'completed',
                updated_at = CURRENT_TIMESTAMP
        ");
        $stmt->execute([
            $order_num, $clt_token ?: null, $donor_name, 
            $price, $pay_type ?: null, $pay_state,
            $client_ip, $raw_payload,
        ]);
        $affected = $stmt->rowCount();
        if ($affected > 0) {
            error_log('[Donate][COMPLETED] ' . number_format($price) . '원 / order=' . $order_num . ' / pay_type=' . $pay_type);
            _send_admin_notify($donor_name, $price, $pay_type, '', $order_num, '', '');
        } else {
            error_log('[Donate][DUP_NOOP] 중복 완료 (변경 없음): ' . $order_num);
        }
    } else {
        // ── pay_state=1: 결제요청 → pending으로 미리 저장 (verify 타이밍 대비)
        //    이후 pay_state=4가 오면 ON DUPLICATE KEY로 completed로 갱신됨
        $stmt = $pdo->prepare("
            INSERT IGNORE INTO donations
                (order_num, clt_token, donor_name, amount, pay_type, pay_state,
                 webhook_ip, raw_payload, is_public, status)
            VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, 0, 'pending')
        ");
        $stmt->execute([
            $order_num, $clt_token ?: null, $donor_name,
            $price, $pay_type ?: null, $pay_state,
            $client_ip, $raw_payload,
        ]);
        error_log('[Donate][PENDING] 결제요청 저장 / order=' . $order_num . ' / token=' . $clt_token . ' / price=' . $price);
    }

} catch (PDOException $e) {
    error_log('[Donate][DB_ERR] ' . $e->getMessage());
}

// PayApp 필수: 'SUCCESS' 응답 (다른 응답이면 checkretry=y 설정 시 최대 10회 재호출)
echo 'SUCCESS';


// ── 함수: 취소/실패 시도 로그 ────────────────────────────────────────
function _log_donation_attempt($order_num, $pay_state, $price, $pay_type,
                               $var1, $var2, $ip, $raw, $status) {
    if (!$order_num) return;
    try {
        $pdo = get_wh_db();
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS donation_attempts (
                id          INT AUTO_INCREMENT PRIMARY KEY,
                order_num   VARCHAR(100) DEFAULT NULL,
                pay_state   VARCHAR(10)  DEFAULT NULL,
                amount      INT          DEFAULT 0,
                pay_type    VARCHAR(30)  DEFAULT NULL,
                donor_name  VARCHAR(50)  DEFAULT NULL,
                message     VARCHAR(200) DEFAULT NULL,
                webhook_ip  VARCHAR(45)  DEFAULT NULL,
                raw_payload TEXT         DEFAULT NULL,
                status      VARCHAR(20)  DEFAULT 'unknown',
                created_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_order (order_num),
                INDEX idx_state (pay_state)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='결제 시도 이력 (취소/실패 포함)';
        ");
        $pdo->prepare("
            INSERT INTO donation_attempts
                (order_num, pay_state, amount, pay_type, donor_name, message,
                 webhook_ip, raw_payload, status)
            VALUES (?,?,?,?,?,?,?,?,?)
        ")->execute([
            $order_num, $pay_state, $price, $pay_type,
            mb_substr($var1, 0, 50), mb_substr($var2, 0, 200),
            $ip, $raw, $status,
        ]);
    } catch (Exception $e) {
        error_log('[Donate][ATTEMPT_LOG_ERR] ' . $e->getMessage());
    }
}

// ── 함수: 관리자 알림 이메일 ─────────────────────────────────────────
function _send_admin_notify($donor_name, $price, $pay_type, $message,
                            $order_num, $var1, $var2) {
    try {
        $mailerPath = __DIR__ . '/vendor/autoload.php';
        if (!file_exists($mailerPath)) return;
        require_once $mailerPath;

        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
        $mail->SMTPSecure = 'tls';
        $mail->Port       = SMTP_PORT;
        $mail->CharSet    = 'UTF-8';
        $mail->setFrom(SMTP_FROM, SMTP_FROM_NAME);
        $mail->addAddress(SMTP_FROM);
        $mail->Subject = '[Vibe Studio] 후원 도착 · ' . number_format($price) . '원';
        $mail->Body    = implode("\n", [
            '새로운 후원이 접수되었습니다.',
            '(후원자 명단 등록은 사용자가 결제 완료 화면에서 직접 처리합니다.)',
            '',
            '후원자:   ' . $donor_name,
            '금액:     ' . number_format($price) . '원',
            '수단:     ' . ($pay_type ?: '—'),
            '메시지:   ' . ($message ?: '(없음)'),
            '주문번호: ' . $order_num,
            '시각:     ' . date('Y-m-d H:i:s'),
        ]);
        $mail->send();
    } catch (Exception $e) {
        error_log('[Donate][MAIL_ERR] ' . $e->getMessage());
    }
}
