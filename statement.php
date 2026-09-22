<?php
/**
 * QID Management System - Official Corporate Statement of Account & Receipt (Portrait PDF)
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_login();

$id = (int)($_GET['id'] ?? 0);
$record = get_qid_record($id);

if (!$record) {
    die("Record not found.");
}

$payments = get_qid_payments($id);

// Calculate chronological running balance
$chronological_payments = array_reverse($payments);
$running_paid = 0;
$payments_with_balance = [];
foreach ($chronological_payments as $p) {
    $running_paid += (float)$p['amount'];
    $p['running_balance'] = max(0, (float)$record['charge_amount'] - $running_paid);
    $payments_with_balance[] = $p;
}
$payments_display = array_reverse($payments_with_balance);

$company_name = get_setting('company_name', 'Qatar Business Solutions');
$company_phone = get_setting('company_phone', '+974 5500 0000');
$currency = get_setting('currency', 'QR');

$statement_no = 'STMT-' . date('Y') . '-' . str_pad($record['id'], 5, '0', STR_PAD_LEFT);
$auto_print = isset($_GET['print']) && $_GET['print'] == '1';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $statement_no ?> - <?= htmlspecialchars($record['full_name']) ?></title>
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome 6 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <!-- Google Fonts: Plus Jakarta Sans -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Noto+Sans+Arabic:wght@500;700&display=swap" rel="stylesheet">

    <style>
        /* PDF Portrait A4 Setup */
        @page {
            size: A4 portrait;
            margin: 10mm 12mm 12mm 12mm;
        }

        :root {
            --qatar-maroon: #8A1538;
            --qatar-navy: #0f172a;
            --qatar-gold: #c5a880;
            --slate-border: #cbd5e1;
        }

        body {
            font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background-color: #f1f5f9;
            color: #1e293b;
            margin: 0;
            padding: 0;
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
        }

        /* Top Action Bar on Screen (Hidden on PDF Print) */
        .statement-action-bar {
            background-color: #0f172a;
            color: #ffffff;
            padding: 12px 24px;
            position: sticky;
            top: 0;
            z-index: 1000;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }

        /* The Virtual A4 Sheet */
        .statement-sheet {
            background: #ffffff;
            width: 210mm;
            min-height: 297mm;
            margin: 24px auto;
            padding: 16mm 18mm;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            border-radius: 4px;
            box-sizing: border-box;
            position: relative;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }

        /* Header Layout */
        .company-title {
            font-size: 1.45rem;
            font-weight: 800;
            color: var(--qatar-maroon);
            letter-spacing: -0.02em;
            margin-bottom: 2px;
        }

        .company-subtitle {
            font-size: 0.8rem;
            color: #64748b;
            font-weight: 500;
        }

        .statement-badge {
            display: inline-block;
            background-color: var(--qatar-maroon);
            color: #ffffff;
            padding: 6px 14px;
            border-radius: 6px;
            font-weight: 800;
            font-size: 0.88rem;
            letter-spacing: 0.05em;
            text-transform: uppercase;
        }

        /* Ribbon line */
        .statement-divider {
            height: 3px;
            background: linear-gradient(90deg, var(--qatar-maroon) 0%, #d97706 50%, var(--qatar-maroon) 100%);
            margin: 14px 0 18px 0;
        }

        /* Two Column Box */
        .info-card {
            border: 1px solid var(--slate-border);
            border-radius: 8px;
            padding: 12px 16px;
            background: #f8fafc;
            height: 100%;
        }

        .info-card-title {
            font-size: 0.72rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            font-weight: 800;
            color: var(--qatar-maroon);
            border-bottom: 1px solid var(--slate-border);
            padding-bottom: 6px;
            margin-bottom: 8px;
        }

        .info-row {
            display: flex;
            justify-content: space-between;
            font-size: 0.85rem;
            margin-bottom: 5px;
        }

        .info-label {
            color: #64748b;
            font-weight: 500;
        }

        .info-value {
            color: #0f172a;
            font-weight: 700;
            text-align: right;
        }

        /* Financial Metric Pill Blocks */
        .metric-tile {
            background: #ffffff;
            border: 1px solid var(--slate-border);
            border-radius: 8px;
            padding: 10px 14px;
            text-align: center;
        }

        .metric-tile-label {
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #64748b;
            font-weight: 700;
        }

        .metric-tile-value {
            font-size: 1.25rem;
            font-weight: 800;
            margin-top: 2px;
        }

        /* Table Styling for Print */
        .statement-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.82rem;
            margin-top: 14px;
        }

        .statement-table thead th {
            background-color: var(--qatar-maroon) !important;
            color: #ffffff !important;
            padding: 9px 12px;
            font-weight: 700;
            text-transform: uppercase;
            font-size: 0.72rem;
            letter-spacing: 0.05em;
            border: 1px solid var(--qatar-maroon);
        }

        .statement-table tbody td {
            padding: 8px 12px;
            border: 1px solid #e2e8f0;
            color: #1e293b;
            vertical-align: middle;
        }

        .statement-table tbody tr:nth-child(even) {
            background-color: #f8fafc;
        }

        .statement-table tfoot td {
            padding: 10px 12px;
            font-weight: 800;
            border: 1px solid #e2e8f0;
            background-color: #f8fafc;
        }

        /* Official Status Stamp */
        .status-stamp {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 4px;
            font-weight: 800;
            font-size: 0.78rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .stamp-paid {
            background-color: #ecfdf5;
            color: #059669;
            border: 1.5px solid #059669;
        }

        .stamp-partial {
            background-color: #fffbeb;
            color: #b45309;
            border: 1.5px solid #d97706;
        }

        .stamp-unpaid {
            background-color: #fef2f2;
            color: #dc2626;
            border: 1.5px solid #dc2626;
        }

        /* Signatures Area */
        .signature-box {
            border-top: 1px dashed #94a3b8;
            padding-top: 8px;
            text-align: center;
            font-size: 0.78rem;
            color: #475569;
            font-weight: 600;
        }

        .stamp-circle {
            width: 86px;
            height: 86px;
            border: 2px dashed #94a3b8;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
            font-size: 0.68rem;
            font-weight: 700;
            color: #94a3b8;
            margin: 0 auto;
            text-transform: uppercase;
        }

        /* Document Footer */
        .statement-footer {
            font-size: 0.72rem;
            color: #94a3b8;
            border-top: 1px solid #e2e8f0;
            padding-top: 10px;
            margin-top: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        /* Print Media Queries */
        @media print {
            body {
                background: #ffffff !important;
                color: #000000 !important;
            }

            .statement-action-bar, .no-print {
                display: none !important;
            }

            .statement-sheet {
                width: 100% !important;
                max-width: 100% !important;
                min-height: auto !important;
                margin: 0 !important;
                padding: 0 !important;
                box-shadow: none !important;
                border-radius: 0 !important;
            }

            .info-card {
                background: #ffffff !important;
                border: 1px solid #ccc !important;
            }

            .statement-table thead th {
                background-color: #8A1538 !important;
                color: #ffffff !important;
                -webkit-print-color-adjust: exact !important;
            }

            .metric-tile {
                border: 1px solid #ccc !important;
            }
        }
    </style>
</head>
<body>

<!-- Floating Screen Action Bar (Hidden in PDF Print) -->
<div class="statement-action-bar no-print d-flex justify-content-between align-items-center">
    <div class="d-flex align-items-center gap-3">
        <a href="record_detail.php?id=<?= $record['id'] ?>" class="btn btn-outline-light btn-sm">
            <i class="fa-solid fa-arrow-left me-1"></i> Back to Profile
        </a>
        <span class="fw-bold fs-6">
            <i class="fa-solid fa-file-pdf text-danger me-1"></i>
            Official Statement PDF Preview &bull; <?= htmlspecialchars($record['full_name']) ?>
        </span>
    </div>
    <div class="d-flex align-items-center gap-2">
        <button onclick="window.print()" class="btn btn-primary btn-sm px-3 fw-bold">
            <i class="fa-solid fa-print me-1"></i> Save as PDF / Print
        </button>
    </div>
</div>

<!-- =========================================================
     OFFICIAL A4 PORTRAIT SHEET
     ========================================================= -->
<div class="statement-sheet">
    <div>
        <!-- 1. Header Section -->
        <div class="d-flex justify-content-between align-items-start">
            <div>
                <div class="company-title"><?= htmlspecialchars($company_name) ?></div>
                <div class="company-subtitle">
                    <span style="font-family: 'Noto Sans Arabic', sans-serif;">خدمات الجوازات والبطاقة الشخصية وإدارة المعاملات</span><br>
                    Qatar ID & Government Clearance Services &bull; Doha, State of Qatar<br>
                    <i class="fa-solid fa-phone me-1"></i> <?= htmlspecialchars($company_phone) ?> &bull; <i class="fa-solid fa-envelope me-1"></i> info@qid-solutions.qa
                </div>
            </div>
            <div class="text-end">
                <div class="statement-badge">Statement of Account</div>
                <div class="small text-muted mt-1 font-monospace fw-bold"><?= $statement_no ?></div>
                <div class="small text-muted">Issue Date: <strong><?= date('d M Y') ?></strong></div>
                <div class="mt-2">
                    <?php if ($record['remaining_balance'] <= 0): ?>
                        <span class="status-stamp stamp-paid"><i class="fa-solid fa-circle-check me-1"></i> Fully Paid</span>
                    <?php elseif ($record['total_paid'] > 0): ?>
                        <span class="status-stamp stamp-partial"><i class="fa-solid fa-clock-rotate-left me-1"></i> Partially Paid</span>
                    <?php else: ?>
                        <span class="status-stamp stamp-unpaid"><i class="fa-solid fa-circle-exclamation me-1"></i> Payment Pending</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="statement-divider"></div>

        <!-- 2. Client Profile & Sponsor Info (Two-Column Grid) -->
        <div class="row g-3 mb-3">
            <!-- Client Identification -->
            <div class="col-6">
                <div class="info-card">
                    <div class="info-card-title">Client / Beneficiary Profile</div>
                    <div class="info-row">
                        <span class="info-label">Full Name:</span>
                        <span class="info-value"><?= htmlspecialchars($record['full_name']) ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Qatar ID (QID):</span>
                        <span class="info-value font-monospace fs-6 text-primary"><?= htmlspecialchars($record['qid_number']) ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Nationality:</span>
                        <span class="info-value"><?= htmlspecialchars($record['nationality'] ?: 'N/A') ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Contact Phone:</span>
                        <span class="info-value"><?= htmlspecialchars($record['phone_number'] ?: 'N/A') ?></span>
                    </div>
                </div>
            </div>

            <!-- Sponsor & Status Details -->
            <div class="col-6">
                <div class="info-card">
                    <div class="info-card-title">Sponsor & Expiry Details</div>
                    <div class="info-row">
                        <span class="info-label">Company / Sponsor:</span>
                        <span class="info-value"><?= htmlspecialchars($record['company_name'] ?: 'Individual Sponsor') ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Job Designation:</span>
                        <span class="info-value"><?= htmlspecialchars($record['job_title'] ?: 'N/A') ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">QID Expiry Date:</span>
                        <span class="info-value text-dark"><?= format_date($record['expiry_date']) ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Collection Due Date:</span>
                        <span class="info-value text-danger"><?= format_date($record['payment_due_date']) ?></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- 3. Financial Summary Tiles -->
        <div class="row g-2 mb-3">
            <div class="col-4">
                <div class="metric-tile" style="border-top: 3px solid #0284c7;">
                    <div class="metric-tile-label">Total Agreed Charge</div>
                    <div class="metric-tile-value text-primary"><?= format_currency($record['charge_amount']) ?></div>
                </div>
            </div>
            <div class="col-4">
                <div class="metric-tile" style="border-top: 3px solid #059669;">
                    <div class="metric-tile-label">Total Amount Paid</div>
                    <div class="metric-tile-value text-success"><?= format_currency($record['total_paid']) ?></div>
                </div>
            </div>
            <div class="col-4">
                <div class="metric-tile" style="border-top: 3px solid <?= ($record['remaining_balance'] > 0) ? '#dc2626' : '#059669' ?>;">
                    <div class="metric-tile-label">Balance Outstanding</div>
                    <div class="metric-tile-value <?= ($record['remaining_balance'] > 0) ? 'text-danger' : 'text-success' ?>">
                        <?= format_currency($record['remaining_balance']) ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- 4. Installment Payment Ledger Table -->
        <div class="mb-3">
            <div class="d-flex justify-content-between align-items-center mb-1">
                <h6 class="fw-bold mb-0 text-dark" style="font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.05em;">
                    <i class="fa-solid fa-list-check me-1 text-muted"></i> Payment Installments Ledger
                </h6>
                <small class="text-muted"><?= count($payments) ?> Transaction(s) Recorded</small>
            </div>

            <table class="statement-table">
                <thead>
                    <tr>
                        <th style="width: 5%;">#</th>
                        <th style="width: 25%;">Date & Exact Time</th>
                        <th style="width: 18%;">Receipt No</th>
                        <th style="width: 15%;">Method</th>
                        <th style="width: 17%;" class="text-end">Amount Paid</th>
                        <th style="width: 20%;" class="text-end">Balance Remaining</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($payments_display)): ?>
                        <tr>
                            <td colspan="6" class="text-center py-3 text-muted">
                                No payments recorded yet. Total balance remains outstanding.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php 
                        $counter = 1;
                        foreach ($payments_display as $pay_item): 
                        ?>
                            <tr>
                                <td class="text-center text-muted"><?= $counter++ ?></td>
                                <td>
                                    <strong><?= format_date($pay_item['payment_date']) ?></strong>
                                    <span class="text-muted" style="font-size: 0.75rem;">(<?= format_time($pay_item['payment_time']) ?>)</span>
                                </td>
                                <td class="font-monospace fw-bold">
                                    <?= htmlspecialchars($pay_item['receipt_no'] ?: ('#RCP-' . $pay_item['id'])) ?>
                                </td>
                                <td>
                                    <?= htmlspecialchars($pay_item['payment_method']) ?>
                                </td>
                                <td class="text-end fw-bold text-success">
                                    + <?= format_currency($pay_item['amount']) ?>
                                </td>
                                <td class="text-end fw-bold <?= ($pay_item['running_balance'] > 0) ? 'text-danger' : 'text-success' ?>">
                                    <?= format_currency($pay_item['running_balance']) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="4" class="text-end text-uppercase" style="font-size: 0.78rem;">Total Collections:</td>
                        <td class="text-end text-success fs-6"><?= format_currency($record['total_paid']) ?></td>
                        <td class="text-end text-danger fs-6"><?= format_currency($record['remaining_balance']) ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <?php if (!empty($record['notes'])): ?>
            <div class="p-2 px-3 bg-light rounded border small mb-3 text-muted">
                <strong>Administrative Notes:</strong> <?= htmlspecialchars($record['notes']) ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- 5. Bottom Signatures & Seal Section -->
    <div>
        <div class="row align-items-end mt-4 pt-3">
            <div class="col-4">
                <div class="signature-box">
                    <div>Client / Payer Signature</div>
                    <div class="text-muted" style="font-size: 0.68rem; margin-top: 35px;">I acknowledge receipt of this statement</div>
                </div>
            </div>

            <div class="col-4 text-center">
                <div class="stamp-circle">
                    Official<br>Seal / Stamp
                </div>
            </div>

            <div class="col-4">
                <div class="signature-box">
                    <div>Authorized Signatory</div>
                    <div class="text-muted" style="font-size: 0.68rem; margin-top: 35px;"><?= htmlspecialchars($company_name) ?></div>
                </div>
            </div>
        </div>

        <!-- 6. Footer Legal / Disclaimer -->
        <div class="statement-footer">
            <div>
                Generated on <strong><?= date('d M Y, h:i A') ?></strong> &bull; QID Tracking System
            </div>
            <div>
                Official Qatar ID Financial Statement &bull; Page 1 of 1
            </div>
        </div>
    </div>
</div>

<?php if ($auto_print): ?>
<script>
    window.addEventListener('DOMContentLoaded', () => {
        window.print();
    });
</script>
<?php endif; ?>

</body>
</html>
