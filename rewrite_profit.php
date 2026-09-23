<?php
$file_path = 'profit_report.php';
$content = file_get_contents($file_path);

// 1. Update Date Defaults
$old_dates = "\$date_from = trim(\$_GET['date_from'] ?? '');\n\$date_to = trim(\$_GET['date_to'] ?? '');";

$new_dates = "// Default to current month if NO filters are applied
\$is_filtered = !empty(\$_GET);
if (!\$is_filtered) {
    \$date_from = date('Y-m-01');
    \$date_to = date('Y-m-t');
} else {
    \$date_from = trim(\$_GET['date_from'] ?? '');
    \$date_to = trim(\$_GET['date_to'] ?? '');
}";
$content = str_replace($old_dates, $new_dates, $content);

// 2. Remove Monthly Stats Computation
$start_str = "\$current_month_key = date('Y-m');";
$end_str = "// Fetch distinct company list for filter";

if (strpos($content, $start_str) !== false && strpos($content, $end_str) !== false) {
    $start_idx = strpos($content, $start_str);
    $end_idx = strpos($content, $end_str);
    $content = substr($content, 0, $start_idx) . substr($content, $end_idx);
}

// 3. Remove Monthly UI block 1
$start_ui_1 = "<!-- Monthly Profit Timing & Upcoming Forecast KPI Row -->";
$end_ui_1 = "<!-- Profit Realization Progress Bar -->";
if (strpos($content, $start_ui_1) !== false && strpos($content, $end_ui_1) !== false) {
    $start_idx = strpos($content, $start_ui_1);
    $end_idx = strpos($content, $end_ui_1);
    $content = substr($content, 0, $start_idx) . substr($content, $end_idx);
}

// 4. Remove Monthly UI block 2
$start_ui_2 = "<!-- Monthly Profit Forecast & Realized Cash Table -->";
$end_ui_2 = "<!-- Profit Summary by Company / Sponsor -->";
if (strpos($content, $start_ui_2) !== false && strpos($content, $end_ui_2) !== false) {
    $start_idx = strpos($content, $start_ui_2);
    $end_idx = strpos($content, $end_ui_2);
    $content = substr($content, 0, $start_idx) . substr($content, $end_idx);
}

file_put_contents($file_path, $content);
echo "Updated profit_report.php successfully.\n";
