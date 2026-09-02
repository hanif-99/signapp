<?php

session_start();
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403); echo 'Forbidden'; exit;
}

$posted_csrf = $_POST['_csrf'] ?? '';
if (empty($posted_csrf) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $posted_csrf)) {
    http_response_code(400); echo 'Invalid CSRF'; exit;
}

require_once __DIR__ . '/../vendor/autoload.php';
$config = require __DIR__ . '/../config.php';

$payloadRaw = $_POST['payload'] ?? null;
if (!$payloadRaw) {
    http_response_code(400); echo 'Missing payload'; exit;
}
$payload = json_decode($payloadRaw, true);
if ($payload === null) {
    http_response_code(400); echo 'Invalid payload JSON'; exit;
}

try {
    $pdo = new PDO(
        "mysql:host={$config['db']['host']};dbname={$config['db']['dbname']};charset=utf8mb4",
        $config['db']['user'],
        $config['db']['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (Exception $e) {
    http_response_code(500);
    echo 'DB connection failed';
    exit;
}

$columnMap = [
    2 => 'd.id',
    3 => 'u.username',
    4 => 'd.signed_at',
    5 => 'd.downloaded_at',
    6 => 'd.note_status'
];

// Correct columns from actual database
$selectColumns = [
    'd.id',
    'u.username AS username',
    'u.name AS fullname',
    'd.signed_at',
    'd.downloaded_at',
    'd.downloaded_confirmed',
    'd.note_status',
    'd.approval_status'
];

$where = [];
$params = [];

$filterStatus = trim((string)($payload['filterStatus'] ?? 'all'));

// PRIMARY LOGIC: Check for selected items (checkbox) first
$hasSelectedItems = false;
if (!empty($payload['selected']) && is_array($payload['selected'])) {
    $ids = array_filter($payload['selected'], function($v){ return ctype_digit((string)$v); });
    if (count($ids) > 0) {
        $hasSelectedItems = true;
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $where[] = "d.id IN ($placeholders)";
        foreach ($ids as $id) $params[] = (int)$id;
    }
}

// FALLBACK LOGIC: If no items selected, apply filters (search and status) - EXPORT ALL
if (!$hasSelectedItems) {
    $search = trim((string)($payload['search'] ?? ''));
    if ($search !== '') {
        $s = '%' . str_replace('%','\\%',$search) . '%';
        $where[] = "(u.username LIKE ? OR u.name LIKE ? OR CAST(d.id AS CHAR) LIKE ? OR COALESCE(d.approval_status, '') LIKE ?)";
        $params[] = $s; 
        $params[] = $s; 
        $params[] = $s; 
        $params[] = $s;
    }

    if ($filterStatus !== '' && $filterStatus !== 'all') {
        if ($filterStatus === 'signed') {
            $where[] = "d.signed_at IS NOT NULL";
        } elseif ($filterStatus === 'unsigned') {
            $where[] = "d.signed_at IS NULL";
        } elseif ($filterStatus === 'downloaded') {
            $where[] = "(d.downloaded_at IS NOT NULL OR d.downloaded_confirmed IS NOT NULL)";
        } elseif ($filterStatus === 'approved') {
            $where[] = "LOWER(COALESCE(d.approval_status, '')) = 'approved'";
        }
    }
}

$whereSql = '';
if (!empty($where)) $whereSql = ' WHERE ' . implode(' AND ', $where);

// ORDER BY - support DataTables order format
$orderSql = '';
if (!empty($payload['order']) && is_array($payload['order'])) {
    $orders = [];
    foreach ($payload['order'] as $o) {
        // DataTables format: {column: int, dir: 'asc'|'desc'}
        if (isset($o['column']) && isset($o['dir'])) {
            $colIndex = intval($o['column']);
            $dir = (strtolower($o['dir']) === 'desc') ? 'DESC' : 'ASC';
            
            if ($colIndex === 2) { // Doc ID
                $orders[] = 'd.id ' . $dir;
            } elseif ($colIndex === 3) { // Username
                $orders[] = 'u.username ' . $dir;
            } elseif ($colIndex === 4) { // Signed
                $orders[] = 'd.signed_at ' . $dir;
            } elseif ($colIndex === 5) { // Downloaded
                $orders[] = 'd.downloaded_at ' . $dir;
            } elseif ($colIndex === 6) { // Note Status
                $orders[] = 'd.note_status ' . $dir;
            }
        }
    }
    if (!empty($orders)) $orderSql = ' ORDER BY ' . implode(', ', $orders);
}

// Default sort if no order specified
if (empty($orderSql)) {
    $orderSql = ' ORDER BY d.id DESC';
}

$selectSql = implode(', ', $selectColumns);
$sql = "SELECT {$selectSql} FROM documents d LEFT JOIN users u ON u.id = d.user_id {$whereSql} {$orderSql}";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
} catch (Exception $e) {
    http_response_code(500);
    echo 'Query failed: ' . $e->getMessage();
    exit;
}

$filename = 'SignApp_report_' . date('d-m-Y_H-i-s') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

$output = fopen('php://output', 'w');
fwrite($output, chr(0xEF) . chr(0xBB) . chr(0xBF)); // BOM for UTF-8

// CSV Header
fputcsv($output, [
    'ID', 
    'Username', 
    'Nama Lengkap', 
    'Signed At', 
    'Downloaded At', 
    'Downloaded Confirmed',
    'Note Status', 
    'Approval Status'
]);

$rowCount = 0;
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $csvRow = [
        $row['id'] ?? '',
        $row['username'] ?? '',
        $row['fullname'] ?? '',
        $row['signed_at'] ?? '',
        $row['downloaded_at'] ?? '',
        $row['downloaded_confirmed'] ?? '',
        $row['note_status'] ?? '',
        $row['approval_status'] ?? ''
    ];
    fputcsv($output, $csvRow);
    $rowCount++;
}

fclose($output);
exit;
?>

