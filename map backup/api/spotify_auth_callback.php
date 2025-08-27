<?php
declare(strict_types=1);
// api/spotify_auth_callback.php

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/Database.php';

session_start();

function db(): PDO { return Database::getConnection(); }

function getUserByToken(PDO $pdo, string $token): ?array {
    $st = $pdo->prepare("SELECT id FROM users WHERE session_token = ? LIMIT 1");
    $st->execute([$token]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

$state = $_GET['state'] ?? null;
$storedNonce = $_SESSION['spotify_csrf_nonce'] ?? null;

$parts = explode('::', $state ?? '', 2);
$receivedNonce = $parts[0] ?? null;
$mapSessionToken = $parts[1] ?? null;

if ($receivedNonce === null || $receivedNonce !== $storedNonce || $mapSessionToken === null) {
    header('Location: ' . SITE_URL . '/public/index.html?error=state_mismatch');
    exit;
}

unset($_SESSION['spotify_csrf_nonce']);

$code = $_GET['code'] ?? null;
if (!$code) {
    header('Location: ' . SITE_URL . '/public/index.html?error=auth_failed');
    exit;
}

// Exchange code for tokens
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, 'https://accounts.spotify.com/api/token');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
    'grant_type' => 'authorization_code',
    'code' => $code,
    'redirect_uri' => SPOTIFY_REDIRECT_URI
]));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Basic ' . base64_encode(SPOTIFY_CLIENT_ID . ':' . SPOTIFY_CLIENT_SECRET),
    'Content-Type: application/x-www-form-urlencoded'
]);

$result = curl_exec($ch);
curl_close($ch);
$tokenData = json_decode($result, true);

if (!isset($tokenData['access_token'])) {
    header('Location: ' . SITE_URL . '/public/index.html?error=token_failed');
    exit;
}

// Get Spotify user info
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, 'https://api.spotify.com/v1/me');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $tokenData['access_token']]);
$userResult = curl_exec($ch);
curl_close($ch);
$spotifyUser = json_decode($userResult, true);

// Save tokens AND user info to our database
$pdo = db();
$user = getUserByToken($pdo, $mapSessionToken);

if ($user && isset($spotifyUser['id'])) {
    // === MODIFICATION START ===
    // 1. Prepare user info variables
    $displayName = $spotifyUser['display_name'] ?? 'Usuário Spotify';
    // Get the first available profile image, or null if none exist
    $avatarUrl = $spotifyUser['images'][0]['url'] ?? null;

    // 2. Update the SQL query to include the new fields
    $stmt = $pdo->prepare("
        UPDATE users SET
            spotify_user_id = ?,
            spotify_access_token = ?,
            spotify_refresh_token = ?,
            spotify_expires_at = ?,
            spotify_display_name = ?,
            spotify_avatar_url = ?
        WHERE id = ?
    ");
    
    $expires_in_seconds = (int)($tokenData['expires_in'] ?? 3600) - 60;
    $expires_timestamp = time() + $expires_in_seconds;
    $mysql_datetime_format = date('Y-m-d H:i:s', $expires_timestamp);

    // 3. Execute with the new user info
    $stmt->execute([
        $spotifyUser['id'],
        $tokenData['access_token'],
        $tokenData['refresh_token'] ?? null, // Refresh token might not always be sent
        $mysql_datetime_format,
        $displayName,
        $avatarUrl,
        $user['id']
    ]);
    // === MODIFICATION END ===
}

// Redirect back to the app
header('Location: ' . SITE_URL . '/public/index.html');
exit;