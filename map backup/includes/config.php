<?php
declare(strict_types=1);

session_start();

/**
 * Configuração do Banco de Dados
 * DB_HOST deve ser '127.0.0.1' para consistência com o Spotify.
 */
define('DB_DRIVER', 'mysql');
define('DB_HOST',   '127.0.0.1'); // ALTERADO: de 'localhost' para '127.0.0.1'
define('DB_NAME',   'map_database');
define('DB_USER',   'root');
define('DB_PASS',   '');
define('DB_CHARSET','utf8mb4');

/** 
 * URLs do site 
 * PRECISA usar 127.0.0.1 para que o fluxo do Spotify funcione.
 */
define('SITE_URL', 'http://127.0.0.1/map'); // ALTERADO: de 'localhost' para '127.0.0.1'
define('API_URL',  SITE_URL . '/public/api'); // CORRIGIDO: O caminho correto é /public/api

/** Erros (dev) */
error_reporting(E_ALL);
ini_set('display_errors', '1');

/** Last.fm API key */
if (!defined('LASTFM_API_KEY')) {
    define('LASTFM_API_KEY', '');
}

// Spotify API Keys (Suas chaves foram mantidas)
if (!defined('SPOTIFY_CLIENT_ID')) {
    define('SPOTIFY_CLIENT_ID', '');
}
if (!defined('SPOTIFY_CLIENT_SECRET')) {
    define('SPOTIFY_CLIENT_SECRET', '');
}
if (!defined('SPOTIFY_REDIRECT_URI')) {
    define('SPOTIFY_REDIRECT_URI', '');

}
