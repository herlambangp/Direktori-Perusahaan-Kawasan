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
    $emailPrefix  = $ssoEmail ? (strstr($ssoEmail, '@', true) ?: '') : '';

    // Pastikan minimal ada satu identifier
    if (!$ssoUsername && !$ssoEmail) {
        write_debug_log(['step' => 'identify', 'error' => 'no identifier', 'userinfo' => $userInfo]);
        header('Location: index.html?auth=error&msg=' . urlencode('Username/email tidak ditemukan dari SSO'));
        exit;
    }

    // ── 4. Cari user di tabel — cocokkan via username ATAU email ─────────────
    // Strategi (OR semua kemungkinan):
    //   a. preferred_username == username di DB
    //   b. email dari SSO     == email di DB
    //   c. prefix email (sebelum @) == username di DB
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

    write_debug_log([
        'step'        => 'db_lookup',
        'ssoUsername' => $ssoUsername,
        'ssoEmail'    => $ssoEmail,
        'emailPrefix' => $emailPrefix,
        'found'       => $dbUser ? $dbUser['username'] : null,
    ]);

    if ($dbUser) {
        // ✅ Admin — simpan ke session
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
}

// ── Error dari Keycloak ───────────────────────────────────────────────────────
if (isset($_GET['error'])) {
    $msg = $_GET['error_description'] ?? $_GET['error'];
    write_debug_log(['step' => 'keycloak_error', 'error' => $msg]);
    header('Location: index.html?auth=error&msg=' . urlencode($msg));
    exit;
}

// ── Belum ada code → mulai flow login via SSO Relay ─────────────────────────
// Redirect ke sso-relay.php di lsp.web.bps.go.id yang sudah terdaftar di Keycloak.
// Setelah login berhasil, relay akan mengirim signed token ke auth-relay-callback.php
$returnUrl = APP_URL . 'auth-relay-callback.php';
header('Location: ' . RELAY_URL . '?return_url=' . urlencode($returnUrl));
exit;
