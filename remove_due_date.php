<?php

function replaceInFile($file, $replacements) {
    if (!file_exists($file)) return;
    $content = file_get_contents($file);
    foreach ($replacements as $search => $replace) {
        $content = str_replace($search, $replace, $content);
    }
    file_put_contents($file, $content);
}

// 1. record_add.php
replaceInFile('record_add.php', [
    '<div class="col-md-6 mb-3">
                            <label class="form-label">Payment Due Date</label>
                            <input type="date" name="payment_due_date" class="form-control" value="<?= htmlspecialchars($_POST[\'payment_due_date\'] ?? date(\'Y-m-d\', strtotime(\'+7 days\'))) ?>">
                        </div>' => '',
    'payment_due_date,' => '',
    ':due,' => '',
    "':due'     => \$payment_due_date," => "",
    "\$payment_due_date = !empty(\$_POST['payment_due_date']) ? \$_POST['payment_due_date'] : null;" => ""
]);

// 2. record_edit.php
replaceInFile('record_edit.php', [
    '<div class="col-md-6 mb-3">
                            <label class="form-label">Payment Due Date</label>
                            <input type="date" name="payment_due_date" class="form-control" value="<?= htmlspecialchars($record[\'payment_due_date\'] ?? \'\') ?>">
                        </div>' => '',
    'payment_due_date = :due,' => '',
    "':due'     => \$payment_due_date," => "",
    "\$payment_due_date = !empty(\$_POST['payment_due_date']) ? \$_POST['payment_due_date'] : null;" => ""
]);

// 3. record_detail.php
replaceInFile('record_detail.php', [
    '<div class="col-6 mb-3">
                    <div class="small text-white-50 text-uppercase tracking-wide mb-1">Payment Due</div>
                    <div class="fw-bold text-white"><?= format_date($record[\'payment_due_date\']) ?></div>
                </div>' => ''
]);

// 4. statement.php
replaceInFile('statement.php', [
    '<div class="info-group">
                        <span class="info-label">Payment Due:</span>
                        <span class="info-value text-danger"><?= format_date($record[\'payment_due_date\']) ?></span>
                    </div>' => ''
]);

// 5. export.php
replaceInFile('export.php', [
    "'Payment Due Date'," => "",
    "\$enriched['payment_due_date'] ?? 'N/A'," => ""
]);

// 6. index.php - Upcoming Payments Widget
// It's inside a col-xl-4 block. Let's find exactly what to remove.
$index = file_get_contents('index.php');
if (strpos($index, "Payment Collection Reminders") !== false) {
    $start = strpos($index, "<!-- Payment Collection Reminders Widget -->");
    $end = strpos($index, "<!-- END Right Column -->");
    if ($start !== false && $end !== false) {
        $index = substr($index, 0, $start) . substr($index, $end);
        file_put_contents('index.php', $index);
    }
}

// 7. records.php
$records = file_get_contents('records.php');
$records = preg_replace('/<th>Due Date<\/th>/', '', $records);
$records = preg_replace('/<td>\s*<\?php if \(!empty\(\$row\[\'payment_due_date\'\]\)\):\s*\?>\s*<div><\?= format_date\(\$row\[\'payment_due_date\'\]\) \?><\/div>.*?(<\/td>)/s', '', $records);
file_put_contents('records.php', $records);

// 8. api/scan_push.php
$api = file_get_contents('api/scan_push.php');
$api = preg_replace('/\'payment_due_date\'\s*=>\s*.*?,/', '', $api);
file_put_contents('api/scan_push.php', $api);

echo "Payment due date removed successfully.";
