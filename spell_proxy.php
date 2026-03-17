<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

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
