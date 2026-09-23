<?php
/**
 * QID Management System - CSV Data Exporter
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_login();

$type = $_GET['type'] ?? 'records';
$db = getDB();

if ($type === 'records') {
    $stmt = $db->query("
        SELECT r.*, COALESCE(SUM(p.amount), 0) AS total_paid
        FROM qid_records r
        LEFT JOIN payments p ON p.qid_record_id = r.id
        GROUP BY r.id
        ORDER BY r.id DESC
    ");
    $records = $stmt->fetchAll();

    $filename = 'qid_records_export_' . date('Y-m-d_H-i') . '.csv';

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $output = fopen('php://output', 'w');
    // UTF-8 BOM for Microsoft Excel compatibility
    fputs($output, "\xEF\xBB\xBF");

    // Header row
    fputcsv($output, [
        'ID',
        'Full Name',
        'QID Number',
        'Nationality',
        'Company',
        'Job Title',
        'Phone',
        'Expiry Date',
        'Expiry Status',
        'Agreed Charge (QR)',
        'Actual Cost (QR)',
        'Total Paid (QR)',
        'Remaining Balance (QR)',
        'Record Status',
        'Notes'
    ]);

    foreach ($records as $r) {
        $enriched = enrich_qid_record($r);
        fputcsv($output, [
            $enriched['id'],
            $enriched['full_name'],
            $enriched['qid_number'],
            $enriched['nationality'],
            $enriched['company_name'],
            $enriched['job_title'],
            $enriched['phone_number'],
            $enriched['expiry_date'],
            $enriched['expiry_status'],
            number_format($enriched['charge_amount'], 2, '.', ''),
            number_format($enriched['actual_cost'], 2, '.', ''),
            number_format($enriched['total_paid'], 2, '.', ''),
            number_format($enriched['remaining_balance'], 2, '.', ''),
            $enriched['status'],
            $enriched['notes']
        ]);
    }

    fclose($output);
    exit;

} elseif ($type === 'payments') {
    $stmt = $db->query("
        SELECT p.*, r.full_name, r.qid_number
        FROM payments p
        JOIN qid_records r ON r.id = p.qid_record_id
        ORDER BY p.payment_date DESC, p.payment_time DESC, p.id DESC
    ");
    $payments = $stmt->fetchAll();

    $filename = 'qid_payments_export_' . date('Y-m-d_H-i') . '.csv';

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $output = fopen('php://output', 'w');
    fputs($output, "\xEF\xBB\xBF");

    fputcsv($output, [
        'Payment ID',
        'Receipt No',
        'Person Name',
        'QID Number',
        'Amount (QR)',
        'Payment Date',
        'Payment Time',
        'Payment Method',
        'Notes'
    ]);

    foreach ($payments as $p) {
        fputcsv($output, [
            $p['id'],
            $p['receipt_no'],
            $p['full_name'],
            $p['qid_number'],
            number_format($p['amount'], 2, '.', ''),
            $p['payment_date'],
            $p['payment_time'],
            $p['payment_method'],
            $p['notes']
        ]);
    }

    fclose($output);
    exit;
} elseif ($type === 'profit') {
    $stmt = $db->query("
        SELECT r.*, COALESCE(SUM(p.amount), 0) AS total_paid
        FROM qid_records r
        LEFT JOIN payments p ON p.qid_record_id = r.id
        GROUP BY r.id
        ORDER BY r.id DESC
    ");
    $records = $stmt->fetchAll();

    $filename = 'qid_profit_report_' . date('Y-m-d_H-i') . '.csv';

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $output = fopen('php://output', 'w');
    fputs($output, "\xEF\xBB\xBF");

    fputcsv($output, [
        'ID',
        'Person Name',
        'QID Number',
        'Company / Sponsor',
        'Job Title',
        'Agreed Charge (QR)',
        'Actual Cost (QR)',
        'Net Profit (QR)',
        'Profit Margin (%)',
        'Total Collected (QR)',
        'Remaining Balance (QR)',
        'Realized Profit in Hand (QR)',
        'Status'
    ]);

    foreach ($records as $r) {
        $charge = (float)$r['charge_amount'];
        $cost = (float)$r['actual_cost'];
        $paid = (float)$r['total_paid'];
        $profit = $charge - $cost;
        $margin = ($charge > 0) ? round(($profit / $charge) * 100, 1) : 0;
        $balance = max(0, $charge - $paid);
        $realized = max(0, $paid - $cost);

        fputcsv($output, [
            $r['id'],
            $r['full_name'],
            $r['qid_number'],
            $r['company_name'] ?: 'Individual',
            $r['job_title'] ?: 'N/A',
            number_format($charge, 2, '.', ''),
            number_format($cost, 2, '.', ''),
            number_format($profit, 2, '.', ''),
            $margin . '%',
            number_format($paid, 2, '.', ''),
            number_format($balance, 2, '.', ''),
            number_format($realized, 2, '.', ''),
            $r['status']
        ]);
    }

    fclose($output);
    exit;
} else {
    header("Location: records.php");
    exit;
}
