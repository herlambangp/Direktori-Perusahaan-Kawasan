<?php
// ============================================================
// local-login.php — DEV ONLY: bypass SSO untuk localhost
// JANGAN upload ke server production!
// ============================================================

session_start();
require_once __DIR__ . '/config.php';

// Keamanan: hanya boleh diakses dari localhost
$host = $_SERVER['HTTP_HOST'] ?? '';
$isLocal = in_array($host, ['localhost', '127.0.0.1', '::1'])
        || str_starts_with($host, 'localhost:')
        || str_starts_with($host, '127.0.0.1:');

if (!$isLocal) {
    http_response_code(403);
    die('Forbidden: halaman ini hanya tersedia di lingkungan development.');
}

// Sudah login
if (!empty($_SESSION['is_admin'])) {
    header('Location: index.html');
    exit;
}

$error = '';

// Proses form login
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $inputUsername = trim($_POST['username'] ?? '');

    if ($inputUsername === '') {
        $error = 'Username tidak boleh kosong.';
    } else {
        $conn = db_connect();
        $stmt = $conn->prepare(
            "SELECT username, nama, email FROM user WHERE username = ? OR email = ? LIMIT 1"
        );
        $stmt->bind_param("ss", $inputUsername, $inputUsername);
        $stmt->execute();
        $dbUser = $stmt->get_result()->fetch_assoc();
        $conn->close();

        if ($dbUser) {
            $_SESSION['is_admin'] = true;
            $_SESSION['username'] = $dbUser['username'];
            $_SESSION['nama']     = $dbUser['nama'];
            $_SESSION['email']    = $dbUser['email'];
            $_SESSION['dev_mode'] = true;

            $logLine = '[' . date('Y-m-d H:i:s') . '] [DEV-LOGIN] username=' . $dbUser['username'] . PHP_EOL;
            @file_put_contents(__DIR__ . '/auth_debug.log', $logLine, FILE_APPEND | LOCK_EX);

            header('Location: index.html?auth=ok');
            exit;
        } else {
            $error = "Username '$inputUsername' tidak ditemukan di database.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dev Login — Direktori Perusahaan Kawasan</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Inter', sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #0f172a;
            background-image: radial-gradient(ellipse at 20% 50%, rgba(251,146,60,0.08) 0%, transparent 50%),
                              radial-gradient(ellipse at 80% 20%, rgba(251,146,60,0.05) 0%, transparent 40%);
        }
        .card {
            background: rgba(30, 41, 59, 0.95);
            border: 1px solid rgba(251,146,60,0.2);
            border-radius: 20px;
            padding: 2.5rem 2rem;
            width: 100%; max-width: 400px;
            box-shadow: 0 25px 60px rgba(0,0,0,0.5);
        }
        .dev-badge {
            display: inline-flex; align-items: center; gap: 6px;
            background: rgba(251,146,60,0.15); border: 1px solid rgba(251,146,60,0.35);
            color: #fb923c; font-size: 0.7rem; font-weight: 600;
            letter-spacing: 0.1em; text-transform: uppercase;
            padding: 4px 12px; border-radius: 999px; margin-bottom: 1.5rem;
        }
        .dev-badge::before {
            content: ''; width: 6px; height: 6px;
            border-radius: 50%; background: #fb923c;
            animation: blink 1.5s ease-in-out infinite;
        }
        @keyframes blink { 0%,100%{opacity:1} 50%{opacity:0.2} }
        .logo-area { display: flex; align-items: center; gap: 12px; margin-bottom: 0.5rem; }
        .logo-icon {
            width: 44px; height: 44px;
            background: linear-gradient(135deg, #fb923c, #f97316);
            border-radius: 12px; display: flex; align-items: center;
            justify-content: center; font-size: 1.3rem;
        }
        .logo-text h1 { font-size: 1rem; font-weight: 700; color: #f1f5f9; }
        .logo-text p  { font-size: 0.72rem; color: #64748b; }
        hr { border: none; border-top: 1px solid rgba(255,255,255,0.06); margin: 1.5rem 0; }
        h2 { font-size: 1.1rem; font-weight: 600; color: #f1f5f9; margin-bottom: 0.35rem; }
        .subtitle { font-size: 0.8rem; color: #64748b; margin-bottom: 1.5rem; line-height: 1.5; }
        label { display: block; font-size: 0.78rem; font-weight: 500; color: #94a3b8; margin-bottom: 6px; }
        input[type="text"] {
            width: 100%; padding: 0.7rem 1rem;
            background: rgba(15,23,42,0.7); border: 1px solid rgba(255,255,255,0.1);
            border-radius: 10px; color: #f1f5f9; font-size: 0.9rem; font-family: inherit;
            transition: border-color .2s, box-shadow .2s; outline: none;
        }
        input[type="text"]:focus { border-color: rgba(251,146,60,0.5); box-shadow: 0 0 0 3px rgba(251,146,60,0.1); }
        input::placeholder { color: #334155; }
        .hint { font-size: 0.72rem; color: #475569; margin-top: 5px; }
        .btn {
            width: 100%; margin-top: 1.25rem; padding: 0.8rem;
            background: linear-gradient(135deg, #fb923c, #f97316);
            color: #fff; font-size: 0.9rem; font-weight: 600; font-family: inherit;
            border: none; border-radius: 10px; cursor: pointer;
            transition: opacity .2s, transform .15s;
            box-shadow: 0 6px 20px rgba(251,146,60,0.35);
        }
        .btn:hover { opacity: 0.92; transform: translateY(-1px); }
        .error-box {
            margin-top: 1rem; padding: 0.7rem 1rem;
            background: rgba(239,68,68,0.1); border: 1px solid rgba(239,68,68,0.3);
            border-radius: 8px; color: #fca5a5; font-size: 0.8rem;
        }
        .footer-note {
            margin-top: 1.5rem; padding-top: 1rem;
            border-top: 1px solid rgba(255,255,255,0.05);
            font-size: 0.7rem; color: #334155; text-align: center; line-height: 1.6;
        }
        .footer-note a { color: #475569; text-decoration: none; }
    </style>
</head>
<body>
<div class="card">
    <span class="dev-badge">Development Mode</span>
    <div class="logo-area">
        <div class="logo-icon">🏭</div>
        <div class="logo-text">
            <h1>Kawasan Industri</h1>
            <p>Direktorat Statistik Industri — BPS</p>
        </div>
    </div>
    <hr>
    <h2>Login Lokal</h2>
    <p class="subtitle">Bypass SSO untuk pengembangan. Masukkan username yang terdaftar di database lokal.</p>
    <form method="POST" action="">
        <label for="username">Username / Email</label>
        <input type="text" id="username" name="username"
               placeholder="contoh: kurnia.rahmasari"
               autocomplete="username" autofocus required>
        <p class="hint">Gunakan username atau email yang ada di tabel <code>user</code>.</p>
        <button type="submit" class="btn">Masuk sebagai Admin</button>
        <?php if ($error): ?>
        <div class="error-box">⚠️ <?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
    </form>
    <p class="footer-note">
        ⚠️ Halaman ini hanya tersedia di <strong>localhost</strong>.<br>
        Di production, login menggunakan SSO BPS.<br><br>
        <a href="index.html">← Kembali ke beranda</a>
    </p>
</div>
</body>
</html>
