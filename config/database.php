<?php
/**
 * ============================================================
 * File     : config/database.php
 * Project  : SKed - Youth Profiling System for Event Management
 * Purpose  : Central PDO/MySQL connection for the SKed skeleton.
 *
 * Registered (self-signed-up) accounts are persisted in the `sked_db`
 * MySQL database created by config/schema.sql — see includes/auth.php
 * for the queries. Demo accounts (usernames "1"-"4") remain a
 * hardcoded, no-DB shortcut on purpose, so the four role dashboards
 * stay reachable for review even if the database is down.
 *
 * Default credentials below match a stock XAMPP install (root, no
 * password). Update them if your MySQL user/password differ.
 * ============================================================
 */

const SKED_DB_HOST = '127.0.0.1';
const SKED_DB_NAME = 'sked_db';
const SKED_DB_USER = 'root';
const SKED_DB_PASS = '';
const SKED_DB_CHARSET = 'utf8mb4';

/**
 * Returns a shared PDO connection, opening it on first use.
 * Throws PDOException (uncaught, by design in this dev-mode skeleton
 * — see config.php's DISPLAY_ERRORS) if MySQL is unreachable or the
 * `sked_db` database/schema hasn't been created yet.
 */
function sked_db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = 'mysql:host=' . SKED_DB_HOST . ';dbname=' . SKED_DB_NAME . ';charset=' . SKED_DB_CHARSET;

    $pdo = new PDO($dsn, SKED_DB_USER, SKED_DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    return $pdo;
}
