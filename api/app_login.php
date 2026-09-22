<?php
/**
 * QID Management System - Mobile App Authentication API
 * POST /api/app_login.php
 */

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use POST.']);
    exit;
}

// Support both JSON body and standard form POST
$raw_input = file_get_contents('php://input');
$json_data = json_decode($raw_input, true) ?: [];

$username = trim($_POST['username'] ?? ($json_data['username'] ?? ''));
$password = $_POST['password'] ?? ($json_data['password'] ?? '');
$device_name = trim($_POST['device_name'] ?? ($json_data['device_name'] ?? 'Android Scanner App'));

if (empty($username) || empty($password)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Username and password are required.']);
    exit;
}

$db = getDB();
$stmt = $db->prepare("SELECT * FROM `users` WHERE `username` = ? LIMIT 1");
$stmt->execute([$username]);
$user = $stmt->fetch();

if (!$user || !password_verify($password, $user['password_hash'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Invalid username or password.']);
    exit;
}

// Block inactive accounts
if (isset($user['status']) && $user['status'] === 'inactive') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Your account has been deactivated. Contact an administrator.']);
    exit;
}

// Generate secure persistent mobile API token (64 hex characters)
$token = bin2hex(random_bytes(32));

try {
    ensure_scanner_tables();
    $token_stmt = $db->prepare("
        INSERT INTO `app_tokens` (`user_id`, `token`, `device_name`, `last_used_at`)
        VALUES (?, ?, ?, NOW())
    ");
    $token_stmt->execute([$user['id'], $token, $device_name]);

    // Update user last_login
    $up_user = $db->prepare("UPDATE `users` SET `last_login` = NOW() WHERE `id` = ?");
    $up_user->execute([$user['id']]);

    echo json_encode([
        'success'      => true,
        'message'      => 'Login successful. Scanner synced with desktop.',
        'token'        => $token,
        'user'         => [
            'id'        => (int)$user['id'],
            'username'  => $user['username'],
            'full_name' => $user['full_name'],
            'role'      => $user['role']
        ],
        'company_name' => get_setting('company_name', 'Qatar Business Solutions'),
        'currency'     => get_setting('currency', 'QR')
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error creating session: ' . $e->getMessage()]);
}
