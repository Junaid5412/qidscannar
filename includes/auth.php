<?php
/**
 * QID Management System - Authentication & Session Management
 */

if (session_status() === PHP_SESSION_NONE) {
    // Set secure session cookie parameters before session start
    $cookie_params = session_get_cookie_params();
    session_set_cookie_params([
        'lifetime' => 86400 * 30, // 30 days
        'path'     => $cookie_params['path'],
        'domain'   => $cookie_params['domain'],
        'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

/**
 * Ensure users table exists and default admin user is seeded
 */
function ensure_users_table() {
    static $ensured = false;
    if ($ensured) return;

    $db = getDB();
    try {
        $db->exec("
            CREATE TABLE IF NOT EXISTS `users` (
                `id` INT(11) NOT NULL AUTO_INCREMENT,
                `username` VARCHAR(50) NOT NULL UNIQUE,
                `password_hash` VARCHAR(255) NOT NULL,
                `full_name` VARCHAR(100) NOT NULL,
                `email` VARCHAR(100) DEFAULT NULL,
                `role` VARCHAR(20) NOT NULL DEFAULT 'admin',
                `last_login` DATETIME DEFAULT NULL,
                `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        // Check if at least one admin user exists
        $stmt = $db->query("SELECT COUNT(*) FROM `users`");
        $count = (int)$stmt->fetchColumn();

        if ($count === 0) {
            $default_user = 'admin';
            $default_pass = 'admin123';
            $default_name = 'Administrator';
            $hash = password_hash($default_pass, PASSWORD_BCRYPT);

            $ins = $db->prepare("INSERT INTO `users` (`username`, `password_hash`, `full_name`, `role`) VALUES (?, ?, ?, 'admin')");
            $ins->execute([$default_user, $hash, $default_name]);
        }
        $ensured = true;
    } catch (PDOException $e) {
        error_log("Database initialization error in auth.php: " . $e->getMessage());
    }
}

// Auto-run schema check
ensure_users_table();

/**
 * Check if the current visitor is logged in
 */
function is_logged_in() {
    if (isset($_SESSION['qid_user_logged_in']) && $_SESSION['qid_user_logged_in'] === true && !empty($_SESSION['qid_user_id'])) {
        return true;
    }

    // Check remember me cookie if session is expired
    if (!empty($_COOKIE['qid_remember_token'])) {
        $token = $_COOKIE['qid_remember_token'];
        $parts = explode(':', base64_decode($token));
        if (count($parts) === 2) {
            list($user_id, $hash_check) = $parts;
            $db = getDB();
            $stmt = $db->prepare("SELECT * FROM `users` WHERE `id` = ?");
            $stmt->execute([(int)$user_id]);
            $user = $stmt->fetch();
            if ($user && hash_equals(hash('sha256', $user['password_hash']), $hash_check)) {
                // Restore session
                $_SESSION['qid_user_logged_in'] = true;
                $_SESSION['qid_user_id'] = $user['id'];
                $_SESSION['qid_user_name'] = $user['username'];
                $_SESSION['qid_full_name'] = $user['full_name'];
                $_SESSION['qid_role'] = $user['role'];
                return true;
            }
        }
    }

    return false;
}

/**
 * Require authentication for the current page; redirect to login.php if not logged in
 */
function require_login() {
    if (!is_logged_in()) {
        $current_url = $_SERVER['REQUEST_URI'] ?? 'index.php';
        $_SESSION['redirect_after_login'] = $current_url;
        header('Location: login.php');
        exit;
    }
}

/**
 * Attempt to authenticate a user
 * 
 * @param string $username
 * @param string $password
 * @param bool $remember
 * @return array ['success' => bool, 'message' => string]
 */
function login_user($username, $password, $remember = false) {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM `users` WHERE `username` = ? LIMIT 1");
    $stmt->execute([trim($username)]);
    $user = $stmt->fetch();

    if (!$user) {
        return ['success' => false, 'message' => 'Invalid username or password. Please try again.'];
    }

    if (!$user || !password_verify($password, $user['password_hash'])) {
        return ['success' => false, 'message' => 'Invalid username or password. Please try again.'];
    }

    // Check if user account is active
    if (isset($user['status']) && $user['status'] === 'inactive') {
        return ['success' => false, 'message' => 'Your account has been deactivated. Contact an administrator.'];
    }

    // Successful login: regenerate session ID to prevent fixation
    if (!headers_sent()) {
        session_regenerate_id(true);
    }

    $_SESSION['qid_user_logged_in'] = true;
    $_SESSION['qid_user_id'] = $user['id'];
    $_SESSION['qid_user_name'] = $user['username'];
    $_SESSION['qid_full_name'] = $user['full_name'];
    $_SESSION['qid_role'] = $user['role'];

    // Update last_login
    $up = $db->prepare("UPDATE `users` SET `last_login` = NOW() WHERE `id` = ?");
    $up->execute([$user['id']]);

    // Handle remember me cookie (valid for 30 days)
    if ($remember) {
        $cookie_val = base64_encode($user['id'] . ':' . hash('sha256', $user['password_hash']));
        setcookie('qid_remember_token', $cookie_val, [
            'expires'  => time() + (86400 * 30),
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
    }

    return ['success' => true, 'message' => 'Login successful.'];
}

/**
 * Log out the current user and destroy session
 */
function logout_user() {
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    // Delete remember cookie
    setcookie('qid_remember_token', '', time() - 3600, '/');
    session_destroy();
}

/**
 * Get the currently logged-in user profile
 */
function get_current_user_profile() {
    if (!is_logged_in()) return null;

    return [
        'id'        => $_SESSION['qid_user_id'] ?? 0,
        'username'  => $_SESSION['qid_user_name'] ?? 'admin',
        'full_name' => $_SESSION['qid_full_name'] ?? 'Administrator',
        'role'      => $_SESSION['qid_role'] ?? 'admin'
    ];
}

/**
 * Check if current user is an admin
 */
function is_admin() {
    return ($_SESSION['qid_role'] ?? '') === 'admin';
}

/**
 * Check if current user has at least staff-level access (admin or staff)
 */
function is_staff_or_admin() {
    return in_array($_SESSION['qid_role'] ?? '', ['admin', 'staff']);
}

