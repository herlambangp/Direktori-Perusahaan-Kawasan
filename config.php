<?php
// ============================================================
// config.php — Konfigurasi terpusat
// ============================================================
// Saat upload ke hosting, HANYA file ini yang perlu diubah.
// 10.0.11.161, lspwebbp_hp, lspwebbp_namadb, *Hp040391
// ============================================================

// --- Database ---
define('DB_HOST', 'localhost');      // Server hosting dsi.web.bps.go.id
define('DB_USER', 'dsiwebbp_admin'); // Username DB hosting
define('DB_PASS', 'dsi@5300');      // Password DB hosting
define('DB_NAME', 'dsiwebbp_kawasan'); // Nama DB di hosting

// --- Auto-detect APP URL (root direktori ini di web) ---
// Jika di hosting mengalami "Invalid parameter: redirect_uri", HAPUS/COMMENT baris // di bawah
// dan sesuaikan dengan URL publik aplikasi Anda secara persis (harus diakhiri garis miring).
// define('APP_URL', 'https://domainbps.go.id/direktori-kawasan/'); 

if (!defined('APP_URL')) {
    // Deteksi HTTPS untuk reverse proxy (X-Forwarded-Proto)
    $isHttps  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || 
                (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    $_proto   = $isHttps ? 'https' : 'http';
    $_host    = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $_docRoot = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
    $_selfDir = rtrim(str_replace('\\', '/', __DIR__), '/');
    $_webPath = ($_docRoot !== '') ? str_replace($_docRoot, '', $_selfDir) : '';
    define('APP_URL', $_proto . '://' . $_host . $_webPath . '/');
}

// --- SSO BPS Keycloak ---
define('SSO_BASE',   'https://sso.bps.go.id');          // Base URL SSO BPS
define('SSO_REALM',  'pegawai-bps');                              // ← Sesuaikan realm Keycloak BPS
define('SSO_CLIENT', '02600-lsp-m5s');                // ← Sesuaikan client_id yg didaftarkan
define('SSO_SECRET', '134d7afa-fe13-4b3f-89e5-b3e8ab632513');    // ← Isi client secret jika confidential client

// --- SSO Relay (via lsp.web.bps.go.id) ---
// sso-relay.php di lsp bertindak sebagai jembatan Keycloak untuk domain ini.
// RELAY_SECRET harus sama persis dengan define('RELAY_SECRET', ...) di sso-relay.php
define('RELAY_URL',    'https://lsp.web.bps.go.id/sso-relay.php');
define('RELAY_SECRET', 'K8xP#mQ2vL9nR4wT7uY1sZ5jA3bC6dE0'); // ← Jaga kerahasiaan!
define('TOKEN_TTL',    300); // Token relay berlaku 5 menit

// --- Helper: buat koneksi MySQLi, langsung exit jika gagal ---
function db_connect(): mysqli {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($conn->connect_error) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'status'  => 'error',
            'message' => 'Database connection failed: ' . $conn->connect_error,
        ]);
        exit;
    }
    $conn->set_charset('utf8mb4');
    return $conn;
}

// --- Helper khusus setup_db.php: koneksi tanpa nama DB ---
function db_connect_no_db(): mysqli {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS);
    if ($conn->connect_error) {
        die('Koneksi gagal: ' . $conn->connect_error);
    }
    $conn->set_charset('utf8mb4');
    return $conn;
}
