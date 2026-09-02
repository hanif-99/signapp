<?php
/**
 * Cleanup script untuk menghapus temporary ZIP files yang sudah lama
 * Jalankan via cron job atau background task setiap jam
 * 
 * Contoh cron: 0 * * * * php /path/to/public/cleanup_temp_downloads.php
 */

$tempDir = __DIR__ . '/../temp_downloads';
$maxAge = 3600; // 1 jam dalam detik

if (!is_dir($tempDir)) {
    exit;
}

$files = glob($tempDir . '/documents_*.zip');
$now = time();

foreach ($files as $file) {
    if (is_file($file)) {
        $fileAge = $now - filemtime($file);
        if ($fileAge > $maxAge) {
            @unlink($file);
        }
    }
}

// Log cleanup (optional)
error_log('[CLEANUP] Temporary downloads cleaned up at ' . date('Y-m-d H:i:s'));
?>
