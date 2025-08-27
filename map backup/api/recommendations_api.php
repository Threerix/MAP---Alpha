<?php
declare(strict_types=1);

// api/recommendations_api.php
header('Content-Type: application/json; charset=utf-8');

function respond(array $payload, int $code = 200): void {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

ini_set('display_errors', '0');
error_reporting(E_ALL);
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        if (ob_get_length()) { ob_clean(); }
        respond(['status'=>'error','message'=>'Fatal error: '.$e['message'],'file'=>basename($e['file']),'line'=>$e['line']], 500);
    }
});

define('ROOT_DIR', realpath(__DIR__ . '/..'));
define('INCLUDES_DIR', ROOT_DIR . '/includes');
require_once INCLUDES_DIR . '/config.php';
require_once INCLUDES_DIR . '/Database.php';
require_once __DIR__ . '/spotify_helper.php';

function db(): PDO { return Database::getConnection(); }
function body(): array {
    $raw = file_get_contents('php://input') ?: '';
    $data = json_decode($raw, true);
    return is_array($data) ? $data : $_POST;
}
function getUserByToken(PDO $pdo, string $token): ?array {
    $st = $pdo->prepare("SELECT id, username, spotify_refresh_token FROM users WHERE session_token = ? LIMIT 1");
    $st->execute([$token]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}
function getFavorites(PDO $pdo, int $userId): array {
    $st = $pdo->prepare("SELECT type, name, artist FROM favorites WHERE user_id = ? ORDER BY created_at DESC");
    $st->execute([$userId]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
function slug(string $s): string { return preg_replace('/[^a-z0-9]/i', '', strtolower(trim($s))); }
function generate_item_key(string $type, string $name, ?string $artist): string { return slug($type) . ':' . slug($name) . ':' . slug($artist ?? ''); }
function curlJson(string $url): ?array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_USERAGENT => 'MAPApp/1.0', CURLOPT_SSL_VERIFYPEER => false]);
    $res = curl_exec($ch);
    curl_close($ch);
    return $res ? json_decode($res, true) : null;
}
function getRecsFromLastFm(array $seed, array &$seenKeys): array {
    $apiKey = defined('LASTFM_API_KEY') ? LASTFM_API_KEY : '';
    if (!$apiKey) return ['music' => [], 'album' => [], 'artist' => []];
    $seedName = $seed['name'];
    $seedArtist = $seed['artist'] ?? $seed['name'];
    $encodedArtist = rawurlencode($seedArtist);
    $allRecs = ['music' => [], 'album' => [], 'artist' => []];
    $reason = "Porque você curte {$seedName}";
    $similarArtists = [];
    $similarUrl = "https://ws.audioscrobbler.com/2.0/?method=artist.getsimilar&artist={$encodedArtist}&api_key={$apiKey}&format=json&limit=3";
    $similarData = curlJson($similarUrl);
    if (!empty($similarData['similarartists']['artist'])) {
        foreach ($similarData['similarartists']['artist'] as $item) {
            $name = $item['name'] ?? null;
            $key = generate_item_key('artist', $name, null);
            if ($name && !isset($seenKeys[$key])) {
                $seenKeys[$key] = true;
                $allRecs['artist'][] = ['type' => 'artist', 'name' => $name, 'artist' => null, 'reason' => $reason];
                $similarArtists[] = $name;
            }
        }
    }
    $pool = ($seed['type'] !== 'album') ? array_merge([$seedArtist], $similarArtists) : $similarArtists;
    foreach ($pool as $currentArtistName) {
        $encodedCurrent = rawurlencode($currentArtistName);
        $tracksUrl = "https://ws.audioscrobbler.com/2.0/?method=artist.gettoptracks&artist={$encodedCurrent}&api_key={$apiKey}&format=json&limit=2";
        $albumsUrl = "https://ws.audioscrobbler.com/2.0/?method=artist.gettopalbums&artist={$encodedCurrent}&api_key={$apiKey}&format=json&limit=1";
        $tracksData = curlJson($tracksUrl);
        if (!empty($tracksData['toptracks']['track'])) {
            foreach ($tracksData['toptracks']['track'] as $item) {
                $name = $item['name'] ?? null;
                $key = generate_item_key('music', $name, $currentArtistName);
                if ($name && !isset($seenKeys[$key])) {
                    $seenKeys[$key] = true;
                    $allRecs['music'][] = ['type' => 'music', 'name' => $name, 'artist' => $currentArtistName, 'reason' => $reason];
                }
            }
        }
        $albumsData = curlJson($albumsUrl);
        if (!empty($albumsData['topalbums']['album'])) {
            foreach ($albumsData['topalbums']['album'] as $item) {
                $name = $item['name'] ?? null;
                $key = generate_item_key('album', $name, $currentArtistName);
                if ($name && !isset($seenKeys[$key])) {
                    $seenKeys[$key] = true;
                    $allRecs['album'][] = ['type' => 'album', 'name' => $name, 'artist' => $currentArtistName, 'reason' => $reason];
                }
            }
        }
    }
    return $allRecs;
}

function enrichWithSpotifyImages(string $accessToken, array &$recs): void {
    foreach ($recs as &$recGroup) {
        foreach ($recGroup as &$item) {
            $type = ($item['type'] === 'music') ? 'track' : $item['type'];
            $spotifyData = search_spotify($accessToken, $type, $item['name'], $item['artist']);
            if ($spotifyData && $spotifyData['imageUrl']) {
                $item['imageUrl'] = $spotifyData['imageUrl'];
            }
        }
    }
}

$input = body();
$action = strtolower((string)($input['action'] ?? ''));
if ($action !== 'get') respond(['status'=>'error','message'=>'Ação inválida.'], 400);

$pdo = db();
$token = trim((string)($input['session_token'] ?? ''));
if (!$token) respond(['status'=>'error','message'=>'Token ausente.'], 401);
$user = getUserByToken($pdo, $token);
if (!$user) respond(['status'=>'error','message'=>'Sessão inválida.'], 401);

$favorites = getFavorites($pdo, (int)$user['id']);
if (!$favorites) respond(['status'=>'success','data'=>['music'=>[], 'album'=>[], 'artist'=>[]]]);

$seenKeys = [];
foreach ($favorites as $fav) { $seenKeys[generate_item_key($fav['type'], $fav['name'], $fav['artist'])] = true; }

$finalRecs = ['music' => [], 'album' => [], 'artist' => []];
shuffle($favorites);
$seeds = array_slice($favorites, 0, 5);

$accessToken = get_spotify_access_token($pdo, (int)$user['id']);

if ($accessToken) {
    $spotifySeeds = ['limit' => 20, 'seed_artists' => [], 'seed_tracks' => []];
    foreach (array_slice($seeds, 0, 4) as $seed) { // Use max 4 seeds for Spotify
        $type = ($seed['type'] === 'music') ? 'track' : $seed['type'];
        $spotifyData = search_spotify($accessToken, $type, $seed['name'], $seed['artist']);
        if ($spotifyData) {
            if ($type === 'artist' && count($spotifySeeds['seed_artists']) < 2) $spotifySeeds['seed_artists'][] = $spotifyData['id'];
            elseif ($type === 'track' && count($spotifySeeds['seed_tracks']) < 3) $spotifySeeds['seed_tracks'][] = $spotifyData['id'];
        }
    }
    
    $spotifyRecs = get_spotify_recommendations($accessToken, $spotifySeeds);
    foreach ($spotifyRecs as $track) {
        $key = generate_item_key('music', $track['name'], $track['artists'][0]['name']);
        if (!isset($seenKeys[$key])) {
            $seenKeys[$key] = true;
            $finalRecs['music'][] = ['type'=>'music','name'=>$track['name'],'artist'=>$track['artists'][0]['name'],'reason'=>'Sugerido pelo Spotify','imageUrl'=>$track['album']['images'][0]['url'] ?? null];
        }
    }
}

foreach ($seeds as $seed) {
    $recs = getRecsFromLastFm($seed, $seenKeys);
    $finalRecs['music'] = array_merge($finalRecs['music'], $recs['music']);
    $finalRecs['album'] = array_merge($finalRecs['album'], $recs['album']);
    $finalRecs['artist'] = array_merge($finalRecs['artist'], $recs['artist']);
}

if ($accessToken) {
    enrichWithSpotifyImages($accessToken, $finalRecs);
}

shuffle($finalRecs['music']);
shuffle($finalRecs['album']);
shuffle($finalRecs['artist']);

respond(['status'=>'success','data'=>$finalRecs]);