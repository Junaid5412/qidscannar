<?php
$f = 'export.php';
$c = file_get_contents($f);

$c = preg_replace('/\'Expected Profit \(QR\)\',\s*/', '', $c);
$c = preg_replace('/number_format\(\\$enriched\[\'expected_profit\'\], 2, \'\.\', \'\'\),\s*/', '', $c);

file_put_contents($f, $c);
echo "Updated export.php";
