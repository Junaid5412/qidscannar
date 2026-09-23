<?php
/**
 * QID Management System - Excel / CSV Bulk Data Importer
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

require_login();

$db = getDB();
$currency = get_setting('currency', 'QR');

// 1. Download Sample Excel / CSV Template
if (isset($_GET['action']) && $_GET['action'] === 'template') {
    $filename = 'qid_import_sample_template.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $output = fopen('php://output', 'w');
    // UTF-8 BOM for Microsoft Excel auto-detection
    fputs($output, "\xEF\xBB\xBF");

    // Header Columns
    fputcsv($output, [
        'Full Name',
        'QID Number',
        'Phone Number',
        'Company Name',
        'Job Title',
        'Nationality',
        'Expiry Date',
        'Agreed Charge',
        'Actual Cost',
        'Initial Paid',
        'Status',
        'Notes'
    ]);

    // Sample Row 1
    fputcsv($output, [
        'Mohammed Al-Kuwari',
        '28863401234',
        '+974 5511 2233',
        'Al Noor Trading W.L.L',
        'Sales Executive',
        'Qatari',
        date('Y-m-d', strtotime('+1 year')),
        '2500.00',
        '1220.00',
        '500.00',
        'Active',
        'First batch renewal'
    ]);

    // Sample Row 2
    fputcsv($output, [
        'Ahmed Tariq',
        '29258602345',
        '+974 6622 3344',
        'Gulf Falcon Contracting',
        'Civil Engineer',
        'Egyptian',
        date('Y-m-d', strtotime('+6 months')),
        '1800.00',
        '1000.00',
        '1800.00',
        'Active',
        'Full advance paid'
    ]);

    // Sample Row 3
    fputcsv($output, [
        'Rajesh Patel',
        '28535603456',
        '+974 7733 4455',
        'Doha Express Logistics',
        'Operations Supervisor',
        'Indian',
        date('Y-m-d', strtotime('+90 days')),
        '3200.00',
        '1600.00',
        '0.00',
        'Active',
        'Pending payment'
    ]);

    fclose($output);
    exit;
}

$import_results = null;
$error_message = '';

// Helper to normalize various date formats from Excel (YYYY-MM-DD, DD/MM/YYYY, DD-MM-YYYY, etc.)
function parse_excel_date($raw) {
    $raw = trim($raw);
    if (empty($raw)) return null;

    // YYYY-MM-DD
    if (preg_match('/^(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})$/', $raw, $m)) {
        return sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
    }
    // DD/MM/YYYY or DD-MM-YYYY
    if (preg_match('/^(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{4})$/', $raw, $m)) {
        return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
    }

    $ts = strtotime($raw);
    return $ts ? date('Y-m-d', $ts) : null;
}

// 2. Process Uploaded CSV / Excel file
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'import_csv') {
    if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        $error_message = 'Please select a valid CSV or Excel file to upload.';
    } else {
        $file_tmp = $_FILES['csv_file']['tmp_name'];
        $file_name = $_FILES['csv_file']['name'];
        $ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

        if (!in_array($ext, ['csv', 'txt'])) {
            $error_message = 'Invalid file format. Please upload a .csv file (or save your Excel sheet as CSV UTF-8).';
        } else {
            $duplicate_action = $_POST['duplicate_action'] ?? 'skip'; // 'skip' or 'update'

            $handle = fopen($file_tmp, 'r');
            if ($handle === false) {
                $error_message = 'Unable to open the uploaded file.';
            } else {
                // Strip UTF-8 BOM if present
                $bom = fread($handle, 3);
                if ($bom !== "\xEF\xBB\xBF") {
                    rewind($handle);
                }

                $total_rows = 0;
                $inserted_count = 0;
                $updated_count = 0;
                $skipped_count = 0;
                $skipped_details = [];

                $db->beginTransaction();

                try {
                    $header = fgetcsv($handle, 4096, ',');
                    
                    // Detect delimiter if comma failed (e.g. semicolon in European Excel)
                    if ($header && count($header) === 1 && strpos($header[0], ';') !== false) {
                        rewind($handle);
                        $bom = fread($handle, 3);
                        if ($bom !== "\xEF\xBB\xBF") rewind($handle);
                        $delimiter = ';';
                        $header = fgetcsv($handle, 4096, ';');
                    } else {
                        $delimiter = ',';
                    }

                    // Map columns based on header titles or fallback to standard indices
                    $col_map = [
                        'name'        => 0,
                        'qid'         => 1,
                        'phone'       => 2,
                        'company'     => 3,
                        'job'         => 4,
                        'nationality' => 5,
                        'expiry'      => 6,
                        'charge'      => 7,
                        'cost'        => 8,
                        'paid'        => 9,
                        'status'      => 10,
                        'notes'       => 11
                    ];

                    if ($header) {
                        foreach ($header as $idx => $title) {
                            $clean_title = strtolower(trim(preg_replace('/[^a-zA-Z0-9]/', '', $title)));
                            if (strpos($clean_title, 'fullname') !== false || strpos($clean_title, 'person') !== false || strpos($clean_title, 'name') !== false) {
                                $col_map['name'] = $idx;
                            } elseif (strpos($clean_title, 'qid') !== false) {
                                $col_map['qid'] = $idx;
                            } elseif (strpos($clean_title, 'phone') !== false || strpos($clean_title, 'mobile') !== false) {
                                $col_map['phone'] = $idx;
                            } elseif (strpos($clean_title, 'company') !== false || strpos($clean_title, 'sponsor') !== false) {
                                $col_map['company'] = $idx;
                            } elseif (strpos($clean_title, 'job') !== false || strpos($clean_title, 'title') !== false || strpos($clean_title, 'occupation') !== false) {
                                $col_map['job'] = $idx;
                            } elseif (strpos($clean_title, 'nat') !== false) {
                                $col_map['nationality'] = $idx;
                            } elseif (strpos($clean_title, 'expir') !== false) {
                                $col_map['expiry'] = $idx;
                            } elseif (strpos($clean_title, 'charge') !== false || strpos($clean_title, 'price') !== false || strpos($clean_title, 'fee') !== false) {
                                $col_map['charge'] = $idx;
                            } elseif (strpos($clean_title, 'cost') !== false) {
                                $col_map['cost'] = $idx;
                            } elseif (strpos($clean_title, 'paid') !== false || strpos($clean_title, 'deposit') !== false || strpos($clean_title, 'collected') !== false) {
                                $col_map['paid'] = $idx;
                            } elseif (strpos($clean_title, 'status') !== false) {
                                $col_map['status'] = $idx;
                            } elseif (strpos($clean_title, 'note') !== false || strpos($clean_title, 'remark') !== false) {
                                $col_map['notes'] = $idx;
                            }
                        }
                    }

                    $insert_stmt = $db->prepare("
                        INSERT INTO qid_records 
                        (full_name, qid_number, phone_number, company_name, job_title, nationality, expiry_date, charge_amount, actual_cost, status, notes)
                        VALUES 
                        (:name, :qid, :phone, :company, :job, :nat, :exp, :charge, :cost, :status, :notes)
                    ");

                    $update_stmt = $db->prepare("
                        UPDATE qid_records 
                        SET full_name = :name,
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

                    $find_stmt = $db->prepare("SELECT id FROM qid_records WHERE qid_number = ? LIMIT 1");
                    $pay_stmt = $db->prepare("
                        INSERT INTO payments (qid_record_id, amount, payment_date, payment_time, payment_method, receipt_no, notes)
                        VALUES (:rid, :amt, CURDATE(), CURTIME(), 'Cash', :rcp, 'Imported opening installment')
                    ");

                    $row_idx = 1;
                    while (($row = fgetcsv($handle, 4096, $delimiter)) !== false) {
                        $row_idx++;
                        // Skip empty rows
                        if (empty(array_filter($row))) continue;
                        $total_rows++;

                        $full_name = trim($row[$col_map['name']] ?? '');
                        $qid_raw = trim($row[$col_map['qid']] ?? '');
                        $clean_qid = preg_replace('/[^\d]/', '', $qid_raw);

                        if (empty($full_name)) {
                            $skipped_count++;
                            $skipped_details[] = "Row {$row_idx}: Skipped because Full Name is empty.";
                            continue;
                        }

                        if (empty($clean_qid)) {
                            $skipped_count++;
                            $skipped_details[] = "Row {$row_idx} ({$full_name}): Skipped because QID number is invalid or missing.";
                            continue;
                        }

                        $expiry_raw = $row[$col_map['expiry']] ?? '';
                        $expiry_date = parse_excel_date($expiry_raw) ?: date('Y-m-d', strtotime('+1 year'));

                        $phone_number = trim($row[$col_map['phone']] ?? '');
                        $company_name = trim($row[$col_map['company']] ?? '');
                        $job_title = trim($row[$col_map['job']] ?? '');
                        $nationality = trim($row[$col_map['nationality']] ?? '');
                        $charge_amount = (float)str_replace([',', ' '], '', $row[$col_map['charge']] ?? 0);
                        $actual_cost = (float)str_replace([',', ' '], '', $row[$col_map['cost']] ?? 0);
                        $initial_paid = (float)str_replace([',', ' '], '', $row[$col_map['paid']] ?? 0);
                        
                        $status_raw = ucfirst(strtolower(trim($row[$col_map['status']] ?? 'Active')));
                        $allowed_statuses = ['Active', 'Expired', 'Renewed', 'Cancelled'];
                        $status = in_array($status_raw, $allowed_statuses) ? $status_raw : 'Active';
                        $notes = trim($row[$col_map['notes']] ?? 'Imported from Excel');

                        // Check if QID exists
                        $find_stmt->execute([$clean_qid]);
                        $existing_id = $find_stmt->fetchColumn();

                        if ($existing_id) {
                            if ($duplicate_action === 'skip') {
                                $skipped_count++;
                                $skipped_details[] = "Row {$row_idx} (QID: {$clean_qid}): Skipped (Already exists in database).";
                                continue;
                            } else {
                                // Update existing
                                $update_stmt->execute([
                                    ':name'    => $full_name,
                                    ':phone'   => $phone_number,
                                    ':company' => $company_name,
                                    ':job'     => $job_title,
                                    ':nat'     => $nationality,
                                    ':exp'     => $expiry_date,
                                    ':charge'  => $charge_amount,
                                    ':cost'    => $actual_cost,
                                    ':status'  => $status,
                                    ':notes'   => $notes,
                                    ':id'      => $existing_id
                                ]);
                                $updated_count++;
                            }
                        } else {
                            // Insert fresh record
                            $insert_stmt->execute([
                                ':name'    => $full_name,
                                ':qid'     => $clean_qid,
                                ':phone'   => $phone_number,
                                ':company' => $company_name,
                                ':job'     => $job_title,
                                ':nat'     => $nationality,
                                ':exp'     => $expiry_date,
                                ':charge'  => $charge_amount,
                                ':cost'    => $actual_cost,
                                ':status'  => $status,
                                ':notes'   => $notes
                            ]);
                            $new_id = $db->lastInsertId();
                            $inserted_count++;

                            // If initial payment was provided in Excel row, insert it
                            if ($initial_paid > 0) {
                                $pay_stmt->execute([
                                    ':rid' => $new_id,
                                    ':amt' => $initial_paid,
                                    ':rcp' => 'RCP-IMP-' . date('Ymd') . '-' . $new_id
                                ]);
                            }
                        }
                    }

                    $db->commit();
                    fclose($handle);

                    // Trigger auto-backup
                    trigger_auto_backup_if_enabled();

                    $import_results = [
                        'total'    => $total_rows,
                        'inserted' => $inserted_count,
                        'updated'  => $updated_count,
                        'skipped'  => $skipped_count,
                        'details'  => $skipped_details
                    ];

                } catch (Throwable $t) {
                    $db->rollBack();
                    fclose($handle);
                    $error_message = 'Import failed due to database error: ' . $t->getMessage();
                }
            }
        }
    }
}

$page_title = 'Import Excel / CSV - QID Management';
include __DIR__ . '/includes/header.php';
?>

<!-- Header Title & Back Button -->
<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
    <div>
        <h3 class="fw-bold mb-1 text-dark">
            <i class="fa-solid fa-file-excel text-success me-2"></i>Import QID Records from Excel
        </h3>
        <p class="text-muted mb-0 small">Bulk upload client records, company sponsorships, agreed pricing, and initial payments</p>
    </div>
    <div class="d-flex align-items-center gap-2">
        <a href="record_import.php?action=template" class="btn btn-outline-success btn-sm">
            <i class="fa-solid fa-download me-1"></i> Download Excel Template (.CSV)
        </a>
        <a href="records.php" class="btn btn-outline-secondary btn-sm">
            <i class="fa-solid fa-arrow-left me-1"></i> Back to Master Records
        </a>
    </div>
</div>

<?php if (!empty($error_message)): ?>
    <div class="alert alert-danger shadow-sm d-flex align-items-center mb-4" role="alert">
        <i class="fa-solid fa-circle-exclamation fs-4 me-3"></i>
        <div><?= htmlspecialchars($error_message) ?></div>
    </div>
<?php endif; ?>

<?php if ($import_results): ?>
    <div class="card shadow-sm border-0 mb-4">
        <div class="card-header-clean bg-light">
            <h5 class="fw-bold mb-0 text-success">
                <i class="fa-solid fa-circle-check me-2"></i> Import Completed Successfully!
            </h5>
        </div>
        <div class="card-body p-4">
            <div class="row g-3 text-center mb-3">
                <div class="col-md-3 col-6">
                    <div class="p-3 bg-light rounded border">
                        <div class="text-muted small text-uppercase">Total Rows Read</div>
                        <div class="fs-3 fw-bold text-dark"><?= $import_results['total'] ?></div>
                    </div>
                </div>
                <div class="col-md-3 col-6">
                    <div class="p-3 bg-success-subtle rounded border border-success-subtle">
                        <div class="text-success-emphasis small text-uppercase fw-semibold">New Records Added</div>
                        <div class="fs-3 fw-bold text-success">+<?= $import_results['inserted'] ?></div>
                    </div>
                </div>
                <div class="col-md-3 col-6">
                    <div class="p-3 bg-info-subtle rounded border border-info-subtle">
                        <div class="text-info-emphasis small text-uppercase fw-semibold">Existing Updated</div>
                        <div class="fs-3 fw-bold text-info"><?= $import_results['updated'] ?></div>
                    </div>
                </div>
                <div class="col-md-3 col-6">
                    <div class="p-3 bg-warning-subtle rounded border border-warning-subtle">
                        <div class="text-warning-emphasis small text-uppercase fw-semibold">Skipped / Duplicates</div>
                        <div class="fs-3 fw-bold text-dark"><?= $import_results['skipped'] ?></div>
                    </div>
                </div>
            </div>

            <?php if (!empty($import_results['details'])): ?>
                <div class="accordion mb-3" id="skippedDetailsAccordion">
                    <div class="accordion-item border">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed py-2 small" type="button" data-bs-toggle="collapse" data-bs-target="#collapseSkipped">
                                <i class="fa-solid fa-list-check me-2"></i> View Skipped Rows Log (<?= count($import_results['details']) ?>)
                            </button>
                        </h2>
                        <div id="collapseSkipped" class="accordion-collapse collapse" data-bs-parent="#skippedDetailsAccordion">
                            <div class="accordion-body p-3 bg-light small" style="max-height: 200px; overflow-y: auto;">
                                <ul class="mb-0 ps-3">
                                    <?php foreach ($import_results['details'] as $detail): ?>
                                        <li><?= htmlspecialchars($detail) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <div class="d-flex gap-2">
                <a href="records.php" class="btn btn-primary btn-sm px-4">
                    <i class="fa-solid fa-list me-1"></i> View All Records
                </a>
                <a href="record_import.php" class="btn btn-outline-secondary btn-sm">
                    <i class="fa-solid fa-file-import me-1"></i> Import Another File
                </a>
            </div>
        </div>
    </div>
<?php endif; ?>

<div class="row g-4">
    <!-- Left Column: Upload Form -->
    <div class="col-lg-7">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header-clean">
                <h5><i class="fa-solid fa-upload text-primary"></i> Upload Excel / CSV File</h5>
            </div>
            <div class="card-body p-4">
                <form method="POST" action="record_import.php" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="import_csv">

                    <div class="mb-4">
                        <label class="form-label fw-bold">Select CSV / Excel File <span class="text-danger">*</span></label>
                        <input type="file" name="csv_file" class="form-control form-control-lg" accept=".csv, .txt" required>
                        <div class="form-text mt-2">
                            <i class="fa-regular fa-file-excel text-success me-1"></i>
                            Supports standard <code>.CSV</code> exported directly from Microsoft Excel, Google Sheets, or Apple Numbers.
                        </div>
                    </div>

                    <div class="mb-4">
                        <label class="form-label fw-bold">Duplicate QID Handling</label>
                        <div class="form-check p-3 border rounded mb-2">
                            <input class="form-check-input" type="radio" name="duplicate_action" id="dup_skip" value="skip" checked>
                            <label class="form-check-label ms-2" for="dup_skip">
                                <strong>Skip Duplicates (Recommended)</strong>
                                <div class="small text-muted">Keep existing records untouched if the QID already exists in your system.</div>
                            </label>
                        </div>
                        <div class="form-check p-3 border rounded">
                            <input class="form-check-input" type="radio" name="duplicate_action" id="dup_update" value="update">
                            <label class="form-check-label ms-2" for="dup_update">
                                <strong>Update Existing Records</strong>
                                <div class="small text-muted">Overwrite the existing person's details, company, and price with the new data from this file.</div>
                            </label>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-success btn-lg w-100 fw-bold">
                        <i class="fa-solid fa-cloud-arrow-up me-2"></i> Start Importing Records
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- Right Column: Instructions & Sample Download -->
    <div class="col-lg-5">
        <div class="card shadow-sm border-0 mb-4 bg-light">
            <div class="card-body p-4">
                <h5 class="fw-bold text-dark mb-3">
                    <i class="fa-solid fa-circle-question text-primary me-2"></i> How to Prepare Your File
                </h5>
                <ol class="small text-muted ps-3 mb-4" style="line-height: 1.8;">
                    <li>Download our pre-formatted <strong><a href="record_import.php?action=template" class="text-success fw-bold text-decoration-none">Sample Template</a></strong>.</li>
                    <li>Open the file in <strong>Microsoft Excel</strong>.</li>
                    <li>Fill in your clients' details (Name and 11-digit QID Number are required).</li>
                    <li>Expiry dates should be in <code>YYYY-MM-DD</code> format (e.g. <code>2027-10-15</code>) or standard Excel date format.</li>
                    <li>If the client has already paid an advance installment, enter it in the <strong>Initial Paid</strong> column.</li>
                    <li>Click <strong>File &rarr; Save As</strong> and choose <strong>CSV UTF-8 (Comma delimited) (.csv)</strong>.</li>
                    <li>Upload it using the form on the left!</li>
                </ol>

                <div class="p-3 bg-white rounded border">
                    <div class="fw-bold small text-dark mb-2"><i class="fa-solid fa-table-columns text-primary me-1"></i> Column Headers Detected:</div>
                    <div class="d-flex flex-wrap gap-1">
                        <span class="badge bg-light text-dark border">Full Name *</span>
                        <span class="badge bg-light text-dark border">QID Number *</span>
                        <span class="badge bg-light text-dark border">Phone Number</span>
                        <span class="badge bg-light text-dark border">Company Name</span>
                        <span class="badge bg-light text-dark border">Job Title</span>
                        <span class="badge bg-light text-dark border">Nationality</span>
                        <span class="badge bg-light text-dark border">Expiry Date</span>
                        <span class="badge bg-light text-dark border">Agreed Charge</span>
                        <span class="badge bg-light text-dark border">Actual Cost</span>
                        <span class="badge bg-light text-dark border">Initial Paid</span>
                        <span class="badge bg-light text-dark border">Status</span>
                        <span class="badge bg-light text-dark border">Notes</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
