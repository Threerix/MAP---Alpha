<?php
declare(strict_types=1);

// Sempre retorna JSON
header('Content-Type: application/json; charset=utf-8');

// Pré-flight CORS (opcional)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    http_response_code(204);
    exit;
}

// Exibir erros como JSON (diagnóstico)
ini_set('display_errors', '0');
error_reporting(E_ALL);

function json_response(array $arr, int $code = 200): void {
    http_response_code($code);
    echo json_encode($arr, JSON_UNESCAPED_UNICODE);
    exit;
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
    ], 500);
});

// Paths
$root    = realpath(__DIR__ . '/../../');       // .../map
$apiDir  = $root ? $root . '/api' : null;       // .../map/api
$incDir  = $root ? $root . '/includes' : null;  // .../map/includes
$target  = $apiDir ? $apiDir . '/favorites_api.php' : null;

if (!$target || !file_exists($target)) {
    json_response([
        'status'  => 'error',
        'message' => 'Arquivo api/favorites_api.php não encontrado pelo proxy.'
    ], 500);
}

// Ajusta include_path para que requires relativos funcionem
if ($apiDir && $incDir) {
    set_include_path(
        $incDir . PATH_SEPARATOR .
        $apiDir . PATH_SEPARATOR .
        get_include_path()
    );
}

// Executa o endpoint real e captura a saída
ob_start();
require $target;
$out = ob_get_clean();

// Se o alvo já emitiu JSON válido, repasse
if ($out !== '' && $out !== null) {
    $parsed = json_decode($out, true);
    if (json_last_error() === JSON_ERROR_NONE) {
        echo $out;
        exit;
    }
    // Não é JSON: devolve erro com trecho da saída
    $raw = strip_tags($out);
    if (function_exists('mb_substr')) {
        $raw = mb_substr($raw, 0, 2000);
    } else {
        $raw = substr($raw, 0, 2000);
    }
    json_response([
        'status'  => 'error',
        'message' => 'Saída inválida do backend (não é JSON).',
        'raw'     => $raw,
    ], 500);
}

// Não houve saída
json_response([
    'status'  => 'error',
    'message' => 'favorites_api.php não produziu saída. Verifique o backend.'
], 500);