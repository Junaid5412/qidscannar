<?php
$f = 'profit_report.php';
$c = file_get_contents($f);

// 1. Remove Net Expected Profit KPI card
$c = preg_replace('/<!-- Net Expected Profit -->.*?<!-- Realized Cash Profit -->/s', '<!-- Realized Cash Profit -->', $c);

// 2. Change col-xl-3 to col-xl-4 in KPI cards
$c = preg_replace('/<div class="col-xl-3 col-sm-6">/', '<div class="col-xl-4 col-sm-12">', $c);

// 3. Remove Profit Realization Progress Bar
$c = preg_replace('/<!-- Profit Realization Progress Bar -->.*?<!-- Search & Filter Controls -->/s', '<!-- Search & Filter Controls -->', $c);

// 4. Remove 'Pending to collect' text inside Realized Cash Profit to keep it simple
$c = preg_replace('/<div class="kpi-sub text-muted">\s*Pending to collect.*?<\/div>/s', '', $c);

file_put_contents($f, $c);
echo "Updated profit_report.php";
