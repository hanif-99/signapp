<?php
session_start();
header('Content-Type: application/pdf; charset=utf-8');

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

require_once __DIR__ . '/../vendor/autoload.php';
$config = require __DIR__ . '/../config.php';

if (!isset($_GET['id'])) {
    http_response_code(400);
    echo 'Bad Request';
    exit;
}

$docId = $_GET['id'];

try {
    $pdo = new PDO(
        "mysql:host={$config['db']['host']};dbname={$config['db']['dbname']};charset=utf8mb4",
        $config['db']['user'],
        $config['db']['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (Exception $e) {
    http_response_code(500);
    echo 'Database connection failed';
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT signed_path FROM documents WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $docId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$row || empty($row['signed_path'])) {
        http_response_code(404);
        echo 'File not found';
        exit;
    }
    
    $signedPath = $row['signed_path'];
    $storageBase = rtrim($config['storage'] ?? __DIR__ . '/../storage', '/\\');
    $realStorage = realpath($storageBase);
    
    // Determine actual file path
    $filePath = null;
    
    if (is_file($signedPath)) {
        $filePath = $signedPath;
    } elseif ($realStorage) {
        $candidate = $realStorage . DIRECTORY_SEPARATOR . ltrim($signedPath, '/\\');
        if (is_file($candidate)) {
            $filePath = $candidate;
        }
    }
    
    if (!$filePath) {
        http_response_code(404);
        echo 'File not found on disk';
        exit;
    }
    
    // Verify file is within storage directory (security check)
    $realPath = realpath($filePath);
    if (!$realPath || !$realStorage || strpos($realPath, $realStorage) !== 0) {
        http_response_code(403);
        echo 'Access denied';
        exit;
    }
    
    // Get file info
    $fileSize = filesize($realPath);
    $fileName = basename($realPath);
    
    // Send file
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $fileName . '"');
    header('Content-Length: ' . $fileSize);
    header('Cache-Control: public, max-age=3600');
    
    readfile($realPath);
    exit;
    
} catch (Exception $e) {
    error_log('admin_download_pdf error: ' . $e->getMessage());
    http_response_code(500);
    echo 'Server error';
    exit;
}
