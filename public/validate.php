<?php

require_once __DIR__ . '/../vendor/autoload.php';
$config = require __DIR__ . '/../config.php';

$token = trim($_GET['token'] ?? '');
if ($token === '') {
    http_response_code(400);
    echo "Token tidak diberikan.";
    exit;
}

$pdo = new PDO("mysql:host={$config['db']['host']};dbname={$config['db']['dbname']}", $config['db']['user'], $config['db']['pass']);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$stmt = $pdo->prepare("SELECT d.*, u.username, u.name, u.nipp
                       FROM documents d
                       LEFT JOIN users u ON u.id = d.user_id
                       WHERE d.token = :token
                       LIMIT 1");
$stmt->execute(['token' => $token]);
$doc = $stmt->fetch(PDO::FETCH_ASSOC);

?><!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Validasi Dokumen</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link rel="icon" type="image/x-icon" href="/img/favicon.ico">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body { background:#f8f9fa; -webkit-font-smoothing:antialiased; -moz-osx-font-smoothing:grayscale; }
    .card-validate { max-width:960px; margin:36px auto; }
    .badge-legal { background:#198754; color:#fff; padding:.35rem .6rem; border-radius:.375rem; font-weight:600; display:inline-block; }
    .muted { color:#6c757d; }
    .pdf-embed { width:100%; height:640px; border:1px solid #ddd; }

    /* Tighter table spacing for compact look */
    .table-sm td, .table-sm th { padding:.45rem .6rem; vertical-align:middle; }

    /* Mobile adjustments */
    @media (max-width: 575.98px) {
      .card-validate { margin:16px 12px; }
      .card-validate .card-body { padding:16px; }
      .badge-legal { display:block; margin-bottom:.5rem; font-size:0.95rem; padding:.4rem .6rem; }
      .muted { font-size:.95rem; }
      .pdf-embed { height:420px; }
      .token-info { margin-top:.5rem; }
      .table thead { display:none; } /* hide table header for a cleaner mobile read */
      .table tbody tr { display:flex; flex-wrap:wrap; border-bottom:1px solid #eef1f4; padding: .45rem 0; }
      .table tbody tr th { width:40%; flex:0 0 40%; font-weight:600; padding-right:.75rem; color:#495057; }
      .table tbody tr td { width:60%; flex:0 0 60%; padding-left:0; }
      .alert { font-size:.95rem; padding:.625rem .75rem; }
      .small.text-muted { display:block; word-break: break-all; }
    }

    /* Slight desktop refinements */
    @media (min-width: 576px) {
      .badge-legal { font-size:1rem; }
      .token-info { min-width:220px; }
    }

  </style>
</head>
<body>
  <div class="container">
    <div class="card card-validate shadow-sm">
      <div class="card-body">
        <?php if (!$doc): ?>
          <h4 class="text-danger">Token tidak ditemukan</h4>
          <p class="muted">Kami tidak menemukan dokumen yang terkait dengan token ini. Pastikan Anda memindai QR code yang benar atau hubungi penerbit dokumen.</p>
          <hr>
          <p class="small text-muted">Token: <?= htmlspecialchars($token) ?></p>
        <?php else: ?>
          <div class="d-flex flex-column flex-sm-row justify-content-between align-items-start">
            <div class="mb-2 mb-sm-0">
              <h4 class="mb-1"><span class="badge-legal" style="font-size: 22px;">AUTHENTIC VALIDATED ✓</span></h4>
              <p class="muted mb-0">Dokumen ini telah divalidasi secara resmi serta dinyatakan sah sesuai hukum yang berlaku.</p>
            </div>

            <div class="text-sm-end text-start muted small token-info">
              <div>Token: <code><?= htmlspecialchars($token) ?></code></div>
              <div>Divalidasi: <strong><?= date('Y-m-d H:i:s') ?></strong></div>
            </div>
          </div>

          <hr>

          <h6>Informasi Dokumen</h6>

          <div class="table-responsive">
            <table class="table table-sm mb-3">
              <tbody>
                <tr><th scope="row">Nama</th><td><?= htmlspecialchars($doc['name']) ?></td></tr>
                <tr><th scope="row">NIP</th><td><?= htmlspecialchars($doc['nipp'] ?? '-') ?></td></tr>
                <tr><th scope="row">Waktu</th><td><?= htmlspecialchars($doc['created_at'] ?? '-') ?></td></tr>
                <tr><th scope="row">Status</th><td><?= $doc['signed_path'] ? '<span class="text-success">Sudah tanda tangan</span>' : '<span class="text-warning">Belum tanda tangan</span>' ?></td></tr>
              </tbody>
            </table>
          </div>

          <div class="alert alert-success">
		  	Validitas dokumen ini terjamin secara sistem melalui QR code yang telah terdaftar.</div>

          <?php if ($doc['signed_path'] && file_exists($doc['signed_path'])): ?>

          <?php else: ?>
            <div class="alert alert-info">
			Dokumen yang telah ditandatangani memiliki kekuatan hukum sesuai ketentuan internal serta dapat dijadikan bukti sah sesuai dengan peraturan yang berlaku.</div>
          <?php endif; ?>

        <?php endif; ?>
      </div>
    </div>
  </div>
</body>
</html>