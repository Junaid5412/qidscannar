<?php
/**
 * Database Connection Provider
 */

$config_file = __DIR__ . '/../config.php';

if (!file_exists($config_file)) {
    // If not installed, redirect to installer
    if (basename($_SERVER['PHP_SELF']) !== 'install.php') {
        header("Location: install.php");
        exit;
    }
} else {
    require_once $config_file;
}

function getDB() {
    static $pdo = null;

    if ($pdo === null) {
        if (!defined('DB_HOST') || !defined('DB_NAME') || !defined('DB_USER')) {
            throw new Exception("Database configuration constants are missing.");
        }

        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            die("<div style='font-family:sans-serif;padding:30px;max-width:600px;margin:50px auto;border:1px solid #f87171;background:#fef2f2;border-radius:10px;'>
                <h3 style='color:#b91c1c;margin-top:0;'>Database Connection Error</h3>
                <p style='color:#7f1d1d;'>Could not connect to the MySQL database: <strong>" . htmlspecialchars($e->getMessage()) . "</strong></p>
                <p style='color:#555;'>Please make sure MySQL is running in XAMPP, or re-run <a href='install.php' style='color:#0284c7;font-weight:bold;'>install.php</a>.</p>
            </div>");
        }
    }

    return $pdo;
}
