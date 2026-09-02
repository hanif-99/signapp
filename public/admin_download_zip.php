<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

// Security: Check admin access
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

// Get filename parameter
$filename = $_GET['f'] ?? '';

if (empty($filename)) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing filename parameter']);
    exit;
}

// Validate filename format: document_YYYYMMDD_HHMMSS.zip
if (!preg_match('/^document_\d{14}\.zip$/', $filename)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid filename format']);
    exit;
}

// Construct file path
$tempDir = __DIR__ . '/../temp_downloads';
$filePath = $tempDir . '/' . $filename;

// Security: Prevent path traversal using realpath
$realPath = realpath($filePath);
$realTempDir = realpath($tempDir);

// Verify file is in temp_downloads directory
if (!$realPath || !$realTempDir || strpos($realPath, $realTempDir) !== 0) {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied']);
    exit;
}

// Check if file exists
if (!is_file($realPath)) {
    http_response_code(404);
    echo json_encode(['error' => 'File not found']);
    exit;
}

// Check file size (max 500MB)
$maxSize = 500 * 1024 * 1024; // 500MB
$fileSize = filesize($realPath);

if ($fileSize > $maxSize) {
    http_response_code(413);
    echo json_encode(['error' => 'File too large']);
    exit;
}

// Verify file is readable
if (!is_readable($realPath)) {
    http_response_code(403);
    echo json_encode(['error' => 'File not readable']);
    exit;
}

// Set appropriate headers for download
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . basename($filename) . '"');
header('Content-Length: ' . $fileSize);
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// Send file content
if (!readfile($realPath)) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to read file']);
    exit;
}

exit;
?>
