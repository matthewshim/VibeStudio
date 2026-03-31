<?php
header('Content-Type: application/json');
// CORS — 허가된 출처만 허용 (외부 API 무단 사용 차단)
$_allowed_origin = 'https://vibestudio.prisincera.com';
$_origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($_origin === $_allowed_origin) {
    header("Access-Control-Allow-Origin: $_allowed_origin");
    header('Access-Control-Allow-Methods: POST');
    header('Access-Control-Allow-Headers: Content-Type');
} else {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden: Origin not allowed']);
    exit;
}

require_once 'Hanspell.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $text = $_POST['text'] ?? '';
    if (empty(trim($text))) {
        echo json_encode(['message' => ['result' => ['html' => '', 'origin_html' => '', 'errata_count' => 0]]]);
        exit;
    }

    $hanspell = new Hanspell();
    $result = $hanspell->check($text);

    echo json_encode($result);
}
else {
    echo json_encode(['error' => 'Only POST allowed']);
}
