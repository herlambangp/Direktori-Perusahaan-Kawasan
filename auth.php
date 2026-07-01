<?php
// auth.php — SSO BPS Keycloak callback & login initiator
// Flow: index.html → auth.php → Keycloak login → auth.php?code=... → index.html

session_start();
require_once __DIR__ . '/config.php';

$redirectUri   = APP_URL . 'auth.php';
$authEndpoint  = SSO_BASE . '/auth/realms/' . SSO_REALM . '/protocol/openid-connect/auth';
$tokenEndpoint = SSO_BASE . '/auth/realms/' . SSO_REALM . '/protocol/openid-connect/token';
$userEndpoint  = SSO_BASE . '/auth/realms/' . SSO_REALM . '/protocol/openid-connect/userinfo';

// ── Sudah punya session admin → langsung ke index ────────────────────────────
if (!empty($_SESSION['is_admin'])) {
    header('Location: index.html');
    exit;
}

// ── Deteksi localhost → langsung OAuth ke Keycloak (tanpa relay, hindari WAF) ─
$_host    = $_SERVER['HTTP_HOST'] ?? '';
$_isLocal = in_array($_host, ['localhost', '127.0.0.1', '::1'])
         || str_starts_with($_host, 'localhost:')
         || str_starts_with($_host, '127.0.0.1:');

// ── Helper: tulis debug log ───────────────────────────────────────────────────
function write_debug_log(array $data): void {
    $logFile = __DIR__ . '/auth_debug.log';
    $line    = '[' . date('Y-m-d H:i:s') . '] ' . json_encode($data, JSON_UNESCAPED_UNICODE) . PHP_EOL;
    file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
}

// ── Callback dari Keycloak (ada ?code=) ──────────────────────────────────────
if (isset($_GET['code'])) {

    // Validasi state (anti-CSRF)
    if (empty($_GET['state']) || $_GET['state'] !== ($_SESSION['oauth_state'] ?? '')) {
        session_destroy();
        header('Location: index.html?auth=error&msg=' . urlencode('State tidak valid (kemungkinan serangan CSRF)'));
        exit;
    }
    unset($_SESSION['oauth_state']);

    // ── 1. Tukar authorization code → access token ────────────────────────────
    $postData = http_build_query([
        'grant_type'    => 'authorization_code',
        'client_id'     => SSO_CLIENT,
        'client_secret' => SSO_SECRET,
        'code'          => $_GET['code'],
        'redirect_uri'  => $redirectUri,
    ]);

    $ctx = stream_context_create([
        'http' => [
            'method'  => 'POST',
            'header'  => "Content-Type: application/x-www-form-urlencoded\r\n"
                       . "Content-Length: " . strlen($postData) . "\r\n",
            'content' => $postData,
            'timeout' => 15,
        ],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);

    $tokenResponse = @file_get_contents($tokenEndpoint, false, $ctx);
    if ($tokenResponse === false) {
        write_debug_log(['step' => 'token', 'error' => 'file_get_contents failed', 'endpoint' => $tokenEndpoint]);
        header('Location: index.html?auth=error&msg=' . urlencode('Gagal menghubungi server SSO'));
        exit;
    }

    $tokenData = json_decode($tokenResponse, true);
    if (empty($tokenData['access_token'])) {
        $errDesc = $tokenData['error_description'] ?? ($tokenData['error'] ?? 'Token tidak diperoleh');
        write_debug_log(['step' => 'token', 'error' => $errDesc, 'response' => $tokenData]);
        header('Location: index.html?auth=error&msg=' . urlencode($errDesc));
        exit;
    }

    // ── 2. Ambil informasi user dari userinfo endpoint ────────────────────────
    $ctx2 = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => "Authorization: Bearer {$tokenData['access_token']}\r\n",
            'timeout' => 10,
        ],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);

    $userInfoRaw = @file_get_contents($userEndpoint, false, $ctx2);
    $userInfo    = $userInfoRaw ? json_decode($userInfoRaw, true) : [];

    // ── DEBUG: Catat semua field yang dikembalikan Keycloak ───────────────────
    write_debug_log(['step' => 'userinfo', 'fields' => $userInfo]);

    // ── 3. Ekstrak kandidat username dari userinfo ────────────────────────────
    $ssoUsername  = trim($userInfo['preferred_username'] ?? '');
    $ssoEmail     = trim($userInfo['email']              ?? '');
    $ssoName      = trim($userInfo['name']               ?? ($userInfo['given_name'] ?? ''));
    $ssoNipLama   = trim($userInfo['nip-lama']           ?? '');
    $emailPrefix  = $ssoEmail ? (strstr($ssoEmail, '@', true) ?: '') : '';

    // Pastikan minimal ada satu identifier
    if (!$ssoNipLama && !$ssoUsername && !$ssoEmail) {
        write_debug_log(['step' => 'identify', 'error' => 'no identifier', 'userinfo' => $userInfo]);
        header('Location: index.html?auth=error&msg=' . urlencode('Identitas tidak ditemukan dari SSO'));
        exit;
    }

    // ── 4. Cari user di tabel — cocokkan via niplama dari SSO ───────────────
    // Tabel user memiliki kolom: niplama, nama, jabatan, admin_dir
    // SSO BPS mengirim field 'nip-lama' yang dicocokkan ke kolom niplama
    $conn = db_connect();
    $stmt = $conn->prepare(
        "SELECT niplama, nama, jabatan, admin_dir FROM user
         WHERE niplama = ?
         LIMIT 1"
    );
    $stmt->bind_param("s", $ssoNipLama);
    $stmt->execute();
    $dbUser = $stmt->get_result()->fetch_assoc();
    $conn->close();

    write_debug_log([
        'step'      => 'db_lookup',
        'nipLama'   => $ssoNipLama,
        'username'  => $ssoUsername,
        'found'     => $dbUser ? $dbUser['niplama'] : null,
        'admin_dir' => $dbUser ? $dbUser['admin_dir'] : null,
    ]);

    if ($dbUser && (int)$dbUser['admin_dir'] === 1) {
        // ✅ Admin terdaftar dengan admin_dir = 1
        $_SESSION['is_admin']  = true;
        $_SESSION['username']  = $ssoUsername ?: $ssoNipLama;
        $_SESSION['nama']      = $dbUser['nama'];
        $_SESSION['niplama']   = $dbUser['niplama'];
        header('Location: index.html?auth=ok');
    } elseif ($dbUser && (int)$dbUser['admin_dir'] !== 1) {
        // ⚠️ Ada di DB tapi bukan admin direktori
        session_destroy();
        header('Location: index.html?auth=notadmin&msg=' . urlencode(
            "Akun '{$dbUser['nama']}' tidak memiliki akses admin direktori."
        ));
    } else {
        // ⚠️ Login SSO berhasil tapi NIP tidak ditemukan di DB
        $identifier = $ssoNipLama ?: $ssoUsername;
        session_destroy();
        header('Location: index.html?auth=notadmin&msg=' . urlencode(
            "NIP '$identifier' tidak terdaftar sebagai admin. Hubungi pengelola sistem."
        ));
    }
    exit;
}

// ── Error dari Keycloak ───────────────────────────────────────────────────────
if (isset($_GET['error'])) {
    $msg = $_GET['error_description'] ?? $_GET['error'];
    write_debug_log(['step' => 'keycloak_error', 'error' => $msg]);
    header('Location: index.html?auth=error&msg=' . urlencode($msg));
    exit;
}

// ── Belum ada code → mulai flow login ────────────────────────────────────────
if ($_isLocal) {
    // LOCALHOST: langsung OAuth ke Keycloak (tanpa relay, hindari WAF)
    // Sama seperti flow awal sebelum relay ditambahkan
    $state = bin2hex(random_bytes(16));
    $_SESSION['oauth_state'] = $state;

    $loginUrl = $authEndpoint . '?' . http_build_query([
        'client_id'     => SSO_CLIENT,
        'redirect_uri'  => $redirectUri,  // http://localhost/dsi/kawasan/auth.php
        'response_type' => 'code',
        'scope'         => 'openid profile-pegawai email',
        'state'         => $state,
    ]);

    header('Location: ' . $loginUrl);
} else {
    // PRODUCTION: Redirect via SSO Relay di lsp.web.bps.go.id
    // Setelah login berhasil, relay mengirim signed token ke auth-relay-callback.php
    $returnUrl = APP_URL . 'auth-relay-callback.php';
    header('Location: ' . RELAY_URL . '?return_url=' . urlencode($returnUrl));
}
exit;

