<?php
declare(strict_types=1);

// api/feedback_api.php
header('Content-Type: application/json; charset=utf-8');

function respond(array $payload, int $code = 200): void {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

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

$input = body();
$action = strtolower((string)($input['action'] ?? ''));

if ($action !== 'rate') {
    respond(['status'=>'error','message'=>'Ação inválida.'], 400);
}

$pdo = db();
$token = trim((string)($input['session_token'] ?? ''));
if (!$token) respond(['status'=>'error','message'=>'Token ausente.'], 401);
$user = getUserByToken($pdo, $token);
if (!$user) respond(['status'=>'error','message'=>'Sessão inválida.'], 401);

$rating = (int)($input['rating'] ?? 0);
$item_type = trim((string)($input['type'] ?? ''));
$item_name = trim((string)($input['name'] ?? ''));
$item_artist = trim((string)($input['artist'] ?? ''));

if ($rating < 1 || $rating > 5 || !$item_type || !$item_name) {
    respond(['status'=>'error','message'=>'Dados de avaliação inválidos.'], 422);
}

$stmt = $pdo->prepare("
    INSERT INTO ratings (user_id, item_type, item_name, item_artist, rating)
    VALUES (?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE rating = VALUES(rating)
");

$stmt->execute([
    (int)$user['id'],
    $item_type,
    $item_name,
    $item_artist ?: null,
    $rating
]);

respond(['status'=>'success','message'=>'Avaliação salva!']);