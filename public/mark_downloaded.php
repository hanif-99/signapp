<?php

session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'not_logged_in']);
    exit;
}

require_once __DIR__ . '/../vendor/autoload.php';
$config = require __DIR__ . '/../config.php';

$body = json_decode(file_get_contents('php://input'), true);
$docId = $body['id'] ?? null;
$csrf = $body['_csrf'] ?? null;

if (!$docId || !$csrf || $csrf !== ($_SESSION['csrf_token'] ?? null)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'invalid_request']);
    exit;
}

try {
    $pdo = new PDO(
        "mysql:host={$config['db']['host']};dbname={$config['db']['dbname']}",
        $config['db']['user'],
        $config['db']['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    // Verify ownership
    $stmt = $pdo->prepare("SELECT user_id FROM documents WHERE id = :id");
    $stmt->execute(['id' => $docId]);
    $doc = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$doc || $doc['user_id'] != $_SESSION['user_id']) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'unauthorized']);
        exit;
    }

    // Update downloaded_at timestamp
    $upd = $pdo->prepare("UPDATE documents SET downloaded_at = NOW() WHERE id = :id");
    $upd->execute(['id' => $docId]);

    echo json_encode(['success' => true, 'message' => 'Download marked']);
    exit;

} catch (Exception $e) {
    error_log('mark_downloaded error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    exit;
}