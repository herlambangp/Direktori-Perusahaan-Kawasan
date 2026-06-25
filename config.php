<?php
// ============================================================
// config.php — Konfigurasi terpusat
// ============================================================
// Saat upload ke hosting, HANYA file ini yang perlu diubah.
// Jangan hapus file ini, jangan rename.
// ============================================================

// --- Database ---
define('DB_HOST', 'localhost');     // ← Ganti ini saat di hosting (misal: 'mysql.hostinger.com')
define('DB_USER', 'root');          // ← Ganti ke username DB hosting
define('DB_PASS', '');              // ← Isi password DB hosting
define('DB_NAME', 'db_kawasan');    // ← Sesuaikan nama DB di hosting

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
