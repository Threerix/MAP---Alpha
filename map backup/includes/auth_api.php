<?php
declare(strict_types=1);

// includes/auth_api.php - Versão Corrigida
header('Content-Type: application/json; charset=utf-8');

// Helpers de resposta
function respond(array $payload, int $code = 200): void {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

// Config e DB
// Correctly determine the root directory relative to this api script's location
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

function getUserByUsernameOrEmail(PDO $pdo, string $login): ?array {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? OR email = ? LIMIT 1");
    $stmt->execute([$login, $login]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function getUserByToken(PDO $pdo, string $token): ?array {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE session_token = ? LIMIT 1");
    $stmt->execute([$token]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

$input  = body();
$action = strtolower(trim((string)($input['action'] ?? '')));

// === LOGIN ===
if ($action === 'login') {
    try {
        $pdo = db();
        $login    = trim((string)($input['username'] ?? ''));
        $password = (string)($input['password'] ?? '');

        if (!$login || !$password) {
            respond(['status' => 'error', 'message' => 'Usuário e senha são obrigatórios.'], 422);
        }

        $user = getUserByUsernameOrEmail($pdo, $login);
        if (!$user) {
            respond(['status' => 'error', 'message' => 'Usuário não encontrado.'], 401);
        }

        $storedHash = $user['password_hash'] ?? $user['password'] ?? '';
        if (!password_verify($password, $storedHash)) {
            respond(['status' => 'error', 'message' => 'Senha incorreta.'], 401);
        }

        $token = bin2hex(random_bytes(24));
        $updateStmt = $pdo->prepare("UPDATE users SET session_token = ? WHERE id = ?");
        $updateStmt->execute([$token, (int)$user['id']]);

        respond(['status' => 'success', 'data' => ['session_token' => $token, 'username' => $user['username']]]);

    } catch (Throwable $e) {
        respond(['status' => 'error', 'message' => 'Erro interno do servidor: ' . $e->getMessage()], 500);
    }

// === VERIFICAR SESSÃO ===
} elseif ($action === 'check_session') {
    try {
        $pdo = db();
        $token = trim((string)($input['session_token'] ?? ''));

        if (!$token) {
            respond(['status' => 'error', 'message' => 'Token de sessão obrigatório.'], 422);
        }

        $user = getUserByToken($pdo, $token);
        if (!$user) {
            respond(['status' => 'error', 'message' => 'Sessão inválida.'], 401);
        }

        // === MODIFICATION START ===
        // Monta os dados do usuário, agora incluindo o `spotify_user_id`
        $userData = [
            'id' => (int)$user['id'],
            'map_username' => $user['username'],
            'email' => $user['email'] ?? '',
            // Added the spotify_user_id field that the javascript is checking for
            'spotify_user_id' => $user['spotify_user_id'] ?? null, 
            'spotify_display_name' => $user['spotify_display_name'] ?? null,
            'spotify_avatar_url' => $user['spotify_avatar_url'] ?? null,
        ];
        
        // This 'spotify_connected' flag is now derived from the actual ID for consistency
        $isSpotifyConnected = !empty($user['spotify_user_id']);
        // === MODIFICATION END ===

        respond([
            'status' => 'success',
            'data' => [
                'is_logged_in' => true,
                'user' => $userData,
                'spotify_connected' => $isSpotifyConnected
            ]
        ]);

    } catch (Throwable $e) {
        respond(['status' => 'error', 'message' => 'Erro interno do servidor: ' . $e->getMessage()], 500);
    }

// === LOGOUT ===
} elseif ($action === 'logout') {
    try {
        $pdo = db();
        $token = trim((string)($input['session_token'] ?? ''));

        if ($token) {
            $stmt = $pdo->prepare("UPDATE users SET session_token = NULL WHERE session_token = ?");
            $stmt->execute([$token]);
        }

        respond(['status' => 'success', 'message' => 'Logout realizado com sucesso.']);

    } catch (Throwable $e) {
        respond(['status' => 'error', 'message' => 'Erro interno do servidor: ' . $e->getMessage()], 500);
    }

// === AÇÃO INVÁLIDA ===
} else {
    respond(['status' => 'error', 'message' => 'Ação inválida. Ações disponíveis: login, check_session, logout'], 400);
}