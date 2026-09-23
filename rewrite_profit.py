import os

file_path = 'profit_report.php'

with open(file_path, 'r', encoding='utf-8') as f:
    content = f.read()

# 1. Update Date Defaults
old_dates = """$date_from = trim($_GET['date_from'] ?? '');
$date_to = trim($_GET['date_to'] ?? '');"""

new_dates = """// Default to current month if NO filters are applied
$is_filtered = !empty($_GET);
if (!$is_filtered) {
    $date_from = date('Y-m-01');
    $date_to = date('Y-m-t');
} else {
    $date_from = trim($_GET['date_from'] ?? '');
    $date_to = trim($_GET['date_to'] ?? '');
}"""

content = content.replace(old_dates, new_dates)

# 2. Remove Monthly Stats Computation
# We need to find the start of monthly stats and remove up to "Fetch distinct company list for filter"
start_str = "$current_month_key = date('Y-m');"
end_str = "// Fetch distinct company list for filter"

if start_str in content and end_str in content:
    start_idx = content.find(start_str)
    end_idx = content.find(end_str)
    content = content[:start_idx] + content[end_idx:]

# 3. Remove Monthly UI block 1
start_ui_1 = "<!-- Monthly Profit Timing & Upcoming Forecast KPI Row -->"
end_ui_1 = "<!-- Profit Realization Progress Bar -->"
if start_ui_1 in content and end_ui_1 in content:
    start_idx = content.find(start_ui_1)
    end_idx = content.find(end_ui_1)
    content = content[:start_idx] + content[end_idx:]

# 4. Remove Monthly UI block 2
start_ui_2 = "<!-- Monthly Profit Forecast & Realized Cash Table -->"
end_ui_2 = "<!-- Profit Summary by Company / Sponsor -->"
if start_ui_2 in content and end_ui_2 in content:
    start_idx = content.find(start_ui_2)
    end_idx = content.find(end_ui_2)
    content = content[:start_idx] + content[end_idx:]


with open(file_path, 'w', encoding='utf-8') as f:
    f.write(content)

print("Updated profit_report.php successfully.")
