<?php
declare(strict_types=1);
// api/spotify_auth_start.php

require_once __DIR__ . '/../includes/config.php';

session_start();

$mapSessionToken = $_GET['token'] ?? null;

if (!$mapSessionToken) {
    die('Erro: Token de sessão do MAP ausente.');
}

$csrfNonce = bin2hex(random_bytes(16));
$_SESSION['spotify_csrf_nonce'] = $csrfNonce;

// Combina o nonce de segurança com o token do MAP
$state = $csrfNonce . '::' . $mapSessionToken;

$scope = 'user-read-private user-read-email user-top-read';

$params = http_build_query([
    'response_type' => 'code',
    'client_id' => SPOTIFY_CLIENT_ID,
    'scope' => $scope,
    'redirect_uri' => SPOTIFY_REDIRECT_URI,
    'state' => $state
]);

header('Location: https://accounts.spotify.com/authorize?' . $params);
exit;