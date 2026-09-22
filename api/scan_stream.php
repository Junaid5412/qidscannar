<?php
/**
 * QID Management System - Real-Time Server-Sent Events (SSE) Stream
 * GET /api/scan_stream.php
 * 
 * PERFORMANCE-OPTIMIZED: Short-lived stream (15s max), releases session lock immediately
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

header('Content-Type: text/event-stream');
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

// Release session lock immediately so other pages load fast
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

// Fallback: Support token in query string
if ($user_id <= 0 && !empty($_GET['token'])) {
    $db = getDB();
    $token_stmt = $db->prepare("SELECT user_id FROM `app_tokens` WHERE `token` = ? LIMIT 1");
    $token_stmt->execute([trim($_GET['token'])]);
    $user_id = (int)$token_stmt->fetchColumn();
}

if ($user_id <= 0) {
    echo "event: error\n";
    echo "data: " . json_encode(['message' => 'Unauthenticated stream connection. Please log in.']) . "\n\n";
    flush();
    exit;
}

$db = getDB();

// Send initial connected handshake
echo "event: connected\n";
echo "data: " . json_encode([
    'status'    => 'active',
    'user_id'   => $user_id,
    'timestamp' => time()
]) . "\n\n";
flush();

// Keep SSE stream open for only 15 seconds (down from 60s) to minimize thread usage
// EventSource auto-reconnects, so the user sees no difference
$start_time = time();
$max_execution = 15;
$last_ping = time();

while (time() - $start_time < $max_execution) {
    if (connection_aborted()) {
        break;
    }

    // Query for pending scans for this user
    $stmt = $db->prepare("
        SELECT id, scan_data 
        FROM `pending_scans` 
        WHERE `user_id` = ? AND `is_consumed` = 0 
        ORDER BY `id` ASC 
        LIMIT 1
    ");
    $stmt->execute([$user_id]);
    $pending = $stmt->fetch();

    if ($pending) {
        // Mark scan as consumed immediately
        $up = $db->prepare("UPDATE `pending_scans` SET `is_consumed` = 1 WHERE `id` = ?");
        $up->execute([$pending['id']]);

        // Push real-time event to desktop browser
        echo "event: qid_scan\n";
        echo "data: " . $pending['scan_data'] . "\n\n";
        flush();
    }

    // Send keep-alive heartbeat every 10 seconds
    if (time() - $last_ping >= 10) {
        echo ": keepalive\n\n";
        flush();
        $last_ping = time();
    }

    // Sleep 500ms (still responsive, half the CPU of 250ms)
    usleep(500000);
}

// Clean exit; browser EventSource will automatically re-establish
exit;
