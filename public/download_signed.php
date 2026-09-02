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

// Get document ID
$docId = $_GET['id'] ?? null;
if (!$docId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'missing_id']);
    exit;
}

try {
    // DB connection
    $pdo = new PDO(
        "mysql:host={$config['db']['host']};dbname={$config['db']['dbname']}",
        $config['db']['user'],
        $config['db']['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    // Fetch document
    $stmt = $pdo->prepare("SELECT * FROM documents WHERE id = :id");
    $stmt->execute(['id' => $docId]);
    $doc = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$doc) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'document_not_found']);
        exit;
    }

    // Authorization: user bisa download file miliknya sendiri ATAU admin
    $userStmt = $pdo->prepare("SELECT role FROM users WHERE id = :id");
    $userStmt->execute(['id' => $_SESSION['user_id']]);
    $user = $userStmt->fetch(PDO::FETCH_ASSOC);
    
    $isOwner = ($doc['user_id'] == $_SESSION['user_id']);
    $isAdmin = ($user && in_array($user['role'], ['admin', 'administrator']));

    if (!$isOwner && !$isAdmin) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'unauthorized']);
        exit;
    }

    // Optional: Check approval status
    if ($doc['approval_status'] !== 'approved') {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'document_not_approved']);
        exit;
    }

    // Get signed PDF path
    $signedPath = $doc['signed_path'] ?? '';
    
    if (!$signedPath || !file_exists($signedPath)) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'signed_file_not_found']);
        exit;
    }

    // Log download activity
    error_log('User ' . $_SESSION['user_id'] . ' downloaded signed document ' . $docId . ' at ' . date('Y-m-d H:i:s'));

// Get file info
$fileSize = filesize($signedPath);

$userStmt = $pdo->prepare("SELECT username FROM users WHERE id = :id");
$userStmt->execute(['id' => $doc['user_id']]);
$userData = $userStmt->fetch(PDO::FETCH_ASSOC);
$username = $userData['username'] ?? 'user';

// Format: kontrak_username.pdf
$downloadFileName = 'kontrak_' . $username . '.pdf';

// Set headers untuk download
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $downloadFileName . '"');
header('Content-Length: ' . $fileSize);
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

readfile($signedPath);
exit;

} catch (Exception $e) {
    error_log('download_signed error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    exit;
}