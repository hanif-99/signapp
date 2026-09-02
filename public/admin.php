<?php
session_start();
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: index.php'); exit;
}

require_once __DIR__ . '/../vendor/autoload.php';
$config = require __DIR__ . '/../config.php';

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf_token'];

function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function human_date($s){
    if (empty($s)) return '-';
    if (is_numeric($s)) $ts = (int)$s;
    else {
        $ts = strtotime($s);
        if ($ts === false) return e($s);
    }
    return date('d M Y H:i', $ts);
}
function human_date_dayonly($s){
    if (empty($s)) return '-';
    if (is_numeric($s)) $ts = (int)$s;
    else {
        $ts = strtotime($s);
        if ($ts === false) return e($s);
    }
    return date('d M Y', $ts);
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
  <link href="https://fonts.googleapis.com/css2?family=Inconsolata:ital,wght@0,400;1,400&display=swap" rel="stylesheet">
  <style>
    :root{
      --bg: #f6f8fb;
      --muted: #6b7280;
      --primary: #0d6efd;
      --highlight-green: #00e676;
      --highlight-green-dark: #00c853;
    }
    
    * { box-sizing: border-box; }
    html, body { margin: 0; padding: 0; }
    body { background:var(--bg); font-family: Inter, system-ui, -apple-system, "Segoe UI", Roboto, Arial; color:#0f172a; }
    
    .container-fluid { padding-left: 40px; padding-right: 40px; }
    .card-inner { background:#fff; border-radius:12px; box-shadow:0 8px 24px rgba(2,6,23,0.06); padding:18px; border: 1px solid rgba(2,6,23,0.04); }
    .small-muted { color:var(--muted); }
    .note-badge { font-size:0.78rem; padding:.35rem .55rem; border-radius:999px; }
    .toast-container { position: fixed; top: 1rem; right: 1rem; z-index: 1080; }
    .spinner-sm { width:1rem; height:1rem; border-width: .15rem; }
    .hidden { display:none !important; }

    /* Approved button */
    .btn.btn-approved-outline {
      background: transparent;
      color: var(--highlight-green-dark);
      border: 2px solid var(--highlight-green);
      box-shadow: 0 2px 0 rgba(0,0,0,0.02);
      transition: all .18s ease;
    }
    .btn.btn-approved-outline:hover,
    .btn.btn-approved-outline:focus {
      background: rgba(0,230,118,0.06);
      color: var(--highlight-green-dark);
      border-color: var(--highlight-green-dark);
    }
    .btn.btn-approved-outline[disabled],
    .btn.btn-approved-outline[aria-disabled="true"],
    .btn.btn-approved-outline.disabled {
      opacity: 1 !important;
      color: var(--highlight-green-dark) !important;
      border-color: var(--highlight-green) !important;
      background: transparent !important;
      cursor: default;
      box-shadow: none;
    }

    /* Desktop Table */
    table#docsTable th, table#docsTable td { vertical-align: middle; border-top-width: 1px; }
    table#docsTable th.no-col, table#docsTable td.no-col { text-align:center; width:36px; }
    table#docsTable th.docid-col, table#docsTable td.docid-col { width:80px; text-align:center; font-family: 'Inconsolata', monospace; font-size: 0.9rem; }
    table#docsTable th.user-col, table#docsTable td.user-col { width: auto; }
    table#docsTable th.signed-col, table#docsTable td.signed-col { width: 130px; text-align: center; }
    table#docsTable th.downloaded-col, table#docsTable td.downloaded-col { width:150px; text-align:center; }
    table#docsTable th.status-col, table#docsTable td.status-col { width:90px; text-align:center; }
    table#docsTable th.draft-col, table#docsTable td.draft-col { width:80px; text-align:center; }
    table#docsTable th.actions-col, table#docsTable td.actions-col { min-width:240px; max-width:360px; }

    table#docsTable td.user-col * {
      display: block !important;
      width: 100% !important;
      margin: 0 !important;
      padding: 0 !important;
      overflow: hidden !important;
      text-overflow: ellipsis !important;
      white-space: nowrap !important;
    }
    table#docsTable td.user-col strong { font-family: 'Inconsolata', monospace; font-weight:700; font-size:0.95rem; margin-bottom: 2px; }
    table#docsTable td.user-col small { color: var(--muted); font-size:0.82rem; text-transform: uppercase; }

    /* Top buttons */
    .top-icon-btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      width: 40px;
      height: 40px;
      padding: 6px;
      border-radius: 10px;
      border: 1px solid rgba(2,6,23,0.06);
      background: linear-gradient(180deg, #fff, #fbfdff);
      color: var(--muted);
      cursor: pointer;
      transition: all .2s ease;
    }
    .top-icon-btn:hover { color: var(--primary); box-shadow: 0 6px 18px rgba(13,110,253,0.06); }
    .top-icon-btn svg { width:18px; height:18px; display:block; }

    .refresh-only-icon {
      background: transparent;
      border: none;
      padding: 6px;
      width: 36px;
      height: 36px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      color: var(--primary);
      cursor: pointer;
      border-radius: 8px;
      transition: all .2s ease;
    }
    .refresh-only-icon:hover { background: rgba(13,110,253,0.06); }

    /* Selection counter */
    .selection-counter {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 0 12px;
      height: 36px;
      background: rgba(13,110,253,0.08);
      border-radius: 8px;
      color: var(--primary);
      font-size: 0.9rem;
      font-weight: 500;
    }

    /* Loaders */
    .table-responsive { position: relative; }
    .loader-overlay {
      position: absolute;
      inset: 0;
      display: flex;
      align-items: center;
      justify-content: center;
      background: rgba(255,255,255,0.6);
      z-index: 1100;
      -webkit-backdrop-filter: blur(2px);
      backdrop-filter: blur(2px);
    }
    .loader-box {
      display: flex;
      gap: 12px;
      align-items: center;
      background: #fff;
      padding: 12px 16px;
      border-radius: 10px;
      box-shadow: 0 10px 30px rgba(2,6,23,0.08);
      border: 1px solid rgba(2,6,23,0.04);
    }
    .loader-ring {
      width: 36px;
      height: 36px;
      border: 4px solid rgba(13,110,253,0.15);
      border-top-color: var(--primary);
      border-radius: 50%;
      animation: spin 1s linear infinite;
    }
    @keyframes spin { to { transform: rotate(360deg); } }

    .loader-dots {
      display: inline-flex;
      gap: 6px;
      align-items: center;
    }
    .loader-dots span {
      width: 8px;
      height: 8px;
      background: var(--primary);
      border-radius: 50%;
      animation: bounce 1s infinite;
    }
    .loader-dots span:nth-child(2) { animation-delay: 0.12s; }
    .loader-dots span:nth-child(3) { animation-delay: 0.24s; }
    @keyframes bounce {
      0%, 80%, 100% { transform: translateY(0); }
      40% { transform: translateY(-8px); }
    }

    /* ===== DESKTOP (> 992px) ===== */
    @media (max-width: 992px) {
      .container-fluid { padding-left: 15px; padding-right: 15px; }
      .card-inner { padding: 12px; }
      table#docsTable th.downloaded-col, table#docsTable td.downloaded-col,
      table#docsTable th.draft-col, table#docsTable td.draft-col { display: none; }
    }

    /* ===== MOBILE (≤ 768px) - CRITICAL CHANGES ===== */
    @media (max-width: 768px) {
      body { font-size: 12px; }
      
      .container-fluid { 
        padding-left: 8px; 
        padding-right: 8px; 
        padding-top: 6px; 
        padding-bottom: 6px;
        overflow-x: hidden;
      }
      
      .py-2 { padding-top: 6px !important; padding-bottom: 6px !important; }
      .py-3 { padding-top: 6px !important; padding-bottom: 6px !important; }
      .py-md-4 { padding: 0 !important; }
      
      .card-inner { 
        padding: 8px; 
        margin-bottom: 8px;
        border-radius: 8px;
      }

      /* ===== HEADER AREA ===== */
      .d-flex.justify-content-between.align-items-center.mb-2 {
        margin-bottom: 6px !important;
        padding: 0;
        gap: 8px;
      }

      .top-icon-btn {
        width: 32px;
        height: 32px;
        padding: 4px;
        border-radius: 6px;
      }
      .top-icon-btn svg { width: 14px; height: 14px; }
      
      .refresh-only-icon {
        width: 28px;
        height: 28px;
        padding: 3px;
      }

      .selection-counter {
        height: 28px;
        padding: 0 8px;
        font-size: 0.75rem;
        gap: 6px;
      }

      /* ===== FILTER SECTION ===== */
      .d-flex.justify-content-between.align-items-start.mb-3 {
        margin-bottom: 6px !important;
        padding: 0;
        gap: 6px;
      }

      .top-buttons-group {
        display: grid !important;
        grid-template-columns: 1fr 1fr !important;
        gap: 5px !important;
        width: 100%;
      }

      .top-buttons-group select,
      .top-buttons-group .btn {
        margin: 0 !important;
        padding: 0.3rem 0.4rem !important;
        font-size: 0.7rem !important;
        height: 26px !important;
        border-radius: 4px;
        font-weight: 500;
        line-height: 1;
        display: flex;
        align-items: center;
        justify-content: center;
      }

      .top-buttons-group select {
        grid-column: 1 / -1;
        width: 100%;
      }

      /* ===== HIDE DEFAULT DATATABLE ELEMENTS ===== */
      .dataTables_wrapper .dataTables_length {
        display: none;
      }

      .dataTables_wrapper .dataTables_filter {
        display: none;
      }

      .dataTables_wrapper .dataTables_info {
        padding: 4px 0;
        margin: 0;
        font-size: 0.65rem;
        text-align: center;
      }

      .dataTables_wrapper .dataTables_paginate {
        padding: 4px 0;
        margin: 0;
      }

      .dataTables_wrapper .dataTables_paginate .paginate_button {
        padding: 2px 4px !important;
        font-size: 0.65rem !important;
        margin: 0 1px !important;
        min-width: 18px !important;
        min-height: 18px !important;
        line-height: 1 !important;
        border-radius: 2px !important;
      }

      .dataTables_wrapper .dataTables_paginate .paginate_button.current {
        background: var(--primary) !important;
        color: white !important;
        border-color: var(--primary) !important;
      }

      /* ===== TABLE TO CARD LAYOUT ===== */
      table#docsTable {
        display: none;
      }

      #docsTable tbody {
        display: block;
        width: 100%;
      }

      #docsTable tr {
        display: block;
        background: #fff;
        border: 1px solid rgba(2,6,23,0.08);
        border-radius: 6px;
        margin-bottom: 8px;
        padding: 0;
        box-shadow: 0 1px 2px rgba(2,6,23,0.02);
        overflow: hidden;
      }

      #docsTable thead {
        display: none;
      }

      #docsTable td {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 7px 8px !important;
        border: none !important;
        border-bottom: 1px solid rgba(2,6,23,0.04);
        font-size: 0.8rem;
        min-height: 24px;
        gap: 6px;
      }

      #docsTable td:last-child {
        border-bottom: none !important;
      }

      #docsTable td::before {
        content: attr(data-label);
        font-weight: 600;
        color: var(--muted);
        font-size: 0.55rem;
        text-transform: uppercase;
        letter-spacing: 0.2px;
        min-width: 45px;
        flex-shrink: 0;
      }

      /* CHECKBOX */
      #docsTable td:first-child {
        display: flex;
        justify-content: center;
        align-items: center;
        padding: 6px !important;
        border-bottom: 1px solid rgba(2,6,23,0.04) !important;
        min-height: 28px;
      }

      #docsTable td:first-child::before { display: none; }

      #docsTable td:first-child input[type="checkbox"] {
        width: 14px;
        height: 14px;
        cursor: pointer;
        margin: 0;
      }

      /* NO COLUMN */
      #docsTable td.no-col { display: none; }

      /* DOC ID */
      #docsTable td.docid-col {
        justify-content: space-between;
        padding: 7px 8px !important;
      }
      #docsTable td.docid-col::before { content: "ID"; min-width: 35px; }
      #docsTable td.docid-col {
        font-family: 'Inconsolata', monospace;
        font-size: 0.7rem;
        font-style: italic;
        font-weight: 500;
      }

      /* USER COLUMN */
      #docsTable td.user-col {
        display: block !important;
        padding: 7px 8px !important;
        border-bottom: 1px solid rgba(2,6,23,0.04) !important;
      }

      #docsTable td.user-col::before {
        content: "Pegawai";
        display: block;
        font-weight: 600;
        color: var(--muted);
        font-size: 0.55rem;
        text-transform: uppercase;
        letter-spacing: 0.2px;
        margin-bottom: 3px;
      }

      #docsTable td.user-col * {
        display: block !important;
        width: 100% !important;
        margin: 0 !important;
        padding: 0 !important;
        overflow: hidden !important;
        text-overflow: ellipsis !important;
        white-space: nowrap !important;
        box-sizing: border-box !important;
      }

      #docsTable td.user-col strong {
        font-family: 'Inconsolata', monospace;
        font-weight: 700;
        font-size: 0.75rem;
        line-height: 1.1;
        margin-bottom: 1px;
      }

      #docsTable td.user-col small {
        color: var(--muted);
        font-size: 0.6rem;
        line-height: 1.1;
        text-transform: uppercase;
      }

      /* SIGNED */
      #docsTable td.signed-col {
        justify-content: space-between;
        padding: 7px 8px !important;
      }
      #docsTable td.signed-col::before { content: "TTD"; min-width: 40px; }

      /* DOWNLOADED */
      #docsTable td.downloaded-col {
        justify-content: space-between;
        padding: 7px 8px !important;
      }
      #docsTable td.downloaded-col::before { content: "DL"; min-width: 40px; }

      /* STATUS */
      #docsTable td.status-col {
        justify-content: space-between;
        padding: 7px 8px !important;
      }
      #docsTable td.status-col::before { content: "Status"; min-width: 40px; }
      #docsTable td.status-col .note-badge { font-size: 0.55rem; padding: 0.15rem 0.3rem; line-height: 1; }

      /* DRAFT */
      #docsTable td.draft-col { display: none; }

      /* ACTIONS */
      #docsTable td.actions-col {
        display: block !important;
        padding: 7px 8px !important;
        border-bottom: none !important;
      }

      #docsTable td.actions-col::before { display: none; }

      #docsTable td.actions-col .btn-group {
        display: flex !important;
        flex-direction: column;
        gap: 4px;
        width: 100%;
      }

      #docsTable td.actions-col .btn {
        flex: 1;
        width: 100% !important;
        padding: 5px 6px !important;
        font-size: 0.65rem !important;
        margin-right: 0 !important;
        border-radius: 3px;
        font-weight: 500;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        line-height: 1;
        height: auto;
        min-height: 20px;
        display: flex;
        align-items: center;
        justify-content: center;
      }
    }

    /* ===== LANDSCAPE (HIGH < 500px) ===== */
    @media (max-height: 500px) and (orientation: landscape) {
      .container-fluid {
        padding-top: 4px !important;
        padding-bottom: 4px !important;
      }

      .card-inner { padding: 6px; margin-bottom: 6px; }

      #docsTable tr { margin-bottom: 6px; }

      #docsTable td {
        padding: 5px 6px !important;
        gap: 4px;
        min-height: 20px;
        font-size: 0.75rem;
      }

      #docsTable td::before {
        min-width: 40px;
        font-size: 0.5rem;
      }

      #docsTable td.user-col strong { font-size: 0.7rem !important; }
      #docsTable td.user-col small { font-size: 0.55rem !important; }

      #docsTable td.actions-col .btn {
        padding: 4px 5px !important;
        font-size: 0.6rem !important;
        min-height: 18px;
      }

      .top-buttons-group .btn {
        height: 22px !important;
        padding: 0.25rem 0.3rem !important;
        font-size: 0.65rem !important;
      }
    }

    /* ===== EXTRA SMALL (≤ 360px) ===== */
    @media (max-width: 360px) {
      .top-buttons-group {
        grid-template-columns: 1fr !important;
      }

      .top-buttons-group select,
      .top-buttons-group .btn {
        grid-column: auto !important;
      }
    }
  </style>
</head>
<body>
  <div class="container-fluid py-2">
    <div class="d-flex justify-content-between align-items-center mb-2">
      <div></div>
      <div class="d-flex gap-2 align-items-center">
        <div id="selectionCounter" class="selection-counter hidden">
          <span id="selectionCount">0</span> selected
        </div>

        <a class="top-icon-btn" href="logout.php" role="button" title="Logout" data-bs-toggle="tooltip" data-bs-placement="bottom">
          <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
            <path d="M12 2v10" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>
            <path d="M5.07 5.07a10 10 0 0113.86 0 10 10 0 010 13.86 10 10 0 01-13.86 0 10 10 0 010-13.86z" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round"/>
          </svg>
        </a>

        <a id="docsBtn" class="top-icon-btn" href="dokumen.php" role="button" title="Dokumen" data-bs-toggle="tooltip" data-bs-placement="bottom">
          <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
            <path d="M3 7.5A2.5 2.5 0 015.5 5h3.5l1.5 2h8.5A2.5 2.5 0 0126 9.5v9A2.5 2.5 0 0123.5 21h-18A2.5 2.5 0 013 18.5v-11z" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round"/>
          </svg>
        </a>

        <button id="refreshBtn" class="refresh-only-icon" title="Refresh" data-bs-toggle="tooltip" data-bs-placement="bottom">
          <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
            <path d="M21 12a9 9 0 10-2.343 5.657L21 18" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>
            <path d="M21 3v5h-5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>
          </svg>
        </button>
      </div>
    </div>

    <div class="card-inner mb-3">
      <div class="d-flex justify-content-between align-items-start mb-2 gap-2">
        <div></div>
        <div class="d-flex gap-2 align-items-center flex-wrap top-buttons-group">
          <select id="filterStatus" class="form-select form-select-sm" style="width:auto;">
            <option value="all">Semua</option>
            <option value="signed">Sudah TTD</option>
            <option value="unsigned">Belum TTD</option>
            <option value="downloaded">Sudah Download</option>
            <option value="approved">Approved</option>
          </select>

          <button id="bulkApproveBtn" class="btn btn-success btn-sm">Approve</button>
          <button id="bulkDownloadBtn" class="btn btn-primary btn-sm">Download</button>
          <button id="bulkExportBtn" class="btn btn-outline-success btn-sm" title="Export semua atau pilih dokumen terlebih dahulu">Export</button>
          <button id="bulkDeleteBtn" class="btn btn-outline-danger btn-sm">Hapus</button>
        </div>
      </div>

      <div class="table-responsive">
        <div id="tableLoader" class="loader-overlay hidden">
          <div class="loader-box">
            <div class="loader-ring"></div>
            <div class="loader-dots">
              <span></span><span></span><span></span>
            </div>
          </div>
        </div>

        <table id="docsTable" class="table table-hover align-middle" style="width:100%;">
          <thead>
            <tr>
              <th style="width:36px;"><input id="selectAll" type="checkbox"></th>
              <th class="no-col">No.</th>
              <th class="docid-col">Doc ID</th>
              <th class="user-col">Nama Pegawai</th>
              <th class="signed-col">Signed</th>
              <th class="downloaded-col">Downloaded</th>
              <th class="status-col">Status</th>
              <th class="draft-col">Draft</th>
              <th class="actions-col">Actions</th>
            </tr>
          </thead>
          <tbody>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Confirm Modal -->
    <div class="modal fade" id="confirmModal" tabindex="-1">
      <div class="modal-dialog modal-sm modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-body" id="confirmMessage" style="font-size: 0.9rem;"></div>
          <div class="modal-footer">
            <button id="confirmCancelBtn" type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
            <button id="confirmOkBtn" type="button" class="btn btn-primary btn-sm">Ya, lanjutkan</button>
          </div>
        </div>
      </div>
    </div>

    <!-- Notes Modal -->
    <div class="modal fade" id="noteModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-md modal-dialog-centered">
        <form id="noteForm" class="modal-content" onsubmit="return false;">
          <div class="modal-header">
            <h5 class="modal-title">Catatan Dokumen</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body">
            <input type="hidden" id="noteDocId" value="">
            <div class="mb-3">
              <label class="form-label">Status</label>
              <select id="noteStatus" class="form-select">
                <option value="done">Selesai</option>
                <option value="fix">Perbaikan</option>
              </select>
            </div>

            <div class="mb-3" id="noteTextWrapper">
              <label class="form-label">Catatan</label>
              <textarea id="noteText" class="form-control" rows="5" placeholder="Tulis catatan untuk user..."></textarea>
            </div>
          </div>
          <div class="modal-footer">
            <button id="deleteNoteBtn" type="button" class="btn btn-outline-danger">Hapus</button>
            <button id="saveNoteBtn" type="button" class="btn btn-primary">Simpan</button>
          </div>
        </form>
      </div>
    </div>

    <div class="toast-container" id="toastContainer"></div>
  </div>

  <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
  <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
  <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>

<script>
const CSRF = <?= json_encode($csrf) ?>;

document.addEventListener('DOMContentLoaded', function () {
  const tipEls = document.querySelectorAll('[data-bs-toggle="tooltip"]');
  tipEls.forEach(el => new bootstrap.Tooltip(el));
});

function safeToast(type, message, timeout = 4000) {
  const id = 't' + Date.now() + Math.floor(Math.random()*1000);
  const container = document.getElementById('toastContainer');
  const toastEl = document.createElement('div');
  toastEl.id = id;
  toastEl.className = `toast align-items-center text-bg-${type} border-0 mb-2`;
  toastEl.setAttribute('role', 'alert');
  const inner = document.createElement('div');
  inner.className = 'd-flex';
  const body = document.createElement('div');
  body.className = 'toast-body';
  body.textContent = message;
  const btn = document.createElement('button');
  btn.type = 'button';
  btn.className = 'btn-close btn-close-white me-2 m-auto';
  btn.setAttribute('data-bs-dismiss', 'toast');
  inner.appendChild(body); inner.appendChild(btn); toastEl.appendChild(inner);
  container.appendChild(toastEl);
  const toast = new bootstrap.Toast(toastEl, { delay: timeout });
  toast.show();
  toastEl.addEventListener('hidden.bs.toast', () => toastEl.remove());
}

function toggleNoteField(status){
  if (status === 'fix') $('#noteTextWrapper').show();
  else $('#noteTextWrapper').hide();
}

function updateSelectionCounter() {
  const count = $('.rowSelect:checked').length;
  const counter = $('#selectionCounter');
  const countEl = $('#selectionCount');
  if (count > 0) {
    countEl.text(count);
    counter.removeClass('hidden');
  } else {
    counter.addClass('hidden');
  }
}

$(document).ready(function(){
  const table = $('#docsTable').DataTable({
    serverSide: true,
    processing: true,
    ajax: {
      url: 'admin_data.php',
      type: 'GET',
      data: function(d){
        d._csrf = CSRF;
        d.filterStatus = $('#filterStatus').val();
        d.downloaded_only = ($('#filterStatus').val() === 'downloaded') ? 1 : 0;
      }
    },
    pageLength: 10,
    lengthMenu: [5, 10, 25, 50, 100],
    order: [],
    columnDefs: [
      { orderable:false, targets: [0,1,2,3,8] },
      { searchable: false, targets: [0,1,8] }
    ],
    columns: [
      { data: 'checkbox', orderable:false, searchable:false },
      { data: 'row_no', orderable:false, searchable:false },
      { data: 'id' },
      { data: 'user_html', orderable:false, searchable:true },
      { data: 'signed' },
      { data: 'downloaded' },
      { data: null, render: function(data, type, row) {
          const ns = ((row.note_status || row.raw_note_status) || '').toString().toLowerCase();
          if (ns === 'done' || ns === 'selesai') {
            return '<span class="badge bg-success note-badge">Selesai</span>';
          } else if (ns === 'fix' || ns === 'perbaikan') {
            return '<span class="badge bg-warning text-dark note-badge">Perbaikan</span>';
          } else {
            return '<span class="small-muted">-</span>';
          }
        }
      },
      { data: 'pdf_html', orderable:true, searchable:false },
      { data: 'actions_html', orderable:false, searchable:false }
    ],
    createdRow: function(row, data, dataIndex){
      const rid = (data.raw_id !== undefined && data.raw_id !== null) ? String(data.raw_id) : String(data.id || '');
      $(row).attr('data-id', rid);
      $(row).attr('data-signed', data.raw_signed ? '1' : (data.signed ? '1' : '0'));
      $(row).attr('data-status', (data.note_status || data.raw_note_status || ''));
      $(row).attr('data-note-status', (data.note_status || data.raw_note_status || ''));
      $(row).attr('data-note-text', (data.note_text || data.raw_note_text || ''));
      $(row).attr('data-note-id', (data.raw_note_id ? String(data.raw_note_id) : (data.id ? String(data.id) : '')));
      $(row).attr('data-approval-status', data.raw_approval_status || data.approval_status || '');

      $(row).find('td').eq(1).addClass('rowNo').addClass('no-col');
      $(row).find('td').eq(2).addClass('docid-col');
      $(row).find('td').eq(3).addClass('user-col');
      $(row).find('td').eq(4).addClass('signed-col');
      $(row).find('td').eq(5).addClass('downloaded-col');
      $(row).find('td').eq(6).addClass('status-col');
      $(row).find('td').eq(7).addClass('draft-col');
      $(row).find('td').eq(8).addClass('actions-col');
    },
    drawCallback: function(settings) {
      const api = this.api();
      const info = api.page.info();
      const start = info.start;
      $(api.rows({ page: 'current' }).nodes()).each(function(i, row){
        $(row).find('.rowNo').text(start + i + 1);
      });
      updateSelectionCounter();
    }
  });

  function showTableLoader(){ $('#tableLoader').removeClass('hidden'); }
  function hideTableLoader(){ $('#tableLoader').addClass('hidden'); }

  table.on('processing.dt', function(e, settings, processing){
    if (processing) showTableLoader();
    else hideTableLoader();
  });

  $('#selectAll').on('change', function(){
    $('.rowSelect').prop('checked', $(this).is(':checked'));
    updateSelectionCounter();
  });

  $('.rowSelect').on('change', function(){
    updateSelectionCounter();
  });

  $('#refreshBtn').on('click', function(e){
    e.preventDefault();
    $('#filterStatus').val('all');
    table.search('').columns().search('');
    table.ajax.reload(() => safeToast('info','Refreshed'), false);
  });

  $('#filterStatus').on('change', function(){
    $('#selectAll').prop('checked', false);
    updateSelectionCounter();
    table.ajax.reload();
  });

  $('#bulkApproveBtn').on('click', () => bulkActionConfirm('approve'));
  $('#bulkDeleteBtn').on('click', () => bulkActionConfirm('delete'));
  $('#bulkDownloadBtn').on('click', () => bulkActionConfirm('download'));
  $('#bulkExportBtn').on('click', bulkExportHandler);

  $('#docsTable').on('click', '.action-btn', function(){
    const action = $(this).data('action');
    const id = $(this).data('id');
    if ($(this).prop('disabled')) return;
    setRowLoadingState(id, true);
    singleActionConfirm(action, [id]);
  });

  $('#docsTable').on('click', '.action-btn-note', function(e){
    e.preventDefault();
    if ($(this).prop('disabled')) {
      safeToast('warning', 'Dokumen harus di-approve terlebih dahulu');
      return;
    }
    const docId = $(this).data('id');
    const noteStatus = $(this).data('note-status') || '';
    const noteText = $(this).data('note-text') || '';
    
    $('#noteDocId').val(docId);
    $('#noteStatus').val(noteStatus || 'done');
    $('#noteText').val(noteText);
    
    toggleNoteField($('#noteStatus').val());
    
    const modal = new bootstrap.Modal(document.getElementById('noteModal'));
    modal.show();
  });

  let pendingAction = null;
  let pendingIds = [];

  function singleActionConfirm(action, ids){
    pendingAction = action;
    pendingIds = ids;
    let message = `${action.charAt(0).toUpperCase() + action.slice(1)} pada ${ids.length} dokumen ?`;
    if (action === 'download') {
      message = `Download ZIP untuk ${ids.length} dokumen ?`;
    } else if (action === 'delete') {
      message = `Hapus ${ids.length} dokumen ?`;
    }
    $('#confirmMessage').text(message);
    new bootstrap.Modal(document.getElementById('confirmModal')).show();
  }

  $('#confirmOkBtn').on('click', async function(){
    bootstrap.Modal.getInstance(document.getElementById('confirmModal')).hide();
    if (pendingAction && pendingIds.length) {
      await performAction(pendingAction, pendingIds);
      pendingAction = null;
      pendingIds = [];
    }
  });

  function bulkActionConfirm(action){
    const ids = selectedIds();
    if (!ids.length) {
      safeToast('warning', 'Pilih minimal satu dokumen.');
      return;
    }
    ids.forEach(id => setRowLoadingState(id, true));
    singleActionConfirm(action, ids);
  }

  function selectedIds(){
    return $('.rowSelect:checked').map(function(){ return $(this).val(); }).get();
  }

  async function performAction(action, ids){
    try {
      const resp = await fetch('admin_bulk.php', {
        method:'POST',
        headers:{ 'Content-Type':'application/json' },
        body: JSON.stringify({ action: action, ids: ids, _csrf: CSRF })
      });
      const j = await resp.json();
      
      if (!j.success) {
        safeToast('danger', 'Gagal: ' + (j.error || 'server error'));
        ids.forEach(id => setRowLoadingState(id, false));
        return;
      }

      if (action === 'download') {
        if (j.url) {
          window.open(j.url, '_blank');
          safeToast('success', `Download berhasil (${j.count} file)`);
        } else {
          safeToast('info', 'Tidak ada file yang bisa diunduh.');
        }
        ids.forEach(id => setRowLoadingState(id, false));
        return;
      }

      if (action === 'approve') {
        const updated = Array.isArray(j.updated) ? j.updated.map(String) : [];
        ids.forEach(id => {
          const sid = String(id);
          const row = $(`#docsTable tr[data-id="${sid}"]`);
          if (!row.length) return;
          
          if (updated.includes(sid)) {
            row.attr('data-approval-status', 'approved');
            let $btn = row.find('button.action-btn[data-action="approve"]');
            if (!$btn.length) {
              $btn = row.find('.action-btn').filter(function(){
                return $(this).text().trim().toLowerCase() === 'approve';
              });
            }
            
            const $newBtn = $('<button>')
              .addClass('btn btn-sm btn-approved-outline action-btn')
              .attr('data-id', sid)
              .attr('disabled', true)
              .text('Approved');

            if ($btn.length) {
              $btn.fadeOut(120, function(){ $(this).replaceWith($newBtn.hide()); $newBtn.fadeIn(220); });
            } else {
              const group = row.find('td').eq(8).find('.btn-group');
              if (group.length) {
                $newBtn.hide();
                group.prepend($newBtn);
                $newBtn.fadeIn(220);
              }
            }

            const notesBtn = row.find('.action-btn-note');
            if (notesBtn.length) {
              notesBtn.prop('disabled', false).css('opacity', '1').css('cursor', 'pointer');
            }

            const resetBtn = row.find('button[data-action="reset"]');
            if (resetBtn.length) {
              resetBtn.prop('disabled', false).css('opacity', '1').css('cursor', 'pointer');
            }
          } else {
            setRowLoadingState(sid, false);
          }
        });
        safeToast('success', `Berhasil di-approve: ${j.count ?? ids.length} item`);
        return;
      }

      table.ajax.reload(null, false);
      safeToast('success', `Berhasil: ${j.count ?? ids.length} item`);
      ids.forEach(id => setRowLoadingState(id, false));
    } catch (err) {
      console.error(err);
      safeToast('danger', 'Error jaringan');
      ids.forEach(id => setRowLoadingState(id, false));
    }
  }

  function setRowLoadingState(id, isLoading) {
    const row = $(`#docsTable tr[data-id="${id}"]`);
    if (!row.length) return;
    const btns = row.find('.action-btn');
    btns.each(function(){
      const $b = $(this);
      if (isLoading) {
        $b.data('orig-text', $b.html());
        $b.data('orig-disabled', !!$b.prop('disabled'));
        $b.prop('disabled', true);
        $b.html(`<span class="spinner-border spinner-border-sm spinner-sm"></span>`);
      } else {
        const orig = $b.data('orig-text');
        const origDisabled = $b.data('orig-disabled');
        $b.prop('disabled', origDisabled === true || (origDisabled === undefined && $b.hasClass('btn-approved-outline')));
        if (orig) $b.html(orig);
      }
    });
  }

  // MODIFIED: bulkExportHandler - Support export without checkbox selection
  function bulkExportHandler(){
    const ids = selectedIds();
    const $form = $('<form method="POST" action="export.php"></form>');
    
    // Create payload with all necessary data
    const payload = {
      order: table.order(),
      search: table.search(),
      filterStatus: $('#filterStatus').val()
    };
    
    // Only include selected items if any are selected
    if (ids.length > 0) {
      payload.selected = ids;
    }
    
    $('<input>').attr({
      type:'hidden',
      name:'payload',
      value: JSON.stringify(payload)
    }).appendTo($form);
    
    $('<input>').attr({
      type:'hidden',
      name:'_csrf',
      value: CSRF
    }).appendTo($form);
    
    $form.appendTo('body').submit();
    $form.remove();
    
    safeToast('success', ids.length > 0 ? `Export ${ids.length} dokumen...` : 'Export semua data sesuai filter...');
  }

  $('#noteStatus').on('change', function(){ toggleNoteField($(this).val()); });

  $('#saveNoteBtn').on('click', async function(){
    const docId = parseInt($('#noteDocId').val(), 10);
    const status = $('#noteStatus').val();
    const text = $('#noteText').val().trim();

    if (!docId) { safeToast('danger','Document ID tidak valid'); return; }
    if (status === 'fix' && text.length === 0) { safeToast('warning','Isi catatan perbaikan terlebih dahulu.'); return; }

    $(this).prop('disabled', true);
    try {
      const payload = { action:'save', doc_id: docId, status: status, note_text: (status === 'fix' ? text : ''), _csrf: CSRF };
      const resp = await fetch('admin_note.php', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(payload) });
      const j = await resp.json();
      if (!j.success) { safeToast('danger', 'Gagal menyimpan: ' + (j.error || 'server error')); $('#saveNoteBtn').prop('disabled', false); return; }
      
      const row = $(`#docsTable tr[data-id="${docId}"]`);
      if (row.length) {
        row.attr('data-note-status', status);
        row.attr('data-note-text', status === 'fix' ? text : '');
        row.attr('data-status', status);
        
        const statusCell = row.find('td').eq(6);
        if (statusCell.length) {
          let statusHtml = '<span class="small-muted">-</span>';
          if (status === 'done' || status === 'selesai') {
            statusHtml = '<span class="badge bg-success note-badge">Selesai</span>';
          } else if (status === 'fix' || status === 'perbaikan') {
            statusHtml = '<span class="badge bg-warning text-dark note-badge">Perbaikan</span>';
          }
          statusCell.html(statusHtml);
        }
        
        const noteBtn = row.find('.action-btn-note');
        if (noteBtn.length) {
          noteBtn.attr('data-note-status', status);
          noteBtn.attr('data-note-text', status === 'fix' ? text : '');
        }
      }
      
      safeToast('success', 'Catatan berhasil disimpan');
      bootstrap.Modal.getInstance(document.getElementById('noteModal')).hide();
    } catch (err) {
      console.error(err);
      safeToast('danger','Kesalahan jaringan');
    } finally {
      $('#saveNoteBtn').prop('disabled', false);
    }
  });

  $('#deleteNoteBtn').on('click', async function(){
    const docId = parseInt($('#noteDocId').val(), 10);
    if (!docId) { safeToast('warning','Document ID tidak valid.'); return; }
    if (!confirm('Hapus catatan ini?')) return;
    try {
      const resp = await fetch('admin_note.php', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ action:'delete', doc_id: docId, _csrf: CSRF }) });
      const j = await resp.json();
      if (!j.success) { safeToast('danger', 'Gagal menghapus: ' + (j.error || 'server error')); return; }
      
      const row = $(`#docsTable tr[data-id="${docId}"]`);
      if (row.length) {
        row.attr('data-note-status', '');
        row.attr('data-note-text', '');
        row.attr('data-status', '');
        
        const statusCell = row.find('td').eq(6);
        if (statusCell.length) {
          statusCell.html('<span class="small-muted">-</span>');
        }
        
        const noteBtn = row.find('.action-btn-note');
        if (noteBtn.length) {
          noteBtn.attr('data-note-status', '');
          noteBtn.attr('data-note-text', '');
        }
      }
      
      safeToast('success', 'Catatan berhasil dihapus');
      bootstrap.Modal.getInstance(document.getElementById('noteModal')).hide();
    } catch (err) {
      console.error(err);
      safeToast('danger','Kesalahan jaringan');
    }
  });
});
</script>
</body>
</html>

