<?php
/**
 * Global Helper Functions & Business Logic
 */

require_once __DIR__ . '/db.php';

if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    session_start();
}

/**
 * Flash message helpers
 */
function set_flash($type, $message) {
    $_SESSION['flash_message'] = [
        'type' => $type,
        'text' => $message
    ];
}

function get_flash() {
    if (isset($_SESSION['flash_message'])) {
        $msg = $_SESSION['flash_message'];
        unset($_SESSION['flash_message']);
        return $msg;
    }
    return null;
}

/**
 * Settings Get / Set
 */
function get_setting($key, $default = '') {
    static $settings_cache = null;
    $db = getDB();

    if ($settings_cache === null) {
        $settings_cache = [];
        try {
            $stmt = $db->query("SELECT setting_key, setting_value FROM settings");
            while ($row = $stmt->fetch()) {
                $settings_cache[$row['setting_key']] = $row['setting_value'];
            }
        } catch (Exception $e) {
            return $default;
        }
    }

    return $settings_cache[$key] ?? $default;
}

function update_setting($key, $value) {
    $db = getDB();
    $stmt = $db->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (:key, :val) ON DUPLICATE KEY UPDATE setting_value = :val2");
    return $stmt->execute([
        ':key'  => $key,
        ':val'  => $value,
        ':val2' => $value
    ]);
}

/**
 * Currency & Number Formatter
 */
function format_currency($amount, $show_symbol = true) {
    $formatted = number_format((float)$amount, 2, '.', ',');
    if ($show_symbol) {
        $currency = get_setting('currency', 'QR');
        return $formatted . ' ' . $currency;
    }
    return $formatted;
}

/**
 * Date and Time Formatter
 */
function format_date($date_str, $format = 'd M Y') {
    if (empty($date_str) || $date_str === '0000-00-00') {
        return '—';
    }
    $timestamp = strtotime($date_str);
    return $timestamp ? date($format, $timestamp) : $date_str;
}

function format_time($time_str, $format = 'h:i A') {
    if (empty($time_str)) {
        return '—';
    }
    $timestamp = strtotime($time_str);
    return $timestamp ? date($format, $timestamp) : $time_str;
}

function format_datetime($dt_str, $format = 'd M Y, h:i A') {
    if (empty($dt_str)) {
        return '—';
    }
    $timestamp = strtotime($dt_str);
    return $timestamp ? date($format, $timestamp) : $dt_str;
}

/**
 * Expiry calculation helper
 */
function calculate_days_left($date_str) {
    if (empty($date_str) || $date_str === '0000-00-00') {
        return null;
    }
    $target = new DateTime($date_str);
    $today = new DateTime('today');
    $interval = $today->diff($target);
    $days = (int)$interval->format('%r%a');
    return $days;
}

/**
 * Fetch a single QID record with calculated financials
 */
function get_qid_record($id) {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT r.*,
               COALESCE(SUM(p.amount), 0) AS total_paid
        FROM qid_records r
        LEFT JOIN payments p ON p.qid_record_id = r.id
        WHERE r.id = :id
        GROUP BY r.id
    ");
    $stmt->execute([':id' => $id]);
    $record = $stmt->fetch();

    if ($record) {
        $record = enrich_qid_record($record);
    }
    return $record;
}

/**
 * Enrich record with profit, remaining, alert badges, and statuses
 */
function enrich_qid_record($record) {
    $charge = (float)$record['charge_amount'];
    $cost = (float)$record['actual_cost'];
    $paid = (float)($record['total_paid'] ?? 0);

    $record['expected_profit'] = $charge - $cost;
    $record['remaining_balance'] = max(0, $charge - $paid);

    // Payment Status
    if ($paid >= $charge && $charge > 0) {
        $record['payment_status'] = 'Paid';
        $record['payment_badge'] = '<span class="badge bg-success-subtle text-success border border-success-subtle"><i class="fa-solid fa-check-circle me-1"></i>Fully Paid</span>';
    } elseif ($paid > 0) {
        $record['payment_status'] = 'Partial';
        $record['payment_badge'] = '<span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle"><i class="fa-solid fa-clock-rotate-left me-1"></i>Partially Paid</span>';
    } else {
        $record['payment_status'] = 'Unpaid';
        $record['payment_badge'] = '<span class="badge bg-danger-subtle text-danger border border-danger-subtle"><i class="fa-solid fa-circle-exclamation me-1"></i>Unpaid</span>';
    }

    // Expiry Status
    $alert_days = (int)get_setting('expiry_alert_days', 30);
    $days_to_expiry = calculate_days_left($record['expiry_date']);
    $record['days_to_expiry'] = $days_to_expiry;

    if ($days_to_expiry < 0) {
        $record['expiry_status'] = 'Expired';
        $record['expiry_badge'] = '<span class="badge bg-danger text-white"><i class="fa-solid fa-triangle-exclamation me-1"></i>Expired (' . abs($days_to_expiry) . 'd ago)</span>';
    } elseif ($days_to_expiry <= $alert_days) {
        $record['expiry_status'] = 'Expiring Soon';
        $record['expiry_badge'] = '<span class="badge bg-warning text-dark"><i class="fa-solid fa-hourglass-half me-1"></i>' . $days_to_expiry . ' days left</span>';
    } else {
        $record['expiry_status'] = 'Valid';
        $record['expiry_badge'] = '<span class="badge bg-success-subtle text-success border border-success-subtle"><i class="fa-solid fa-shield-check me-1"></i>Valid (' . $days_to_expiry . 'd)</span>';
    }

    return $record;
}

/**
 * Fetch payments for a specific QID
 */
function get_qid_payments($record_id) {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT * FROM payments 
        WHERE qid_record_id = :id 
        ORDER BY payment_date DESC, payment_time DESC, id DESC
    ");
    $stmt->execute([':id' => $record_id]);
    return $stmt->fetchAll();
}

/**
 * Dashboard Overall Statistics
 */
function get_dashboard_stats() {
    $db = getDB();
    $alert_expiry_days = (int)get_setting('expiry_alert_days', 30);
    $alert_due_days = (int)get_setting('due_alert_days', 7);

    // Totals query
    $stmt = $db->query("
        SELECT 
            COUNT(r.id) AS total_records,
            COALESCE(SUM(r.charge_amount), 0) AS total_receivable,
            COALESCE(SUM(r.actual_cost), 0) AS total_cost,
            SUM(CASE WHEN r.expiry_date < CURDATE() THEN 1 ELSE 0 END) AS expired_count,
            SUM(CASE WHEN r.expiry_date >= CURDATE() AND r.expiry_date <= DATE_ADD(CURDATE(), INTERVAL {$alert_expiry_days} DAY) THEN 1 ELSE 0 END) AS expiring_count
        FROM qid_records r
    ");
    $stats = $stmt->fetch();

    // Total Payments Received
    $pay_stmt = $db->query("SELECT COALESCE(SUM(amount), 0) AS total_received FROM payments");
    $pay_res = $pay_stmt->fetch();

    $total_receivable = (float)$stats['total_receivable'];
    $total_received = (float)$pay_res['total_received'];
    $total_cost = (float)$stats['total_cost'];

    // Records with pending balance count (Unpaid/Partial)
    $unpaid_stmt = $db->query("
        SELECT r.id
        FROM qid_records r
        LEFT JOIN payments p ON p.qid_record_id = r.id
        GROUP BY r.id
        HAVING (r.charge_amount - COALESCE(SUM(p.amount), 0)) > 0
    ");
    $unpaid_count = $unpaid_stmt->rowCount();

    return [
        'total_records'       => (int)$stats['total_records'],
        'total_receivable'    => $total_receivable,
        'total_received'      => $total_received,
        'total_balance_due'   => max(0, $total_receivable - $total_received),
        'total_cost'          => $total_cost,
        'total_profit'        => ($total_receivable - $total_cost),
        'expired_count'       => (int)$stats['expired_count'],
        'expiring_count'      => (int)$stats['expiring_count'],
        'unpaid_count'        => $unpaid_count,
    ];
}

/**
 * Get QIDs Expiring Soon or Expired
 */
function get_expiry_reminders($limit = 10) {
    $db = getDB();
    $alert_days = (int)get_setting('expiry_alert_days', 30);
    $stmt = $db->prepare("
        SELECT r.*, COALESCE(SUM(p.amount), 0) AS total_paid
        FROM qid_records r
        LEFT JOIN payments p ON p.qid_record_id = r.id
        WHERE r.expiry_date <= DATE_ADD(CURDATE(), INTERVAL :days DAY)
          AND r.status != 'Cancelled'
        GROUP BY r.id
        ORDER BY r.expiry_date ASC
        LIMIT :limit
    ");
    $stmt->bindValue(':days', $alert_days, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();

    $results = [];
    while ($row = $stmt->fetch()) {
        $results[] = enrich_qid_record($row);
    }
    return $results;
}

/**
 * Get Records with Pending Balance (Unpaid or Partial)
 */
function get_pending_payment_records($limit = 10) {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT r.*, COALESCE(SUM(p.amount), 0) AS total_paid
        FROM qid_records r
        LEFT JOIN payments p ON p.qid_record_id = r.id
        GROUP BY r.id
        HAVING (r.charge_amount - total_paid) > 0
        ORDER BY (r.charge_amount - total_paid) DESC
        LIMIT :limit
    ");
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();

    $results = [];
    while ($row = $stmt->fetch()) {
        $results[] = enrich_qid_record($row);
    }
    return $results;
}

function get_payment_due_reminders($limit = 10) {
    return get_pending_payment_records($limit);
}

/**
 * Get Recent Payments across the system
 */
function get_recent_payments($limit = 8) {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT p.*, r.full_name, r.qid_number, r.phone_number
        FROM payments p
        JOIN qid_records r ON r.id = p.qid_record_id
        ORDER BY p.payment_date DESC, p.payment_time DESC, p.id DESC
        LIMIT :limit
    ");
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

/**
 * Auto-Backup Hook
 * Executes backup silently if auto-backup is enabled in settings
 */
function trigger_auto_backup_if_enabled() {
    $enabled = get_setting('auto_backup_on_entry', '1');
    if ($enabled == '1') {
        require_once __DIR__ . '/../backup_service.php';
        run_system_backup('auto_on_entry');
    }
}

/**
 * Ensure scanner tables exist for real-time mobile sync
 * Uses a file-based flag to avoid running DDL on every page load
 */
function ensure_scanner_tables() {
    static $tables_checked = false;
    if ($tables_checked) return;

    // Use a marker file to skip DDL after first successful creation
    $marker = sys_get_temp_dir() . '/qid_scanner_tables_ok.flag';
    if (file_exists($marker)) {
        $tables_checked = true;
        return;
    }

    $db = getDB();
    try {
        $db->exec("
            CREATE TABLE IF NOT EXISTS `app_tokens` (
              `id` INT(11) NOT NULL AUTO_INCREMENT,
              `user_id` INT(11) NOT NULL,
              `token` VARCHAR(64) NOT NULL UNIQUE,
              `device_name` VARCHAR(100) DEFAULT NULL,
              `last_used_at` DATETIME DEFAULT NULL,
              `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              INDEX `idx_token` (`token`),
              INDEX `idx_user` (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

            CREATE TABLE IF NOT EXISTS `pending_scans` (
              `id` INT(11) NOT NULL AUTO_INCREMENT,
              `user_id` INT(11) NOT NULL,
              `qid_number` VARCHAR(30) NOT NULL,
              `scan_data` TEXT DEFAULT NULL,
              `is_consumed` TINYINT(1) NOT NULL DEFAULT 0,
              `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              INDEX `idx_user_scan` (`user_id`, `is_consumed`, `id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
        // Mark as done so we never run DDL again
        @file_put_contents($marker, date('Y-m-d H:i:s'));
        $tables_checked = true;
    } catch (Exception $e) {
        error_log("Error creating scanner tables: " . $e->getMessage());
    }
}

