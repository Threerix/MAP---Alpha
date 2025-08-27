<?php
// helpers.php

/**
 * Envia uma resposta de sucesso em formato JSON e encerra o script.
 *
 * @param array $data Dados a serem retornados.
 * @param string $message Mensagem opcional de sucesso.
 * @param int $code Código HTTP (ex: 200, 201).
 */
function sendSuccess($data = [], $message = 'Sucesso', $code = 200) {
    http_response_code($code);
    echo json_encode([
        'status' => 'success',
        'message' => $message,
        'data' => $data
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

/**
 * Envia uma resposta de erro em formato JSON e encerra o script.
 *
 * @param string $message Mensagem de erro.
 * @param int $code Código HTTP do erro (ex: 400, 401, 404).
 */
function sendError($message, $code = 400) {
    http_response_code($code);
    echo json_encode([
        'status' => 'error',
        'message' => $message
    ], JSON_UNESCAPED_UNICODE);
    exit();
}