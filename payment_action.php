<?php
/**
 * QID Management System - Payment Action Processor
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: records.php");
    exit;
}

$action = $_POST['action'] ?? '';
$qid_record_id = (int)($_POST['qid_record_id'] ?? 0);

if ($qid_record_id <= 0) {
    set_flash('danger', 'Invalid record identifier.');
    header("Location: records.php");
    exit;
}

$db = getDB();

if ($action === 'add') {
    $amount = (float)($_POST['amount'] ?? 0);
    $payment_date = trim($_POST['payment_date'] ?? date('Y-m-d'));
    $payment_time = trim($_POST['payment_time'] ?? date('H:i:s'));
    $payment_method = trim($_POST['payment_method'] ?? 'Cash');
    $receipt_no = trim($_POST['receipt_no'] ?? '');
    $notes = trim($_POST['notes'] ?? '');

    if ($amount <= 0) {
        set_flash('danger', 'Payment amount must be greater than zero.');
        header("Location: record_detail.php?id=" . $qid_record_id);
        exit;
    }

    if (empty($receipt_no)) {
        $receipt_no = 'RCP-' . date('Ymd') . '-' . rand(100, 999);
    }

    try {
        $stmt = $db->prepare("
            INSERT INTO payments (qid_record_id, amount, payment_date, payment_time, payment_method, receipt_no, notes)
            VALUES (:rid, :amt, :pdate, :ptime, :method, :rcp, :notes)
        ");
        $stmt->execute([
            ':rid'    => $qid_record_id,
            ':amt'    => $amount,
            ':pdate'  => $payment_date,
            ':ptime'  => $payment_time,
            ':method' => $payment_method,
            ':rcp'    => $receipt_no,
            ':notes'  => $notes
        ]);

        // Auto-backup hook on entry
        trigger_auto_backup_if_enabled();

        set_flash('success', 'Payment installment of ' . format_currency($amount) . ' recorded successfully on ' . format_date($payment_date) . ' at ' . format_time($payment_time) . '.');

    } catch (Exception $e) {
        set_flash('danger', 'Failed to save payment: ' . $e->getMessage());
    }

    header("Location: record_detail.php?id=" . $qid_record_id);
    exit;

} elseif ($action === 'delete') {
    $payment_id = (int)($_POST['payment_id'] ?? 0);

    if ($payment_id > 0) {
        try {
            $stmt = $db->prepare("DELETE FROM payments WHERE id = :pid AND qid_record_id = :rid");
            $stmt->execute([
                ':pid' => $payment_id,
                ':rid' => $qid_record_id
            ]);

            // Auto-backup hook
            trigger_auto_backup_if_enabled();

            set_flash('success', 'Payment installment was removed.');
        } catch (Exception $e) {
            set_flash('danger', 'Could not delete payment: ' . $e->getMessage());
        }
    }

    header("Location: record_detail.php?id=" . $qid_record_id);
    exit;
} else {
    header("Location: record_detail.php?id=" . $qid_record_id);
    exit;
}
