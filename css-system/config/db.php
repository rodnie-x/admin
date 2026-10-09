<?php
/**
 * Database connection settings.
 * XAMPP (local): values below work out of the box.
 * InfinityFree (live): swap each value with the one from Client Area > MySQL Databases.
 */
define('DB_HOST',    'sql313.infinityfree.com');             // InfinityFree → sqlXXX.infinityfree.com
define('DB_NAME',    'if0_43074378_css_system');            // InfinityFree → if0_XXXXXXXX_css_system
define('DB_USER',    'if0_43074378');             // InfinityFree → if0_XXXXXXXX
define('DB_PASS',    'YSqmqXE8gKl'); // InfinityFree → your MySQL password
define('DB_CHARSET', 'utf8mb4');

/**
 * Returns a single shared PDO connection (lazy singleton).
 */
function getDB(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            // Never show the real reason to visitors (it can reveal the host and user name); it goes to the server log.
            error_log('CSS DB connection failed: ' . $e->getMessage());
            http_response_code(500);
            exit('Database connection failed. Please tell the administrator.');
        }
    }

    return $pdo;
}