<?php
/**
 * QID Management System - Server Auto-Discovery Endpoint
 * GET /api/discovery.php
 * Ultra-fast unauthenticated endpoint for mobile app subnet discovery.
 */

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Cache-Control: no-cache, no-store, must-revalidate');

$req_method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($req_method === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$local_ip = gethostbyname(gethostname());
$server_ip = $_SERVER['SERVER_ADDR'] ?? $local_ip;

// Detect folder path e.g. /QID
$script_name = $_SERVER['SCRIPT_NAME'] ?? '';
$base_path = '';
if (!empty($script_name)) {
    $dir = '/' . trim(str_replace('\\', '/', dirname($script_name)), '/');
    $base_path = preg_replace('#/api$#i', '', $dir);
}

// Fallback to /QID if empty and running inside QID directory
if ($base_path === '' || $base_path === '/') {
    $cur_dir_name = basename(dirname(__DIR__));
    if (strtolower($cur_dir_name) === 'qid') {
        $base_path = '/' . $cur_dir_name;
    } else {
        $base_path = '';
    }
}

$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? $server_ip;
$recommended_url = rtrim($protocol . '://' . $host . $base_path, '/');

echo json_encode([
    'status' => 'ok',
    'app' => 'qid_scanner',
    'system' => 'QID Management System',
    'version' => '2.0',
    'server_ip' => $server_ip,
    'local_ip' => $local_ip,
    'base_path' => $base_path,
    'recommended_url' => $recommended_url,
    'timestamp' => time()
], JSON_UNESCAPED_SLASHES);
