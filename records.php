<?php
/**
 * QID Management System - Master Records List
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

$page_title = 'QID Records - QID Management System';

$db = getDB();

// Handle Secure Record Deletion (Password Protected)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_record_secure') {
    $del_id = (int)($_POST['record_id'] ?? 0);
    $entered_pass = trim($_POST['delete_password'] ?? '');
    $admin_pass = get_setting('admin_delete_password', 'admin123');

    if (empty($entered_pass)) {
        set_flash('danger', 'Security verification failed: Confirmation password cannot be blank.');
    } elseif ($entered_pass !== $admin_pass) {
        set_flash('danger', 'Security verification failed: Incorrect password. The record was NOT deleted.');
    } else {
        $rec = get_qid_record($del_id);
        if ($rec) {
            $del_stmt = $db->prepare("DELETE FROM qid_records WHERE id = :id");
            $del_stmt->execute([':id' => $del_id]);

            // Auto-backup hook on entry/deletion
            trigger_auto_backup_if_enabled();

            set_flash('success', 'QID Record for <strong>' . htmlspecialchars($rec['full_name']) . '</strong> (QID: ' . htmlspecialchars($rec['qid_number']) . ') and all associated payments were permanently deleted.');
        } else {
            set_flash('danger', 'Record not found or already deleted.');
        }
    }
    header("Location: records.php");
    exit;
}

// Filters & Search
$filter_expiry = $_GET['filter_expiry'] ?? '';
$filter_payment = $_GET['filter_payment'] ?? '';
$search = trim($_GET['search'] ?? '');

$query = "
    SELECT r.*,
           COALESCE(SUM(p.amount), 0) AS total_paid
    FROM qid_records r
    LEFT JOIN payments p ON p.qid_record_id = r.id
    WHERE 1=1
";

$params = [];

if (!empty($search)) {
    $query .= " AND (r.full_name LIKE :s OR r.qid_number LIKE :s OR r.phone_number LIKE :s OR r.company_name LIKE :s)";
    $params[':s'] = "%{$search}%";
}

$alert_days = (int)get_setting('expiry_alert_days', 30);
if ($filter_expiry === 'expired') {
    $query .= " AND r.expiry_date < CURDATE()";
} elseif ($filter_expiry === 'expiring') {
    $query .= " AND r.expiry_date >= CURDATE() AND r.expiry_date <= DATE_ADD(CURDATE(), INTERVAL {$alert_days} DAY)";
} elseif ($filter_expiry === 'valid') {
    $query .= " AND r.expiry_date > DATE_ADD(CURDATE(), INTERVAL {$alert_days} DAY)";
}

$query .= " GROUP BY r.id";

// Having clause for payment status filter
if ($filter_payment === 'paid') {
    $query .= " HAVING (r.charge_amount - total_paid) <= 0 AND r.charge_amount > 0";
} elseif ($filter_payment === 'partial') {
    $query .= " HAVING total_paid > 0 AND (r.charge_amount - total_paid) > 0";
} elseif ($filter_payment === 'unpaid') {
    $query .= " HAVING total_paid = 0";
}

$query .= " ORDER BY r.id DESC";

$stmt = $db->prepare($query);
$stmt->execute($params);

$raw_records = $stmt->fetchAll();
$records = [];
foreach ($raw_records as $r) {
    $records[] = enrich_qid_record($r);
}

include __DIR__ . '/includes/header.php';
?>

<!-- Header Title & Actions -->
<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
    <div>
        <h3 class="fw-bold mb-1 text-dark">QID Master Records</h3>
        <p class="text-muted mb-0 small">Manage Resident IDs, renewal dates, costs, and collection balances</p>
    </div>
    <div class="d-flex align-items-center gap-2">
        <a href="export.php?type=records" class="btn btn-outline-secondary btn-sm" title="Export as CSV">
            <i class="fa-solid fa-file-csv me-1"></i> Export CSV
        </a>
        <button onclick="window.print()" class="btn btn-outline-secondary btn-sm" title="Print Current View">
            <i class="fa-solid fa-print me-1"></i> Print
        </button>
        <a href="record_add.php" class="btn btn-primary btn-sm">
            <i class="fa-solid fa-user-plus me-1"></i> Add New Person
        </a>
    </div>
</div>

<!-- Search & Filter Controls -->
<div class="card mb-4 shadow-sm border-0">
    <div class="card-body p-3">
        <form method="GET" action="records.php" class="row g-2 align-items-center">
            <div class="col-lg-4 col-md-6">
                <div class="input-group">
                    <span class="input-group-text bg-white text-muted"><i class="fa-solid fa-magnifying-glass"></i></span>
                    <input type="text" name="search" id="tableSearchInput" data-table-target="#recordsTable" class="form-control" placeholder="Search by name, QID, phone, company..." value="<?= htmlspecialchars($search) ?>">
                </div>
            </div>

            <div class="col-lg-3 col-md-6">
                <select name="filter_expiry" class="form-select" onchange="this.form.submit()">
                    <option value="">All Expiry Statuses</option>
                    <option value="expired" <?= ($filter_expiry === 'expired') ? 'selected' : '' ?>>Expired Only</option>
                    <option value="expiring" <?= ($filter_expiry === 'expiring') ? 'selected' : '' ?>>Expiring Soon (&le; <?= $alert_days ?>d)</option>
                    <option value="valid" <?= ($filter_expiry === 'valid') ? 'selected' : '' ?>>Valid / Safe</option>
                </select>
            </div>

            <div class="col-lg-3 col-md-6">
                <select name="filter_payment" class="form-select" onchange="this.form.submit()">
                    <option value="">All Payment Statuses</option>
                    <option value="unpaid" <?= ($filter_payment === 'unpaid') ? 'selected' : '' ?>>Unpaid (0 Received)</option>
                    <option value="partial" <?= ($filter_payment === 'partial') ? 'selected' : '' ?>>Partially Paid</option>
                    <option value="paid" <?= ($filter_payment === 'paid') ? 'selected' : '' ?>>Fully Settled / Paid</option>
                </select>
            </div>

            <div class="col-lg-2 col-md-6 d-flex gap-1">
                <button type="submit" class="btn btn-primary btn-sm flex-grow-1">Filter</button>
                <?php if (!empty($search) || !empty($filter_expiry) || !empty($filter_payment)): ?>
                    <a href="records.php" class="btn btn-outline-secondary btn-sm" title="Clear Filters">
                        <i class="fa-solid fa-xmark"></i>
                    </a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Records Data Table -->
<div class="card shadow-sm">
    <div class="card-body p-0">
        <?php if (empty($records)): ?>
            <div class="text-center py-5 text-muted">
                <i class="fa-solid fa-folder-open fa-3x text-secondary mb-3"></i>
                <h5 class="text-dark fw-bold">No Records Found</h5>
                <p class="small text-muted mb-3">No entries match your search criteria or the database is empty.</p>
                <a href="record_add.php" class="btn btn-primary btn-sm">
                    <i class="fa-solid fa-plus me-1"></i> Add First Record
                </a>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-custom table-hover align-middle mb-0" id="recordsTable">
                    <thead>
                        <tr>
                            <th>Person Details</th>
                            <th>QID & Expiry</th>
                            <th>Cost vs Charge</th>
                            <th>Our Profit</th>
                            <th>Paid & Balance</th>
                            <th>Payment Due Date</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($records as $row): ?>
                            <tr>
                                <td>
                                    <div class="fw-bold text-dark fs-6">
                                        <a href="record_detail.php?id=<?= $row['id'] ?>" class="text-decoration-none text-dark">
                                            <?= htmlspecialchars($row['full_name']) ?>
                                        </a>
                                    </div>
                                    <div class="small text-muted">
                                        <?php if (!empty($row['company_name'])): ?>
                                            <span><i class="fa-solid fa-building me-1"></i><?= htmlspecialchars($row['company_name']) ?></span> &bull; 
                                        <?php endif; ?>
                                        <span><i class="fa-solid fa-phone me-1"></i><?= htmlspecialchars($row['phone_number'] ?? 'N/A') ?></span>
                                    </div>
                                </td>
                                <td>
                                    <div class="fw-semibold font-monospace fs-6">
                                        <?= htmlspecialchars($row['qid_number']) ?>
                                    </div>
                                    <div class="mt-1">
                                        <?= $row['expiry_badge'] ?>
                                    </div>
                                    <small class="text-muted d-block mt-1">Exp: <?= format_date($row['expiry_date']) ?></small>
                                </td>
                                <td>
                                    <div class="fw-bold text-dark">Charge: <?= format_currency($row['charge_amount']) ?></div>
                                    <small class="text-muted">Cost: <?= format_currency($row['actual_cost']) ?></small>
                                </td>
                                <td>
                                    <span class="fw-bold <?= ($row['expected_profit'] >= 0) ? 'text-success' : 'text-danger' ?>">
                                        <?= format_currency($row['expected_profit']) ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="fw-bold text-success">Paid: <?= format_currency($row['total_paid']) ?></div>
                                    <div class="small">
                                        Remaining: <strong class="<?= ($row['remaining_balance'] > 0) ? 'text-danger' : 'text-muted' ?>">
                                            <?= format_currency($row['remaining_balance']) ?>
                                        </strong>
                                    </div>
                                    <div class="mt-1">
                                        <?= $row['payment_badge'] ?>
                                    </div>
                                </td>
                                
                                <td class="text-end">
                                    <div class="btn-group btn-group-sm">
                                        <a href="record_detail.php?id=<?= $row['id'] ?>" class="btn btn-outline-primary" title="View Full Profile & Installments">
                                            <i class="fa-solid fa-eye"></i>
                                        </a>
                                        <a href="statement.php?id=<?= $row['id'] ?>" target="_blank" class="btn btn-outline-danger" title="Official Statement PDF">
                                            <i class="fa-solid fa-file-pdf"></i>
                                        </a>
                                        <a href="record_edit.php?id=<?= $row['id'] ?>" class="btn btn-outline-secondary" title="Edit Record">
                                            <i class="fa-solid fa-pen"></i>
                                        </a>
                                        <button type="button" class="btn btn-outline-danger btn-delete-modal-trigger" 
                                                title="Delete Record (Password Protected)"
                                                data-bs-toggle="modal" 
                                                data-bs-target="#deleteRecordModal"
                                                data-id="<?= $row['id'] ?>"
                                                data-name="<?= htmlspecialchars($row['full_name'], ENT_QUOTES) ?>"
                                                data-qid="<?= htmlspecialchars($row['qid_number'], ENT_QUOTES) ?>"
                                                data-paid="<?= format_currency($row['total_paid']) ?>"
                                                data-balance="<?= format_currency($row['remaining_balance']) ?>">
                                            <i class="fa-solid fa-trash"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="p-3 bg-light border-top text-muted small d-flex justify-content-between align-items-center">
                <span>Showing <strong><?= count($records) ?></strong> total records</span>
                <span class="fst-italic">Live search active across visible fields</span>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Modal: Security Verification Delete Record -->
<div class="modal fade" id="deleteRecordModal" tabindex="-1" aria-labelledby="deleteRecordModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <form method="POST" action="records.php">
                <input type="hidden" name="action" value="delete_record_secure">
                <input type="hidden" name="record_id" id="modalDeleteRecordId" value="">

                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title fw-bold" id="deleteRecordModalLabel">
                        <i class="fa-solid fa-triangle-exclamation me-1"></i> Security Verification: Confirm Deletion
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body p-4">
                    <div class="p-3 bg-danger-subtle rounded-3 border border-danger-subtle mb-3">
                        <div class="d-flex align-items-start gap-3">
                            <i class="fa-solid fa-radiation text-danger fs-3 mt-1"></i>
                            <div>
                                <h6 class="fw-bold text-danger mb-1">Permanent Data Destruction Warning</h6>
                                <p class="small text-danger-emphasis mb-0">
                                    You are about to permanently erase the QID profile, all payment installment logs, and PDF statements for this person. This action cannot be reversed.
                                </p>
                            </div>
                        </div>
                    </div>

                    <div class="p-3 bg-light rounded-3 border mb-3">
                        <div class="row g-2 small">
                            <div class="col-6">
                                <span class="text-muted text-uppercase fw-bold" style="font-size: 0.7rem;">Target Beneficiary:</span>
                                <div class="fw-bold text-dark fs-6" id="modalDeleteName">—</div>
                            </div>
                            <div class="col-6">
                                <span class="text-muted text-uppercase fw-bold" style="font-size: 0.7rem;">Qatar ID (QID):</span>
                                <div class="font-monospace fw-bold text-primary" id="modalDeleteQid">—</div>
                            </div>
                            <div class="col-6 mt-2">
                                <span class="text-muted text-uppercase fw-bold" style="font-size: 0.7rem;">Total Paid to Date:</span>
                                <div class="fw-bold text-success" id="modalDeletePaid">—</div>
                            </div>
                            <div class="col-6 mt-2">
                                <span class="text-muted text-uppercase fw-bold" style="font-size: 0.7rem;">Balance Remaining:</span>
                                <div class="fw-bold text-danger" id="modalDeleteBalance">—</div>
                            </div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold text-danger">
                            <i class="fa-solid fa-lock me-1"></i> Enter Admin Deletion Password to Proceed <span class="text-danger">*</span>
                        </label>
                        <div class="input-group">
                            <span class="input-group-text bg-white text-muted"><i class="fa-solid fa-key"></i></span>
                            <input type="password" name="delete_password" id="modalDeletePassword" class="form-control font-monospace" placeholder="Enter security authorization password" required autocomplete="current-password">
                            <button class="btn btn-outline-secondary" type="button" onclick="const p = document.getElementById('modalDeletePassword'); p.type = p.type === 'password' ? 'text' : 'password';">
                                <i class="fa-solid fa-eye"></i>
                            </button>
                        </div>
                        <div class="form-text small text-muted">
                            <i class="fa-solid fa-shield-halved text-muted me-1"></i>Administrator verification required. Configurable in <a href="settings.php" target="_blank">Settings</a>.
                        </div>
                    </div>
                </div>

                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger px-4 fw-bold">
                        <i class="fa-solid fa-trash-can me-1"></i> Confirm & Permanently Delete
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
