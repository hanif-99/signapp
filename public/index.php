<?php

session_start();
require_once __DIR__ . '/../vendor/autoload.php';
$config = require __DIR__ . '/../config.php';

if (isset($_SESSION['user_id'])) {
    if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin') {
        header('Location: admin.php');
    } else {
        header('Location: sign.php');
    }
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pdo = new PDO(
        "mysql:host={$config['db']['host']};dbname={$config['db']['dbname']}",
        $config['db']['user'],
        $config['db']['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = :u");
    $stmt->execute(['u' => $_POST['username'] ?? '']);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($user) {
        $pass = $_POST['password'] ?? '';
        $ok = false;
        if ($pass === $user['password']) $ok = true;
        if ($ok) {
            // regenerate session id after login
            session_regenerate_id(true);
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['role'] = $user['role'];
            if ($user['role'] === 'admin') {
                header('Location: admin.php');
            } else {
                header('Location: sign.php');
            }
            exit;
        }
    }
    $error = "Login gagal. Periksa username/password.";
}

// Helper for escaping
function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <title>SignApp</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link rel="icon" type="image/x-icon" href="/img/favicon.ico">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700&display=swap" rel="stylesheet">
  <style>
    :root{
      --card-bg: #ffffffcc;
      --accent: linear-gradient(90deg,#2563eb,#1e40af);
      --muted: #6b7280;
    }
    html,body{height:100%;}
    body{
      margin:0;
      font-family: 'Inter', system-ui, -apple-system, "Segoe UI", Roboto, Arial;
      color:#0b1220;
      display:flex;
      align-items:center;
      justify-content:center;
      padding:24px;
      background: linear-gradient(180deg, rgba(2,6,23,0.35), rgba(2,6,23,0.45));
    }
    .page-bg {
      position: fixed;
      inset: 0;
      background-image: url('img/background.jpg');
      background-size: cover;
      background-position: center;
      filter: saturate(60%) brightness(65%) contrast(85%);
      z-index: -2;
    }
    .bg-overlay {
      position: fixed;
      inset: 0;
      background: linear-gradient(180deg, rgba(2,6,23,0.5) 0%, rgba(2,6,23,0.55) 60%);
      z-index: -1;
    }

    .login-card {
      width:100%;
      max-width: 480px;
      background: var(--card-bg);
      border-radius:14px;
      padding:28px;
      box-shadow: 0 18px 40px rgba(2,6,23,0.36);
      border: 1px solid rgba(2,6,23,0.06);
      backdrop-filter: blur(6px) saturate(120%);
    }

    .brand {
      text-align:center;
      margin-bottom:12px;
    }
    .brand h2 { margin:0; font-weight:700; color:#07203a; }
    .form-label { font-weight:600; color:#334155; }
    .form-control { border-radius:10px; padding:12px 14px; border: 1px solid rgba(2,6,23,0.06); }
    .btn-primary {
      width:100%;
      background: var(--accent);
      border:none;
      padding:12px 16px;
      font-weight:700;
      border-radius:10px;
      box-shadow: 0 10px 26px rgba(37,99,235,0.12);
    }
    .btn-primary:active { transform: translateY(0); }
    .small-muted { color:var(--muted); font-size:14px; text-align:center; margin-top:12px; }

    .pw-row { display:flex; gap:8px; align-items:center; }
    .pw-row .form-control { flex:1; }
    .pw-toggle { white-space:nowrap; padding:8px 10px; border-radius:8px; border:1px solid rgba(2,6,23,0.06); background:#fff; }

    .error { color:#b91c1c; margin-bottom:12px; background:#fff0f0; padding:10px 12px; border-radius:8px; border:1px solid #fca5a5; }

    @media (max-width:520px){
      .login-card{ padding:18px; border-radius:12px; max-width:94%; }
    }
  </style>
</head>
<body>
  <div class="page-bg" aria-hidden="true"></div>
  <div class="bg-overlay" aria-hidden="true"></div>

  <main class="login-card" role="main" aria-labelledby="loginTitle">

    <?php if (!empty($error)): ?>
      <div class="error" role="alert"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="post" action="" novalidate>
      <div class="mb-3">
        <label class="form-label" for="username">Username</label>
        <div class="input-group">
          <span class="input-group-text" aria-hidden="true" style="border-radius:10px 0 0 10px;border:1px solid rgba(2,6,23,0.06);background:#fff;">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="#64748b" viewBox="0 0 16 16"><path d="M8 8a3 3 0 1 0 0-6 3 3 0 0 0 0 6zm4-3a4 4 0 1 1-8 0 4 4 0 0 1 8 0z"/><path d="M14 14s-1-1.5-6-1.5S2 14 2 14s1-4 6-4 6 4 6 4z"/></svg>
          </span>
          <input id="username" name="username" class="form-control" required placeholder="Masukkan username" autocomplete="username" />
        </div>
      </div>

      <div class="mb-3">
        <label class="form-label" for="password">Password</label>
        <div class="pw-row">
          <input id="password" name="password" type="password" class="form-control" required placeholder="Masukkan password" autocomplete="current-password" />
          <button id="togglePwd" type="button" class="pw-toggle" aria-pressed="false">Lihat</button>
        </div>
      </div>

      <div class="mb-2">
        <button class="btn btn-primary" type="submit">Masuk</button>
      </div>

      <div class="small-muted">© <?= date('Y') ?> - Hak Cipta Dilindungi.</div>
    </form>
  </main>

  <script>
    (function(){
      try { document.getElementById('username').focus(); } catch(e){}
      const pwd = document.getElementById('password');
      const btn = document.getElementById('togglePwd');
      btn.addEventListener('click', function(){
        if (pwd.type === 'password') { pwd.type = 'text'; btn.textContent = 'Sembunyikan'; btn.setAttribute('aria-pressed','true'); }
        else { pwd.type = 'password'; btn.textContent = 'Lihat'; btn.setAttribute('aria-pressed','false'); }
      });
      pwd.addEventListener('keyup', function(e){ if (e.key === 'Enter') { this.form.submit(); } });

      // reduce motion for users who prefer it
      const prefersReduced = window.matchMedia('(prefers-reduced-motion: reduce)');
      if (prefersReduced && prefersReduced.matches) {
        document.querySelectorAll('.login-card').forEach(el => { el.style.transition = 'none'; });
      }
    })();
  </script>
</body>
</html>