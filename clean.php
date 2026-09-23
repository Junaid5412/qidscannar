<?php
function rmr($f, $p) {
    if(!file_exists($f)) return;
    $c = file_get_contents($f);
    $c = preg_replace($p, '', $c);
    file_put_contents($f, $c);
}

rmr('index.php', '/<!-- Payment Collection Reminders Widget -->.*?<!-- END Right Column -->/s');
rmr('record_edit.php', '/<div class="col-md-6 mb-3">\s*<label class="form-label">Payment Due Date<\/label>.*?<\/div>/s');
rmr('record_add.php', '/<div class="col-md-6 mb-3">\s*<label class="form-label">Payment Due Date<\/label>.*?<\/div>/s');
rmr('record_detail.php', '/<div class="col-6 mb-3">\s*<div class="small text-white-50 text-uppercase tracking-wide mb-1">Payment Due<\/div>.*?<\/div>/s');
rmr('statement.php', '/<div class="info-group">\s*<span class="info-label">Payment Due:<\/span>.*?<\/div>/s');
rmr('includes/functions.php', '/\/\/ Overdue \/ Due payments count.*?\/\/ Fetch Upcoming Expiries/s');

echo "Done.";
