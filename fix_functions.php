<?php
$f = 'includes/functions.php';
$c = file_get_contents($f);

// 1. Remove // Due Date Status in enrich_qid_record
$p1 = '/\s*\/\/ Due Date Status.*?return \$record;/s';
$c = preg_replace($p1, "\n\n    return \$record;", $c);

// 2. Remove Due payments count in get_dashboard_stats
$p2 = '/\s*\/\/ Overdue \/ Due payments count.*?\/\/ Fetch Upcoming Expiries/s';
$c = preg_replace($p2, "\n\n    // Fetch Upcoming Expiries", $c);

// 3. Remove Due payments count from return array of get_dashboard_stats
$c = preg_replace('/\'due_payments_count\'\s*=>\s*\$due_count\s*/', '', $c);

file_put_contents($f, $c);
echo "Fixed functions.php.";
