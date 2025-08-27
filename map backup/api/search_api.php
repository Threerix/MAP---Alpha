<?php
declare(strict_types=1);

// api/search_api.php
header('Content-Type: application/json; charset=utf-8');

function respond(array $payload, int $code = 200): void {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

define('ROOT_DIR', realpath(__DIR__ . '/..'));
require_once ROOT_DIR . '/includes/config.php';

function curlJson(string $url): ?array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5, CURLOPT_USERAGENT => 'MAPApp/1.0']);
    $res = curl_exec($ch);
    curl_close($ch);
    return $res ? json_decode($res, true) : null;
}

$query = trim((string)($_GET['query'] ?? ''));
$type = trim((string)($_GET['type'] ?? 'track')); // 'track', 'album', 'artist'
$limit = 5;

if (strlen($query) < 2) {
    respond(['status'=>'success','data'=>[]]);
}

$apiKey = defined('LASTFM_API_KEY') ? LASTFM_API_KEY : '';
if (!$apiKey) respond(['status'=>'error','message'=>'Chave da API não configurada.'], 500);

$method = "{$type}.search";
$url = "https://ws.audioscrobbler.com/2.0/?method={$method}&{$type}=" . rawurlencode($query) . "&api_key={$apiKey}&format=json&limit={$limit}";

$data = curlJson($url);
$results = [];

if ($type === 'track' && !empty($data['results']['trackmatches']['track'])) {
    foreach ($data['results']['trackmatches']['track'] as $item) {
        if (!empty($item['name']) && !empty($item['artist'])) {
            $results[] = ['name' => $item['name'], 'artist' => $item['artist']];
        }
    }
} else if ($type === 'album' && !empty($data['results']['albummatches']['album'])) {
    foreach ($data['results']['albummatches']['album'] as $item) {
        if (!empty($item['name']) && !empty($item['artist'])) {
            $results[] = ['name' => $item['name'], 'artist' => $item['artist']];
        }
    }
} else if ($type === 'artist' && !empty($data['results']['artistmatches']['artist'])) {
    foreach ($data['results']['artistmatches']['artist'] as $item) {
        if (!empty($item['name'])) {
            $results[] = ['name' => $item['name'], 'artist' => null];
        }
    }
}

respond(['status'=>'success','data'=>$results]);