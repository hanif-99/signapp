<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Forbidden']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid request method']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['_csrf']) || $input['_csrf'] !== ($_SESSION['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'CSRF token invalid']);
    exit;
}

$action = $input['action'] ?? null;
$ids = (array)($input['ids'] ?? []);
$ids = array_filter(array_map('intval', $ids));

if (!$action || empty($ids)) {
    echo json_encode(['success' => false, 'error' => 'Invalid input']);
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

$placeholders = implode(',', array_fill(0, count($ids), '?'));

switch ($action) {
    case 'download':
        handleDownload($pdo, $ids, $config);
        break;
    
    case 'approve':
        handleApprove($pdo, $ids);
        break;
    
    case 'delete':
        handleDelete($pdo, $ids);
        break;
    
    case 'reset':
        handleReset($pdo, $ids);
        break;
    
    default:
        echo json_encode(['success' => false, 'error' => 'Unknown action']);
        exit;
}

function handleDownload($pdo, $ids, $config) {
    try {
        $sql = "SELECT d.id, d.signed_path, u.username FROM documents d 
                LEFT JOIN users u ON u.id = d.user_id 
                WHERE d.id IN (" . implode(',', array_fill(0, count($ids), '?')) . ") 
                AND d.signed_path IS NOT NULL AND d.signed_path != ''";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($ids);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($rows)) {
            echo json_encode(['success' => false, 'error' => 'no_signed_files']);
            exit;
        }
        
        $storageBase = rtrim($config['storage'] ?? __DIR__ . '/../storage', '/\\');
        $realStorage = realpath($storageBase);
        
        // Create temp directory in project root
        $tempDir = __DIR__ . '/../temp_downloads';
        if (!is_dir($tempDir)) {
            if (!@mkdir($tempDir, 0755, true)) {
                echo json_encode(['success' => false, 'error' => 'temp_dir_creation_failed']);
                exit;
            }
        }
        
        $tempZipPath = tempnam($tempDir, 'signapp_');
        
        if ($tempZipPath === false) {
            echo json_encode(['success' => false, 'error' => 'temp_file_creation_failed']);
            exit;
        }
        
        $zip = new ZipArchive();
        if ($zip->open($tempZipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            @unlink($tempZipPath);
            echo json_encode(['success' => false, 'error' => 'zip_creation_failed']);
            exit;
        }
        
        $addedCount = 0;
        $usedNames = [];
        
        foreach ($rows as $row) {
            $filePath = $row['signed_path'];
            
            // Try direct path first
            if (!is_file($filePath) && $realStorage) {
                $filePath = $realStorage . DIRECTORY_SEPARATOR . ltrim($row['signed_path'], '/\\');
            }
            
            if (is_file($filePath)) {
                // Get username (fallback to doc_id if username not available)
                $username = !empty($row['username']) ? $row['username'] : 'doc_' . $row['id'];
                
                // Sanitize filename: allow alphanumeric, underscore, hyphen, space
                $username = preg_replace('/[^a-zA-Z0-9_\-\s]/', '_', $username);
                $username = trim($username);
                
                // Get file extension
                $fileExt = pathinfo($filePath, PATHINFO_EXTENSION);
                
                // Build filename - hanya username.ext
                $fileName = $username . '.' . $fileExt;
                
                // Handle duplicate filenames
                $counter = 1;
                $originalFileName = $fileName;
                while (isset($usedNames[$fileName])) {
                    $fileName = $username . '_' . $counter . '.' . $fileExt;
                    $counter++;
                }
                $usedNames[$fileName] = true;
                
                if ($zip->addFile($filePath, $fileName)) {
                    $addedCount++;
                }
            }
        }
        
        $zip->close();
        
        if ($addedCount === 0) {
            @unlink($tempZipPath);
            echo json_encode(['success' => false, 'error' => 'no_files_added']);
            exit;
        }
        
        // Generate simple filename dengan format timestamp device
        // Format: document_YYYYMMDD_HHMMSS.zip
        $fileName = 'document_' . date('YmdHis') . '.zip';
        $finalPath = $tempDir . '/' . $fileName;
        
        // Handle jika file sudah ada (add random suffix)
        $counter = 0;
        while (file_exists($finalPath) && $counter < 10) {
            $counter++;
            $fileName = 'document_' . date('YmdHis') . '_' . $counter . '.zip';
            $finalPath = $tempDir . '/' . $fileName;
        }
        
        if (!rename($tempZipPath, $finalPath)) {
            @unlink($tempZipPath);
            echo json_encode(['success' => false, 'error' => 'file_save_failed']);
            exit;
        }
        
        // Make sure file is readable
        @chmod($finalPath, 0644);
        
        echo json_encode([
            'success' => true,
            'url' => 'admin_download_zip.php?f=' . urlencode($fileName),
            'count' => $addedCount,
            'fileName' => $fileName
        ]);
        exit;
        
    } catch (Exception $e) {
        error_log('handleDownload error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'exception_error', 'message' => $e->getMessage()]);
        exit;
    }
}

function handleApprove($pdo, $ids) {
    try {
        $sql = "UPDATE documents SET approval_status = 'approved' WHERE id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($ids);
        $count = $stmt->rowCount();
        
        echo json_encode(['success' => true, 'count' => $count, 'updated' => $ids]);
        exit;
    } catch (Exception $e) {
        error_log('handleApprove error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'exception_error']);
        exit;
    }
}

function handleDelete($pdo, $ids) {
    try {
        $sql = "DELETE FROM documents WHERE id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($ids);
        $count = $stmt->rowCount();
        
        echo json_encode(['success' => true, 'count' => $count]);
        exit;
    } catch (Exception $e) {
        error_log('handleDelete error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'exception_error']);
        exit;
    }
}

function handleReset($pdo, $ids) {
    try {
        $sql = "UPDATE documents SET downloaded_at = NULL WHERE id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($ids);
        $count = $stmt->rowCount();
        
        echo json_encode(['success' => true, 'count' => $count]);
        exit;
    } catch (Exception $e) {
        error_log('handleReset error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'exception_error']);
        exit;
    }
}
?>
