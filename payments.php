<?php
/**
 * QID Management System - Master Payments Ledger
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

$page_title = 'Payment History - QID Management System';

$db = getDB();

$method_filter = $_GET['method'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$search = trim($_GET['search'] ?? '');

$query = "
    SELECT p.*, 
           r.full_name, r.qid_number, r.phone_number, r.company_name
    FROM payments p
    JOIN qid_records r ON r.id = p.qid_record_id
    WHERE 1=1
";

$params = [];

if (!empty($search)) {
    $query .= " AND (r.full_name LIKE :s OR r.qid_number LIKE :s OR p.receipt_no LIKE :s OR p.notes LIKE :s)";
    $params[':s'] = "%{$search}%";
}

if (!empty($method_filter)) {
    $query .= " AND p.payment_method = :method";
    $params[':method'] = $method_filter;
}

if (!empty($date_from)) {
    $query .= " AND p.payment_date >= :dfrom";
    $params[':dfrom'] = $date_from;
}

if (!empty($date_to)) {
    $query .= " AND p.payment_date <= :dto";
    $params[':dto'] = $date_to;
}

$query .= " ORDER BY p.payment_date DESC, p.payment_time DESC, p.id DESC";

$stmt = $db->prepare($query);
$stmt->execute($params);
$payments = $stmt->fetchAll();

// Calculations for top statistics
$total_collected = 0;
$today_collected = 0;
$today_str = date('Y-m-d');
foreach ($payments as $p) {
    $amt = (float)$p['amount'];
    $total_collected += $amt;
    if ($p['payment_date'] === $today_str) {
        $today_collected += $amt;
    }
}

include __DIR__ . '/includes/header.php';
?>

<!-- Title & Header Actions -->
<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
    <div>
        <h3 class="fw-bold mb-1 text-dark">Payment Installments Ledger</h3>
        <p class="text-muted mb-0 small">Audit trail of all money collected with exact date and time timestamps</p>
    </div>
    <div class="d-flex align-items-center gap-2">
        <a href="export.php?type=payments" class="btn btn-outline-secondary btn-sm" title="Export to CSV">
            <i class="fa-solid fa-file-csv me-1"></i> Export CSV
        </a>
        <button onclick="window.print()" class="btn btn-outline-secondary btn-sm" title="Print Ledger">
            <i class="fa-solid fa-print me-1"></i> Print
        </button>
    </div>
</div>

<!-- Ledger Summary Stats -->
<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="kpi-card kpi-success">
            <div class="kpi-title">Total Collected (Filtered)</div>
            <div class="kpi-value text-success"><?= format_currency($total_collected) ?></div>
            <div class="kpi-sub"><?= count($payments) ?> installment transactions</div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="kpi-card kpi-primary">
            <div class="kpi-title">Today's Collections</div>
            <div class="kpi-value text-primary"><?= format_currency($today_collected) ?></div>
            <div class="kpi-sub"><?= date('d M Y') ?></div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="kpi-card kpi-purple">
            <div class="kpi-title">Average Payment Size</div>
            <div class="kpi-value text-dark">
                <?= (count($payments) > 0) ? format_currency($total_collected / count($payments)) : format_currency(0) ?>
            </div>
            <div class="kpi-sub">Per transaction</div>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="card mb-4 shadow-sm border-0">
    <div class="card-body p-3">
        <form method="GET" action="payments.php" class="row g-2 align-items-center">
            <div class="col-lg-3 col-md-6">
                <div class="input-group">
                    <span class="input-group-text bg-white text-muted"><i class="fa-solid fa-magnifying-glass"></i></span>
                    <input type="text" name="search" id="tableSearchInput" data-table-target="#paymentsTable" class="form-control" placeholder="Search person, QID, receipt..." value="<?= htmlspecialchars($search) ?>">
                </div>
            </div>

            <div class="col-lg-2 col-md-6">
                <select name="method" class="form-select" onchange="this.form.submit()">
                    <option value="">All Payment Methods</option>
                    <option value="Cash" <?= ($method_filter === 'Cash') ? 'selected' : '' ?>>Cash</option>
                    <option value="Bank Transfer" <?= ($method_filter === 'Bank Transfer') ? 'selected' : '' ?>>Bank Transfer</option>
                    <option value="Card" <?= ($method_filter === 'Card') ? 'selected' : '' ?>>Card</option>
                    <option value="Cheque" <?= ($method_filter === 'Cheque') ? 'selected' : '' ?>>Cheque</option>
                    <option value="Online" <?= ($method_filter === 'Online') ? 'selected' : '' ?>>Online</option>
                </select>
            </div>

            <div class="col-lg-2 col-md-6">
                <input type="date" name="date_from" class="form-control" placeholder="From Date" value="<?= htmlspecialchars($date_from) ?>" title="From Date">
            </div>

            <div class="col-lg-2 col-md-6">
                <input type="date" name="date_to" class="form-control" placeholder="To Date" value="<?= htmlspecialchars($date_to) ?>" title="To Date">
            </div>

            <div class="col-lg-3 col-md-12 d-flex gap-1">
                <button type="submit" class="btn btn-primary btn-sm flex-grow-1">Apply Filters</button>
                <?php if (!empty($search) || !empty($method_filter) || !empty($date_from) || !empty($date_to)): ?>
                    <a href="payments.php" class="btn btn-outline-secondary btn-sm" title="Clear Filters">
                        <i class="fa-solid fa-xmark"></i>
                    </a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Payments Table -->
<div class="card shadow-sm">
    <div class="card-body p-0">
        <?php if (empty($payments)): ?>
            <div class="text-center py-5 text-muted">
                <i class="fa-solid fa-receipt fa-3x text-secondary mb-3"></i>
                <h5 class="fw-bold text-dark">No Payments Found</h5>
                <p class="small text-muted mb-0">No payment transaction records match your search criteria.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-custom table-hover align-middle mb-0" id="paymentsTable">
                    <thead>
                        <tr>
                            <th>Receipt / ID</th>
                            <th>Person Name</th>
                            <th>Amount Collected</th>
                            <th>Payment Date & Time</th>
                            <th>Payment Method</th>
                            <th>Notes</th>
                            <th class="text-end">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($payments as $item): ?>
                            <tr>
                                <td>
                                    <span class="badge bg-light text-dark border font-monospace">
                                        <?= htmlspecialchars($item['receipt_no'] ?: ('#RCP-' . $item['id'])) ?>
                                    </span>
                                </td>
                                <td>
                                    <a href="record_detail.php?id=<?= $item['qid_record_id'] ?>" class="fw-bold text-dark text-decoration-none">
                                        <?= htmlspecialchars($item['full_name']) ?>
                                    </a>
                                    <div class="small text-muted font-monospace">QID: <?= htmlspecialchars($item['qid_number']) ?></div>
                                </td>
                                <td>
                                    <span class="fw-bold text-success fs-6">
                                        + <?= format_currency($item['amount']) ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="fw-semibold"><i class="fa-regular fa-calendar me-1 text-muted"></i><?= format_date($item['payment_date']) ?></div>
                                    <small class="text-muted"><i class="fa-regular fa-clock me-1 text-primary"></i><?= format_time($item['payment_time']) ?></small>
                                </td>
                                <td>
                                    <span class="badge bg-light text-secondary border">
                                        <i class="fa-solid fa-credit-card me-1"></i><?= htmlspecialchars($item['payment_method']) ?>
                                    </span>
                                </td>
                                <td>
                                    <small class="text-muted"><?= htmlspecialchars($item['notes'] ?: '—') ?></small>
                                </td>
                                <td class="text-end">
                                    <a href="record_detail.php?id=<?= $item['qid_record_id'] ?>" class="btn btn-sm btn-outline-primary" title="View Profile">
                                        <i class="fa-solid fa-arrow-right"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="p-3 bg-light border-top text-muted small d-flex justify-content-between align-items-center">
                <span>Showing <strong><?= count($payments) ?></strong> payments</span>
                <span>Total: <strong class="text-success"><?= format_currency($total_collected) ?></strong></span>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
