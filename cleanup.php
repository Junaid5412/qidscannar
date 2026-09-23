<?php
function remove_widget($file) {
    if(!file_exists($file)) return;
    $c = file_get_contents($file);
    $c = preg_replace('/<!-- Payment Collection Reminders Widget -->.*?<!-- END Right Column -->/s', '<!-- END Right Column -->', $c);
    file_put_contents($file, $c);
}
remove_widget('index.php');

function remove_lines($file, $patterns) {
    if(!file_exists($file)) return;
    $c = file_get_contents($file);
    foreach($patterns as $p) {
        $c = preg_replace($p, '', $c);
    }
    file_put_contents($file, $c);
}

remove_lines('record_edit.php', [
    '/<div class="col-md-6 mb-3">\s*<label class="form-label">Payment Due Date<\/label>.*?<\/div>/s',
    '/payment_due_date\s*=\s*:due,/',
    '/\':due\'\s*=>\s*\$payment_due_date,/',
    '/\$payment_due_date\s*=\s*!empty.*?;/'
]);

remove_lines('record_add.php', [
    '/<div class="col-md-6 mb-3">\s*<label class="form-label">Payment Due Date<\/label>.*?<\/div>/s',
    '/,\s*payment_due_date/',
    '/,\s*:due/',
    '/\':due\'\s*=>\s*\$payment_due_date,/',
    '/\$payment_due_date\s*=\s*!empty.*?;/'
]);

remove_lines('record_detail.php', [
    '/<div class="col-6 mb-3">\s*<div class="small text-white-50 text-uppercase tracking-wide mb-1">Payment Due<\/div>.*?<\/div>/s'
]);

remove_lines('statement.php', [
    '/<div class="info-group">\s*<span class="info-label">Payment Due:<\/span>.*?<\/div>/s'
]);

remove_lines('includes/functions.php', [
    '/\/\/ Due Date Status.*?(?=\/\/ Overdue \/ Due payments count)/s',
    '/\/\/ Overdue \/ Due payments count.*?(?=\/\/ Fetch Upcoming Expiries)/s'
]);

echo "Done.";
