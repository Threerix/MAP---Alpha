<?php
declare(strict_types=1);

/**
 * includes/Database.php
 * Conecta ao banco e, se for MySQL e o DB não existir, cria automaticamente.
 * Compatível com:
 *   - Database::getConnection()
 *   - (novo) Database::getInstance()->pdo
 *   - (compat) new Database()->pdo
 *
 * Requer no config:
 *   define('DB_DRIVER','mysql'); // ou 'sqlite'
 *   define('DB_HOST','localhost'); define('DB_NAME','map_database');
 *   define('DB_USER','root'); define('DB_PASS',''); define('DB_CHARSET','utf8mb4');
 *   (opcional) define('DB_PORT', 3306); define('DB_COLLATION', 'utf8mb4_unicode_ci');
 */

class Database
{
    public PDO $pdo;
    private static ?PDO $shared = null;

    public function __construct()
    {
        $this->pdo = self::getConnection();
    }

    public static function getInstance(): self
    {
        return new self();
    }

    public static function getConnection(): PDO
    {
        if (self::$shared instanceof PDO) {
            return self::$shared;
        }

        // Descobre raiz para fallback SQLite
        $root = defined('ROOT_DIR') ? ROOT_DIR : realpath(__DIR__ . '/..');

        // 1) Se houver DB_DSN direto
        if (defined('DB_DSN') && DB_DSN) {
            $user = defined('DB_USER') ? DB_USER : null;
            $pass = defined('DB_PASS') ? DB_PASS : null;
            self::$shared = new PDO(DB_DSN, $user, $pass, self::pdoOptions());
            return self::$shared;
        }

        $driver = defined('DB_DRIVER') ? strtolower((string)DB_DRIVER) : 'sqlite';

        // 2) MySQL com auto-criação de database
        if ($driver === 'mysql') {
            $host     = defined('DB_HOST')     ? DB_HOST     : '127.0.0.1';
            $dbname   = defined('DB_NAME')     ? DB_NAME     : 'map_database';
            $user     = defined('DB_USER')     ? DB_USER     : 'root';
            $pass     = defined('DB_PASS')     ? DB_PASS     : '';
            $charset  = defined('DB_CHARSET')  ? DB_CHARSET  : 'utf8mb4';
            $port     = defined('DB_PORT')     ? (int)DB_PORT : 3306;
            $coll     = defined('DB_COLLATION')? DB_COLLATION : 'utf8mb4_unicode_ci';

            // Conecta ao servidor (sem dbname) para garantir a criação do DB
            $serverDsn = "mysql:host={$host};port={$port};charset={$charset}";
            try {
                $serverPdo = new PDO($serverDsn, $user, $pass, self::pdoOptions());
                $serverPdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbname}` CHARACTER SET {$charset} COLLATE {$coll}");
            } catch (Throwable $e) {
                throw new RuntimeException(
                    "Falha ao conectar no servidor MySQL ou criar o DB '{$dbname}': " . $e->getMessage()
                );
            }

            // Agora conecta no DB
            $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset={$charset}";
            self::$shared = new PDO($dsn, $user, $pass, self::pdoOptions());
            // Ajuste de charset por segurança
            self::$shared->exec("SET NAMES {$charset}");
            return self::$shared;
        }

        // 3) SQLite (dev/fallback)
        $sqliteFile = $root . '/database/app.sqlite';
        @is_dir(dirname($sqliteFile)) || @mkdir(dirname($sqliteFile), 0777, true);
        self::$shared = new PDO('sqlite:' . $sqliteFile, null, null, self::pdoOptions());
        return self::$shared;
    }

    private static function pdoOptions(): array
    {
        return [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ];
    }
}