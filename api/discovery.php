<?php
/**
 * QID Management System - Server Auto-Discovery Endpoint
 * GET|HEAD /api/discovery.php
 *
 * Unauthenticated, database-free, intentionally tiny. The mobile app probes this
 * on every host of the local Wi-Fi subnet, so it must answer fast and must never
 * depend on config.php, sessions or MySQL being available.
 *
 * The `server_id` is stable across IP changes, which lets the app confirm it has
 * found the *same* PC again after the router handed out a different address.
 */

require_once __DIR__ . '/../includes/lan_ip.php';

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, HEAD, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Cache-Control: no-cache, no-store, must-revalidate');
// Lets the app identify a QID server from the headers alone on a HEAD request.
header('X-QID-Server: qid_scanner');

$method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';

if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$base_path = qid_base_path('api');
$port      = qid_server_port();
$suffix    = ($port === 80) ? '' : ':' . $port;
$scheme    = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';

// The host the app actually used to reach us is the address we know works, so it
// is always the recommended one. LAN enumeration is the fallback for localhost.
$request_host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';
$host_only    = preg_replace('/:\d+$/', '', $request_host);
$host_is_local = ($host_only === '' || $host_only === 'localhost' || $host_only === '127.0.0.1' || $host_only === '::1');

if (!$host_is_local) {
    $recommended_url = rtrim($scheme . '://' . $request_host . $base_path, '/');
} else {
    // Reached over loopback (e.g. the desktop browser). "localhost" is useless to
    // a phone, so advertise the real Wi-Fi address instead.
    $recommended_url = rtrim($scheme . '://' . qid_primary_lan_ip() . $suffix . $base_path, '/');
}

if ($method === 'HEAD') {
    http_response_code(200);
    exit;
}

$lan_ips = qid_collect_lan_ips();

echo json_encode(array(
    'status'          => 'ok',
    'app'             => 'qid_scanner',
    'system'          => 'QID Management System',
    'version'         => '2.1',
    'server_id'       => qid_server_id(),
    'hostname'        => gethostname(),
    'server_ip'       => qid_primary_lan_ip(),
    'lan_ips'         => $lan_ips,
    'port'            => $port,
    'base_path'       => $base_path,
    'recommended_url' => $recommended_url,
    // Every LAN URL that reaches this install - the app stores these as instant
    // reconnect candidates for when the phone rejoins a different Wi-Fi.
    'alternate_urls'  => qid_lan_urls('api'),
    'timestamp'       => time(),
), JSON_UNESCAPED_SLASHES);
