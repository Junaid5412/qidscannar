<?php
/**
 * QID Management System - Edit Record & Terms
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

$id = (int)($_GET['id'] ?? 0);
$record = get_qid_record($id);

if (!$record) {
    set_flash('danger', 'Record not found.');
    header("Location: records.php");
    exit;
}

$page_title = 'Edit ' . htmlspecialchars($record['full_name']);
$currency = get_setting('currency', 'QR');
$error = '';

$db = getDB();

// Handle Record Deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_record') {
    try {
        $del_stmt = $db->prepare("DELETE FROM qid_records WHERE id = :id");
        $del_stmt->execute([':id' => $id]);

        trigger_auto_backup_if_enabled();

        set_flash('success', 'Record for ' . htmlspecialchars($record['full_name']) . ' was successfully deleted.');
        header("Location: records.php");
        exit;
    } catch (Exception $e) {
        $error = 'Failed to delete record: ' . $e->getMessage();
    }
}

// Handle Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (!isset($_POST['action']) || $_POST['action'] === 'update')) {
    $full_name = trim($_POST['full_name'] ?? '');
    $qid_number = trim($_POST['qid_number'] ?? '');
    $expiry_date = trim($_POST['expiry_date'] ?? '');
    $phone_number = trim($_POST['phone_number'] ?? '');
    $company_name = trim($_POST['company_name'] ?? '');
    $job_title = trim($_POST['job_title'] ?? '');
    $nationality = trim($_POST['nationality'] ?? '');
    
    $charge_amount = (float)($_POST['charge_amount'] ?? 0);
    $actual_cost = (float)($_POST['actual_cost'] ?? 0);
    $status = $_POST['status'] ?? 'Active';
    $notes = trim($_POST['notes'] ?? '');

    if (empty($full_name) || empty($qid_number) || empty($expiry_date)) {
        $error = 'Name, QID Number, and Expiry Date are required.';
    } else {
        try {
            $stmt = $db->prepare("
                UPDATE qid_records 
                SET full_name = :name,
                    qid_number = :qid,
                    phone_number = :phone,
                    company_name = :company,
                    job_title = :job,
                    nationality = :nat,
                    expiry_date = :exp,
                    
                    charge_amount = :charge,
                    actual_cost = :cost,
                    status = :status,
                    notes = :notes
                WHERE id = :id
            ");
            $stmt->execute([
                ':name'    => $full_name,
                ':qid'     => $qid_number,
                ':phone'   => $phone_number,
                ':company' => $company_name,
                ':job'     => $job_title,
                ':nat'     => $nationality,
                ':exp'     => $expiry_date,
                
                ':charge'  => $charge_amount,
                ':cost'    => $actual_cost,
                ':status'  => $status,
                ':notes'   => $notes,
                ':id'      => $id
            ]);

            trigger_auto_backup_if_enabled();

            set_flash('success', 'Profile updated successfully.');
            header("Location: record_detail.php?id=" . $id);
            exit;

        } catch (Exception $e) {
            $error = 'Database update error: ' . $e->getMessage();
        }
    }
}

include __DIR__ . '/includes/header.php';
?>

<div class="row justify-content-center">
    <div class="col-lg-10 col-xl-9">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h3 class="fw-bold mb-1 text-dark">Edit QID Record</h3>
                <p class="text-muted mb-0 small">Updating information for <strong><?= htmlspecialchars($record['full_name']) ?></strong></p>
            </div>
            <div class="d-flex gap-2">
                <a href="record_detail.php?id=<?= $record['id'] ?>" class="btn btn-outline-secondary btn-sm">
                    <i class="fa-solid fa-arrow-left me-1"></i> Back to Profile
                </a>
            </div>
        </div>

        <?php if (!empty($error)): ?>
            <div class="alert alert-danger d-flex align-items-center mb-4" role="alert">
                <i class="fa-solid fa-circle-exclamation me-2 fs-5"></i>
                <div><?= htmlspecialchars($error) ?></div>
            </div>
        <?php endif; ?>

        <form method="POST" action="record_edit.php?id=<?= $record['id'] ?>">
            <input type="hidden" name="action" value="update">

            <!-- Personal & Identification Details -->
            <div class="card shadow-sm mb-4">
                <div class="card-header-clean">
                    <h5><i class="fa-solid fa-user-pen text-primary"></i> Personal & Identification Details</h5>
                </div>
                <div class="card-body p-4">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Full Name <span class="text-danger">*</span></label>
                            <input type="text" name="full_name" class="form-control" required value="<?= htmlspecialchars($record['full_name']) ?>">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Qatar ID (QID Number) <span class="text-danger">*</span></label>
                            <input type="text" name="qid_number" class="form-control font-monospace" required value="<?= htmlspecialchars($record['qid_number']) ?>">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">QID Expiry Date <span class="text-danger">*</span></label>
                            <input type="date" name="expiry_date" class="form-control" required value="<?= htmlspecialchars($record['expiry_date']) ?>">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Phone Number</label>
                            <input type="text" name="phone_number" class="form-control" value="<?= htmlspecialchars($record['phone_number'] ?? '') ?>">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Nationality</label>
                            <input type="text" name="nationality" class="form-control" value="<?= htmlspecialchars($record['nationality'] ?? '') ?>">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Company / Sponsor</label>
                            <input type="text" name="company_name" class="form-control" value="<?= htmlspecialchars($record['company_name'] ?? '') ?>">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Job Title</label>
                            <input type="text" name="job_title" class="form-control" value="<?= htmlspecialchars($record['job_title'] ?? '') ?>">
                        </div>
                    </div>
                </div>
            </div>

            <!-- Financial Terms & Due Date -->
            <div class="card shadow-sm mb-4">
                <div class="card-header-clean">
                    <h5><i class="fa-solid fa-coins text-success"></i> Financial Pricing & Collection Terms</h5>
                </div>
                <div class="card-body p-4">
                    <div class="row g-3 align-items-center">
                        <div class="col-md-4">
                            <label class="form-label">Agreed Charge (Receivable) <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <input type="number" step="0.01" name="charge_amount" id="charge_amount" class="form-control fw-bold" required value="<?= htmlspecialchars($record['charge_amount']) ?>">
                                <span class="input-group-text bg-light fw-bold"><?= htmlspecialchars($currency) ?></span>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Actual Processing Cost <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <input type="number" step="0.01" name="actual_cost" id="actual_cost" class="form-control" required value="<?= htmlspecialchars($record['actual_cost']) ?>">
                                <span class="input-group-text bg-light fw-bold"><?= htmlspecialchars($currency) ?></span>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Expected Net Profit</label>
                            <div class="p-2 px-3 bg-light rounded border">
                                <div class="fw-bold fs-5 text-success">
                                    <span id="expected_profit_display"><?= number_format($record['expected_profit'], 2) ?></span> <?= htmlspecialchars($currency) ?>
                                </div>
                                <small class="text-muted">(Charge minus Actual Cost)</small>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Payment Due Date</label>
                            <input type="date" name="payment_due_date" class="form-control" value="<?= htmlspecialchars($record['payment_due_date'] ?? '') ?>">
                            <div class="form-text">Reminder triggers on Dashboard when pending balance &le; configured days.</div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Status</label>
                            <select name="status" class="form-select">
                                <option value="Active" <?= ($record['status'] === 'Active') ? 'selected' : '' ?>>Active</option>
                                <option value="Renewed" <?= ($record['status'] === 'Renewed') ? 'selected' : '' ?>>Renewed</option>
                                <option value="Expired" <?= ($record['status'] === 'Expired') ? 'selected' : '' ?>>Expired</option>
                                <option value="Cancelled" <?= ($record['status'] === 'Cancelled') ? 'selected' : '' ?>>Cancelled</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Notes & Submission -->
            <div class="card shadow-sm mb-4">
                <div class="card-body p-4">
                    <div class="mb-3">
                        <label class="form-label">Notes & Remarks</label>
                        <textarea name="notes" class="form-control" rows="3"><?= htmlspecialchars($record['notes'] ?? '') ?></textarea>
                    </div>

                    <div class="d-flex justify-content-between align-items-center">
                        <button type="button" class="btn btn-outline-danger btn-sm" onclick="if(confirm('Are you sure you want to permanently delete this person and all their payment installments? This cannot be undone.')) { document.getElementById('deleteRecordForm').submit(); }">
                            <i class="fa-solid fa-trash me-1"></i> Delete Person Record
                        </button>
                        <div class="d-flex gap-2">
                            <a href="record_detail.php?id=<?= $record['id'] ?>" class="btn btn-outline-secondary">Cancel</a>
                            <button type="submit" class="btn btn-primary px-4">
                                <i class="fa-solid fa-check me-1"></i> Update Changes
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </form>

        <form id="deleteRecordForm" method="POST" action="record_edit.php?id=<?= $record['id'] ?>" style="display:none;">
            <input type="hidden" name="action" value="delete_record">
        </form>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
