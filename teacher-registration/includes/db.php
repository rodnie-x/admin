<?php
/**
 * One shared PDO connection (prepared statements everywhere).
 */
function getDB(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            error_log('Teacher Registration DB connection failed: ' . $e->getMessage());
            http_response_code(500);
            exit('Cannot connect to the database. Check DB_HOST, DB_NAME, DB_USER and DB_PASS in config/config.local.php.');
        }
    }
    return $pdo;
}
