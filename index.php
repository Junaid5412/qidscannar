<?php
/**
 * QID Management System - Executive Dashboard
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

$page_title = 'Dashboard - QID Management System';

$stats = get_dashboard_stats();
$expiry_reminders = get_expiry_reminders(8);
$payment_reminders = get_payment_due_reminders(8);
$recent_payments = get_recent_payments(6);

$currency = get_setting('currency', 'QR');
$expiry_alert_days = get_setting('expiry_alert_days', 30);

include __DIR__ . '/includes/header.php';
?>

<!-- Dashboard Top Welcome Bar -->
<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
    <div>
        <h3 class="fw-bold mb-1 text-dark">Executive Overview</h3>
        <p class="text-muted mb-0 small">
            <i class="fa-regular fa-calendar me-1"></i> Today is <?= date('l, d F Y') ?> &bull; Qatar Time (UTC+3)
        </p>
    </div>
    <div class="d-flex align-items-center gap-2">
        <a href="profit_report.php" class="btn btn-outline-success btn-sm">
            <i class="fa-solid fa-chart-pie me-1"></i> Profit Report
        </a>
        <a href="records.php" class="btn btn-outline-secondary btn-sm">
            <i class="fa-solid fa-list me-1"></i> View All Records
        </a>
        <a href="record_add.php" class="btn btn-primary btn-sm">
            <i class="fa-solid fa-plus-circle me-1"></i> Add QID Entry
        </a>
    </div>
</div>

<!-- Financial Summary KPI Row -->
<div class="row g-3 mb-4">
    <!-- Total Receivable -->
    <div class="col-md-4 col-sm-6">
        <div class="kpi-card kpi-primary card-hover">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="kpi-title">Total Receivable</div>
                    <div class="kpi-value text-primary"><?= format_currency($stats['total_receivable']) ?></div>
                    <div class="kpi-sub"><i class="fa-solid fa-hand-holding-dollar me-1"></i>Total amount to collect</div>
                </div>
                <div class="kpi-icon bg-primary-subtle text-primary">
                    <i class="fa-solid fa-sack-dollar"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Total Collected -->
    <div class="col-md-4 col-sm-6">
        <div class="kpi-card kpi-success card-hover">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="kpi-title">Amount Received</div>
                    <div class="kpi-value text-success"><?= format_currency($stats['total_received']) ?></div>
                    <div class="kpi-sub">
                        <?php 
                        $pct_collected = ($stats['total_receivable'] > 0) ? round(($stats['total_received'] / $stats['total_receivable']) * 100, 1) : 0;
                        ?>
                        <i class="fa-solid fa-circle-check text-success me-1"></i><?= $pct_collected ?>% of total collected
                    </div>
                </div>
                <div class="kpi-icon bg-success-subtle text-success">
                    <i class="fa-solid fa-circle-dollar-to-slot"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Remaining Balance -->
    <div class="col-md-4 col-sm-12">
        <div class="kpi-card kpi-danger card-hover">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="kpi-title">Remaining Balance</div>
                    <div class="kpi-value text-danger"><?= format_currency($stats['total_balance_due']) ?></div>
                    <div class="kpi-sub"><i class="fa-solid fa-triangle-exclamation text-danger me-1"></i>Uncollected balance</div>
                </div>
                <div class="kpi-icon bg-danger-subtle text-danger">
                    <i class="fa-solid fa-clock-rotate-left"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Security Deposit Held -->
    <?php if ($stats['total_security_held'] > 0 || $stats['total_security_returned'] > 0): ?>
        <div class="col-md-6 col-lg-3">
            <div class="card shadow-sm h-100 border-start border-info border-3">
                <div class="card-body py-3">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="text-muted small mb-1">Security Deposits Held</div>
                            <div class="fs-4 fw-bold text-info"><?= format_currency($stats['total_security_held']) ?></div>
                            <div class="small text-muted mt-1">
                                <i class="fa-solid fa-users me-1"></i><?= $stats['security_held_count'] ?> people
                                <?php if ($stats['total_security_returned'] > 0): ?>
                                    · <span class="text-success"><?= format_currency($stats['total_security_returned']) ?> returned</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="rounded-circle d-flex align-items-center justify-content-center" style="width:48px;height:48px;background:rgba(13,148,136,0.1)">
                            <i class="fa-solid fa-shield-halved text-info fs-5"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- Operational Reminder KPI Badges -->
<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="card p-3 d-flex flex-row align-items-center justify-content-between border-0 shadow-sm">
            <div class="d-flex align-items-center gap-3">
                <div class="p-3 bg-danger-subtle text-danger rounded-circle">
                    <i class="fa-solid fa-ban fs-4"></i>
                </div>
                <div>
                    <h5 class="fw-bold mb-0 text-danger"><?= $stats['expired_count'] ?></h5>
                    <div class="small text-muted">QIDs Already Expired</div>
                </div>
            </div>
            <a href="records.php?filter_expiry=expired" class="btn btn-outline-danger btn-sm">Review</a>
        </div>
    </div>

    <div class="col-md-4">
        <div class="card p-3 d-flex flex-row align-items-center justify-content-between border-0 shadow-sm">
            <div class="d-flex align-items-center gap-3">
                <div class="p-3 bg-warning-subtle text-warning-emphasis rounded-circle">
                    <i class="fa-solid fa-hourglass-half fs-4"></i>
                </div>
                <div>
                    <h5 class="fw-bold mb-0 text-dark"><?= $stats['expiring_count'] ?></h5>
                    <div class="small text-muted">Expiring in &le; <?= $expiry_alert_days ?> Days</div>
                </div>
            </div>
            <a href="records.php?filter_expiry=expiring" class="btn btn-outline-warning btn-sm">View Alerts</a>
        </div>
    </div>

    <div class="col-md-4">
        <div class="card p-3 d-flex flex-row align-items-center justify-content-between border-0 shadow-sm">
            <div class="d-flex align-items-center gap-3">
                <div class="p-3 bg-primary-subtle text-primary rounded-circle">
                    <i class="fa-solid fa-coins fs-4"></i>
                </div>
                <div>
                    <h5 class="fw-bold mb-0 text-primary"><?= $stats['unpaid_count'] ?></h5>
                    <div class="small text-muted">Records with Pending Balance</div>
                </div>
            </div>
            <a href="records.php?filter_payment=unpaid" class="btn btn-outline-primary btn-sm">Collect</a>
        </div>
    </div>
</div>

<!-- Two Column Main Alerts & Reminders Grid -->
<div class="row g-4 mb-4">
    <!-- LEFT PANEL: QID Expiry Reminders -->
    <div class="col-lg-6">
        <div class="card h-100 shadow-sm">
            <div class="card-header-clean">
                <h5>
                    <i class="fa-solid fa-id-card-clip text-warning"></i>
                    <span>QID Expiry Reminders</span>
                </h5>
                <a href="records.php?filter_expiry=expiring" class="btn btn-sm btn-link text-decoration-none">View All</a>
            </div>
            <div class="card-body p-0">
                <?php if (empty($expiry_reminders)): ?>
                    <div class="text-center py-5 text-muted">
                        <i class="fa-solid fa-shield-check fa-3x text-success mb-2"></i>
                        <p class="mb-0">All QIDs are currently up to date! No pending expiry warnings.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-custom align-middle">
                            <thead>
                                <tr>
                                    <th>Name / QID</th>
                                    <th>Expiry Date</th>
                                    <th>Status</th>
                                    <th class="text-end">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($expiry_reminders as $item): ?>
                                    <tr>
                                        <td>
                                            <a href="record_detail.php?id=<?= $item['id'] ?>" class="fw-bold text-decoration-none text-dark">
                                                <?= htmlspecialchars($item['full_name']) ?>
                                            </a>
                                            <div class="small text-muted font-monospace">
                                                <?= htmlspecialchars($item['qid_number']) ?>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="fw-semibold"><?= format_date($item['expiry_date']) ?></div>
                                            <small class="text-muted"><?= htmlspecialchars($item['company_name'] ?? 'Individual') ?></small>
                                        </td>
                                        <td>
                                            <?= $item['expiry_badge'] ?>
                                        </td>
                                        <td class="text-end">
                                            <a href="record_detail.php?id=<?= $item['id'] ?>" class="btn btn-sm btn-outline-primary" title="View Profile & Payments">
                                                <i class="fa-solid fa-arrow-right"></i>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- RIGHT PANEL: Pending Balance Collections -->
    <div class="col-lg-6">
        <div class="card h-100 shadow-sm">
            <div class="card-header-clean">
                <h5>
                    <i class="fa-solid fa-hand-holding-dollar text-danger"></i>
                    <span>Pending Balance Collections</span>
                </h5>
                <a href="records.php?filter_payment=unpaid" class="btn btn-sm btn-link text-decoration-none">View All</a>
            </div>
            <div class="card-body p-0">
                <?php if (empty($payment_reminders)): ?>
                    <div class="text-center py-5 text-muted">
                        <i class="fa-solid fa-circle-check fa-3x text-success mb-2"></i>
                        <p class="mb-0">All client balances are fully collected!</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-custom align-middle">
                            <thead>
                                <tr>
                                    <th>Client / Employee</th>
                                    <th>Balance Due</th>
                                    <th>Status</th>
                                    <th class="text-end">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($payment_reminders as $p_item): ?>
                                    <tr>
                                        <td>
                                            <a href="record_detail.php?id=<?= $p_item['id'] ?>" class="fw-bold text-decoration-none text-dark">
                                                <?= htmlspecialchars($p_item['full_name']) ?>
                                            </a>
                                            <div class="small text-muted">
                                                <i class="fa-solid fa-phone me-1"></i><?= htmlspecialchars($p_item['phone_number'] ?? 'N/A') ?>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="fw-bold text-danger"><?= format_currency($p_item['remaining_balance']) ?></span>
                                            <div class="small text-muted">of <?= format_currency($p_item['charge_amount']) ?></div>
                                        </td>
                                        <td>
                                            <?= $p_item['payment_badge'] ?>
                                        </td>
                                        <td class="text-end">
                                            <a href="record_detail.php?id=<?= $p_item['id'] ?>#addPaymentSection" class="btn btn-sm btn-success" title="Record Installment">
                                                <i class="fa-solid fa-plus me-1"></i>Collect
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Recent Payment Installments Ledger Feed -->
<div class="card shadow-sm mb-4">
    <div class="card-header-clean">
        <h5>
            <i class="fa-solid fa-receipt text-primary"></i>
            <span>Recent Payment Installments Received</span>
        </h5>
        <a href="payments.php" class="btn btn-sm btn-link text-decoration-none">View All Payments</a>
    </div>
    <div class="card-body p-0">
        <?php if (empty($recent_payments)): ?>
            <div class="text-center py-4 text-muted">
                <p class="mb-0">No payment entries recorded yet.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-custom align-middle">
                    <thead>
                        <tr>
                            <th>Receipt / ID</th>
                            <th>Person Name</th>
                            <th>Amount Received</th>
                            <th>Payment Date & Time</th>
                            <th>Payment Method</th>
                            <th>Notes</th>
                            <th class="text-end">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent_payments as $pay): ?>
                            <tr>
                                <td>
                                    <span class="badge bg-light text-dark border font-monospace">
                                        <?= htmlspecialchars($pay['receipt_no'] ?: ('#RCP-' . $pay['id'])) ?>
                                    </span>
                                </td>
                                <td>
                                    <a href="record_detail.php?id=<?= $pay['qid_record_id'] ?>" class="fw-bold text-dark text-decoration-none">
                                        <?= htmlspecialchars($pay['full_name']) ?>
                                    </a>
                                    <div class="small text-muted font-monospace"><?= htmlspecialchars($pay['qid_number']) ?></div>
                                </td>
                                <td>
                                    <span class="fw-bold text-success fs-6">+ <?= format_currency($pay['amount']) ?></span>
                                </td>
                                <td>
                                    <div><i class="fa-regular fa-calendar me-1 text-muted"></i><?= format_date($pay['payment_date']) ?></div>
                                    <small class="text-muted"><i class="fa-regular fa-clock me-1"></i><?= format_time($pay['payment_time']) ?></small>
                                </td>
                                <td>
                                    <span class="badge bg-light text-secondary border">
                                        <i class="fa-solid fa-credit-card me-1"></i><?= htmlspecialchars($pay['payment_method']) ?>
                                    </span>
                                </td>
                                <td>
                                    <small class="text-muted"><?= htmlspecialchars($pay['notes'] ?: '—') ?></small>
                                </td>
                                <td class="text-end">
                                    <a href="record_detail.php?id=<?= $pay['qid_record_id'] ?>" class="btn btn-sm btn-outline-secondary">
                                        <i class="fa-solid fa-eye"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
