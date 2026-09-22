<?php
/**
 * QID Management System - Mobile Scan Push Receiver
 * POST /api/scan_push.php
 * Receives scans from Android App and syncs to authenticated user's desktop browser
 */

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-Auth-Token');

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

// 1. Extract Bearer Token from Authorization Header or Request
$token = '';
$auth_header = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');

if (function_exists('getallheaders')) {
    $req_headers = getallheaders();
    foreach ($req_headers as $k => $v) {
        if (strtolower($k) === 'authorization') {
            $auth_header = $v;
        } elseif (strtolower($k) === 'x-auth-token') {
            $token = trim($v);
        }
    }
}

if (!empty($auth_header) && preg_match('/Bearer\s+(\S+)/i', $auth_header, $matches)) {
    $token = $matches[1];
} elseif (!empty($_SERVER['HTTP_X_AUTH_TOKEN'])) {
    $token = trim($_SERVER['HTTP_X_AUTH_TOKEN']);
}

// Support body token parameter if header is stripped by Apache
$raw_input = file_get_contents('php://input');
$json_data = json_decode($raw_input, true) ?: [];

if (empty($token)) {
    $token = trim($_POST['token'] ?? ($json_data['token'] ?? ($_GET['token'] ?? '')));
}

if (empty($token)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized. Missing bearer token.']);
    exit;
}

$db = getDB();
ensure_scanner_tables();

// Validate token and identify user
$stmt = $db->prepare("
    SELECT t.user_id, t.device_name, u.username, u.full_name, u.role
    FROM `app_tokens` t
    JOIN `users` u ON u.id = t.user_id
    WHERE t.token = ?
    LIMIT 1
");
$stmt->execute([$token]);
$auth_user = $stmt->fetch();

if (!$auth_user) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Invalid or expired authorization token.']);
    exit;
}

$user_id = (int)$auth_user['user_id'];

// Touch last_used_at
$touch = $db->prepare("UPDATE `app_tokens` SET `last_used_at` = NOW() WHERE `token` = ?");
$touch->execute([$token]);

// 2. Extract QID Number and Payload
$qid_raw = trim($_POST['qid_number'] ?? ($json_data['qid_number'] ?? ''));
$scan_type = trim($_POST['scan_type'] ?? ($json_data['scan_type'] ?? 'barcode'));
$card_details = $_POST['card_data'] ?? ($json_data['card_data'] ?? []);

// Sanitize 11-digit QID (extract 11 consecutive digits if card barcode contained other prefixes)
$qid_number = '';
if (preg_match('/\b(\d{11})\b/', $qid_raw, $m)) {
    $qid_number = $m[1];
} else {
    // If not strict 11 digits, clean non-numeric and take whatever is provided
    $clean_digits = preg_replace('/[^\d]/', '', $qid_raw);
    $qid_number = !empty($clean_digits) ? $clean_digits : $qid_raw;
}

if (empty($qid_number)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'QID number is required.']);
    exit;
}

// 3. Query Database for existing record
$record_stmt = $db->prepare("
    SELECT r.*, COALESCE(SUM(p.amount), 0) AS total_paid
    FROM `qid_records` r
    LEFT JOIN `payments` p ON p.qid_record_id = r.id
    WHERE r.qid_number = ?
    GROUP BY r.id
    LIMIT 1
");
$record_stmt->execute([$qid_number]);
$existing_record = $record_stmt->fetch();

$payload = [
    'scanned_at'     => date('Y-m-d H:i:s'),
    'scanned_time'   => date('h:i A'),
    'qid_number'     => $qid_number,
    'scan_type'      => $scan_type,
    'scanned_by'     => [
        'id'          => $user_id,
        'username'    => $auth_user['username'],
        'full_name'   => $auth_user['full_name'],
        'device_name' => $auth_user['device_name']
    ],
    'exists'         => false,
    'record'         => null,
    'payments'       => [],
    'card_extracted' => is_array($card_details) ? $card_details : []
];

if ($existing_record) {
    $payload['exists'] = true;
    $enriched = enrich_qid_record($existing_record);
    
    // Fetch last 5 payments
    $pay_stmt = $db->prepare("
        SELECT amount, payment_date, payment_time, payment_method, receipt_no 
        FROM `payments` 
        WHERE `qid_record_id` = ? 
        ORDER BY payment_date DESC, payment_time DESC, id DESC 
        LIMIT 5
    ");
    $pay_stmt->execute([$existing_record['id']]);
    $recent_payments = $pay_stmt->fetchAll();

    $currency = get_setting('currency', 'QR');

    $payload['record'] = [
        'id'                => (int)$enriched['id'],
        'full_name'         => $enriched['full_name'],
        'qid_number'        => $enriched['qid_number'],
        'phone_number'      => $enriched['phone_number'] ?? '',
        'company_name'      => $enriched['company_name'] ?? '',
        'job_title'         => $enriched['job_title'] ?? '',
        'nationality'       => $enriched['nationality'] ?? '',
        'expiry_date'       => $enriched['expiry_date'],
        'expiry_formatted'  => format_date($enriched['expiry_date']),
        'expiry_status'     => $enriched['expiry_status'],
        'days_to_expiry'    => $enriched['days_to_expiry'],
        'payment_due_date'  => $enriched['payment_due_date'] ? format_date($enriched['payment_due_date']) : 'Not specified',
        'charge_amount'     => (float)$enriched['charge_amount'],
        'charge_formatted'  => format_currency($enriched['charge_amount']),
        'actual_cost'       => (float)$enriched['actual_cost'],
        'cost_formatted'    => format_currency($enriched['actual_cost']),
        'net_profit'        => (float)($enriched['expected_profit'] ?? 0),
        'profit_formatted'  => format_currency($enriched['expected_profit'] ?? 0),
        'total_paid'        => (float)($enriched['total_paid'] ?? 0),
        'paid_formatted'    => format_currency($enriched['total_paid'] ?? 0),
        'remaining_balance' => (float)$enriched['remaining_balance'],
        'balance_formatted' => format_currency($enriched['remaining_balance']),
        'status'            => $enriched['status'],
        'payment_status'    => $enriched['payment_status']
    ];
    $payload['payments'] = $recent_payments;
}

// 4. Save to pending_scans queue for this specific user
$ins = $db->prepare("
    INSERT INTO `pending_scans` (`user_id`, `qid_number`, `scan_data`, `is_consumed`)
    VALUES (?, ?, ?, 0)
");
$ins->execute([$user_id, $qid_number, json_encode($payload)]);

// 5. Respond back to Mobile App immediately
echo json_encode([
    'success'       => true,
    'message'       => 'Scan synchronized with desktop successfully.',
    'qid_number'    => $qid_number,
    'record_found'  => $payload['exists'],
    'person_name'   => $payload['exists'] ? $payload['record']['full_name'] : 'Unregistered QID',
    'balance_due'   => $payload['exists'] ? $payload['record']['balance_formatted'] : null,
    'expiry_status' => $payload['exists'] ? $payload['record']['expiry_status'] : null
]);
