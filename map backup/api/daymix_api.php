<?php
declare(strict_types=1);

// api/daymix_api.php
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

function db(): PDO { return Database::getConnection(); }

function body(): array {
    $raw = file_get_contents('php://input') ?: '';
    $data = json_decode($raw, true);
    return is_array($data) ? $data : $_POST;
}

function getUserByToken(PDO $pdo, string $token): ?array {
    $st = $pdo->prepare("SELECT id FROM users WHERE session_token = ? LIMIT 1");
    $st->execute([$token]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

// --- MODIFIED FUNCTION ---
// Added cURL error checking to see if the request itself failed.
function curlJson(string $url): ?array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
        CURLOPT_USERAGENT => 'MAPApp/1.0', CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $res = curl_exec($ch);
    
    // NEW: Check for cURL errors (e.g., network issues)
    if (curl_errno($ch)) {
        // Log the error or handle it as needed. For now, we'll return null.
        // error_log('cURL error: ' . curl_error($ch));
        curl_close($ch);
        return null;
    }

    curl_close($ch);
    return $res ? json_decode($res, true) : null;
}

// --- HEAVILY MODIFIED FUNCTION ---
// Added extensive error checking and clear failure messages.
function generateDayMixFromSeed(string $seedTrack, string $seedArtist = ''): array {
    $apiKey = defined('LASTFM_API_KEY') ? LASTFM_API_KEY : '';
    // NEW: Check for API Key and fail clearly if it's missing.
    if (!$apiKey) {
        return ['error' => 'A chave da API da Last.fm (LASTFM_API_KEY) não está configurada no servidor.'];
    }
    
    // 1. Search for the track to get confirmed details
    $searchUrl = "https://ws.audioscrobbler.com/2.0/?method=track.search&track=" . rawurlencode($seedTrack);
    if ($seedArtist) $searchUrl .= "&artist=" . rawurlencode($seedArtist);
    $searchUrl .= "&api_key={$apiKey}&format=json&limit=1";
    
    $searchData = curlJson($searchUrl);
    // NEW: Check if the API call itself failed
    if ($searchData === null) {
        return ['error' => 'Não foi possível conectar à API da Last.fm para buscar a música.'];
    }

    $track = $searchData['results']['trackmatches']['track'][0] ?? null;
    // NEW: Check if the track was actually found
    if (!$track) {
        return ['error' => 'A música semente não foi encontrada. Tente ser mais específico, como "Nome da Música - Nome do Artista".'];
    }

    $confirmedArtist = $track['artist'];
    $confirmedTrack = $track['name'];

    // 2. Get similar tracks based on the confirmed details
    $similarUrl = "https://ws.audioscrobbler.com/2.0/?method=track.getsimilar&artist=" . rawurlencode($confirmedArtist) . "&track=" . rawurlencode($confirmedTrack) . "&api_key={$apiKey}&format=json&limit=100";
    
    $similarData = curlJson($similarUrl);
    // NEW: Check if the similar tracks API call failed
    if ($similarData === null) {
        return ['error' => 'Não foi possível conectar à API da Last.fm para buscar músicas similares.'];
    }

    // NEW: Check if the API returned an error message
    if (isset($similarData['error'])) {
        return ['error' => 'API da Last.fm retornou um erro: ' . $similarData['message']];
    }
    
    // NEW: Check if the similar tracks array is empty
    if (empty($similarData['similartracks']['track'])) {
        return ['error' => 'Não foram encontradas músicas similares para esta semente. Tente outra música.'];
    }
    
    $playlist = [];
    $tracks = $similarData['similartracks']['track'];
    shuffle($tracks);

    $seenArtists = [];
    foreach ($tracks as $item) {
        if (count($playlist) >= 25) break;

        $artistName = $item['artist']['name'] ?? '';
        $trackName = $item['name'] ?? '';

        if ($artistName && $trackName) {
            $seenArtists[$artistName] = ($seenArtists[$artistName] ?? 0) + 1;
            if ($seenArtists[$artistName] <= 2) {
                $playlist[] = ['type' => 'music', 'name' => $trackName, 'artist' => $artistName, 'reason' => 'Day Mix'];
            }
        }
    }
    
    // If after all filtering the playlist is still empty, return a message
    if (empty($playlist)) {
        return ['error' => 'Não foi possível gerar uma mix com as músicas encontradas.'];
    }
    
    // On success, return the playlist in the expected format
    return ['tracks' => $playlist];
}


// --- MAIN SCRIPT LOGIC ---
$input = body();
$action = strtolower((string)($input['action'] ?? ''));

if ($action !== 'generate') {
    respond(['status'=>'error','message'=>'Ação inválida.'], 400);
}

$pdo = db();
$token = trim((string)($input['session_token'] ?? ''));
if (!$token) respond(['status'=>'error','message'=>'Token ausente.'], 401);
$user = getUserByToken($pdo, $token);
if (!$user) respond(['status'=>'error','message'=>'Sessão inválida.'], 401);

$seed = trim((string)($input['seed'] ?? ''));
if (!$seed) respond(['status'=>'error','message'=>'Música semente é obrigatória.'], 422);

$parts = explode('-', $seed, 2);
$seedTrack = trim($parts[0]);
$seedArtist = isset($parts[1]) ? trim($parts[1]) : '';

$result = generateDayMixFromSeed($seedTrack, $seedArtist);

// --- NEW: Check the result for an error ---
if (isset($result['error'])) {
    // If the function returned an error, send it to the user.
    respond(['status' => 'error', 'message' => $result['error']], 400);
}

// If successful, send the tracks
respond(['status'=>'success','data'=>['tracks' => $result['tracks']]]);