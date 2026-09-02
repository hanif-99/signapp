<?php

session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'forbidden']);
    exit;
}

require_once __DIR__ . '/../vendor/autoload.php';
$config = require __DIR__ . '/../config.php';

try {
    $pdo = new PDO(
        "mysql:host={$config['db']['host']};dbname={$config['db']['dbname']};charset=utf8mb4",
        $config['db']['user'],
        $config['db']['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'db_connection_failed']);
    exit;
}

// read JSON body
$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'invalid_json']);
    exit;
}

// CSRF check
$csrf = $body['_csrf'] ?? '';
if (empty($csrf) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string)$csrf)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'invalid_csrf']);
    exit;
}

$action = $body['action'] ?? '';

try {
    if ($action === 'save') {
        $docId = isset($body['doc_id']) ? (int)$body['doc_id'] : 0;
        $status = isset($body['status']) ? trim($body['status']) : '';
        $noteText = isset($body['note_text']) ? trim($body['note_text']) : '';

        if ($docId <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'invalid_doc_id']);
            exit;
        }
        if ($status !== 'done' && $status !== 'fix') {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'invalid_status']);
            exit;
        }
        if ($status === 'fix' && $noteText === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'note_text_required_for_fix']);
            exit;
        }

        // Update documents table: store latest note fields in documents.*
        $upd = $pdo->prepare("UPDATE documents
                              SET note_status = :st, note_text = :nt, note_admin_id = :aid, note_updated_at = NOW()
                              WHERE id = :did");
        $upd->execute([
            'st'  => $status,
            'nt'  => $noteText,
            'aid' => $_SESSION['user_id'],
            'did' => $docId
        ]);

        echo json_encode(['success' => true, 'doc_id' => $docId, 'message' => 'Note saved']);
        exit;

    } elseif ($action === 'delete') {
        $docId = isset($body['doc_id']) ? (int)$body['doc_id'] : 0;
        if ($docId <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'invalid_doc_id']);
            exit;
        }

        // Optionally check document exists
        $sel = $pdo->prepare("SELECT id FROM documents WHERE id = :id LIMIT 1");
        $sel->execute(['id' => $docId]);
        $found = $sel->fetch(PDO::FETCH_ASSOC);
        if (!$found) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'document_not_found']);
            exit;
        }

        // Clear note fields on documents (hard-delete of last note)
        $del = $pdo->prepare("UPDATE documents SET note_status = NULL, note_text = NULL, note_admin_id = NULL, note_updated_at = NULL WHERE id = :id");
        $del->execute(['id' => $docId]);

        echo json_encode(['success' => true, 'message' => 'Note deleted']);
        exit;

    } else {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'invalid_action']);
        exit;
    }
} catch (Exception $e) {
    // Do not leak internal errors to clients
    error_log('admin_note error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'server_error']);
    exit;
}
?>