<?php
// ============================================================
// sso-relay.php
// UPLOAD FILE INI KE: https://lsp.web.bps.go.id/sso-relay.php
//
// Fungsi: Menjadi jembatan SSO antara lsp.web.bps.go.id (client
// terdaftar di Keycloak) dan dsi.web.bps.go.id (client belum
// terdaftar). Setelah login berhasil, mengirim signed token
// ke callback URL di dsi.
// ============================================================

session_start();

// ============================================================
// KONFIGURASI — Sesuaikan jika perlu
// ============================================================

// Shared secret: HARUS SAMA dengan RELAY_SECRET di config.php dsi
define('RELAY_SECRET', 'K8xP#mQ2vL9nR4wT7uY1sZ5jA3bC6dE0');

// Whitelist domain yang boleh menerima relay token (keamanan)
// Production + local development hosts
define('ALLOWED_RETURN_HOSTS', [
    'dsi.web.bps.go.id',
    'localhost',
    '127.0.0.1',
]);

// Token berlaku max N detik (anti replay-attack)
define('TOKEN_TTL', 300); // 5 menit

// SSO BPS Keycloak — sesuaikan jika berbeda dengan di lsp
define('SSO_BASE',   'https://sso.bps.go.id');
define('SSO_REALM',  'pegawai-bps');
define('SSO_CLIENT', '02600-lsp-m5s');
define('SSO_SECRET', '134d7afa-fe13-4b3f-89e5-b3e8ab632513');

// redirect_uri HARUS terdaftar di Keycloak untuk client ini
$selfUrl       = 'https://lsp.web.bps.go.id/sso-relay.php';
$authEndpoint  = SSO_BASE . '/auth/realms/' . SSO_REALM . '/protocol/openid-connect/auth';
$tokenEndpoint = SSO_BASE . '/auth/realms/' . SSO_REALM . '/protocol/openid-connect/token';
$userEndpoint  = SSO_BASE . '/auth/realms/' . SSO_REALM . '/protocol/openid-connect/userinfo';

// ============================================================
// Helper: buat signed token
// ============================================================
function make_relay_token(array $payload) {
    $payloadB64 = base64_encode(json_encode($payload));
    $sig        = hash_hmac('sha256', $payloadB64, RELAY_SECRET);
    return $payloadB64 . '.' . $sig;
}

// ============================================================
// STEP 2: Callback dari Keycloak (ada ?code=)
// ============================================================
if (isset($_GET['code'])) {

    // Validasi state (anti-CSRF)
    if (empty($_GET['state']) || $_GET['state'] !== ($_SESSION['relay_state'] ?? '')) {
        $returnUrl = $_SESSION['relay_return'] ?? '';
        session_destroy();
        if ($returnUrl) {
            header('Location: ' . $returnUrl . '?relay_error=' . urlencode('State tidak valid'));
        } else {
            die('State tidak valid.');
        }
        exit;
    }

    $returnUrl = $_SESSION['relay_return'] ?? '';
    unset($_SESSION['relay_state'], $_SESSION['relay_return']);

    if (empty($returnUrl)) {
        die('Return URL tidak ditemukan di sesi.');
    }

    // Tukar authorization code dengan access token
    $postData = http_build_query([
        'grant_type'    => 'authorization_code',
        'client_id'     => SSO_CLIENT,
        'client_secret' => SSO_SECRET,
        'code'          => $_GET['code'],
        'redirect_uri'  => $selfUrl,
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
        header('Location: ' . $returnUrl . '?relay_error=' . urlencode('Gagal menghubungi server SSO'));
        exit;
    }

    $tokenData = json_decode($tokenResponse, true);
    if (empty($tokenData['access_token'])) {
        $err = $tokenData['error_description'] ?? ($tokenData['error'] ?? 'Token tidak diperoleh');
        header('Location: ' . $returnUrl . '?relay_error=' . urlencode($err));
        exit;
    }

    // Ambil user info dari Keycloak
    $ctx2 = stream_context_create([
        'http' => [
            'method'  => 'GET',
            'header'  => "Authorization: Bearer {$tokenData['access_token']}\r\n",
            'timeout' => 10,
        ],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);

    $userInfoRaw = @file_get_contents($userEndpoint, false, $ctx2);
    $userInfo    = $userInfoRaw ? json_decode($userInfoRaw, true) : [];

    // Buat signed payload
    // NIP di Keycloak BPS bisa ada di field 'nip', 'employeeId', atau 'preferred_username'
    $nip = trim(
        $userInfo['nip']            ??
        $userInfo['employeeId']     ??
        $userInfo['employee_id']    ??
        $userInfo['preferred_username'] ?? ''
    );

    // Log userinfo mentah untuk debugging (hapus di production)
    $debugLog = date('Y-m-d H:i:s') . ' userinfo=' . json_encode($userInfo, JSON_UNESCAPED_UNICODE) . PHP_EOL;
    @file_put_contents(__DIR__ . '/sso_relay_debug.log', $debugLog, FILE_APPEND | LOCK_EX);

    $payload = [
        'nip'      => $nip,
        'niplama'  => trim($userInfo['nip-lama'] ?? ''),  // NIP lama (9 digit) — key di tabel user
        'username' => trim($userInfo['preferred_username'] ?? ''),
        'email'    => trim($userInfo['email']              ?? ''),
        'name'     => trim($userInfo['name'] ?? ($userInfo['given_name'] ?? '')),
        'ts'       => time(),
    ];

    $token = make_relay_token($payload);

    // Redirect ke dsi dengan token
    header('Location: ' . $returnUrl . '?relay_token=' . urlencode($token));
    exit;
}

// ============================================================
// Error dari Keycloak
// ============================================================
if (isset($_GET['error'])) {
    $returnUrl = $_SESSION['relay_return'] ?? '';
    $msg = $_GET['error_description'] ?? $_GET['error'];
    if ($returnUrl) {
        header('Location: ' . $returnUrl . '?relay_error=' . urlencode($msg));
    } else {
        die('SSO Error: ' . htmlspecialchars($msg));
    }
    exit;
}

// ============================================================
// STEP 1: Mulai flow — simpan return URL, redirect ke Keycloak
// ============================================================
$returnUrl = $_GET['return_url'] ?? '';

// Validasi return URL (keamanan: hanya boleh ke host terdaftar)
if (empty($returnUrl)) {
    die('Parameter return_url diperlukan.');
}

$parsedReturn = parse_url($returnUrl);
$returnHost   = $parsedReturn['host'] ?? '';

// Debug log untuk troubleshooting
$debugLine = date('Y-m-d H:i:s') . ' [STEP1] return_url=' . $returnUrl . ' host=' . $returnHost . PHP_EOL;
@file_put_contents(__DIR__ . '/sso_relay_debug.log', $debugLine, FILE_APPEND | LOCK_EX);

if (!in_array($returnHost, ALLOWED_RETURN_HOSTS, true)) {
    $debugLine2 = date('Y-m-d H:i:s') . ' [BLOCKED] host=' . $returnHost . ' not in whitelist' . PHP_EOL;
    @file_put_contents(__DIR__ . '/sso_relay_debug.log', $debugLine2, FILE_APPEND | LOCK_EX);
    die('Return URL tidak diizinkan: ' . htmlspecialchars($returnHost) . '. Allowed: ' . implode(', ', ALLOWED_RETURN_HOSTS));
}

$_SESSION['relay_return'] = $returnUrl;
$state = bin2hex(random_bytes(16));
$_SESSION['relay_state'] = $state;

$loginUrl = $authEndpoint . '?' . http_build_query([
    'client_id'     => SSO_CLIENT,
    'redirect_uri'  => $selfUrl,
    'response_type' => 'code',
    'scope'         => 'openid profile-pegawai email',
    'state'         => $state,
]);

header('Location: ' . $loginUrl);
exit;
