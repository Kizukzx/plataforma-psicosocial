<?php
/**
 * Conexão com o banco de dados MySQL via PDO.
 * Ajuste as credenciais conforme o ambiente (nunca versionar senha real em produção;
 * prefira variáveis de ambiente).
 */

define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1:3307');
define('DB_NAME', getenv('DB_NAME') ?: 'senai_ppa');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: '');
define('DB_CHARSET', 'utf8mb4');

function getDB(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['sucesso' => false, 'erro' => 'Falha na conexão com o banco de dados.']);
            exit;
        }
    }
    return $pdo;
}
