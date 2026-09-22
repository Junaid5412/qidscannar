<?php
/**
 * QID Management System - Profit & Earnings Analytics Report
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

$page_title = 'Profit & Earnings Report';
$currency = get_setting('currency', 'QR');
$db = getDB();

// Filters
$company_filter = trim($_GET['company'] ?? '');
$status_filter = trim($_GET['status'] ?? '');
$search = trim($_GET['search'] ?? '');
$date_from = trim($_GET['date_from'] ?? '');
$date_to = trim($_GET['date_to'] ?? '');

$query = "
    SELECT r.*,
           COALESCE(SUM(p.amount), 0) AS total_paid
    FROM qid_records r
    LEFT JOIN payments p ON p.qid_record_id = r.id
    WHERE 1=1
";

$params = [];

if (!empty($search)) {
    $query .= " AND (r.full_name LIKE :s OR r.qid_number LIKE :s OR r.company_name LIKE :s)";
    $params[':s'] = "%{$search}%";
}

if (!empty($company_filter)) {
    $query .= " AND r.company_name = :comp";
    $params[':comp'] = $company_filter;
}

if (!empty($status_filter)) {
    $query .= " AND r.status = :stat";
    $params[':stat'] = $status_filter;
}

if (!empty($date_from)) {
    $query .= " AND DATE(r.created_at) >= :dfrom";
    $params[':dfrom'] = $date_from;
}

if (!empty($date_to)) {
    $query .= " AND DATE(r.created_at) <= :dto";
    $params[':dto'] = $date_to;
}

$query .= " GROUP BY r.id ORDER BY r.id DESC";

$stmt = $db->prepare($query);
$stmt->execute($params);
$raw_records = $stmt->fetchAll();

// Financial Aggregations
$total_billed = 0;
$total_cost = 0;
$total_profit = 0;
$total_collected = 0;
$total_pending_balance = 0;
$realized_profit_total = 0;

$records = [];
$company_stats = [];

foreach ($raw_records as $r) {
    $charge = (float)$r['charge_amount'];
    $cost = (float)$r['actual_cost'];
    $paid = (float)$r['total_paid'];
    $profit = $charge - $cost;
    $balance = max(0, $charge - $paid);

    $total_billed += $charge;
    $total_cost += $cost;
    $total_profit += $profit;
    $total_collected += $paid;
    $total_pending_balance += $balance;

    // Realized Profit (Once actual cost is covered, every subsequent riyal is pure cash profit in pocket)
    $realized_profit = max(0, $paid - $cost);
    $realized_profit_total += $realized_profit;

    $margin_pct = ($charge > 0) ? round(($profit / $charge) * 100, 1) : 0;

    $r['profit'] = $profit;
    $r['margin_pct'] = $margin_pct;
    $r['remaining_balance'] = $balance;
    $r['realized_profit'] = $realized_profit;

    // Company grouping
    $comp = !empty($r['company_name']) ? $r['company_name'] : 'Individual / Unspecified';
    if (!isset($company_stats[$comp])) {
        $company_stats[$comp] = [
            'count'    => 0,
            'billed'   => 0,
            'cost'     => 0,
            'profit'   => 0,
            'collected'=> 0
        ];
    }
    $company_stats[$comp]['count']++;
    $company_stats[$comp]['billed'] += $charge;
    $company_stats[$comp]['cost'] += $cost;
    $company_stats[$comp]['profit'] += $profit;
    $company_stats[$comp]['collected'] += $paid;

    $records[] = $r;
}

// Sort company stats by profit DESC
uasort($company_stats, function($a, $b) {
    return $b['profit'] <=> $a['profit'];
});

$overall_margin = ($total_billed > 0) ? round(($total_profit / $total_billed) * 100, 1) : 0;
$pending_profit = max(0, $total_profit - $realized_profit_total);

$current_month_key = date('Y-m'); // e.g. '2026-09'
$next_month_key = date('Y-m', strtotime('+1 month')); // e.g. '2026-10'

$monthly_stats = [];

// Helper to initialize month bucket
$init_month = function($m_key) use (&$monthly_stats) {
    if (!isset($monthly_stats[$m_key])) {
        $monthly_stats[$m_key] = [
            'month_label'       => date('F Y', strtotime($m_key . '-01')),
            'expected_profit'   => 0,
            'received_profit'   => 0,
            'pending_profit'    => 0,
            'cash_collected'    => 0,
            'cost_recovered'    => 0,
            'clients_pending'   => [],
            'clients_received'  => []
        ];
    }
};

// Always ensure current month and next month are visible in monthly analysis
$init_month($current_month_key);
$init_month($next_month_key);

// 1. Process all payments chronologically to know EXACTLY when costs are completed
$all_payments_stmt = $db->query("
    SELECT p.*, r.full_name, r.charge_amount, r.actual_cost 
    FROM payments p
    JOIN qid_records r ON r.id = p.qid_record_id
    ORDER BY p.qid_record_id ASC, p.payment_date ASC, p.payment_time ASC, p.id ASC
");
$all_payments = $all_payments_stmt->fetchAll();

$running_record_paid = [];
foreach ($all_payments as $pay) {
    $rid = $pay['qid_record_id'];
    $cost = (float)$pay['actual_cost'];
    $amount = (float)$pay['amount'];
    $pay_month = date('Y-m', strtotime($pay['payment_date']));

    $init_month($pay_month);
    $monthly_stats[$pay_month]['cash_collected'] += $amount;

    $prev_paid = $running_record_paid[$rid] ?? 0;
    $new_paid = $prev_paid + $amount;
    $running_record_paid[$rid] = $new_paid;

    // Determine how much of this installment is pure profit above the cost
    $payment_profit = 0;
    $payment_cost_reimbursed = 0;

    if ($new_paid > $cost) {
        if ($prev_paid >= $cost) {
            // Cost was already completed in an earlier payment, entire payment is pure profit!
            $payment_profit = $amount;
        } else {
            // This payment completed the cost threshold!
            $payment_cost_reimbursed = $cost - $prev_paid;
            $payment_profit = $new_paid - $cost;
        }
    } else {
        // Entire payment goes towards cost recovery
        $payment_cost_reimbursed = $amount;
    }

    $monthly_stats[$pay_month]['received_profit'] += $payment_profit;
    $monthly_stats[$pay_month]['cost_recovered'] += $payment_cost_reimbursed;

    if ($payment_profit > 0) {
        $monthly_stats[$pay_month]['clients_received'][] = [
            'name'   => $pay['full_name'],
            'amount' => $payment_profit,
            'date'   => $pay['payment_date']
        ];
    }
}

// 2. Process all pending/expected profits based on Payment Due Date
foreach ($raw_records as $r) {
    $charge = (float)$r['charge_amount'];
    $cost = (float)$r['actual_cost'];
    $paid = (float)$r['total_paid'];
    $total_deal_profit = max(0, $charge - $cost);
    $deal_realized_profit = max(0, $paid - $cost);
    $deal_pending_profit = max(0, $total_deal_profit - $deal_realized_profit);

    if ($deal_pending_profit > 0) {
        // Schedule it in the month when payment is due (Upcoming Expected Month)
        $due_month = !empty($r['payment_due_date']) ? date('Y-m', strtotime($r['payment_due_date'])) : $current_month_key;
        $init_month($due_month);

        $monthly_stats[$due_month]['pending_profit'] += $deal_pending_profit;
        $monthly_stats[$due_month]['clients_pending'][] = [
            'name'     => $r['full_name'],
            'amount'   => $deal_pending_profit,
            'due_date' => $r['payment_due_date']
        ];
    }
}

// 3. For every month: Expected Profit = Received Profit + Pending Profit
// ("Both will be same, but before receiving it is Expected, not completed")
foreach ($monthly_stats as $m_key => &$m_data) {
    $m_data['expected_profit'] = $m_data['received_profit'] + $m_data['pending_profit'];
}
unset($m_data);

// Sort months descending (future / latest first)
krsort($monthly_stats);

// Quick KPIs for the cards
$this_month_expected = $monthly_stats[$current_month_key]['expected_profit'] ?? 0;
$this_month_received = $monthly_stats[$current_month_key]['received_profit'] ?? 0;
$this_month_pending  = $monthly_stats[$current_month_key]['pending_profit'] ?? 0;

$next_month_expected = $monthly_stats[$next_month_key]['expected_profit'] ?? 0;
$next_month_received = $monthly_stats[$next_month_key]['received_profit'] ?? 0;
$next_month_pending  = $monthly_stats[$next_month_key]['pending_profit'] ?? 0;

// Fetch distinct company list for filter
$comp_stmt = $db->query("SELECT DISTINCT company_name FROM qid_records WHERE company_name IS NOT NULL AND company_name != '' ORDER BY company_name ASC");
$all_companies = $comp_stmt->fetchAll(PDO::FETCH_COLUMN);

include __DIR__ . '/includes/header.php';
?>

<!-- Title & Action Buttons -->
<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
    <div>
        <h3 class="fw-bold mb-1 text-dark">Profit & Revenue Analysis</h3>
        <p class="text-muted mb-0 small">Comprehensive earnings breakdown: Total Billed, Direct Costs, and Net Profits</p>
    </div>
    <div class="d-flex align-items-center gap-2">
        <a href="export.php?type=profit" class="btn btn-outline-secondary btn-sm" title="Export to CSV">
            <i class="fa-solid fa-file-csv me-1"></i> Export Profit CSV
        </a>
        <button onclick="window.print()" class="btn btn-outline-secondary btn-sm" title="Print Report">
            <i class="fa-solid fa-print me-1"></i> Print Report
        </button>
    </div>
</div>

<!-- Primary Profit KPI Summary Row -->
<div class="row g-3 mb-4">
    <!-- Total Billed / Revenue -->
    <div class="col-xl-3 col-sm-6">
        <div class="kpi-card kpi-primary">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="kpi-title">Total Billed Revenue</div>
                    <div class="kpi-value text-primary"><?= format_currency($total_billed) ?></div>
                    <div class="kpi-sub"><i class="fa-solid fa-file-invoice-dollar me-1"></i>Gross charges to clients</div>
                </div>
                <div class="kpi-icon bg-primary-subtle text-primary">
                    <i class="fa-solid fa-money-bill-wave"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Government & Processing Cost -->
    <div class="col-xl-3 col-sm-6">
        <div class="kpi-card kpi-danger">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="kpi-title">Total Government Cost</div>
                    <div class="kpi-value text-danger"><?= format_currency($total_cost) ?></div>
                    <div class="kpi-sub"><i class="fa-solid fa-receipt me-1"></i>Our processing outlay</div>
                </div>
                <div class="kpi-icon bg-danger-subtle text-danger">
                    <i class="fa-solid fa-building-columns"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Net Expected Profit -->
    <div class="col-xl-3 col-sm-6">
        <div class="kpi-card kpi-success">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="kpi-title">Net Expected Profit</div>
                    <div class="kpi-value text-success"><?= format_currency($total_profit) ?></div>
                    <div class="kpi-sub">
                        <i class="fa-solid fa-arrow-trend-up text-success me-1"></i><strong><?= $overall_margin ?>%</strong> overall profit margin
                    </div>
                </div>
                <div class="kpi-icon bg-success-subtle text-success">
                    <i class="fa-solid fa-sack-dollar"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Realized Cash Profit -->
    <div class="col-xl-3 col-sm-6">
        <div class="kpi-card kpi-purple">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="kpi-title">Realized Cash Profit</div>
                    <div class="kpi-value text-dark"><?= format_currency($realized_profit_total) ?></div>
                    <div class="kpi-sub text-muted">
                        Pending to collect: <?= format_currency($pending_profit) ?>
                    </div>
                </div>
                <div class="kpi-icon bg-info-subtle text-info">
                    <i class="fa-solid fa-hand-holding-dollar"></i>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Monthly Profit Timing & Upcoming Forecast KPI Row -->
<div class="row g-3 mb-4">
    <!-- This Month Expected Profit -->
    <div class="col-xl-3 col-sm-6">
        <div class="card p-3 shadow-sm border-start border-4 border-warning h-100">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="text-muted small text-uppercase fw-bold">This Month Expected Profit</div>
                    <div class="fs-4 fw-bold text-dark mt-1"><?= format_currency($this_month_expected) ?></div>
                    <div class="small text-muted">
                        <i class="fa-regular fa-calendar me-1 text-warning"></i><?= date('F Y') ?> Total Target
                    </div>
                </div>
                <div class="p-2 bg-warning-subtle text-warning-emphasis rounded">
                    <i class="fa-solid fa-calendar-day fs-5"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- This Month Received in Cash -->
    <div class="col-xl-3 col-sm-6">
        <div class="card p-3 shadow-sm border-start border-4 border-success h-100">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="text-muted small text-uppercase fw-bold">This Month Received (Cash)</div>
                    <div class="fs-4 fw-bold text-success mt-1"><?= format_currency($this_month_received) ?></div>
                    <div class="small text-muted">
                        <?php if ($this_month_pending > 0): ?>
                            <span class="text-danger fw-semibold"><i class="fa-solid fa-clock me-1"></i><?= format_currency($this_month_pending) ?> still pending</span>
                        <?php else: ?>
                            <span class="text-success"><i class="fa-solid fa-circle-check me-1"></i>100% Collected</span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="p-2 bg-success-subtle text-success rounded">
                    <i class="fa-solid fa-coins fs-5"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- This Month Still Pending Collection -->
    <div class="col-xl-3 col-sm-6">
        <div class="card p-3 shadow-sm border-start border-4 border-danger h-100">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="text-muted small text-uppercase fw-bold">This Month Still Pending</div>
                    <div class="fs-4 fw-bold text-danger mt-1"><?= format_currency($this_month_pending) ?></div>
                    <div class="small text-muted">
                        <i class="fa-solid fa-hourglass-half text-danger me-1"></i>Awaiting collection this month
                    </div>
                </div>
                <div class="p-2 bg-danger-subtle text-danger rounded">
                    <i class="fa-solid fa-clock-rotate-left fs-5"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Next Month Upcoming Expected Profit -->
    <div class="col-xl-3 col-sm-6">
        <div class="card p-3 shadow-sm border-start border-4 border-info h-100 bg-info-subtle bg-opacity-10">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="text-info-emphasis small text-uppercase fw-bold">Next Month Upcoming Profit</div>
                    <div class="fs-4 fw-bold text-primary mt-1"><?= format_currency($next_month_expected) ?></div>
                    <div class="small text-muted">
                        <i class="fa-solid fa-calendar-plus me-1 text-info"></i><?= date('F Y', strtotime('+1 month')) ?> Forecast
                    </div>
                </div>
                <div class="p-2 bg-info-subtle text-info rounded">
                    <i class="fa-solid fa-calendar-arrow-up fs-5"></i>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Profit Realization Progress Bar -->
<div class="card shadow-sm mb-4">
    <div class="card-body p-4">
        <div class="d-flex justify-content-between align-items-center mb-2">
            <div>
                <span class="fw-bold text-dark">Profit Realization Status</span>
                <span class="text-muted small ms-2">(Realized in Cash vs Awaiting Client Payment)</span>
            </div>
            <div class="fw-bold text-success">
                <?php 
                $profit_collected_pct = ($total_profit > 0) ? round(($realized_profit_total / $total_profit) * 100, 1) : 0;
                ?>
                <?= $profit_collected_pct ?>% Realized in Cash
            </div>
        </div>
        <div class="progress" style="height: 12px; border-radius: 6px; background-color: #f1f5f9;">
            <div class="progress-bar bg-success progress-bar-striped" role="progressbar" style="width: <?= $profit_collected_pct ?>%" aria-valuenow="<?= $profit_collected_pct ?>" aria-valuemin="0" aria-valuemax="100"></div>
        </div>
        <div class="d-flex justify-content-between small text-muted mt-2">
            <span><i class="fa-solid fa-circle text-success me-1"></i> Realized Profit in Hand: <strong><?= format_currency($realized_profit_total) ?></strong></span>
            <span><i class="fa-solid fa-circle text-warning me-1"></i> Expected Profit Pending Collection: <strong><?= format_currency($pending_profit) ?></strong></span>
        </div>
    </div>
</div>

<!-- Search & Filter Controls -->
<div class="card mb-4 shadow-sm border-0">
    <div class="card-body p-3">
        <form method="GET" action="profit_report.php" class="row g-2 align-items-center">
            <div class="col-lg-3 col-md-6">
                <div class="input-group">
                    <span class="input-group-text bg-white text-muted"><i class="fa-solid fa-magnifying-glass"></i></span>
                    <input type="text" name="search" id="tableSearchInput" data-table-target="#profitTable" class="form-control" placeholder="Search person, QID, company..." value="<?= htmlspecialchars($search) ?>">
                </div>
            </div>

            <div class="col-lg-3 col-md-6">
                <select name="company" class="form-select" onchange="this.form.submit()">
                    <option value="">All Companies / Sponsors</option>
                    <?php foreach ($all_companies as $comp_name): ?>
                        <option value="<?= htmlspecialchars($comp_name) ?>" <?= ($company_filter === $comp_name) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($comp_name) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-lg-2 col-md-6">
                <input type="date" name="date_from" class="form-control" value="<?= htmlspecialchars($date_from) ?>" title="From Date">
            </div>

            <div class="col-lg-2 col-md-6">
                <input type="date" name="date_to" class="form-control" value="<?= htmlspecialchars($date_to) ?>" title="To Date">
            </div>

            <div class="col-lg-2 col-md-12 d-flex gap-1">
                <button type="submit" class="btn btn-primary btn-sm flex-grow-1">Filter</button>
                <?php if (!empty($search) || !empty($company_filter) || !empty($date_from) || !empty($date_to)): ?>
                    <a href="profit_report.php" class="btn btn-outline-secondary btn-sm" title="Clear Filters">
                        <i class="fa-solid fa-xmark"></i>
                    </a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Monthly Profit Forecast & Realized Cash Table -->
<div class="card shadow-sm mb-4">
    <div class="card-header-clean">
        <h5>
            <i class="fa-solid fa-calendar-days text-primary"></i>
            <span>Monthly Profit Forecast & Realized Cash (Upcoming vs Completed)</span>
        </h5>
        <span class="badge bg-light text-dark border"><?= count($monthly_stats) ?> Month(s)</span>
    </div>
    <div class="card-body p-4 pb-0">
        <div class="p-3 bg-light rounded-3 mb-3 border">
            <div class="d-flex align-items-start gap-2">
                <i class="fa-solid fa-circle-info text-primary mt-1"></i>
                <div class="small text-muted">
                    <strong>How Expected vs Received Profit Works:</strong> For any month, <strong>Expected Profit</strong> is the total profit scheduled to be collected based on payment due dates. Before collection it remains <strong>Expected (Not Completed)</strong>. Once the client pays and reimburses the government cost (e.g. 1,220 QR), that profit becomes <strong>Received in Cash</strong>!
                </div>
            </div>
        </div>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-custom align-middle mb-0">
                <thead>
                    <tr>
                        <th>Month Period</th>
                        <th>Expected Profit (Total)</th>
                        <th>Received in Cash</th>
                        <th>Pending to Receive</th>
                        <th>Timeline Status</th>
                        <th>Contributing Clients</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($monthly_stats as $m_code => $m_data): 
                        $is_current = ($m_code === $current_month_key);
                        $is_next = ($m_code === $next_month_key);
                        $is_past = ($m_code < $current_month_key);
                        $is_future = ($m_code > $current_month_key);
                    ?>
                        <tr class="<?= $is_current ? 'table-warning bg-opacity-25' : ($is_next ? 'table-info bg-opacity-10' : '') ?>">
                            <td>
                                <div class="fw-bold text-dark fs-6">
                                    <i class="fa-regular fa-calendar me-1 text-primary"></i> <?= htmlspecialchars($m_data['month_label']) ?>
                                </div>
                                <?php if ($is_current): ?>
                                    <span class="badge bg-warning text-dark"><i class="fa-solid fa-calendar-day me-1"></i> Current Month</span>
                                <?php elseif ($is_next): ?>
                                    <span class="badge bg-info text-white"><i class="fa-solid fa-calendar-plus me-1"></i> Upcoming Next Month</span>
                                <?php elseif ($is_future): ?>
                                    <span class="badge bg-secondary text-white">Future Month</span>
                                <?php else: ?>
                                    <span class="badge bg-light text-muted border">Past Month</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="fw-bold text-dark fs-6"><?= format_currency($m_data['expected_profit']) ?></span>
                                <div class="small text-muted">Total target for month</div>
                            </td>
                            <td>
                                <span class="fw-bold text-success fs-6">
                                    <?= ($m_data['received_profit'] > 0) ? '+ ' . format_currency($m_data['received_profit']) : '0.00 ' . $currency ?>
                                </span>
                                <div class="small text-muted">Received in cash</div>
                            </td>
                            <td>
                                <?php if ($m_data['pending_profit'] > 0): ?>
                                    <span class="fw-bold text-danger fs-6"><?= format_currency($m_data['pending_profit']) ?></span>
                                    <div class="small text-muted">Expected / Not yet received</div>
                                <?php else: ?>
                                    <span class="text-success small fw-semibold"><i class="fa-solid fa-circle-check me-1"></i>0.00 (All Received)</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($m_data['expected_profit'] > 0 && $m_data['pending_profit'] <= 0): ?>
                                    <span class="badge bg-success text-white">
                                        <i class="fa-solid fa-check-double me-1"></i> 100% Completed
                                    </span>
                                <?php elseif ($is_current && $m_data['pending_profit'] > 0): ?>
                                    <span class="badge bg-warning text-dark">
                                        <i class="fa-solid fa-clock me-1"></i> In Progress (Due This Month)
                                    </span>
                                <?php elseif ($is_future && $m_data['pending_profit'] > 0): ?>
                                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle">
                                        <i class="fa-solid fa-calendar-arrow-up me-1"></i> Upcoming Expected
                                    </span>
                                <?php elseif ($is_past && $m_data['pending_profit'] > 0): ?>
                                    <span class="badge bg-danger text-white">
                                        <i class="fa-solid fa-triangle-exclamation me-1"></i> Overdue Collections
                                    </span>
                                <?php else: ?>
                                    <span class="badge bg-light text-muted border">No Collections</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php 
                                $all_client_names = [];
                                foreach ($m_data['clients_pending'] as $cp) {
                                    $all_client_names[] = '<span class="badge bg-light text-dark border me-1 mb-1">' . htmlspecialchars($cp['name']) . ' (Due ' . format_date($cp['due_date'], 'd M') . ')</span>';
                                }
                                foreach ($m_data['clients_received'] as $cr) {
                                    $all_client_names[] = '<span class="badge bg-success-subtle text-success border border-success-subtle me-1 mb-1"><i class="fa-solid fa-check me-1"></i>' . htmlspecialchars($cr['name']) . '</span>';
                                }
                                echo !empty($all_client_names) ? implode(' ', array_unique($all_client_names)) : '<span class="text-muted small">—</span>';
                                ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Profit Summary by Company / Sponsor -->
<?php if (!empty($company_stats)): ?>
    <div class="card shadow-sm mb-4">
        <div class="card-header-clean">
            <h5>
                <i class="fa-solid fa-building text-primary"></i>
                <span>Profit Breakdown by Company / Sponsor</span>
            </h5>
            <span class="badge bg-light text-dark border"><?= count($company_stats) ?> Sponsors</span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-custom align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Company / Sponsor Name</th>
                            <th>Total QIDs</th>
                            <th>Total Billed</th>
                            <th>Direct Cost</th>
                            <th>Net Profit Earned</th>
                            <th>Margin</th>
                            <th>Collected Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($company_stats as $cname => $cstat): 
                            $c_margin = ($cstat['billed'] > 0) ? round(($cstat['profit'] / $cstat['billed']) * 100, 1) : 0;
                        ?>
                            <tr>
                                <td>
                                    <div class="fw-bold text-dark">
                                        <i class="fa-solid fa-briefcase me-1 text-muted"></i> <?= htmlspecialchars($cname) ?>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge bg-light text-dark border font-monospace"><?= $cstat['count'] ?> records</span>
                                </td>
                                <td>
                                    <span class="fw-semibold"><?= format_currency($cstat['billed']) ?></span>
                                </td>
                                <td>
                                    <span class="text-muted"><?= format_currency($cstat['cost']) ?></span>
                                </td>
                                <td>
                                    <span class="fw-bold text-success fs-6">
                                        <?= format_currency($cstat['profit']) ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge bg-success-subtle text-success border border-success-subtle">
                                        <?= $c_margin ?>%
                                    </span>
                                </td>
                                <td>
                                    <span class="fw-semibold text-primary"><?= format_currency($cstat['collected']) ?></span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- Detailed Itemized Profit Ledger -->
<div class="card shadow-sm mb-4">
    <div class="card-header-clean">
        <h5>
            <i class="fa-solid fa-table-list text-success"></i>
            <span>Itemized Record-by-Record Profit Ledger</span>
        </h5>
        <span class="badge bg-light text-dark border"><?= count($records) ?> Entries</span>
    </div>
    <div class="card-body p-0">
        <?php if (empty($records)): ?>
            <div class="text-center py-5 text-muted">
                <i class="fa-solid fa-coins fa-3x text-secondary mb-3"></i>
                <h5 class="fw-bold text-dark">No Records Found</h5>
                <p class="small text-muted mb-0">No profit records match your selected filter criteria.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-custom table-hover align-middle mb-0" id="profitTable">
                    <thead>
                        <tr>
                            <th>Client / Employee</th>
                            <th>QID Number</th>
                            <th>Agreed Charge</th>
                            <th>Actual Cost</th>
                            <th>Net Profit</th>
                            <th>Margin</th>
                            <th>Total Paid</th>
                            <th>Profit Realization</th>
                            <th class="text-end no-print">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($records as $r): ?>
                            <tr>
                                <td>
                                    <a href="record_detail.php?id=<?= $r['id'] ?>" class="fw-bold text-dark text-decoration-none">
                                        <?= htmlspecialchars($r['full_name']) ?>
                                    </a>
                                    <div class="small text-muted"><?= htmlspecialchars($r['company_name'] ?: 'Individual') ?></div>
                                </td>
                                <td>
                                    <span class="font-monospace fw-semibold"><?= htmlspecialchars($r['qid_number']) ?></span>
                                </td>
                                <td>
                                    <span class="fw-bold text-dark"><?= format_currency($r['charge_amount']) ?></span>
                                </td>
                                <td>
                                    <span class="text-muted"><?= format_currency($r['actual_cost']) ?></span>
                                </td>
                                <td>
                                    <span class="fw-bold text-success fs-6">
                                        <?= format_currency($r['profit']) ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge bg-success-subtle text-success border border-success-subtle">
                                        <?= $r['margin_pct'] ?>%
                                    </span>
                                </td>
                                <td>
                                    <span class="fw-bold text-primary"><?= format_currency($r['total_paid']) ?></span>
                                    <div class="small text-muted">Bal: <?= format_currency($r['remaining_balance']) ?></div>
                                </td>
                                <td>
                                    <?php if ($r['remaining_balance'] <= 0): ?>
                                        <span class="badge bg-success text-white">
                                            <i class="fa-solid fa-circle-check me-1"></i> 100% Secured
                                        </span>
                                    <?php elseif ($r['realized_profit'] > 0): ?>
                                        <span class="badge bg-info-subtle text-info border border-info-subtle">
                                            <i class="fa-solid fa-money-bill-transfer me-1"></i> Cost Recovered (+<?= format_currency($r['realized_profit']) ?>)
                                        </span>
                                    <?php else: ?>
                                        <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle">
                                            <i class="fa-solid fa-hourglass-half me-1"></i> Pending Recovery
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end no-print">
                                    <a href="record_detail.php?id=<?= $r['id'] ?>" class="btn btn-sm btn-outline-primary" title="View Full Details">
                                        <i class="fa-solid fa-arrow-right"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot class="bg-light fw-bold">
                        <tr>
                            <td colspan="2" class="text-end">Grand Totals:</td>
                            <td class="text-dark fs-6"><?= format_currency($total_billed) ?></td>
                            <td class="text-danger fs-6"><?= format_currency($total_cost) ?></td>
                            <td class="text-success fs-5"><?= format_currency($total_profit) ?></td>
                            <td class="text-success"><?= $overall_margin ?>%</td>
                            <td class="text-primary fs-6"><?= format_currency($total_collected) ?></td>
                            <td colspan="2">Realized: <?= format_currency($realized_profit_total) ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
