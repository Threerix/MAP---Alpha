<?php
declare(strict_types=1);

// Sempre JSON
header('Content-Type: application/json; charset=utf-8');

// Pré-flight (opcional)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    http_response_code(204);
    exit;
}

ini_set('display_errors', '0');
error_reporting(E_ALL);

function json_response(array $arr, int $code = 200): void {
    http_response_code($code);
    echo json_encode($arr, JSON_UNESCAPED_UNICODE);
    exit;
}

function debug_info(): array {
    $root = realpath(__DIR__ . '/../../');
    return [
        'current_dir' => __DIR__,
        'root_path' => $root,
        'api_dir' => $root ? $root . '/api' : 'N/A',
        'includes_dir' => $root ? $root . '/includes' : 'N/A',
        'target_file' => $root ? $root . '/api/recommendations_api.php' : 'N/A',
        'target_exists' => $root && file_exists($root . '/api/recommendations_api.php'),
        'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'N/A',
    ];
}

register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        if (ob_get_length()) { ob_clean(); }
        json_response([
            'status'  => 'error',
            'message' => 'Fatal error: ' . $e['message'],
            'file'    => basename($e['file']),
            'line'    => $e['line'],
            'debug'   => debug_info(),
        ], 500);
    }
});

set_error_handler(function ($severity, $message, $file, $line) {
    if (ob_get_length()) { ob_clean(); }
    json_response([
        'status'  => 'error',
        'message' => $message,
        'file'    => basename($file),
        'line'    => $line,
        'debug'   => debug_info(),
    ], 500);
});

// Paths do projeto
$root   = realpath(__DIR__ . '/../../');    // .../map
$apiDir = $root ? $root . '/api' : null;
$incDir = $root ? $root . '/includes' : null;
$target = $apiDir ? $apiDir . '/recommendations_api.php' : null;

// Verificações detalhadas
if (!$root) {
    json_response([
        'status'  => 'error',
        'message' => 'Não foi possível determinar o diretório raiz do projeto.',
        'debug'   => debug_info(),
    ], 500);
}

if (!$apiDir || !is_dir($apiDir)) {
    json_response([
        'status'  => 'error',
        'message' => 'Diretório /api não encontrado.',
        'debug'   => debug_info(),
    ], 500);
}

if (!$target || !file_exists($target)) {
    json_response([
        'status'  => 'error',
        'message' => 'Arquivo api/recommendations_api.php não encontrado.',
        'debug'   => debug_info(),
    ], 500);
}

if (!is_readable($target)) {
    json_response([
        'status'  => 'error',
        'message' => 'Arquivo api/recommendations_api.php não é legível.',
        'debug'   => debug_info(),
    ], 500);
}

// Ajuda requires relativos do alvo
if ($apiDir && $incDir && is_dir($incDir)) {
    set_include_path($incDir . PATH_SEPARATOR . $apiDir . PATH_SEPARATOR . get_include_path());
}

// Verifica se spotify_helper.php existe
$spotifyHelper = $apiDir . '/spotify_helper.php';
if (!file_exists($spotifyHelper)) {
    // Cria um arquivo temporário se não existir
    $tempSpotifyHelper = '<?php
function get_spotify_access_token($pdo, $userId) { return null; }
function search_spotify($accessToken, $type, $name, $artist) { return null; }
function get_spotify_recommendations($accessToken, $seeds) { return []; }
';
    file_put_contents($spotifyHelper, $tempSpotifyHelper);
}

// Tenta executar o alvo e capturar saída
try {
    ob_start();
    
    // Salva o diretório atual e muda para o diretório da API
    $originalDir = getcwd();
    chdir(dirname($target));
    
    require $target;
    
    // Restaura o diretório original
    chdir($originalDir);
    
    $out = ob_get_clean();
} catch (Throwable $e) {
    if (ob_get_length()) { ob_clean(); }
    json_response([
        'status'  => 'error',
        'message' => 'Erro ao executar recommendations_api.php: ' . $e->getMessage(),
        'file'    => basename($e->getFile()),
        'line'    => $e->getLine(),
        'debug'   => debug_info(),
        'trace'   => $e->getTraceAsString(),
    ], 500);
}

// Se já veio JSON, repassa
if ($out !== '' && $out !== null) {
    $parsed = json_decode($out, true);
    if (json_last_error() === JSON_ERROR_NONE) {
        echo $out;
        exit;
    }
    $raw = strip_tags($out);
    $raw = function_exists('mb_substr') ? mb_substr($raw, 0, 2000) : substr($raw, 0, 2000);
    json_response([
        'status'  => 'error',
        'message' => 'Saída inválida do backend (não é JSON).',
        'raw'     => $raw,
        'debug'   => debug_info(),
    ], 500);
}

// Sem saída
json_response([
    'status'  => 'error',
    'message' => 'recommendations_api.php não produziu saída.',
    'debug'   => debug_info(),
], 500);