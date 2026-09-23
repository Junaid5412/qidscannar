<?php
/**
 * QID Management System - Add New Record
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

$page_title = 'Add New QID Record';

$currency = get_setting('currency', 'QR');
$error = '';
$prefill_qid = trim($_GET['prefill_qid'] ?? '');
$prefill_name = trim($_GET['prefill_name'] ?? '');
$prefill_nationality = trim($_GET['prefill_nationality'] ?? '');
$prefill_job = trim($_GET['prefill_job'] ?? '');
$prefill_expiry = trim($_GET['prefill_expiry'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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

    // Initial payment optional fields
    $has_initial_pay = isset($_POST['has_initial_pay']);
    $initial_amount = (float)($_POST['initial_amount'] ?? 0);
    $initial_date = $_POST['initial_date'] ?? date('Y-m-d');
    $initial_time = $_POST['initial_time'] ?? date('H:i');
    $initial_method = $_POST['initial_method'] ?? 'Cash';
    $initial_receipt = trim($_POST['initial_receipt'] ?? '');
    $initial_notes = trim($_POST['initial_notes'] ?? '');

    if (empty($full_name) || empty($qid_number) || empty($expiry_date)) {
        $error = 'Please fill in all required fields: Name, QID Number, and Expiry Date.';
    } else {
        try {
            $db = getDB();
            $db->beginTransaction();

            $stmt = $db->prepare("
                INSERT INTO qid_records 
                (full_name, qid_number, phone_number, company_name, job_title, nationality, expiry_date,  charge_amount, actual_cost, security_deposit, status, notes)
                VALUES 
                (:name, :qid, :phone, :company, :job, :nat, :exp,  :charge, :cost, :security_deposit, :status, :notes)
            ");
            $stmt->execute([
                ':name'             => $full_name,
                ':qid'              => $qid_number,
                ':phone'            => $phone_number,
                ':company'          => $company_name,
                ':job'              => $job_title,
                ':nat'              => $nationality,
                ':exp'              => $expiry_date,
                ':charge'           => $charge_amount,
                ':cost'             => $actual_cost,
                ':security_deposit' => (float)($_POST['security_deposit'] ?? 0),
                ':status'           => $status,
                ':notes'            => $notes
            ]);

            $record_id = $db->lastInsertId();

            // Insert initial payment if provided
            if ($has_initial_pay && $initial_amount > 0) {
                $pay_stmt = $db->prepare("
                    INSERT INTO payments (qid_record_id, amount, payment_date, payment_time, payment_method, receipt_no, notes)
                    VALUES (:rid, :amt, :pdate, :ptime, :method, :rcp, :pnotes)
                ");
                $pay_stmt->execute([
                    ':rid'    => $record_id,
                    ':amt'    => $initial_amount,
                    ':pdate'  => $initial_date,
                    ':ptime'  => $initial_time,
                    ':method' => $initial_method,
                    ':rcp'    => $initial_receipt ?: ('RCP-' . date('Ymd') . '-' . $record_id),
                    ':pnotes' => $initial_notes ?: 'Initial installment on registration'
                ]);
            }

            $db->commit();

            // Trigger auto backup hook (Google Drive + Local)
            trigger_auto_backup_if_enabled();

            set_flash('success', 'QID Record for ' . htmlspecialchars($full_name) . ' was successfully created!');
            header("Location: record_detail.php?id=" . $record_id);
            exit;

        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $error = 'Database error: ' . $e->getMessage();
        }
    }
}

include __DIR__ . '/includes/header.php';
?>

<div class="row justify-content-center">
    <div class="col-lg-10 col-xl-9">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h3 class="fw-bold mb-1 text-dark">Add New QID Record</h3>
                <p class="text-muted mb-0 small">Create a new resident profile with financial agreement terms</p>
            </div>
            <a href="records.php" class="btn btn-outline-secondary btn-sm">
                <i class="fa-solid fa-arrow-left me-1"></i> Back to List
            </a>
        </div>

        <?php if (!empty($error)): ?>
            <div class="alert alert-danger d-flex align-items-center mb-4" role="alert">
                <i class="fa-solid fa-circle-exclamation me-2 fs-5"></i>
                <div><?= htmlspecialchars($error) ?></div>
            </div>
        <?php endif; ?>

        <form method="POST" action="record_add.php" id="addQidForm">
            <!-- 1. Personal & QID Information -->
            <div class="card shadow-sm mb-4">
                <div class="card-header-clean">
                    <h5><i class="fa-solid fa-user-gear text-primary"></i> Personal & Identification Details</h5>
                </div>
                <div class="card-body p-4">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Full Name <span class="text-danger">*</span></label>
                            <input type="text" name="full_name" class="form-control" placeholder="e.g. Mohammed Al-Kuwari" required value="<?= htmlspecialchars($_POST['full_name'] ?? $prefill_name) ?>">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Qatar ID (QID Number) <span class="text-danger">*</span></label>
                            <input type="text" name="qid_number" class="form-control font-monospace" placeholder="11-digit QID number (e.g. 28863401234)" required value="<?= htmlspecialchars($_POST['qid_number'] ?? $prefill_qid) ?>">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">QID Expiry Date <span class="text-danger">*</span></label>
                            <input type="date" name="expiry_date" class="form-control" required value="<?= htmlspecialchars($_POST['expiry_date'] ?? $prefill_expiry) ?>">
                            <div class="form-text">System alerts when expiry is within configured days.</div>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Phone / WhatsApp Number</label>
                            <input type="text" name="phone_number" class="form-control" placeholder="+974 5500 0000" value="<?= htmlspecialchars($_POST['phone_number'] ?? '') ?>">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Nationality</label>
                            <input type="text" name="nationality" class="form-control" placeholder="e.g. Qatari, Egyptian, Indian" value="<?= htmlspecialchars($_POST['nationality'] ?? $prefill_nationality) ?>">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Company / Sponsor Name</label>
                            <input type="text" name="company_name" class="form-control" placeholder="e.g. Al Noor Trading W.L.L" value="<?= htmlspecialchars($_POST['company_name'] ?? '') ?>">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Job Title / Designation</label>
                            <input type="text" name="job_title" class="form-control" placeholder="e.g. Sales Executive / Engineer" value="<?= htmlspecialchars($_POST['job_title'] ?? $prefill_job) ?>">
                        </div>
                    </div>
                </div>
            </div>

            <!-- 2. Financial Terms -->
            <div class="card shadow-sm mb-4">
                <div class="card-header-clean">
                    <h5><i class="fa-solid fa-coins text-success"></i> Financial Pricing & Terms</h5>
                </div>
                <div class="card-body p-4">
                    <div class="row g-3 align-items-center">
                        <div class="col-md-4">
                            <label class="form-label">Amount To Collect (Charge) <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <input type="number" step="0.01" name="charge_amount" id="charge_amount" class="form-control fw-bold" placeholder="2500.00" required value="<?= htmlspecialchars($_POST['charge_amount'] ?? '2500.00') ?>">
                                <span class="input-group-text bg-light text-muted fw-bold"><?= htmlspecialchars($currency) ?></span>
                            </div>
                            <div class="form-text">Total fee agreed with client.</div>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Actual Processing Cost <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <input type="number" step="0.01" name="actual_cost" id="actual_cost" class="form-control" placeholder="1220.00" required value="<?= htmlspecialchars($_POST['actual_cost'] ?? '1220.00') ?>">
                                <span class="input-group-text bg-light text-muted fw-bold"><?= htmlspecialchars($currency) ?></span>
                            </div>
                            <div class="form-text">Government or service cost to us.</div>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Security Deposit Amount</label>
                            <div class="input-group">
                                <span class="input-group-text bg-light"><i class="fa-solid fa-shield-halved text-info"></i></span>
                                <input type="number" name="security_deposit" id="security_deposit" class="form-control" step="0.01" min="0" value="0.00">
                                <span class="input-group-text bg-light"><?= htmlspecialchars($currency) ?></span>
                            </div>
                            <div class="form-text">Refundable deposit collected from client</div>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Expected Net Profit</label>
                            <div class="p-2 px-3 bg-light rounded border">
                                <div class="fw-bold fs-5 text-success">
                                    <span id="expected_profit_display">1,280.00</span> <?= htmlspecialchars($currency) ?>
                                </div>
                                <small class="text-muted">(Charge minus Actual Cost)</small>
                            </div>
                        </div>

                        <div class="col-md-12">
                            <label class="form-label">Record Status</label>
                            <select name="status" class="form-select">
                                <option value="Active" selected>Active</option>
                                <option value="Renewed">Renewed</option>
                                <option value="Expired">Expired</option>
                                <option value="Cancelled">Cancelled</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 3. Optional Initial Installment Payment -->
            <div class="card shadow-sm mb-4 border-info">
                <div class="card-header bg-info-subtle border-bottom border-info-subtle d-flex justify-content-between align-items-center py-3">
                    <div class="form-check m-0">
                        <input class="form-check-input" type="checkbox" name="has_initial_pay" id="has_initial_pay" value="1" onchange="document.getElementById('initialPayFields').style.display = this.checked ? 'block' : 'none';">
                        <label class="form-check-label fw-bold text-dark" for="has_initial_pay">
                            <i class="fa-solid fa-receipt me-1 text-primary"></i> Record Initial Advance Payment Now (Installment #1)
                        </label>
                    </div>
                    <span class="badge bg-info text-white">Optional</span>
                </div>
                <div class="card-body p-4" id="initialPayFields" style="display: none;">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Paid Amount</label>
                            <div class="input-group">
                                <input type="number" step="0.01" name="initial_amount" id="initial_payment" class="form-control fw-bold text-success" placeholder="500.00" value="500.00">
                                <span class="input-group-text bg-light fw-bold"><?= htmlspecialchars($currency) ?></span>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Payment Date</label>
                            <input type="date" name="initial_date" class="form-control" value="<?= date('Y-m-d') ?>">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Payment Time</label>
                            <input type="time" name="initial_time" class="form-control" value="<?= date('H:i') ?>">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Payment Method</label>
                            <select name="initial_method" class="form-select">
                                <option value="Cash" selected>Cash</option>
                                <option value="Bank Transfer">Bank Transfer</option>
                                <option value="Card">Credit / Debit Card</option>
                                <option value="Cheque">Cheque</option>
                                <option value="Online">Online Payment</option>
                            </select>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Receipt / Voucher Number</label>
                            <input type="text" name="initial_receipt" class="form-control font-monospace" placeholder="e.g. RCP-<?= date('Y') ?>-001">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Estimated Remaining Balance</label>
                            <div class="p-2 px-3 bg-light rounded border">
                                <div class="fw-bold text-danger">
                                    <span id="remaining_balance_display">2,000.00</span> <?= htmlspecialchars($currency) ?>
                                </div>
                                <small class="text-muted">(Will update automatically)</small>
                            </div>
                        </div>

                        <div class="col-12">
                            <label class="form-label">Payment Notes</label>
                            <input type="text" name="initial_notes" class="form-control" placeholder="e.g. Paid in cash at counter, balance to be collected before completion">
                        </div>
                    </div>
                </div>
            </div>

            <!-- Notes & Submission -->
            <div class="card shadow-sm mb-4">
                <div class="card-body p-4">
                    <div class="mb-3">
                        <label class="form-label">General Notes / Remarks</label>
                        <textarea name="notes" class="form-control" rows="3" placeholder="Optional comments regarding this person or documents..."><?= htmlspecialchars($_POST['notes'] ?? '') ?></textarea>
                    </div>

                    <div class="d-flex justify-content-end gap-2">
                        <a href="records.php" class="btn btn-outline-secondary">Cancel</a>
                        <button type="submit" class="btn btn-primary px-4">
                            <i class="fa-solid fa-check me-1"></i> Save QID Record
                        </button>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
