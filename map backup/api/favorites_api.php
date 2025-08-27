<?php
declare(strict_types=1);

// api/favorites_api.php
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
    $st = $pdo->prepare("SELECT id, username FROM users WHERE session_token = ? LIMIT 1");
    $st->execute([$token]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}
function getFavorites(PDO $pdo, int $userId): array {
    $st = $pdo->prepare("SELECT type, name, artist FROM favorites WHERE user_id = ? ORDER BY created_at DESC");
    $st->execute([$userId]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$input = body();
$action = strtolower((string)($input['action'] ?? ''));

$pdo = db();
$token = trim((string)($input['session_token'] ?? ''));
if (!$token) respond(['status'=>'error','message'=>'Token ausente.'], 401);
$user = getUserByToken($pdo, $token);
if (!$user) respond(['status'=>'error','message'=>'Sessão inválida.'], 401);

switch ($action) {
    case 'get_all': {
        $favorites = getFavorites($pdo, (int)$user['id']);
        $accessToken = get_spotify_access_token($pdo, (int)$user['id']);
        if ($accessToken) {
            foreach ($favorites as &$fav) {
                $type = ($fav['type'] === 'music') ? 'track' : $fav['type'];
                $spotifyData = search_spotify($accessToken, $type, $fav['name'], $fav['artist']);
                if ($spotifyData && $spotifyData['imageUrl']) {
                    $fav['imageUrl'] = $spotifyData['imageUrl'];
                }
            }
        }
        respond(['status' => 'success', 'data' => ['favorites' => $favorites]]);
        break;
    }
    
    case 'add': {
        $type = trim((string)($input['type'] ?? ''));
        $name = trim((string)($input['name'] ?? ''));
        $artist = trim((string)($input['artist'] ?? ''));
        if (!$type || !$name) respond(['status'=>'error','message'=>'Tipo e nome são obrigatórios.'], 422);
        
        $stmt = $pdo->prepare("INSERT INTO favorites (user_id, type, name, artist) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE type=type");
        $stmt->execute([(int)$user['id'], $type, $name, $artist ?: null]);
        respond(['status' => 'success', 'message' => 'Adicionado aos favoritos!']);
        break;
    }
    
    case 'remove': {
        $type = trim((string)($input['type'] ?? ''));
        $name = trim((string)($input['name'] ?? ''));
        $artist = trim((string)($input['artist'] ?? ''));
        if (!$type || !$name) respond(['status'=>'error','message'=>'Dados inválidos.'], 422);

        if ($artist === '') {
             $stmt = $pdo->prepare("DELETE FROM favorites WHERE user_id = ? AND type = ? AND name = ? AND (artist IS NULL OR artist = '')");
             $stmt->execute([(int)$user['id'], $type, $name]);
        } else {
             $stmt = $pdo->prepare("DELETE FROM favorites WHERE user_id = ? AND type = ? AND name = ? AND artist = ?");
             $stmt->execute([(int)$user['id'], $type, $name, $artist]);
        }
        respond(['status' => 'success', 'message' => 'Removido dos favoritos.']);
        break;
    }
    
    default:
        respond(['status'=>'error','message'=>'Ação inválida.'], 400);
}