<?php
/**
 * Database connection. Same database as the Class Scheduling System.
 * Secrets live in config/db.local.php (never shared/committed).
 */
define('DB_CHARSET', 'utf8mb4');

$local = __DIR__ . '/db.local.php';
if (!is_file($local)) {
    http_response_code(500);
    exit('Server is not configured.');
}
require $local;

function getDB(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        try {
            $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            error_log('Student portal DB connection failed: ' . $e->getMessage());
            http_response_code(500);
            exit('Cannot connect to the database. Please tell the Registrar.');
        }
    }
    return $pdo;
}
