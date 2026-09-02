<?php

session_start();
require_once __DIR__ . '/../vendor/autoload.php';
$config = require __DIR__ . '/../config.php';

// DB
$pdo = new PDO(
    "mysql:host={$config['db']['host']};dbname={$config['db']['dbname']};charset=utf8mb4",
    $config['db']['user'],
    $config['db']['pass'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

// params
$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$type = $_GET['type'] ?? 'draft'; // allowed: draft, signed, uploaded
$token = trim($_GET['token'] ?? '');
$downloadParam = isset($_GET['download']) && ($_GET['download'] == '1' || $_GET['download'] === 'true');
$requestedFile = isset($_GET['file']) ? (string)$_GET['file'] : '';

if (!$id) {
    header('HTTP/1.1 400 Bad Request');
    echo "Invalid request";
    exit;
}

// ambil dokumen
$stmt = $pdo->prepare("SELECT d.* FROM documents d WHERE d.id = :id LIMIT 1");
$stmt->execute(['id' => $id]);
$doc = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$doc) {
    header('HTTP/1.1 404 Not Found');
    echo "Document not found";
    exit;
}

// check access
$allowed = false;
if ($token && isset($doc['token']) && hash_equals((string)$doc['token'], (string)$token)) {
    $allowed = true;
} elseif (isset($_SESSION['user_id'])) {
    if ($_SESSION['role'] === 'admin') $allowed = true;
    if ($doc['user_id'] == $_SESSION['user_id']) {
        if ($type === 'signed') {
            // check approval_status — allow only if approved
            if (!empty($doc['approval_status']) && ($doc['approval_status'] === 'approved')) {
                $allowed = true;
            } else {
                header('HTTP/1.1 403 Forbidden');
                echo "Dokumen belum disetujui administrator.";
                exit;
            }
        } else {
            $allowed = true;
        }
    }
}

if (!$allowed) {
    header('HTTP/1.1 403 Forbidden');
    echo "Forbidden";
    exit;
}

// Determine path based on type
$path = '';
if ($type === 'signed') {
    $path = $doc['signed_path'] ?? '';
} elseif ($type === 'draft') {
    $path = $doc['draft_path'] ?? '';
} elseif ($type === 'uploaded') {
    // Quick-fix: allow admin to download any file present in uploads/signed/{docId}/ even if not registered.
    // Non-admin users must still have file registered in documents.uploaded_files.
    if ($requestedFile === '') { header('HTTP/1.1 400 Bad Request'); echo "missing file"; exit; }
    $requestedFile = basename($requestedFile);

    $baseDir = realpath(__DIR__ . '/../uploads') ?: (__DIR__ . '/../uploads');
    $targetDir = $baseDir . '/signed/' . $id;
    $path = $targetDir . '/' . $requestedFile;

    // ensure file exists and is inside target dir
    $realBase = realpath($targetDir);
    $realFile = realpath($path);
    if (!$realFile || !$realBase || strpos($realFile, $realBase) !== 0 || !is_file($realFile)) {
        header('HTTP/1.1 404 Not Found'); echo "File not found"; exit;
    }

    // For non-admin users, enforce that filename is registered in documents.uploaded_files
    if (empty($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
        $uploadedFiles = [];
        if (!empty($doc['uploaded_files'])) {
            $uploadedFiles = json_decode($doc['uploaded_files'], true) ?: [];
        }
        if (!in_array($requestedFile, $uploadedFiles, true)) {
            header('HTTP/1.1 404 Not Found'); echo "File not registered"; exit;
        }
    }
} else {
    header('HTTP/1.1 400 Bad Request'); echo "Invalid type"; exit;
}

if (!$path || !file_exists($path) || !is_file($path)) {
    header('HTTP/1.1 404 Not Found');
    echo "File not found";
    exit;
}

// If download requested and it's a signed file, record download in DB
if ($downloadParam && $type === 'signed') {
    try {
        $upd = $pdo->prepare("UPDATE documents SET downloaded_at = NOW() WHERE id = :id");
        $upd->execute(['id' => $doc['id']]);

        // attempt to log in download_logs if table exists
        try {
            $log = $pdo->prepare("INSERT INTO download_logs (document_id, user_id, role, ip_address, user_agent) VALUES (:doc, :uid, :role, :ip, :ua)");
            $log->execute([
                'doc' => $doc['id'],
                'uid' => $_SESSION['user_id'] ?? null,
                'role' => $_SESSION['role'] ?? 'user',
                'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
                'ua' => $_SERVER['HTTP_USER_AGENT'] ?? null
            ]);
        } catch (Exception $e) {
            // ignore if table missing
        }
    } catch (Exception $e) {
        // ignore update errors
    }
}

// Serve file (attachment if download requested)
$basename = basename($path);
$mime = @mime_content_type($path) ?: 'application/pdf';
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
if ($downloadParam) {
    header('Content-Disposition: attachment; filename="' . $basename . '"');
} else {
    header('Content-Disposition: inline; filename="' . $basename . '"');
}
while (ob_get_level()) ob_end_clean();
readfile($path);
exit;
?>