<?php
/**
 * QID Management System - Fast Polling Fallback API
 * GET /api/scan_poll.php
 * Used by desktop browser to guarantee ZERO lost scans
 */

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

require_once __DIR__ . '/../includes/db.php';

if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    session_start();
}

$user_id = (int)($_SESSION['qid_user_id'] ?? 0);
$user_role = $_SESSION['qid_user_role'] ?? 'staff';

// Release session lock immediately so other pages load fast
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

if ($user_id <= 0 && !empty($_GET['token'])) {
    $db = getDB();
    $token_stmt = $db->prepare("SELECT t.user_id, u.role FROM `app_tokens` t JOIN users u ON u.id = t.user_id WHERE t.token = ? LIMIT 1");
    $token_stmt->execute([trim($_GET['token'])]);
    $row = $token_stmt->fetch();
    if ($row) {
        $user_id = (int)$row['user_id'];
        $user_role = $row['role'];
    }
}

if ($user_id <= 0) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthenticated.']);
    exit;
}

$db = getDB();

if ($user_role === 'admin') {
    $stmt = $db->query("
        SELECT id, scan_data 
        FROM `pending_scans` 
        WHERE `is_consumed` = 0 
        ORDER BY `id` ASC 
        LIMIT 1
    ");
    $pending = $stmt->fetch();
} else {
    $stmt = $db->prepare("
        SELECT id, scan_data 
        FROM `pending_scans` 
        WHERE `user_id` = ? AND `is_consumed` = 0 
        ORDER BY `id` ASC 
        LIMIT 1
    ");
    $stmt->execute([$user_id]);
    $pending = $stmt->fetch();
}

if ($pending) {
    $up = $db->prepare("UPDATE `pending_scans` SET `is_consumed` = 1 WHERE `id` = ?");
    $up->execute([$pending['id']]);

    echo json_encode([
        'has_scan' => true,
        'payload'  => json_decode($pending['scan_data'], true)
    ]);
} else {
    echo json_encode([
        'has_scan' => false
    ]);
}
