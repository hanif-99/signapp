<?php

session_start();
if (!isset($_SESSION['user_id'])) { header('Location: index.php'); exit; }

require_once __DIR__ . '/../vendor/autoload.php';
$config = require __DIR__ . '/../config.php';

// CSRF token
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf_token'];

// DB
$pdo = new PDO(
  "mysql:host={$config['db']['host']};dbname={$config['db']['dbname']};charset=utf8mb4",
  $config['db']['user'],
  $config['db']['pass'],
  [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

// fetch latest document
$stmt = $pdo->prepare("SELECT * FROM documents WHERE user_id = :uid ORDER BY created_at DESC LIMIT 1");
$stmt->execute(['uid' => $_SESSION['user_id']]);
$doc = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$doc) { header('Location: sign.php'); exit; }
if (empty($doc['signed_at'])) { header('Location: sign.php'); exit; }

// user info
$userStmt = $pdo->prepare("SELECT username, name FROM users WHERE id = :id LIMIT 1");
$userStmt->execute(['id' => $doc['user_id'] ?? $_SESSION['user_id']]);
$user = $userStmt->fetch(PDO::FETCH_ASSOC);

// latest admin note — migrated fields are now stored on documents (note_status, note_text, note_admin_id, note_updated_at)
$note = null;
if (!empty($doc['note_status']) || !empty($doc['note_text'])) {
    $note = [
        'status' => $doc['note_status'] ?? '',
        'note_text' => $doc['note_text'] ?? '',
        'admin_id' => $doc['note_admin_id'] ?? null,
        'created_at' => $doc['note_updated_at'] ?? null,
        'admin_username' => null
    ];
    // if there's an admin id, try to fetch username for display
    if (!empty($note['admin_id'])) {
        try {
            $adm = $pdo->prepare("SELECT username FROM users WHERE id = :id LIMIT 1");
            $adm->execute(['id' => $note['admin_id']]);
            $a = $adm->fetch(PDO::FETCH_ASSOC);
            if ($a) $note['admin_username'] = $a['username'];
        } catch (Exception $e) {
            // ignore — admin username is optional
            $note['admin_username'] = null;
        }
    }
}

function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Format datetime helper
function formatDateTime($dateString){
  if (!$dateString) return null;
  try {
    $dt = new DateTime($dateString);
    return $dt->format('d F Y \p\u\k\u\l H:i:s'); // Format: 04 Juni 2026 pukul 14:30:45
  } catch (Exception $e) {
    return $dateString;
  }
}

$docId = (int)$doc['id'];
$signedAt = $doc['signed_at'];
$approval = strtolower($doc['approval_status'] ?? 'pending');
$signedPath = $doc['signed_path'] ?? '';
$signedExists = ($signedPath && file_exists($signedPath));
$downloadedAt = $doc['downloaded_at'] ?? null;
$uploadedAt = $doc['uploaded_at'] ?? null;
$noteStatus = $note['status'] ?? '';

// UI flags
$showDownloadButton = ($approval === 'approved' && empty($downloadedAt));
$isCompleted = !empty($downloadedAt); // Completed setelah download
$downloadedAtFormatted = $downloadedAt ? formatDateTime($downloadedAt) : null;

?>
<!doctype html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <title>SignApp</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link rel="icon" type="image/x-icon" href="/img/favicon.ico">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body{background:#f4f6f9;font-family:system-ui, -apple-system, "Segoe UI", Roboto, Arial;color:#0f172a;}
    .box{max-width:980px;margin:34px auto;padding:26px;background:#fff;border-radius:10px;box-shadow:0 12px 40px rgba(2,6,23,0.06);}
    .btn-brand{background:#0d6efd;color:#fff;border:none}
    .dropzone{min-height:72px;border:1px dashed #d1d5db;border-radius:8px;padding:10px;background:#fbfdff;display:flex;align-items:center;gap:12px}
    .dz-filename{font-weight:600;color:#0f172a}
    .dz-meta{font-size:.85rem;color:#6b7280}
    .dz-hint{font-size:.85rem;color:#6b7280}
    .toast-container{position:fixed;top:1rem;right:1rem;z-index:2000}
    .hidden{display:none !important}
    .note-box{background:#fff3cd;border-left:4px solid #ffecb5;padding:12px;border-radius:6px}
    .download-info-box{background:#e7f3ff;border-left:4px solid #0d6efd;padding:12px;border-radius:6px;margin-top:12px}
    .download-info-box strong{color:#0d6efd}
  </style>
</head>
<body>
  <div class="container">
    <div class="box">
      <div class="d-flex justify-content-between align-items-start mb-3">
        <div>
          <h3 class="text-success">Selamat, tanda tangan berhasil !!!</h3>
          <p class="text-muted mb-0">Dokumen Anda telah berhasil ditandatangani dan disimpan di sistem.</p>
        </div>
        <div><a href="logout.php" class="btn btn-outline-secondary btn-sm">Logout</a></div>
      </div>

      <ul>
        <li><strong>Nama</strong> : <?= e($user['name'] ?? $user['username'] ?? '-') ?></li>
        <li><strong>Waktu ditandatangani</strong> : <?= e($signedAt) ?></li>
      </ul>

      <?php if ($isCompleted): ?>
        <div class="mb-3">
          <div class="alert alert-success">Semua tahapan telah lengkap.
		  <br>Anda telah berhasil menyelesaikan proses download dokumen. <br>Terima kasih.</div>
          <?php if ($downloadedAtFormatted): ?>
            <div class="download-info-box">
              <strong>Dokumen Diunduh:</strong><br>
              <?= e($downloadedAtFormatted) ?>
            </div>
          <?php endif; ?>
        </div>
      <?php else: ?>

        <?php if ($note && ($note['status'] ?? '') === 'fix'): ?>
          <div class="mb-3">
            <h6>Catatan Perbaikan :</h6>
            <div class="note-box mb-1"><div><?= e($note['note_text']) ?></div></div>
          </div>
        <?php endif; ?>

        <div id="approvalInfo" class="mb-3">
          <?php if ($approval !== 'approved'): ?>
            <div class="alert alert-info mb-0">
              Saat ini dokumen sedang dalam proses TTE.
			  <br>File dapat diunduh setelah seluruh proses penandatanganan selesai.
            </div>
          <?php else: ?>
            <div class="alert alert-success mb-0">Dokumen Anda telah selesai TTE. Silakan download dokumen di bawah.</div>
          <?php endif; ?>
        </div>

        <div id="downloadContainer" class="mb-3" <?= $showDownloadButton ? '' : 'style="display:none;"' ?>>
          <button id="downloadBtn" class="btn btn-brand">Download Dokumen PDF</button>
        </div>

      <?php endif; ?>
    </div>
  </div>

  <div class="toast-container" id="toastContainer"></div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
const DOC_ID = <?= json_encode($docId) ?>;
const CSRF = <?= json_encode($csrf) ?>;
const POLL_INTERVAL = 5000;
const IS_COMPLETED = <?= json_encode($isCompleted ? true : false) ?>;
let previousApproval = <?= json_encode($approval) ?>;

// safe toast
function safeToast(type, message, timeout=4500){
  const c = document.getElementById('toastContainer'); if(!c) return console.log(message);
  const el = document.createElement('div'); el.className = `toast align-items-center text-bg-${type} border-0 mb-2`;
  el.setAttribute('role','alert'); el.setAttribute('aria-live','assertive'); el.setAttribute('aria-atomic','true');
  const inner = document.createElement('div'); inner.className='d-flex'; const body = document.createElement('div'); body.className='toast-body'; body.textContent=message;
  const btn = document.createElement('button'); btn.type='button'; btn.className='btn-close btn-close-white me-2 m-auto'; btn.setAttribute('aria-label','Close'); btn.addEventListener('click', ()=> el.remove());
  inner.appendChild(body); inner.appendChild(btn); el.appendChild(inner); c.appendChild(el);
  try { new bootstrap.Toast(el,{delay:timeout}).show(); el.addEventListener('hidden.bs.toast', ()=>el.remove()); } catch(e){ setTimeout(()=>el.remove(),timeout); }
}

// fetch status (reload on approval transition)
async function fetchStatus(){
  try {
    const resp = await fetch('doc_status.php',{cache:'no-store'});
    if (!resp.ok) return;
    const j = await resp.json();
    if (!j.success) return;

    const currentApproval = (j.approval_status || '').toLowerCase();

    // reload only when there's a transition from non-approved -> approved AND user hasn't clicked download previously
    if (previousApproval !== 'approved' && currentApproval === 'approved') {
      previousApproval = currentApproval;
      window.location.reload();
      return;
    }
    previousApproval = currentApproval;

    // If download completed, reload to show completion message
    if (j.downloaded_at && !IS_COMPLETED) {
      window.location.reload();
      return;
    }

  } catch (err) {
    console.error('status poll error', err);
  }
}

// mark_downloaded flow
async function handleDownloadClick(){
  const btn = document.getElementById('downloadBtn'); if (!btn) return;
  btn.disabled = true; btn.textContent = 'Mempersiapkan unduhan...';
  try {
    const resp = await fetch('mark_downloaded.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({id:DOC_ID,_csrf:CSRF})});
    const j = await resp.json().catch(()=>null);
    if (!resp.ok || !j || !j.success) { safeToast('danger','Gagal mempersiapkan unduhan: ' + (j && j.error ? j.error : resp.statusText || 'server error')); btn.disabled=false; btn.textContent='Download Dokumen PDF'; return; }
    window.open('download_signed.php?id=' + encodeURIComponent(DOC_ID), '_blank');
    const downloadContainer = document.getElementById('downloadContainer'); if (downloadContainer) downloadContainer.style.display = 'none';
    safeToast('success','Selamat, proses unduh berhasil.',5000);
    try { document.getElementById('approvalInfo').style.display='none'; } catch(e){}
    setTimeout(()=> window.location.reload(), 1500);
  } catch(e){ console.error(e); safeToast('danger','Kesalahan jaringan saat memulai unduhan'); btn.disabled=false; btn.textContent='Download Dokumen PDF'; }
}

document.addEventListener('DOMContentLoaded', function(){
  const dl = document.getElementById('downloadBtn'); if(dl) dl.addEventListener('click', handleDownloadClick);
  if (!IS_COMPLETED) { fetchStatus(); setInterval(fetchStatus, POLL_INTERVAL); }
});
</script>
</body>
</html>