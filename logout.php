<?php
// logout.php — Hancurkan sesi lokal dan redirect ke SSO logout

session_start();
session_destroy();

require_once __DIR__ . '/config.php';

// Redirect ke Keycloak logout (agar sesi SSO juga dihapus)
$ssoLogout = SSO_BASE . '/auth/realms/' . SSO_REALM
           . '/protocol/openid-connect/logout?redirect_uri=' . urlencode(APP_URL);

header('Location: ' . $ssoLogout);
exit;
