<?php
declare(strict_types=1);

// map/public/api/daymix_api.php (Proxy para a Day Mix)
header('Content-Type: application/json; charset=utf-8');

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

register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        if (ob_get_length()) { ob_clean(); }
        json_response(['status'=>'error','message'=>'Fatal error: '.$e['message'],'file'=>basename($e['file']),'line'=>$e['line']], 500);
    }
});

$root   = realpath(__DIR__ . '/../../');
$apiDir = $root ? $root . '/api' : null;
$incDir = $root ? $root . '/includes' : null;
$target = $apiDir ? $apiDir . '/daymix_api.php' : null;

if (!$target || !file_exists($target)) {
    json_response(['status'=>'error','message'=>'Arquivo api/daymix_api.php não encontrado pelo proxy.'], 500);
}

if ($apiDir && $incDir) {
    set_include_path($incDir . PATH_SEPARATOR . $apiDir . PATH_SEPARATOR . get_include_path());
}

ob_start();
require $target;
$out = ob_get_clean();

if ($out !== '' && $out !== null) {
    $parsed = json_decode($out, true);
    if (json_last_error() === JSON_ERROR_NONE) {
        echo $out; exit;
    }
    $raw = strip_tags($out);
    $raw = function_exists('mb_substr') ? mb_substr($raw, 0, 2000) : substr($raw, 0, 2000);
    json_response(['status'=>'error','message'=>'Saída inválida do backend (não é JSON).','raw'=>$raw], 500);
}

json_response(['status'=>'error','message'=>'daymix_api.php não produziu saída.'], 500);