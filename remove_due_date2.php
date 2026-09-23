<?php

function rm_preg($file, $pattern) {
    if (!file_exists($file)) return;
    $content = file_get_contents($file);
    $new_content = preg_replace($pattern, '', $content);
    file_put_contents($file, $new_content);
}

// 1. record_add.php
rm_preg('record_add.php', '/<div class="col-md-6 mb-3">[\s\n]*<label class="form-label">Payment Due Date<\/label>.*?<\/div>/s');
rm_preg('record_add.php', '/\s*payment_due_date,?\s*/');
rm_preg('record_add.php', '/\s*\':due\'\s*=>\s*\$payment_due_date,?\s*/');
rm_preg('record_add.php', '/\s*\$payment_due_date\s*=\s*!empty.*?;\s*/');
rm_preg('record_add.php', '/\s*:due,?\s*/');

// 2. record_edit.php
rm_preg('record_edit.php', '/<div class="col-md-6 mb-3">[\s\n]*<label class="form-label">Payment Due Date<\/label>.*?<\/div>/s');
rm_preg('record_edit.php', '/\s*payment_due_date\s*=\s*:due,?\s*/');
rm_preg('record_edit.php', '/\s*\':due\'\s*=>\s*\$payment_due_date,?\s*/');
rm_preg('record_edit.php', '/\s*\$payment_due_date\s*=\s*!empty.*?;\s*/');

// 3. record_detail.php
rm_preg('record_detail.php', '/<div class="col-6 mb-3">[\s\n]*<div class="small text-white-50 text-uppercase tracking-wide mb-1">Payment Due<\/div>.*?<\/div>/s');

// 4. statement.php
rm_preg('statement.php', '/<div class="info-group">[\s\n]*<span class="info-label">Payment Due:<\/span>.*?<\/div>/s');

echo "Regex replacement complete.";
