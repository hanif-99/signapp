<?php
session_start();
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: index.php'); exit;
}

require_once __DIR__ . '/../vendor/autoload.php'; // optional

$VERIF_TTL = 60 * 30; // 30 minutes

$ACCESS_CODE = getenv('DOC_ACCESS_CODE') ?: null;
if (!$ACCESS_CODE) {
    $access_file = __DIR__ . '/../.doc_access';
    if (is_readable($access_file)) {
        $ACCESS_CODE = trim((string)@file_get_contents($access_file));
    }
}
if (!$ACCESS_CODE) {
    $ACCESS_CODE = null;
}

if (!isset($_SESSION['doc_verify_attempts']) || !is_array($_SESSION['doc_verify_attempts'])) {
    $_SESSION['doc_verify_attempts'] = ['count' => 0, 'last' => 0, 'blocked_until' => 0];
}

$doc_verified = false;
$ua_hash = hash('sha256', $_SERVER['HTTP_USER_AGENT'] ?? '');
if (!empty($_SESSION['doc_verified_at']) && is_numeric($_SESSION['doc_verified_at'])
    && !empty($_SESSION['doc_verified_ua']) && $_SESSION['doc_verified_ua'] === $ua_hash) {
    if ((time() - (int)$_SESSION['doc_verified_at']) <= $VERIF_TTL) {
        $doc_verified = true;
    } else {
        // expired
        unset($_SESSION['doc_verified_at'], $_SESSION['doc_verified_ua']);
        $doc_verified = false;
    }
}

// CSRF
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf_token'];

function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function fmt_bytes($b){
    if ($b < 1024) return $b.' B';
    if ($b < 1024*1024) return round($b/1024,2).' KB';
    return round($b/(1024*1024),2).' MB';
}

function find_signed_dir(){
    $candidates = [
        realpath(__DIR__ . '/../storage/signed'),  // Primary
        realpath(__DIR__ . '/storage/signed'),      // Fallback
    ];
    
    foreach ($candidates as $c) {
        if ($c && is_dir($c) && is_readable($c)) {
            return $c;
        }
    }
    return false;
}

$basedir = find_signed_dir();

function get_json_body(){
    $raw = file_get_contents('php://input');
    $data = @json_decode($raw, true);
    if (is_array($data)) return $data;
    return $_POST;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = file_get_contents('php://input');
    $json = json_decode($raw, true);
    $isJson = is_array($json);

    $action = $isJson ? ($json['action'] ?? '') : ($_POST['action'] ?? '');
    if ($action === 'verify_doc') {
        header('Content-Type: application/json; charset=utf-8');
        $token = $isJson ? ($json['_csrf'] ?? '') : ($_POST['_csrf'] ?? '');
        if ($token !== ($_SESSION['csrf_token'] ?? '')) {
            echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']); exit;
        }
        $code = $isJson ? ($json['code'] ?? '') : ($_POST['code'] ?? '');
        $code = (string)$code;

        $attempts =& $_SESSION['doc_verify_attempts'];
        if (!empty($attempts['blocked_until']) && time() < (int)$attempts['blocked_until']) {
            $wait = (int)$attempts['blocked_until'] - time();
            echo json_encode(['success' => false, 'error' => "Terlalu banyak percobaan. Coba lagi dalam {$wait} detik."]); exit;
        }

        $stored = $ACCESS_CODE; // may be null
        if ($stored === null) {
            // no code configured — safe fail
            echo json_encode(['success' => false, 'error' => 'Access code not configured']); exit;
        }

        $ok = false;
        // If stored looks like a password_hash value, use password_verify
        if (strlen($stored) > 0 && ($stored[0] === '$')) {
            // assume hashed via password_hash()
            if (@password_verify($code, $stored)) $ok = true;
        } else {
            // plain text compare (using hash_equals to mitigate timing)
            if (hash_equals((string)$stored, $code)) $ok = true;
        }

        if ($ok) {
            $_SESSION['doc_verified_at'] = time();
            $_SESSION['doc_verified_ua'] = hash('sha256', $_SERVER['HTTP_USER_AGENT'] ?? '');
            $_SESSION['doc_verify_attempts'] = ['count' => 0, 'last' => 0, 'blocked_until' => 0];
            session_regenerate_id(true);
            echo json_encode(['success' => true, 'ttl' => $VERIF_TTL]);
            exit;
        } else {
            $attempts['count'] = ($attempts['count'] ?? 0) + 1;
            $attempts['last'] = time();
            $threshold = 5;
            $block_seconds = 15 * 60;
            if ($attempts['count'] >= $threshold) {
                $attempts['blocked_until'] = time() + $block_seconds;
                $attempts['count'] = 0; // reset count after blocking
                echo json_encode(['success' => false, 'error' => 'Terlalu banyak percobaan. Diblokir sementara.']); exit;
            }
            echo json_encode(['success' => false, 'error' => 'Kode salah']); exit;
        }
    }

    if ($action === 'lock_doc') {
        header('Content-Type: application/json; charset=utf-8');
        $data = $isJson ? $json : $_POST;
        $token = $data['_csrf'] ?? '';
        if ($token !== ($_SESSION['csrf_token'] ?? '')) {
            echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']); exit;
        }
        unset($_SESSION['doc_verified_at'], $_SESSION['doc_verified_ua']);
        echo json_encode(['success' => true]);
        exit;
    }

    if (!$doc_verified) {
        if (!empty($_FILES) || (isset($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'multipart/') === 0)) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'error' => 'Access denied: verification required']); exit;
        }
        $body = get_json_body();
        if (is_array($body) && isset($body['action'])) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'error' => 'Access denied: verification required']); exit;
        }
    }

}

if (isset($_GET['download']) && $basedir) {
    if (!$doc_verified) {
        http_response_code(403);
        echo "Akses ditolak. Harap verifikasi kode terlebih dahulu.";
        exit;
    }

    $fname = (string)$_GET['download'];
    $fname_base = basename($fname);
    $target = @realpath($basedir . DIRECTORY_SEPARATOR . $fname_base);
    if ($target === false || strpos($target, $basedir) !== 0 || !is_file($target) || !is_readable($target)) {
        http_response_code(404);
        echo "File tidak ditemukan.";
        exit;
    }
    if (strtolower(pathinfo($target, PATHINFO_EXTENSION)) !== 'pdf') {
        http_response_code(403);
        echo "Akses hanya untuk .pdf.";
        exit;
    }
    set_time_limit(0);
    header('Content-Description: File Transfer');
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . e(basename($target)) . '"');
    header('Content-Length: ' . filesize($target));
    header('Expires: 0');
    header('Cache-Control: must-revalidate');
    header('Pragma: public');
    flush();
    $fp = fopen($target, 'rb');
    if ($fp) {
        while (!feof($fp)) {
            echo fread($fp, 8192);
            flush();
        }
        fclose($fp);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!empty($_FILES) && isset($_FILES['pdffiles'])) {
        header('Content-Type: application/json; charset=utf-8');
        $token = $_POST['_csrf'] ?? '';
        if ($token !== ($_SESSION['csrf_token'] ?? '')) {
            echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']); exit;
        }
        if (!$doc_verified) { echo json_encode(['success' => false, 'error' => 'Verification required']); exit; }

        if (!$basedir) {
            echo json_encode(['success' => false, 'error' => 'Signed directory not available']); exit;
        }

        $results = [];
        $names = $_FILES['pdffiles']['name'];
        $tmp_names = $_FILES['pdffiles']['tmp_name'];
        $errors = $_FILES['pdffiles']['error'];
        $sizes = $_FILES['pdffiles']['size'];

        if (!is_array($names)) {
            $names = [$names];
            $tmp_names = [$tmp_names];
            $errors = [$errors];
            $sizes = [$sizes];
        }

        $maxBytes = 1073741824; // 1GB

        foreach ($names as $idx => $origName) {
            $status = ['name' => (string)$origName, 'ok' => false, 'error' => null];
            $err = $errors[$idx] ?? UPLOAD_ERR_OK;
            $tmp = $tmp_names[$idx] ?? '';
            $size = $sizes[$idx] ?? 0;

            if ($err !== UPLOAD_ERR_OK) {
                $msg = 'Upload error';
                if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) $msg = 'File melebihi batas server.';
                $status['error'] = $msg;
                $results[] = $status;
                continue;
            }
            if ($size > $maxBytes) {
                $status['error'] = 'Ukuran file melebihi 1GB';
                $results[] = $status;
                continue;
            }
            $base = basename($origName);
            $ext = strtolower(pathinfo($base, PATHINFO_EXTENSION));
            if ($ext !== 'pdf') {
                $status['error'] = 'Hanya .pdf yang diizinkan';
                $results[] = $status;
                continue;
            }
            $target = $basedir . DIRECTORY_SEPARATOR . $base;
            // try override
            if (is_file($target)) {
                @chmod($target, 0664);
                @unlink($target);
            }
            if (!move_uploaded_file($tmp, $target)) {
                $status['error'] = 'Gagal menyimpan file. Periksa permission.';
                $results[] = $status;
                continue;
            }
            @chmod($target, 0644);
            $status['ok'] = true;
            $status['size'] = $size;
            $results[] = $status;
        }

        echo json_encode(['success' => true, 'results' => $results]);
        exit;
    }

    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!is_array($data)) $data = $_POST;
    header('Content-Type: application/json; charset=utf-8');

    $action = $data['action'] ?? '';
    $token = $data['_csrf'] ?? '';
    if ($token !== ($_SESSION['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']); exit;
    }

    if (!$doc_verified) {
        echo json_encode(['success' => false, 'error' => 'Verification required']); exit;
    }

    if ($action === 'delete') {
        if (!$basedir) { echo json_encode(['success' => false, 'error' => 'Signed directory not available']); exit; }
        $file = $data['file'] ?? '';
        $file_base = basename((string)$file);
        $target = @realpath($basedir . DIRECTORY_SEPARATOR . $file_base);
        if ($target === false || strpos($target, $basedir) !== 0 || !is_file($target)) {
            echo json_encode(['success' => false, 'error' => 'File not found']); exit;
        }
        if (strtolower(pathinfo($target, PATHINFO_EXTENSION)) !== 'pdf') {
            echo json_encode(['success' => false, 'error' => 'Only .pdf files can be deleted']); exit;
        }
        if (!is_writable($target)) {
            echo json_encode(['success' => false, 'error' => 'No permission to delete file']); exit;
        }
        $ok = @unlink($target);
        if ($ok) echo json_encode(['success' => true, 'file' => $file_base]);
        else echo json_encode(['success' => false, 'error' => 'Failed to delete file']);
        exit;
    }

    if ($action === 'delete_multiple') {
        if (!$basedir) { echo json_encode(['success' => false, 'error' => 'Signed directory not available']); exit; }
        $files = $data['files'] ?? [];
        if (!is_array($files)) { echo json_encode(['success' => false, 'error' => 'Invalid files list']); exit; }

        $results = [];
        foreach ($files as $f) {
            $file_base = basename((string)$f);
            $target = @realpath($basedir . DIRECTORY_SEPARATOR . $file_base);
            $res = ['name' => $file_base, 'ok' => false, 'error' => null];
            if ($target === false || strpos($target, $basedir) !== 0 || !is_file($target)) {
                $res['error'] = 'File not found';
                $results[] = $res;
                continue;
            }
            if (strtolower(pathinfo($target, PATHINFO_EXTENSION)) !== 'pdf') {
                $res['error'] = 'Only .pdf files can be deleted';
                $results[] = $res;
                continue;
            }
            if (!is_writable($target)) {
                $res['error'] = 'No permission to delete file';
                $results[] = $res;
                continue;
            }
            $ok = @unlink($target);
            if ($ok) $res['ok'] = true;
            else $res['error'] = 'Failed to delete file';
            $results[] = $res;
        }

        $total = count($results);
        $deleted = 0;
        $failed = 0;
        foreach ($results as $r) { if (!empty($r['ok'])) $deleted++; else $failed++; }
        $allOk = ($failed === 0) && ($total > 0);

        echo json_encode([
            'success' => $allOk,
            'results' => $results,
            'summary' => ['total' => $total, 'deleted' => $deleted, 'failed' => $failed]
        ]);
        exit;
    }

    echo json_encode(['success' => false, 'error' => 'Unknown action']);
    exit;
}

$files = [];
if ($doc_verified && $basedir && is_dir($basedir)) {
    $all = @scandir($basedir);
    if ($all === false) $all = [];
    foreach ($all as $f) {
        if ($f === '.' || $f === '..') continue;
        $fp = $basedir . DIRECTORY_SEPARATOR . $f;
        if (!is_file($fp) || !is_readable($fp)) continue;
        if (strtolower(pathinfo($f, PATHINFO_EXTENSION)) !== 'pdf') continue;
        $files[] = [
            'name' => $f,
            'size' => @filesize($fp) ?: 0,
            'mtime' => @filemtime($fp) ?: 0,
            'writable' => is_writable($fp),
        ];
    }
    usort($files, function($a,$b){ return $b['mtime'] <=> $a['mtime']; });
}
?>
<!doctype html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <title>Admin SignApp</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link rel="icon" type="image/x-icon" href="/img/favicon.ico">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
  <style>
    :root{
      --primary: #16a34a;
      --primary-dark: #0f9b3f;
      --muted: #6b7280;
      --card-bg: #ffffff;
      --drop-bg: #f7faf8;
      --btn-font: .78rem;
      --btn-padding: .22rem .0.5rem;
      --btn-height: 30px;
    }
    body { background:#f3f6fb; color:#0f172a; font-family: Inter, system-ui, -apple-system, "Segoe UI", Roboto, Arial; }
    .container { max-width:1200px; }
    .topbar { display:flex; justify-content:space-between; align-items:center; gap:12px; margin-bottom:18px; }
    .card-panel { background:var(--card-bg); border-radius:12px; padding:16px; box-shadow:0 8px 30px rgba(15,23,42,0.04); border:1px solid rgba(15,23,42,0.03); }
    .upload-panel { border-radius:12px; padding:16px; background: linear-gradient(180deg, rgba(34,197,94,0.02), rgba(16,185,129,0.01)); border: 2px dotted rgba(16,185,129,0.16); display:flex; gap:16px; align-items:flex-start; }
    .upload-drop { flex:1; min-height:200px; display:flex; align-items:center; justify-content:center; gap:12px; flex-direction:column; text-align:center; padding:20px; border-radius:10px; background:var(--drop-bg); cursor:pointer; border: 2px dashed rgba(15,23,42,0.06); box-shadow: inset 0 2px 8px rgba(2,6,23,0.02); transition: all 160ms ease; }
    .upload-drop:hover { transform: translateY(-2px); }
    .upload-drop.dragover { border-style:dotted; border-color: rgba(16,185,129,0.6); background:#f6fff7; }
    .file-chip { display:inline-flex; gap:8px; align-items:center; background:#fff; padding:8px 10px; border-radius:8px; box-shadow:0 6px 12px rgba(2,6,23,0.03); border:1px solid rgba(2,6,23,0.04); font-weight:700; font-size:.9rem; }
    .upload-status { flex:1; min-width:300px; display:flex; flex-direction:column; gap:10px; }
    .mono { font-family: Menlo, Monaco, 'Courier New', monospace; font-size:0.92rem; color:#0b1220; }
    table#filesTable th, table#filesTable td { vertical-align:middle; }
    table#filesTable th.no-col, table#filesTable td.no-col { width:56px; text-align:center; }
    .text-small-muted { color:var(--muted); font-size:0.88rem; }
    .hidden { display:none !important; }

    .btn-upload {
      background: transparent;
      border: 2px solid rgba(16,185,129,0.95);
      color: var(--primary-dark);
      font-weight:700;
      transition: all 140ms ease;
    }
    .btn-upload:hover, .btn-upload:focus {
      background: linear-gradient(90deg,var(--primary) 0%, var(--primary-dark) 100%);
      color: #fff;
      border-color: transparent;
      transform: translateY(-1px);
      box-shadow: 0 8px 20px rgba(16,185,129,0.12);
    }

    .btn-cancel {
      background: #facc15;
      border-color: #f59e0b;
      color: #0b1220;
      font-weight:700;
    }

    .file-item { padding:8px; border-radius:8px; background:#fff; box-shadow:0 6px 12px rgba(2,6,23,0.03); display:flex; gap:10px; align-items:center; }
    .file-item .name { font-weight:700; font-size:0.92rem; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:180px; }
    .file-item .size { color:var(--muted); font-size:0.82rem; margin-top:2px; }
    .file-status { min-width:160px; text-align:right; font-size:0.85rem; }

    /* progress animations */
    .progress-bar { transition: width 320ms cubic-bezier(.2,.9,.2,1), box-shadow 320ms ease; }
    .progress-bar.anim { box-shadow: 0 6px 18px rgba(16,185,129,0.14); }
    .file-status .spinner-border { width:16px; height:16px; border-width: .15rem; }

    /* modal confirm styling */
    .confirm-modal .modal-dialog { max-width:520px; }
    .confirm-modal .modal-body { word-break: break-word; white-space:normal; font-size:0.94rem; }
    .confirm-modal .modal-footer { display:flex; gap:8px; justify-content:flex-end; }

    /* verification modal styling (keren) */
    .verify-modal .modal-content { border-radius: 14px; overflow: hidden; }
    .verify-hero { background: linear-gradient(90deg, rgba(22,163,74,0.12), rgba(6,95,70,0.04)); padding:18px; display:flex; gap:12px; align-items:center; }
    .verify-hero .dot { width:42px;height:42px;border-radius:12px;background:linear-gradient(180deg,#10b981,#059669); display:flex; align-items:center; justify-content:center; color:#fff; font-weight:800; box-shadow: 0 6px 18px rgba(6,95,70,0.16); }
    .verify-note { font-size:0.95rem; color:var(--muted); }
  </style>
</head>
<body>
  <div class="container py-4">
    <div class="topbar">
      <div style="display:flex;gap:8px;align-items:center">
        <a id="backBtn" class="btn btn-outline-secondary btn-sm" href="admin.php">Kembali</a>
        <button id="deleteSelectedBtn" class="btn btn-danger btn-sm" <?= $doc_verified ? '' : 'disabled' ?>>Hapus Terpilih</button>
        <button id="uploadToggleBtn" class="btn btn-upload btn-sm" <?= $doc_verified ? '' : 'disabled' ?>>Upload PDF</button>
      </div>
      <div>
        <?php if ($doc_verified): ?>
          <button id="lockDocBtn" class="btn btn-outline-warning btn-sm" title="Kunci akses dokumen">Kunci</button>
        <?php else: ?>
          <button id="openVerifyBtn" class="btn btn-outline-primary btn-sm" title="Verifikasi akses dokumen">Verifikasi</button>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($doc_verified): ?>
    <div id="uploadPanelWrapper" class="card-panel mb-3">
      <div class="upload-panel">
        <div class="upload-drop" id="uploadDrop" tabindex="0" role="button" aria-label="Drop files here or click to select">
          <div style="font-size:18px;font-weight:800;color:var(--primary)">Drop files here or click to select</div>
          <div class="text-small-muted">Ukuran file PDF yang diizinkan maksimal 1 GB.</div>
          <div>
            <label class="file-chip btn-sm" for="pdffilesInput" style="cursor:pointer">Pilih file</label>
            <input id="pdffilesInput" type="file" accept=".pdf" multiple class="hidden">
          </div>
        </div>

        <div class="upload-status">
          <div id="uploadList" style="display:flex;flex-direction:column;gap:8px;max-height:320px;overflow:auto">
            <div class="text-small-muted">Belum ada file yang dipilih.</div>
          </div>

          <div id="overallProgress" class="mt-2" style="display:none">
            <div class="d-flex justify-content-between mb-1">
              <div class="small text-small-muted">Overall progress</div>
              <div id="overallPercent" class="small text-small-muted">0%</div>
            </div>
            <div class="progress">
              <div id="overallBar" class="progress-bar bg-success" role="progressbar" style="width:0%"></div>
            </div>
          </div>

          <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:8px">
            <button id="uploadCancelBtn" class="btn btn-cancel btn-sm">Batal</button>
            <button id="uploadStartBtn" class="btn btn-upload btn-sm">Go Upload</button>
          </div>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <div class="card-panel">
      <div class="table-responsive">
        <table id="filesTable" class="table table-hover align-middle">
          <thead>
            <tr>
              <th class="no-col"><input id="selectAll" type="checkbox" aria-label="Pilih semua" <?= $doc_verified ? '' : 'disabled' ?>></th>
              <th class="no-col">No.</th>
              <th>Nama File</th>
              <th class="text-end">Ukuran</th>
              <th class="text-end">Waktu</th>
              <th class="text-center">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($doc_verified): ?>
              <?php foreach ($files as $f): ?>
                <tr data-name="<?= e($f['name']) ?>">
                  <td class="no-col"><input type="checkbox" class="row-select" value="<?= e($f['name']) ?>" <?= $f['writable'] ? '' : 'disabled' ?>></td>
                  <td class="no-col rowNo"></td>
                  <td class="mono"><?= e($f['name']) ?></td>
                  <td class="text-end" data-size="<?= e((int)$f['size']) ?>"><?= e(fmt_bytes($f['size'])) ?></td>
                  <td class="text-end ts-cell" data-ts="<?= e((int)$f['mtime']) ?>" data-order="<?= e((int)$f['mtime']) ?>"><?= e(date('d M Y H:i', $f['mtime'])) ?></td>
                  <td class="text-center">
                    <a class="btn btn-outline-primary btn-sm table-action" href="?download=<?= rawurlencode($f['name']) ?>">Download</a>
                    <button class="btn btn-outline-danger btn-sm table-action delete-btn" data-name="<?= e($f['name']) ?>" <?= $f['writable'] ? '' : 'disabled' ?>>Hapus</button>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php else: ?>
              <tr>
                <td></td>
                <td></td>
                <td class="text-center text-small-muted" style="white-space:normal;">
                  Anda harus melakukan verifikasi terlebih dahulu untuk melihat atau mengelola file.
                </td>
                <td></td>
                <td></td>
                <td></td>
              </tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

  </div>

  <div class="modal fade confirm-modal" id="confirmModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-body px-3">
          <div id="confirmBodyText">Konfirmasi penghapusan</div>
          <div id="confirmList" style="max-height:220px; overflow:auto; margin-top:8px;"></div>
          <div class="small text-small-muted mt-2">Tindakan ini tidak dapat dibatalkan.</div>
        </div>
        <div class="modal-footer py-2">
          <button id="confirmCancelBtn" type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
          <button id="confirmOkBtn" type="button" class="btn btn-danger btn-sm">Ya, hapus</button>
        </div>
      </div>
    </div>
  </div>

  <div class="modal fade verify-modal" id="verifyModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content">
        <div class="verify-hero d-flex align-items-center">
          <div>
            <div class="verify-note">Masukkan kode akses untuk membuka halaman ini.</div>
          </div>
        </div>
        <div class="modal-body">
          <form id="verifyForm" autocomplete="off">
            <div class="mb-3">
              <input id="verifyCode" name="code" type="password" class="form-control" required placeholder="Apa kode akses nya?">
            </div>
            <div id="verifyMsg" class="text-small-muted mb-2"></div>
            <div class="d-flex justify-content-end gap-2">
              <button type="button" id="verifyCancelBtn" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
              <button type="submit" id="verifySubmitBtn" class="btn btn-primary btn-sm">Verifikasi</button>
            </div>
          </form>
        </div>
      </div>
    </div>
  </div>

  <div class="toast-container position-fixed top-0 end-0 p-3" id="toastContainer"></div>

  <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
  <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
  <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>

<script>
const CSRF = <?= json_encode($csrf) ?>;
const DOC_VERIFIED = <?= json_encode($doc_verified ? true : false) ?>;
function safeToast(type, message, timeout = 4500) {
  const id = 't' + Date.now() + Math.floor(Math.random()*1000);
  const container = document.getElementById('toastContainer');
  const toastEl = document.createElement('div');
  toastEl.id = id;
  toastEl.className = `toast align-items-center text-bg-${type} border-0 mb-2`;
  toastEl.setAttribute('role', 'alert');
  toastEl.setAttribute('aria-live', 'assertive');
  toastEl.setAttribute('aria-atomic', 'true');
  const inner = document.createElement('div');
  inner.className = 'd-flex';
  const body = document.createElement('div');
  body.className = 'toast-body';
  body.textContent = message;
  const btn = document.createElement('button');
  btn.type = 'button';
  btn.className = 'btn-close btn-close-white me-2 m-auto';
  btn.setAttribute('data-bs-dismiss', 'toast');
  btn.setAttribute('aria-label', 'Close');
  inner.appendChild(body); inner.appendChild(btn); toastEl.appendChild(inner);
  container.appendChild(toastEl);
  const toast = new bootstrap.Toast(toastEl, { delay: timeout });
  toast.show();
  toastEl.addEventListener('hidden.bs.toast', () => toastEl.remove());
}

let table;
$(document).ready(function(){
  // render timestamps (24h)
  document.querySelectorAll('.ts-cell').forEach(td => {
    const ts = parseInt(td.getAttribute('data-ts') || '0', 10);
    if (!isNaN(ts) && ts > 0) {
      const d = new Date(ts * 1000);
      try {
        td.textContent = d.toLocaleString([], { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit', hour12: false });
      } catch (err) { td.textContent = d.toLocaleString(); }
    }
  });

  $.fn.dataTable.ext.errMode = 'none';

  table = $('#filesTable').DataTable({
    pageLength: 10,
    lengthMenu: [5,10,25,50,100],
    order: [[4, 'desc']], // waktu column index (accounting for select+no-col)
    searching: false,
    columnDefs: [
      { orderable: false, targets: [0,1,2,5] },
      { targets: 3, render: function(data, type, row, meta){
          if (type === 'sort' || type === 'type') {
            const node = meta.settings.aoData[meta.row].nTr;
            const cell = node ? node.querySelector('td[data-size]') : null;
            if (cell) return parseInt(cell.getAttribute('data-size') || '0', 10);
            return 0;
          }
          return data;
        }
      }
    ],
    drawCallback: function(settings) {
      const api = this.api();
      const info = api.page.info();
      const start = info.start;
      $(api.rows({ page: 'current' }).nodes()).each(function(i, row){
        $(row).find('.rowNo').text(start + i + 1);
      });
    }
  });

  const verifyModalEl = document.getElementById('verifyModal');
  const verifyModal = new bootstrap.Modal(verifyModalEl, { backdrop: 'static', keyboard: false });
  const verifyForm = document.getElementById('verifyForm');
  const verifyCode = document.getElementById('verifyCode');
  const verifyMsg = document.getElementById('verifyMsg');

  if (!DOC_VERIFIED) {
    setTimeout(() => verifyModal.show(), 220);
  }

  document.getElementById('openVerifyBtn')?.addEventListener('click', function(){ verifyModal.show(); setTimeout(()=> verifyCode.focus(), 200); });

  document.getElementById('lockDocBtn')?.addEventListener('click', async function(){
    if (!confirm('Kunci akses dokumen sekarang?')) return;
    try {
      const r = await fetch('<?= basename(__FILE__) ?>', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ action:'lock_doc', _csrf: CSRF })
      });
      const j = await r.json();
      if (j && j.success) {
        safeToast('success', 'Akses dokumen dikunci.');
        setTimeout(()=> location.reload(), 500);
      } else {
        safeToast('danger', j && j.error ? j.error : 'Gagal mengunci.');
      }
    } catch (err) {
      safeToast('danger', 'Kesalahan jaringan.');
    }
  });

  verifyForm.addEventListener('submit', async function(ev){
    ev.preventDefault();
    verifyMsg.textContent = '';
    const code = verifyCode.value || '';
    if (!code) { verifyMsg.textContent = 'Masukkan kode akses'; return; }
    try {
      const resp = await fetch('<?= basename(__FILE__) ?>', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ action:'verify_doc', code: code, _csrf: CSRF })
      });
      const j = await resp.json();
      if (j && j.success) {
        safeToast('success', 'Verifikasi berhasil. Akses terbuka.');
        setTimeout(()=> location.reload(), 300);
      } else {
        verifyMsg.textContent = (j && j.error) ? j.error : 'Verifikasi gagal';
        verifyCode.focus();
      }
    } catch (err) {
      verifyMsg.textContent = 'Kesalahan jaringan';
    }
  });

  if (!DOC_VERIFIED) {
    document.getElementById('pdffilesInput')?.setAttribute('disabled', 'disabled');
    document.getElementById('uploadToggleBtn')?.setAttribute('disabled', 'disabled');
    document.getElementById('selectAll')?.setAttribute('disabled', 'disabled');
    $('#uploadDrop').off('click drop dragover dragenter dragleave');
  }

  let pendingFilesList = [];
  const confirmModalEl = document.getElementById('confirmModal');
  const confirmModal = new bootstrap.Modal(confirmModalEl, { backdrop: 'static', keyboard: false });
  const confirmBodyText = document.getElementById('confirmBodyText');
  const confirmList = document.getElementById('confirmList');

  $('#filesTable').on('click', '.delete-btn', function(e){
    e.preventDefault();
    if (!DOC_VERIFIED) { safeToast('warning', 'Verifikasi diperlukan'); return; }
    const fname = $(this).data('name');
    pendingFilesList = [fname];

    confirmBodyText.textContent = 'Anda akan menghapus file berikut:';
    confirmList.innerHTML = '';
    const ul = document.createElement('ul');
    ul.style.paddingLeft = '1rem';
    const li = document.createElement('li');
    li.textContent = fname;
    ul.appendChild(li);
    confirmList.appendChild(ul);

    confirmModal.show();
    setTimeout(()=> document.getElementById('confirmCancelBtn').focus(), 120);
  });

  const deleteSelectedBtn = document.getElementById('deleteSelectedBtn');
  $('#deleteSelectedBtn').on('click', function(){
    if (!DOC_VERIFIED) { safeToast('warning', 'Verifikasi diperlukan'); return; }
    const selected = Array.from(document.querySelectorAll('#filesTable tbody .row-select:checked'))
                          .map(ch => ch.value);
    if (!selected.length) {
      safeToast('warning', 'Tidak ada file yang dipilih.');
      return;
    }
    pendingFilesList = selected;

    confirmBodyText.textContent = `Hapus ${pendingFilesList.length} file terpilih:`;
    confirmList.innerHTML = '';
    const ul = document.createElement('ul');
    ul.style.paddingLeft = '1rem';
    ul.style.margin = 0;
    pendingFilesList.forEach(name => {
      const li = document.createElement('li');
      li.textContent = name;
      li.style.marginBottom = '4px';
      ul.appendChild(li);
    });
    confirmList.appendChild(ul);

    confirmModal.show();
    setTimeout(()=> document.getElementById('confirmCancelBtn').focus(), 120);
  });

  document.getElementById('confirmOkBtn').addEventListener('click', async function(){
    confirmModal.hide();
    if (!pendingFilesList || !pendingFilesList.length) return;
    try {
      const resp = await fetch('<?= basename(__FILE__) ?>', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ action:'delete_multiple', files: pendingFilesList, _csrf: CSRF })
      });
      const j = await resp.json();
      if (!j || typeof j !== 'object') {
        safeToast('danger', 'Respons server tidak valid');
      } else {
        const results = Array.isArray(j.results) ? j.results : [];
        const summary = j.summary || null;

        let anyDeleted = false;
        let anyFailed = false;
        for (const r of results) {
          if (r.ok) {
            const row = $('#filesTable tbody tr').filter(function(){ return $(this).data('name') === r.name; });
            if (row.length) table.row(row).remove();
            anyDeleted = true;
          } else {
            anyFailed = true;
          }
        }

        if (anyDeleted) table.draw(false);

        if (summary) {
          if (summary.deleted > 0 && summary.failed === 0) {
            safeToast('success', `Berhasil menghapus ${summary.deleted} / ${summary.total} file.`);
          } else if (summary.deleted > 0 && summary.failed > 0) {
            safeToast('warning', `Sebagian berhasil: ${summary.deleted} dihapus, ${summary.failed} gagal.`);
          } else {
            safeToast('danger', `Gagal menghapus ${summary.total} file.`);
          }
        } else {
          if (anyDeleted && !anyFailed) safeToast('success', 'Semua file terhapus.');
          else if (anyDeleted && anyFailed) safeToast('warning', 'Sebagian file terhapus.');
          else safeToast('danger', 'Gagal menghapus file.');
        }

        if (anyFailed) {
          console.warn('Beberapa file gagal dihapus:', results.filter(r=>!r.ok));
        }
      }
    } catch (err) {
      console.error(err);
      safeToast('danger', 'Kesalahan jaringan saat menghapus');
    } finally {
      pendingFilesList = [];
      document.getElementById('selectAll').checked = false;
      updateSelectionState();
    }
  });

  const $uploadPanel = $('#uploadPanelWrapper');
  const $uploadToggle = $('#uploadToggleBtn');
  const fileInput = document.getElementById('pdffilesInput');
  const dropArea = document.getElementById('uploadDrop');
  const uploadList = document.getElementById('uploadList');
  const uploadStart = document.getElementById('uploadStartBtn');
  const uploadCancel = document.getElementById('uploadCancelBtn');
  const overallProgress = document.getElementById('overallProgress');
  const overallBar = document.getElementById('overallBar');
  const overallPercent = document.getElementById('overallPercent');

  $uploadToggle.on('click', function(){
    if (!DOC_VERIFIED) { safeToast('warning','Verifikasi diperlukan'); return; }
    $uploadPanel.toggleClass('hidden');
    const hidden = $uploadPanel.hasClass('hidden');
    $uploadPanel.attr('aria-hidden', hidden ? 'true' : 'false');
  });

  let pendingFiles = [];

  function renderUploadList(){
    uploadList.innerHTML = '';
    if (!pendingFiles.length) {
      const p = document.createElement('div');
      p.className = 'text-small-muted';
      p.textContent = 'Belum ada file yang dipilih.';
      uploadList.appendChild(p);
      overallProgress.style.display = 'none';
      return;
    }
    overallProgress.style.display = 'block';
    pendingFiles.forEach((f, idx) => {
      const wrapper = document.createElement('div');
      wrapper.className = 'file-item';
      const left = document.createElement('div'); left.style.flex='1';
      left.innerHTML = `<div class="name" title="${f.name}">${f.name}</div><div class="size">${(f.size/1024/1024).toFixed(2)} MB</div>`;
      const mid = document.createElement('div'); mid.style.flex='1';
      mid.innerHTML = `<div class="progress" style="height:10px;border-radius:8px"><div class="progress-bar bg-success" role="progressbar" style="width:0%"></div></div>`;
      const right = document.createElement('div'); right.className='file-status';
      right.innerHTML = `<div class="text-small-muted">Pending</div>`;
      wrapper.appendChild(left); wrapper.appendChild(mid); wrapper.appendChild(right);
      uploadList.appendChild(wrapper);
      f._progressEl = mid.querySelector('.progress-bar');
      f._statusEl = right;
    });
  }

  function addFiles(files){
    if (!DOC_VERIFIED) { safeToast('warning','Verifikasi diperlukan'); return; }
    for (const f of files) {
      if (!f.name.toLowerCase().endsWith('.pdf')) { safeToast('warning', 'Skip (not .pdf): ' + f.name); continue; }
      const maxBytes = 1073741824;
      if (f.size > maxBytes) { safeToast('warning', 'Skip (too large): ' + f.name); continue; }
      if (pendingFiles.some(x=>x.name===f.name)) { safeToast('info','Sudah ada: ' + f.name); continue; }
      pendingFiles.push(f);
    }
    renderUploadList();
  }

  const label = document.querySelector('label[for="pdffilesInput"]');
  if (label) label.addEventListener('click', ()=> fileInput.click());
  fileInput.addEventListener('change', function(){ addFiles(this.files); this.value = ''; });

  ['dragenter','dragover'].forEach(ev => {
    dropArea.addEventListener(ev, (e)=>{ e.preventDefault(); e.stopPropagation(); dropArea.classList.add('dragover'); }, false);
  });
  ['dragleave','drop'].forEach(ev => {
    dropArea.addEventListener(ev, (e)=>{ e.preventDefault(); e.stopPropagation(); dropArea.classList.remove('dragover'); }, false);
  });
  dropArea.addEventListener('drop', (e)=> {
    const dt = e.dataTransfer; if(!dt) return; addFiles(dt.files);
  });
  dropArea.addEventListener('click', ()=> fileInput.click());
  dropArea.addEventListener('keypress', (e)=> { if (e.key === 'Enter' || e.key === ' ') fileInput.click(); });

  // sequential upload with nicer animation handling
  async function uploadSequential() {
    if (!DOC_VERIFIED) { safeToast('warning','Verifikasi diperlukan'); return; }
    if (!pendingFiles.length) { safeToast('warning','Pilih minimal satu file.'); return; }
    const total = pendingFiles.reduce((s,f)=> s + (f.size || 0), 0) || pendingFiles.length;
    let uploadedWeight = 0;

    uploadStart.disabled = true;
    uploadCancel.disabled = true;

    for (let i=0;i<pendingFiles.length;i++) {
      const f = pendingFiles[i];
      f._statusEl.innerHTML = `<div class="d-flex align-items-center justify-content-end"><div class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></div><div class="text-small-muted">Uploading...</div></div>`;
      f._progressEl.classList.add('anim');

      const fd = new FormData();
      fd.append('pdffiles[]', f, f.name);
      fd.append('_csrf', CSRF);

      try {
        await new Promise((resolve, reject) => {
          const xhr = new XMLHttpRequest();
          xhr.open('POST', '<?= basename(__FILE__) ?>', true);
          xhr.upload.onprogress = function(e){
            if (!e.lengthComputable) return;
            const pct = Math.round((e.loaded / e.total) * 100);
            if (f._progressEl) f._progressEl.style.width = pct + '%';
            const overallPct = Math.round(((uploadedWeight + (e.loaded)) / total) * 100);
            overallBar.style.width = overallPct + '%';
            overallPercent.textContent = overallPct + '%';
          };
          xhr.onload = function(){
            f._progressEl.classList.remove('anim');
            if (xhr.status === 200) {
              try {
                const j = JSON.parse(xhr.responseText);
                if (j.success) {
                  f._statusEl.innerHTML = `<div style="color:green;font-weight:700">Done</div>`;
                  uploadedWeight += f.size || 0;
                  if (f._progressEl) f._progressEl.style.width = '100%';
                  resolve();
                } else {
                  f._statusEl.innerHTML = `<div style="color:#b91c1c">Error</div>`;
                  reject(new Error(j.error || 'server error'));
                }
              } catch (err) {
                f._statusEl.innerHTML = `<div style="color:#b91c1c">Error</div>`;
                reject(err);
              }
            } else {
              f._progressEl.classList.remove('anim');
              f._statusEl.innerHTML = `<div style="color:#b91c1c">Error</div>`;
              let msg = `status: ${xhr.status}`;
              try { msg = xhr.responseText || msg; } catch(e){}
              reject(new Error(msg));
            }
          };
          xhr.onerror = function(){ f._progressEl.classList.remove('anim'); f._statusEl.innerHTML = `<div style="color:#b91c1c">Error</div>`; reject(new Error('Network error')); };
          xhr.send(fd);
        });
      } catch (err) {
        safeToast('danger', 'Upload gagal: ' + (err.message || 'error'));
        uploadStart.disabled = false; uploadCancel.disabled = false;
        return;
      }
    }

    overallBar.style.width = '100%';
    overallPercent.textContent = '100%';
    safeToast('success', 'Semua file berhasil diupload.');
    setTimeout(()=> location.reload(), 700);
  }

  uploadStart.addEventListener('click', uploadSequential);

  uploadCancel.addEventListener('click', function(){
    pendingFiles = []; renderUploadList();
    $uploadPanel.addClass('hidden'); $uploadPanel.attr('aria-hidden','true');
  });

  function updateSelectionState() {
    const checked = document.querySelectorAll('#filesTable tbody .row-select:checked').length;
    deleteSelectedBtn.disabled = (checked === 0);
    const totalEnabled = document.querySelectorAll('#filesTable tbody .row-select:not(:disabled)').length;
    const totalChecked = document.querySelectorAll('#filesTable tbody .row-select:checked').length;
    const selectAllBox = document.getElementById('selectAll');
    if (totalEnabled === 0) {
      selectAllBox.checked = false;
      selectAllBox.indeterminate = false;
    } else if (totalChecked === totalEnabled) {
      selectAllBox.checked = true;
      selectAllBox.indeterminate = false;
    } else if (totalChecked > 0) {
      selectAllBox.checked = false;
      selectAllBox.indeterminate = true;
    } else {
      selectAllBox.checked = false;
      selectAllBox.indeterminate = false;
    }
  }

  $('#filesTable').on('change', '.row-select', updateSelectionState);
  document.getElementById('selectAll').addEventListener('change', function(){
    const checked = this.checked;
    document.querySelectorAll('#filesTable tbody .row-select:not(:disabled)').forEach(ch => ch.checked = checked);
    updateSelectionState();
  });

  updateSelectionState();
  renderUploadList();
});
</script>
</body>
</html>