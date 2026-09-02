<?php

session_start();
header('Content-Type: application/json; charset=utf-8');
if (!isset($_SESSION['user_id'])) { echo json_encode(['success'=>false,'error'=>'not_logged_in']); exit; }

require_once __DIR__ . '/../vendor/autoload.php';
$config = require __DIR__ . '/../config.php';

try {
    $pdo = new PDO(
        "mysql:host={$config['db']['host']};dbname={$config['db']['dbname']};charset=utf8mb4",
        $config['db']['user'],
        $config['db']['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $stmt = $pdo->prepare("SELECT * FROM documents WHERE user_id = :uid ORDER BY created_at DESC LIMIT 1");
    $stmt->execute(['uid' => $_SESSION['user_id']]);
    $doc = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$doc) { echo json_encode(['success'=>false,'error'=>'no_document']); exit; }

    $zipExists = (!empty($doc['signed_zip']) && file_exists($doc['signed_zip']));

    // Use migrated fields on documents (preferred)
    $latest_note_status = $doc['note_status'] ?? null;
    $latest_note_text = $doc['note_text'] ?? null;
    $latest_note_created_at = $doc['note_updated_at'] ?? null;
    $latest_note_admin = null;

    if (!empty($doc['note_admin_id'])) {
        try {
            $admStmt = $pdo->prepare("SELECT username FROM users WHERE id = :id LIMIT 1");
            $admStmt->execute(['id' => $doc['note_admin_id']]);
            $admRow = $admStmt->fetch(PDO::FETCH_ASSOC);
            if ($admRow) $latest_note_admin = $admRow['username'];
        } catch (Exception $e) {
            // ignore; admin username is optional
            $latest_note_admin = null;
        }
    }

    // Return same keys as before so clients don't need changes
    echo json_encode([
        'success' => true,
        'id' => (int)$doc['id'],
        'approval_status' => $doc['approval_status'] ?? 'pending',
        'downloaded_at' => $doc['downloaded_at'] ?? null,
        'downloaded_allowed' => (bool)($doc['downloaded_allowed'] ?? 0),
        'downloaded_confirmed' => $doc['downloaded_confirmed'] ?? null,
        'uploaded_at' => $doc['uploaded_at'] ?? null,
        'zip_exists' => $zipExists,
        // migrated note fields
        'latest_note_status' => $latest_note_status ?? null,
        'latest_note_text' => $latest_note_text ?? null,
        'latest_note_admin' => $latest_note_admin ?? null,
        'latest_note_created_at' => $latest_note_created_at ?? null
    ]);
    exit;
} catch (Exception $e) {
    error_log('doc_status error: ' . $e->getMessage());
    echo json_encode(['success'=>false,'error'=>'server_error']);
    exit;
}