<?php
/**
 * QID Management System - Person Profile & Installment Ledger
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

$id = (int)($_GET['id'] ?? 0);
$record = get_qid_record($id);

if (!$record) {
    set_flash('danger', 'The requested QID record does not exist.');
    header("Location: records.php");
    exit;
}

$page_title = htmlspecialchars($record['full_name']) . ' - QID & Payment Details';
$currency = get_setting('currency', 'QR');
$company_name = get_setting('company_name', 'Qatar Business Solutions');
$company_phone = get_setting('company_phone', '+974 5500 0000');

$payments = get_qid_payments($id);

// Calculate running balance per payment in chronological order
$chronological_payments = array_reverse($payments);
$running_paid = 0;
$payments_with_balance = [];
foreach ($chronological_payments as $p) {
    $running_paid += (float)$p['amount'];
    $p['running_balance'] = max(0, (float)$record['charge_amount'] - $running_paid);
    $payments_with_balance[] = $p;
}
$payments_display = array_reverse($payments_with_balance);

include __DIR__ . '/includes/header.php';
?>

<!-- Action Topbar -->
<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4 no-print">
    <div class="d-flex align-items-center gap-2">
        <a href="records.php" class="btn btn-outline-secondary btn-sm">
            <i class="fa-solid fa-arrow-left me-1"></i> Records
        </a>
        <h4 class="fw-bold mb-0 text-dark">Profile & Installment Ledger</h4>
    </div>
    <div class="d-flex align-items-center gap-2">
        <a href="statement.php?id=<?= $record['id'] ?>" target="_blank" class="btn btn-danger btn-sm text-white fw-bold shadow-sm" title="Generate Official Portrait PDF Statement">
            <i class="fa-solid fa-file-pdf me-1"></i> Official PDF Statement
        </a>
        <a href="record_edit.php?id=<?= $record['id'] ?>" class="btn btn-outline-secondary btn-sm">
            <i class="fa-solid fa-pen me-1"></i> Edit Profile
        </a>
        <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addPaymentModal">
            <i class="fa-solid fa-plus-circle me-1"></i> Add Installment Payment
        </button>
    </div>
</div>

<!-- Profile Banner Card -->
<div class="person-header-card shadow-sm">
    <div class="row align-items-center g-3">
        <div class="col-lg-7">
            <div class="d-flex align-items-center gap-3 mb-2">
                <h3 class="fw-bold mb-0 text-white"><?= htmlspecialchars($record['full_name']) ?></h3>
                <span class="badge bg-light text-dark font-monospace fs-6 px-3">
                    <i class="fa-solid fa-id-card me-1 text-primary"></i> <?= htmlspecialchars($record['qid_number']) ?>
                </span>
            </div>

            <div class="d-flex flex-wrap align-items-center gap-3 text-white-50 small mt-2">
                <?php if (!empty($record['company_name'])): ?>
                    <span><i class="fa-solid fa-building me-1 text-info"></i><?= htmlspecialchars($record['company_name']) ?></span>
                <?php endif; ?>
                <?php if (!empty($record['job_title'])): ?>
                    <span>&bull; <i class="fa-solid fa-briefcase me-1 text-info"></i><?= htmlspecialchars($record['job_title']) ?></span>
                <?php endif; ?>
                <?php if (!empty($record['phone_number'])): ?>
                    <span>&bull; <i class="fa-solid fa-phone me-1 text-info"></i><?= htmlspecialchars($record['phone_number']) ?></span>
                <?php endif; ?>
                <?php if (!empty($record['nationality'])): ?>
                    <span>&bull; <i class="fa-solid fa-earth-asia me-1 text-info"></i><?= htmlspecialchars($record['nationality']) ?></span>
                <?php endif; ?>
            </div>
        </div>

        <div class="col-lg-5 text-lg-end">
            <div class="d-flex flex-column flex-sm-row justify-content-lg-end gap-2">
                <div class="bg-dark bg-opacity-50 p-2 px-3 rounded border border-secondary text-start">
                    <div class="small text-white-50">QID Expiry Date</div>
                    <div class="fw-bold text-white"><?= format_date($record['expiry_date']) ?></div>
                    <div class="mt-1"><?= $record['expiry_badge'] ?></div>
                </div>

                <div class="bg-dark bg-opacity-50 p-2 px-3 rounded border border-secondary text-start">
                    <div class="small text-white-50">Payment Due Date</div>
                    <div class="fw-bold text-white"><?= format_date($record['payment_due_date']) ?></div>
                    <div class="mt-1"><?= $record['due_badge'] ?></div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Financial Summary Widgets -->
<div class="row g-3 mb-4">
    <!-- Total Charge (Receivable) -->
    <div class="col-md-4 col-xl-2">
        <div class="card p-3 shadow-sm h-100 border-start border-4 border-primary">
            <div class="text-muted small text-uppercase fw-semibold">Agreed Charge</div>
            <div class="fs-5 fw-bold text-dark mt-1"><?= format_currency($record['charge_amount']) ?></div>
            <div class="small text-muted">Receivable fee</div>
        </div>
    </div>

    <!-- Actual Cost -->
    <div class="col-md-4 col-xl-2">
        <div class="card p-3 shadow-sm h-100 border-start border-4 border-secondary">
            <div class="text-muted small text-uppercase fw-semibold">Actual Cost</div>
            <div class="fs-5 fw-bold text-secondary mt-1"><?= format_currency($record['actual_cost']) ?></div>
            <div class="small text-muted">Government / Base cost</div>
        </div>
    </div>

    <!-- Our Profit -->
    <div class="col-md-4 col-xl-3">
        <div class="card p-3 shadow-sm h-100 border-start border-4 border-info">
            <div class="text-muted small text-uppercase fw-semibold">Expected Profit</div>
            <div class="fs-5 fw-bold <?= ($record['expected_profit'] >= 0) ? 'text-success' : 'text-danger' ?> mt-1">
                <?= format_currency($record['expected_profit']) ?>
            </div>
            <div class="small text-muted">
                <?php 
                $profit_pct = ($record['charge_amount'] > 0) ? round(($record['expected_profit'] / $record['charge_amount']) * 100, 1) : 0;
                ?>
                Margin: <strong><?= $profit_pct ?>%</strong>
            </div>
        </div>
    </div>

    <!-- Total Received -->
    <div class="col-md-6 col-xl-2">
        <div class="card p-3 shadow-sm h-100 border-start border-4 border-success">
            <div class="text-muted small text-uppercase fw-semibold">Total Paid</div>
            <div class="fs-5 fw-bold text-success mt-1"><?= format_currency($record['total_paid']) ?></div>
            <div class="small text-muted">
                <?= count($payments) ?> installment(s)
            </div>
        </div>
    </div>

    <!-- Remaining Balance -->
    <div class="col-md-6 col-xl-3">
        <div class="card p-3 shadow-sm h-100 border-start border-4 border-danger bg-danger-subtle bg-opacity-25">
            <div class="text-muted small text-uppercase fw-semibold">Remaining Balance</div>
            <div class="fs-4 fw-bold text-danger mt-1">
                <?= format_currency($record['remaining_balance']) ?>
            </div>
            <div class="mt-1">
                <?= $record['payment_badge'] ?>
            </div>
        </div>
    </div>
</div>

<!-- Installment Payment History Table -->
<div class="card shadow-sm mb-4">
    <div class="card-header-clean">
        <h5 class="mb-0">
            <i class="fa-solid fa-clock-rotate-left text-primary"></i>
            <span>Payment Installments History (Exact Date & Time)</span>
        </h5>
        <div class="no-print d-flex gap-2">
            <a href="statement.php?id=<?= $record['id'] ?>" target="_blank" class="btn btn-sm btn-outline-danger" title="Open Official Statement PDF">
                <i class="fa-solid fa-file-pdf me-1"></i> Print / PDF Statement
            </a>
            <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#addPaymentModal">
                <i class="fa-solid fa-plus me-1"></i> Add Installment
            </button>
        </div>
    </div>
    <div class="card-body p-0">
        <?php if (empty($payments_display)): ?>
            <div class="text-center py-5 text-muted">
                <i class="fa-solid fa-hand-holding-dollar fa-3x text-secondary mb-3"></i>
                <h6 class="fw-bold text-dark">No Payment Installments Recorded Yet</h6>
                <p class="small text-muted mb-3">Add the first payment installment received from this client.</p>
                <button type="button" class="btn btn-sm btn-primary no-print" data-bs-toggle="modal" data-bs-target="#addPaymentModal">
                    <i class="fa-solid fa-plus me-1"></i> Add Payment Installment
                </button>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-custom align-middle">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Date & Exact Time</th>
                            <th>Receipt / Ref #</th>
                            <th>Payment Method</th>
                            <th>Amount Paid</th>
                            <th>Remaining Balance</th>
                            <th>Notes</th>
                            <th class="text-end no-print">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $counter = count($payments_display);
                        foreach ($payments_display as $p): 
                        ?>
                            <tr>
                                <td class="fw-bold text-muted"><?= $counter-- ?></td>
                                <td>
                                    <div class="fw-bold text-dark">
                                        <i class="fa-regular fa-calendar-days me-1 text-muted"></i>
                                        <?= format_date($p['payment_date']) ?>
                                    </div>
                                    <div class="small text-muted">
                                        <i class="fa-regular fa-clock me-1 text-primary"></i>
                                        <?= format_time($p['payment_time']) ?>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge bg-light text-dark border font-monospace">
                                        <?= htmlspecialchars($p['receipt_no'] ?: ('#RCP-' . $p['id'])) ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge bg-light text-secondary border">
                                        <?= htmlspecialchars($p['payment_method']) ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="fw-bold text-success fs-6">
                                        + <?= format_currency($p['amount']) ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="fw-bold <?= ($p['running_balance'] > 0) ? 'text-danger' : 'text-success' ?>">
                                        <?= format_currency($p['running_balance']) ?>
                                    </span>
                                </td>
                                <td>
                                    <small class="text-muted"><?= htmlspecialchars($p['notes'] ?: '—') ?></small>
                                </td>
                                <td class="text-end no-print">
                                    <form method="POST" action="payment_action.php" onsubmit="return confirm('Are you sure you want to delete this payment installment?');" style="display:inline;">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="payment_id" value="<?= $p['id'] ?>">
                                        <input type="hidden" name="qid_record_id" value="<?= $record['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete Payment">
                                            <i class="fa-solid fa-trash"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot class="bg-light fw-bold">
                        <tr>
                            <td colspan="4" class="text-end">Total Amount Collected:</td>
                            <td class="text-success fs-6"><?= format_currency($record['total_paid']) ?></td>
                            <td class="text-danger fs-6"><?= format_currency($record['remaining_balance']) ?></td>
                            <td colspan="2" class="no-print"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Printable Statement Section (Clean layout for Print mode) -->
<div class="print-sheet d-none">
    <div class="text-center mb-4">
        <h3 class="fw-bold"><?= htmlspecialchars($company_name) ?></h3>
        <p class="text-muted small">Qatar ID Services &bull; Phone: <?= htmlspecialchars($company_phone) ?></p>
        <hr>
        <h5 class="fw-bold text-uppercase">Payment Statement & Installment Summary</h5>
    </div>
    <div class="row mb-4">
        <div class="col-6">
            <strong>Client Name:</strong> <?= htmlspecialchars($record['full_name']) ?><br>
            <strong>QID Number:</strong> <?= htmlspecialchars($record['qid_number']) ?><br>
            <strong>Company:</strong> <?= htmlspecialchars($record['company_name'] ?? 'N/A') ?>
        </div>
        <div class="col-6 text-end">
            <strong>Statement Date:</strong> <?= date('d M Y') ?><br>
            <strong>Agreed Charge:</strong> <?= format_currency($record['charge_amount']) ?><br>
            <strong>Total Received:</strong> <?= format_currency($record['total_paid']) ?><br>
            <strong>Remaining Due:</strong> <?= format_currency($record['remaining_balance']) ?>
        </div>
    </div>
    <div class="mt-5 pt-4 border-top d-flex justify-content-between">
        <div>Client Signature: _______________________</div>
        <div>Authorized Signatory: _______________________</div>
    </div>
</div>

<!-- Modal: Add Payment Installment -->
<div class="modal fade" id="addPaymentModal" tabindex="-1" aria-labelledby="addPaymentModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <form method="POST" action="payment_action.php">
                <input type="hidden" name="action" value="add">
                <input type="hidden" name="qid_record_id" value="<?= $record['id'] ?>">

                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title fw-bold" id="addPaymentModalLabel">
                        <i class="fa-solid fa-receipt me-1"></i> Record Payment Installment
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body p-4">
                    <!-- Balance context info banner -->
                    <div class="p-3 bg-light rounded-3 mb-3 border">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <small class="text-muted text-uppercase fw-bold">Client</small>
                                <div class="fw-bold text-dark"><?= htmlspecialchars($record['full_name']) ?></div>
                            </div>
                            <div class="text-end">
                                <small class="text-muted text-uppercase fw-bold">Current Balance Due</small>
                                <div class="fw-bold text-danger fs-6" id="modal_remaining_balance" data-balance="<?= $record['remaining_balance'] ?>">
                                    <?= format_currency($record['remaining_balance']) ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Amount Paid <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input type="number" step="0.01" name="amount" id="pay_amount" class="form-control fw-bold fs-5 text-success" placeholder="e.g. 500.00" required value="<?= ($record['remaining_balance'] > 0) ? min(500, $record['remaining_balance']) : '' ?>">
                            <span class="input-group-text bg-light fw-bold"><?= htmlspecialchars($currency) ?></span>
                        </div>
                        <div class="form-text mt-1 text-muted">
                            New balance will be: <strong class="text-danger"><span id="modal_new_balance"><?= format_currency($record['remaining_balance'], false) ?></span> <?= htmlspecialchars($currency) ?></strong>
                        </div>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Payment Date <span class="text-danger">*</span></label>
                            <input type="date" name="payment_date" id="pay_date" class="form-control" required value="<?= date('Y-m-d') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Payment Time <span class="text-danger">*</span></label>
                            <input type="time" name="payment_time" id="pay_time" class="form-control" required value="<?= date('H:i') ?>">
                        </div>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Payment Method</label>
                            <select name="payment_method" class="form-select">
                                <option value="Cash" selected>Cash</option>
                                <option value="Bank Transfer">Bank Transfer</option>
                                <option value="Card">Credit / Debit Card</option>
                                <option value="Cheque">Cheque</option>
                                <option value="Online">Online / Portal</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Receipt / Ref Number</label>
                            <input type="text" name="receipt_no" class="form-control font-monospace" placeholder="RCP-<?= date('Ymd') ?>-<?= rand(100, 999) ?>">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Notes / Remarks</label>
                        <input type="text" name="notes" class="form-control" placeholder="e.g. Received at office, installment 2 of 3">
                    </div>
                </div>

                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success px-4">
                        <i class="fa-solid fa-check me-1"></i> Save Payment Entry
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
