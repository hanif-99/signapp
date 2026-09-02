<?php

session_start();
require_once __DIR__ . '/../vendor/autoload.php';
$config = require __DIR__ . '/../config.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403); echo "Forbidden"; exit;
}

$file = basename($_GET['file'] ?? '');
if ($file === '') { http_response_code(400); echo "Missing file"; exit; }

$storage = rtrim($config['storage'] ?? __DIR__ . '/../storage', '/\\');
$path = $storage . '/tmp/' . $file;
if (!file_exists($path)) { http_response_code(404); echo "Not found"; exit; }

// ensure the file is under storage/tmp
$real = realpath($path);
$realTmp = realpath($storage . '/tmp');
if ($real === false || $realTmp === false || strpos($real, $realTmp) !== 0) {
    http_response_code(403); echo "Forbidden"; exit;
}

// stream and delete afterwards
$fsize = filesize($real);
if (ob_get_level()) ob_end_clean();
header('Content-Description: File Transfer');
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . basename($real) . '"');
header('Content-Length: ' . $fsize);
readfile($real);

// attempt to delete
@unlink($real);
exit;