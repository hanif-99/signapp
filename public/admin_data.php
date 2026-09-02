<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
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
    echo json_encode(['error' => 'db_connection_failed']);
    exit;
}

$draw = isset($_GET['draw']) ? (int)$_GET['draw'] : 0;
$start = isset($_GET['start']) ? max(0, (int)$_GET['start']) : 0;
$length = isset($_GET['length']) ? (int)$_GET['length'] : 25;
$length = ($length <= 0) ? 25 : $length;

$searchGlobal = trim((string)($_GET['search']['value'] ?? ''));
$orderInput = $_GET['order'] ?? [];
$columnsInput = $_GET['columns'] ?? [];
$filterStatus = $_GET['filterStatus'] ?? 'all';

$downloadedOnly = isset($_GET['downloaded_only']) && (
    $_GET['downloaded_only'] === '1' ||
    strtolower((string)$_GET['downloaded_only']) === 'true'
);
if ($downloadedOnly) {
    $filterStatus = 'downloaded';
}

$columnMap = [
    0 => null,
    1 => null,
    2 => 'd.id',
    3 => null,
    4 => 'd.signed_at',
    5 => "COALESCE(d.downloaded_confirmed, d.downloaded_at)",
    6 => "d.note_status",
    7 => "CASE WHEN d.signed_path IS NOT NULL AND d.signed_path <> '' THEN 1 ELSE 0 END",
    8 => null,
];

$whereClauses = ["(u.username IS NULL OR LOWER(u.username) <> 'admin')"];
$params = [];

switch ($filterStatus) {
    case 'signed':
        $whereClauses[] = "d.signed_at IS NOT NULL";
        break;
    case 'unsigned':
        $whereClauses[] = "d.signed_at IS NULL";
        break;
    case 'downloaded':
        $whereClauses[] = "(d.downloaded_confirmed IS NOT NULL OR d.downloaded_at IS NOT NULL)";
        break;
    case 'approved':
        $whereClauses[] = "LOWER(COALESCE(d.approval_status, '')) = 'approved'";
        break;
    default:
        break;
}

if ($searchGlobal !== '') {
    $whereClauses[] = "(CAST(d.id AS CHAR) LIKE :gsearch OR u.username LIKE :gsearch OR u.name LIKE :gsearch OR u.nipp LIKE :gsearch)";
    $params[':gsearch'] = '%' . $searchGlobal . '%';
}

foreach ($columnsInput as $colIdx => $colDef) {
    $colSearch = trim((string)($colDef['search']['value'] ?? ''));
    if ($colSearch === '') continue;
    $colIdx = (int)$colIdx;

    if ($colIdx === 2) { // Doc ID
        $whereClauses[] = "CAST(d.id AS CHAR) LIKE :col_search_{$colIdx}";
        $params[":col_search_{$colIdx}"] = '%' . $colSearch . '%';
    } elseif ($colIdx === 3) { // Nama Pegawai
        $whereClauses[] = "(u.username LIKE :col_search_{$colIdx} OR u.name LIKE :col_search_{$colIdx})";
        $params[":col_search_{$colIdx}"] = '%' . $colSearch . '%';
    } elseif ($colIdx === 6) { // Status (note_status stored on documents)
        $whereClauses[] = "d.note_status LIKE :col_search_{$colIdx}";
        $params[":col_search_{$colIdx}"] = '%' . $colSearch . '%';
    } elseif ($colIdx === 7) { // PDF column
        $s = strtolower($colSearch);
        if ($s === 'pdf') {
            $whereClauses[] = "d.signed_path IS NOT NULL AND d.signed_path <> ''";
        } elseif ($s === 'kosong' || $s === 'empty' || $s === 'none') {
            $whereClauses[] = "(d.signed_path IS NULL OR d.signed_path = '')";
        } else {
            $whereClauses[] = "(d.signed_path LIKE :col_search_{$colIdx})";
            $params[":col_search_{$colIdx}"] = '%' . $colSearch . '%';
        }
    }
}

$whereSql = '';
if (!empty($whereClauses)) $whereSql = 'WHERE ' . implode(' AND ', $whereClauses);

// total records (unfiltered)
try {
    $totalStmt = $pdo->query("SELECT COUNT(*) FROM documents d LEFT JOIN users u ON u.id = d.user_id WHERE (u.username IS NULL OR LOWER(u.username) <> 'admin')");
    $total = (int)$totalStmt->fetchColumn();
} catch (Exception $e) {
    error_log('admin_data total count error: '.$e->getMessage());
    echo json_encode(['error' => 'server_error']);
    exit;
}

// recordsFiltered
try {
    $countSql = "SELECT COUNT(*) FROM documents d LEFT JOIN users u ON u.id = d.user_id {$whereSql}";
    $countStmt = $pdo->prepare($countSql);
    foreach ($params as $k => $v) $countStmt->bindValue($k, $v);
    $countStmt->execute();
    $recordsFiltered = (int)$countStmt->fetchColumn();
} catch (Exception $e) {
    error_log('admin_data filtered count error: '.$e->getMessage());
    echo json_encode(['error' => 'server_error']);
    exit;
}

// ORDER BY
$orderParts = [];
if (is_array($orderInput) && count($orderInput)) {
    foreach ($orderInput as $ord) {
        $colIdx = (int)($ord['column'] ?? -1);
        $dir = strtoupper(($ord['dir'] ?? 'ASC')) === 'ASC' ? 'ASC' : 'DESC';
        if (!isset($columnMap[$colIdx])) continue;
        $expr = $columnMap[$colIdx];
        if ($expr === null) continue;
        $orderParts[] = $expr . ' ' . $dir;
    }
}
$orderClause = '';
if (!empty($orderParts)) {
    $orderClause = 'ORDER BY ' . implode(', ', $orderParts);
} else {
    $orderClause = 'ORDER BY d.id DESC';
}

$sql = "
SELECT d.id,
       d.signed_at,
       d.signed_path,
       COALESCE(d.downloaded_confirmed, d.downloaded_at) AS downloaded_at,
       d.approval_status,
       d.user_id,
       u.username,
       u.name AS fullname,
       d.note_status,
       d.note_text,
       d.note_admin_id,
       d.note_updated_at,
       (CASE WHEN d.signed_path IS NOT NULL AND d.signed_path <> '' THEN 1 ELSE 0 END) AS has_pdf,
       d.downloaded_confirmed,
       d.downloaded_at AS raw_downloaded_at
FROM documents d
LEFT JOIN users u ON u.id = d.user_id
{$whereSql}
{$orderClause}
LIMIT :start, :length
";

try {
    $stmt = $pdo->prepare($sql);
    foreach ($params as $k => $v) $stmt->bindValue($k, $v);
    $stmt->bindValue(':start', $start, PDO::PARAM_INT);
    $stmt->bindValue(':length', $length, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log('admin_data fetch error: '.$e->getMessage());
    echo json_encode(['error' => 'server_error']);
    exit;
}

$data = [];
$storageBase = rtrim($config['storage'] ?? __DIR__ . '/../storage', '/\\');
$realStorage = realpath($storageBase);

foreach ($rows as $r) {
    $rawSigned = !empty($r['signed_at']) && !empty($r['signed_path']);
    $rawDownloaded = !empty($r['downloaded_at']);
    
    $isDownloaded = !empty($r['downloaded_confirmed']) || !empty($r['raw_downloaded_at']);

$pdfExists = false;
if (!empty($r['signed_path'])) {
    $p = $r['signed_path'];
    if (is_file($p)) {
        $pdfExists = true;
    } else {
        if ($realStorage) {
            $candidate = $realStorage . DIRECTORY_SEPARATOR . ltrim($p, '/\\');
            if (is_file($candidate)) {
                $pdfExists = true;
            }
        }
    }
}

// Generate PDF HTML button
$pdfHtml = $pdfExists ? '<a class="btn btn-sm btn-outline-info" href="admin_download.php?id=' . rawurlencode($r['id']) . '" target="_blank">PDF</a>' : '<button class="btn btn-sm btn-outline-secondary" disabled>Kosong</button>';

    $approval = strtolower((string)($r['approval_status'] ?? ''));
        
    $notesDisabled = ($approval !== 'approved' || !$isDownloaded) ? 'disabled' : '';
    $approveDisabled = (!$rawSigned || $approval === 'approved') ? 'disabled' : '';
    $resetDisabled = ((!$rawSigned && $approval !== 'approved') || !$isDownloaded) ? 'disabled' : '';

    if ($approval === 'approved') {
        $approveHtml = '<button class="btn btn-sm btn-approved-outline action-btn" data-id="' . htmlspecialchars($r['id'], ENT_QUOTES) . '" disabled aria-disabled="true">Approved</button> ';
    } else {
        $approveHtml = '<button class="btn btn-sm btn-success action-btn" data-action="approve" data-id="' . htmlspecialchars($r['id'], ENT_QUOTES) . '" ' . $approveDisabled . '>Approve</button> ';
    }

    $actions = '<div class="btn-group" role="group">';
    $actions .= '<button class="btn btn-sm btn-outline-primary action-btn-note" data-action="note" data-id="' . htmlspecialchars($r['id'], ENT_QUOTES) . '" data-note-status="' . htmlspecialchars($r['note_status'] ?? '', ENT_QUOTES) . '" data-note-text="' . htmlspecialchars($r['note_text'] ?? '', ENT_QUOTES) . '" ' . $notesDisabled . '>Notes</button> ';
    $actions .= $approveHtml;
    $actions .= '<button class="btn btn-sm btn-warning action-btn" data-action="reset" data-id="' . htmlspecialchars($r['id'], ENT_QUOTES) . '" ' . $resetDisabled . '>Reset</button> ';
    $actions .= '<button class="btn btn-sm btn-outline-danger action-btn" data-action="delete" data-id="' . htmlspecialchars($r['id'], ENT_QUOTES) . '">Hapus</button>';
    $actions .= '</div>';

	$pdfHtml = $pdfExists ? '<a class="btn btn-sm btn-outline-info" href="admin_download.php?id=' . rawurlencode($r['id']) . '" target="_blank">PDF</a>' : '<button class="btn btn-sm btn-outline-secondary" disabled>Kosong</button>';

    $statusHtml = '<span class="small-muted">-</span>';
    if (!empty($r['note_status'])) {
        $ns = strtolower($r['note_status']);
        if ($ns === 'fix' || $ns === 'perbaikan') $statusHtml = '<span class="badge bg-warning text-dark note-badge">Perbaikan</span>';
        elseif ($ns === 'done' || $ns === 'selesai') $statusHtml = '<span class="badge bg-success note-badge">Selesai</span>';
    }

    $userHtml = '<div class="fw-semibold">' . htmlspecialchars($r['username'] ?? '-', ENT_QUOTES) . '</div><div class="small-muted">' . htmlspecialchars($r['fullname'] ?? '-', ENT_QUOTES) . '</div>';

    // Format signed date: tanggal-bulan jam:menit (tanpa tahun)
    $signedFormatted = '-';
    if (!empty($r['signed_at'])) {
        $signedFormatted = date('d M H:i', strtotime($r['signed_at']));
    }

    $data[] = [
        'checkbox' => '<input class="rowSelect" type="checkbox" value="' . htmlspecialchars($r['id'], ENT_QUOTES) . '"/>',
        'row_no' => '',
        'id' => htmlspecialchars($r['id'], ENT_QUOTES),
        'user_html' => $userHtml,
        'signed' => $signedFormatted,
        'downloaded' => !empty($r['downloaded_at']) ? date('d M Y H:i', strtotime($r['downloaded_at'])) : '-',
        'status_html' => $statusHtml,
        'pdf_html' => $pdfHtml,
        'actions_html' => $actions,
        'raw_id' => $r['id'],
        'raw_signed' => $rawSigned ? 1 : 0,
        'raw_status' => $r['note_status'] ?? '',
        'raw_note_status' => $r['note_status'] ?? '',
        'raw_note_text' => $r['note_text'] ?? '',
        'raw_note_id' => $r['id'],
        'raw_pdf' => intval($r['has_pdf'] ?? ($pdfExists ? 1 : 0)),
        'raw_approval_status' => $r['approval_status'] ?? '',
        'raw_downloaded' => $rawDownloaded ? 1 : 0,
        'raw_is_downloaded' => $isDownloaded ? 1 : 0,
        'note_status' => $r['note_status'] ?? null,
        'note_text' => $r['note_text'] ?? null,
        'note_admin_id' => $r['note_admin_id'] ?? null,
        'note_updated_at' => $r['note_updated_at'] ?? null,
        'approval_status' => $r['approval_status'] ?? null,
    ];
}

$response = [
    'draw' => $draw,
    'recordsTotal' => $total,
    'recordsFiltered' => $recordsFiltered,
    'data' => $data,
];

echo json_encode($response);
exit;
