<?php
/**
 * QID Management System - Real-Time Server-Sent Events (SSE) Stream
 * GET /api/scan_stream.php
 * 
 * PERFORMANCE & WINDOWS APACHE OPTIMIZED:
 * - Immediate output buffer bypass with padding
 * - Admin receives all scans; staff receives user-specific scans
 * - Short-lived stream (20s) with clean reconnect
 */

// Disable all buffering
if (function_exists('apache_setenv')) {
    @apache_setenv('no-gzip', '1');
}
@ini_set('zlib.output_compression', '0');
@ini_set('implicit_flush', '1');
while (ob_get_level()) {
    ob_end_clean();
}

header('Content-Type: text/event-stream; charset=UTF-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

// Session-based or Token-based authentication
if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    session_start();
}

$user_id = (int)($_SESSION['qid_user_id'] ?? 0);
$user_role = $_SESSION['qid_user_role'] ?? 'staff';

// Release session lock immediately so other pages load fast
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

// Fallback: Support token in query string
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
    echo "event: error\n";
    echo "data: " . json_encode(['message' => 'Unauthenticated stream connection. Please log in.']) . "\n\n";
    flush();
    exit;
}

// Send padding to immediately force Apache to flush through mod_php on Windows
echo ":" . str_repeat(" ", 2048) . "\n\n";
flush();

// Send initial connected handshake
echo "event: connected\n";
echo "data: " . json_encode([
    'status'    => 'active',
    'user_id'   => $user_id,
    'timestamp' => time()
]) . "\n\n";
flush();

$db = getDB();
$start_time = time();
$max_execution = 20;
$last_ping = time();

while (time() - $start_time < $max_execution) {
    if (connection_aborted()) {
        break;
    }

    // Query for pending scans: Admins receive all company scans, Staff receive their own
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
        // Mark scan as consumed immediately
        $up = $db->prepare("UPDATE `pending_scans` SET `is_consumed` = 1 WHERE `id` = ?");
        $up->execute([$pending['id']]);

        // Push real-time event to desktop browser with padding to ensure immediate socket dispatch
        echo "event: qid_scan\n";
        echo "data: " . $pending['scan_data'] . "\n\n";
        echo ":" . str_repeat(" ", 512) . "\n\n";
        flush();
    }

    // Send keep-alive heartbeat every 8 seconds
    if (time() - $last_ping >= 8) {
        echo ": keepalive\n\n";
        flush();
        $last_ping = time();
    }

    // Responsive 250ms check
    usleep(250000);
}

exit;
