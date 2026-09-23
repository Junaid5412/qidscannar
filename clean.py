import os
import re

def remove_regex(filepath, pattern):
    if not os.path.exists(filepath): return
    with open(filepath, 'r', encoding='utf-8') as f:
        content = f.read()
    content = re.sub(pattern, '', content, flags=re.DOTALL)
    with open(filepath, 'w', encoding='utf-8') as f:
        f.write(content)

# Remove widget from index.php
remove_regex('index.php', r'<!-- Payment Collection Reminders Widget -->.*?<!-- END Right Column -->')

# Remove HTML from record_edit
remove_regex('record_edit.php', r'<div class="col-md-6 mb-3">\s*<label class="form-label">Payment Due Date</label>.*?</div>')
# Remove HTML from record_add
remove_regex('record_add.php', r'<div class="col-md-6 mb-3">\s*<label class="form-label">Payment Due Date</label>.*?</div>')
# Remove HTML from record_detail
remove_regex('record_detail.php', r'<div class="col-6 mb-3">\s*<div class="small text-white-50 text-uppercase tracking-wide mb-1">Payment Due</div>.*?</div>')
# Remove HTML from statement
remove_regex('statement.php', r'<div class="info-group">\s*<span class="info-label">Payment Due:</span>.*?</div>')

# Clean functions.php (removing remaining functions or logic)
remove_regex('includes/functions.php', r'// Overdue / Due payments count.*?// Fetch Upcoming Expiries')

print("Done via python.")
