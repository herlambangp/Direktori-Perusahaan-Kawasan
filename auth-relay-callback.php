<?php
// ============================================================
// auth-relay-callback.php — dsi.web.bps.go.id/kawasan/
//
// Menerima signed token dari sso-relay.php (lsp.web.bps.go.id),
// memvalidasi signature & TTL, lalu membuat sesi admin jika
// user terdaftar di tabel `user`.
// ============================================================

session_start();
require_once __DIR__ . '/config.php';

// ── Helper: validasi relay token ─────────────────────────────
function verify_relay_token(string $token): ?array {
    $parts = explode('.', $token, 2);
    if (count($parts) !== 2) return null;

    list($payloadB64, $sig) = $parts;

    // Validasi signature (constant-time comparison)
    $expectedSig = hash_hmac('sha256', $payloadB64, RELAY_SECRET);
    if (!hash_equals($expectedSig, $sig)) return null;

    $payload = json_decode(base64_decode($payloadB64), true);
    if (!is_array($payload)) return null;

    // Validasi TTL (max 5 menit)
    if (empty($payload['ts']) || (time() - $payload['ts']) > TOKEN_TTL) return null;

    return $payload;
}

// ── Tangani error dari relay ──────────────────────────────────
if (isset($_GET['relay_error'])) {
    $msg = $_GET['relay_error'];
    write_auth_log(['step' => 'relay_error', 'error' => $msg]);
    header('Location: index.html?auth=error&msg=' . urlencode($msg));
    exit;
}

// ── Tidak ada token → redirect ke relay ──────────────────────
if (!isset($_GET['relay_token'])) {
    $returnUrl = APP_URL . 'auth-relay-callback.php';
    header('Location: ' . RELAY_URL . '?return_url=' . urlencode($returnUrl));
    exit;
}

// ── Ada token → validasi ─────────────────────────────────────
$payload = verify_relay_token($_GET['relay_token']);

if ($payload === null) {
    write_auth_log(['step' => 'token_invalid', 'raw' => substr($_GET['relay_token'], 0, 60)]);
    header('Location: index.html?auth=error&msg=' . urlencode('Token relay tidak valid atau sudah kedaluwarsa.'));
    exit;
}

$ssoUsername = $payload['username'] ?? '';
$ssoEmail    = $payload['email']    ?? '';
$ssoName     = $payload['name']     ?? '';
$emailPrefix = $ssoEmail ? (strstr($ssoEmail, '@', true) ?: '') : '';

write_auth_log([
    'step'        => 'relay_received',
    'ssoUsername' => $ssoUsername,
    'ssoEmail'    => $ssoEmail,
]);

if (!$ssoUsername && !$ssoEmail) {
    header('Location: index.html?auth=error&msg=' . urlencode('Username/email tidak ditemukan dari SSO'));
    exit;
}

// ── Cari user di tabel ────────────────────────────────────────
$conn = db_connect();
$stmt = $conn->prepare(
    "SELECT username, nama, email FROM user
     WHERE username = ?
        OR email    = ?
        OR username = ?
     LIMIT 1"
);
$stmt->bind_param("sss", $ssoUsername, $ssoEmail, $emailPrefix);
$stmt->execute();
$dbUser = $stmt->get_result()->fetch_assoc();
$conn->close();

write_auth_log([
    'step'        => 'db_lookup',
    'ssoUsername' => $ssoUsername,
    'ssoEmail'    => $ssoEmail,
    'emailPrefix' => $emailPrefix,
    'found'       => $dbUser ? $dbUser['username'] : null,
]);

if ($dbUser) {
    // ✅ User terdaftar sebagai admin
    $_SESSION['is_admin']  = true;
    $_SESSION['username']  = $dbUser['username'];
    $_SESSION['nama']      = $dbUser['nama'];
    $_SESSION['email']     = $dbUser['email'];
    header('Location: index.html?auth=ok');
} else {
    // ⚠️ Login SSO berhasil tapi bukan admin terdaftar
    $identifier = $ssoUsername ?: $ssoEmail;
    session_destroy();
    header('Location: index.html?auth=notadmin&msg=' . urlencode(
        "Akun '$identifier' tidak terdaftar sebagai admin. Hubungi pengelola sistem."
    ));
}
exit;

// ── Helper: tulis debug log ───────────────────────────────────
function write_auth_log(array $data): void {
    $logFile = __DIR__ . '/auth_debug.log';
    $line    = '[' . date('Y-m-d H:i:s') . '] [RELAY] ' . json_encode($data, JSON_UNESCAPED_UNICODE) . PHP_EOL;
    file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
}
