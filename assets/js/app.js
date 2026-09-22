/**
 * QID Management System - Frontend Interactivity
 */

document.addEventListener('DOMContentLoaded', () => {
    // 1. Initialize Bootstrap Tooltips
    const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.map((tooltipTriggerEl) => new bootstrap.Tooltip(tooltipTriggerEl));

    // 2. Mobile Sidebar Toggle & Backdrop Handlers (PC & Android)
    const sidebar = document.getElementById('sidebar');
    const sidebarToggle = document.getElementById('sidebarToggle');
    const sidebarCloseBtn = document.getElementById('sidebarCloseBtn');
    const sidebarBackdrop = document.getElementById('sidebarBackdrop');

    function openSidebar() {
        if (sidebar) sidebar.classList.add('sidebar-open');
        if (sidebarBackdrop) sidebarBackdrop.classList.add('show');
        document.body.style.overflow = 'hidden'; // Prevent background scrolling on Android
    }

    function closeSidebar() {
        if (sidebar) sidebar.classList.remove('sidebar-open');
        if (sidebarBackdrop) sidebarBackdrop.classList.remove('show');
        document.body.style.overflow = '';
    }

    if (sidebarToggle) sidebarToggle.addEventListener('click', openSidebar);
    if (sidebarCloseBtn) sidebarCloseBtn.addEventListener('click', closeSidebar);
    if (sidebarBackdrop) sidebarBackdrop.addEventListener('click', closeSidebar);

    // Close on escape key
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && sidebar && sidebar.classList.contains('sidebar-open')) {
            closeSidebar();
        }
    });

    // Close sidebar on mobile when navigating links
    if (sidebar) {
        sidebar.querySelectorAll('.sidebar-nav-link').forEach(link => {
            link.addEventListener('click', () => {
                if (window.innerWidth < 992) {
                    closeSidebar();
                }
            });
        });
    }

    // 2. Real-time Calculations on Add/Edit QID Forms
    const chargeInput = document.getElementById('charge_amount');
    const costInput = document.getElementById('actual_cost');
    const profitDisplay = document.getElementById('expected_profit_display');
    const initialPaidInput = document.getElementById('initial_payment');
    const balanceDisplay = document.getElementById('remaining_balance_display');

    function calculateProfitAndBalance() {
        const charge = parseFloat(chargeInput?.value) || 0;
        const cost = parseFloat(costInput?.value) || 0;
        const paid = parseFloat(initialPaidInput?.value) || 0;

        const profit = charge - cost;
        const balance = Math.max(0, charge - paid);

        if (profitDisplay) {
            profitDisplay.textContent = profit.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            profitDisplay.classList.remove('text-danger', 'text-success');
            profitDisplay.classList.add(profit >= 0 ? 'text-success' : 'text-danger');
        }

        if (balanceDisplay) {
            balanceDisplay.textContent = balance.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }
    }

    if (chargeInput) chargeInput.addEventListener('input', calculateProfitAndBalance);
    if (costInput) costInput.addEventListener('input', calculateProfitAndBalance);
    if (initialPaidInput) initialPaidInput.addEventListener('input', calculateProfitAndBalance);

    // Initial calculation on load
    calculateProfitAndBalance();

    // 3. Payment Modal Calculation
    const paymentModal = document.getElementById('addPaymentModal');
    if (paymentModal) {
        const payAmountInput = paymentModal.querySelector('#pay_amount');
        const payDateInput = paymentModal.querySelector('#pay_date');
        const payTimeInput = paymentModal.querySelector('#pay_time');
        const originalBalanceEl = paymentModal.querySelector('#modal_remaining_balance');
        const newBalanceEl = paymentModal.querySelector('#modal_new_balance');

        paymentModal.addEventListener('show.bs.modal', () => {
            // Set current date and time if empty
            const now = new Date();
            if (payDateInput && !payDateInput.value) {
                const year = now.getFullYear();
                const month = String(now.getMonth() + 1).padStart(2, '0');
                const day = String(now.getDate()).padStart(2, '0');
                payDateInput.value = `${year}-${month}-${day}`;
            }
            if (payTimeInput && !payTimeInput.value) {
                const hours = String(now.getHours()).padStart(2, '0');
                const minutes = String(now.getMinutes()).padStart(2, '0');
                payTimeInput.value = `${hours}:${minutes}`;
            }
            updateModalNewBalance();
        });

        function updateModalNewBalance() {
            if (!originalBalanceEl || !newBalanceEl || !payAmountInput) return;
            const currentBal = parseFloat(originalBalanceEl.dataset.balance) || 0;
            const entered = parseFloat(payAmountInput.value) || 0;
            const newBal = Math.max(0, currentBal - entered);
            newBalanceEl.textContent = newBal.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }

        if (payAmountInput) {
            payAmountInput.addEventListener('input', updateModalNewBalance);
        }
    }

    // 4. Delete Record Modal Dynamic Data Injection
    const deleteModal = document.getElementById('deleteRecordModal');
    if (deleteModal) {
        deleteModal.addEventListener('show.bs.modal', (event) => {
            const button = event.relatedTarget;
            if (!button) return;

            const id = button.getAttribute('data-id');
            const name = button.getAttribute('data-name');
            const qid = button.getAttribute('data-qid');
            const paid = button.getAttribute('data-paid');
            const balance = button.getAttribute('data-balance');

            const idInput = document.getElementById('modalDeleteRecordId');
            const nameEl = document.getElementById('modalDeleteName');
            const qidEl = document.getElementById('modalDeleteQid');
            const paidEl = document.getElementById('modalDeletePaid');
            const balanceEl = document.getElementById('modalDeleteBalance');
            const passInput = document.getElementById('modalDeletePassword');

            if (idInput) idInput.value = id || '';
            if (nameEl) nameEl.textContent = name || '—';
            if (qidEl) qidEl.textContent = qid || '—';
            if (paidEl) paidEl.textContent = paid || '—';
            if (balanceEl) balanceEl.textContent = balance || '—';

            if (passInput) {
                passInput.value = '';
                setTimeout(() => passInput.focus(), 350);
            }
        });
    }

    // 5. Client-side Live Table Filter (Instant Search)
    const tableSearchInput = document.getElementById('tableSearchInput');
    if (tableSearchInput) {
        tableSearchInput.addEventListener('keyup', function() {
            const query = this.value.toLowerCase().trim();
            const targetTable = document.querySelector(this.dataset.tableTarget || '.table-filterable');
            if (!targetTable) return;

            const rows = targetTable.querySelectorAll('tbody tr');
            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                row.style.display = text.includes(query) ? '' : 'none';
            });
        });
    }

    // 5. Header Quick Backup Button Handler
    const quickBackupBtn = document.getElementById('btnQuickBackup');
    if (quickBackupBtn) {
        quickBackupBtn.addEventListener('click', (e) => {
            e.preventDefault();
            const originalHtml = quickBackupBtn.innerHTML;
            quickBackupBtn.disabled = true;
            quickBackupBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i> Backing up...';

            fetch('backup_service.php?action=backup_now', {
                method: 'POST'
            })
            .then(res => res.json())
            .then(data => {
                quickBackupBtn.disabled = false;
                quickBackupBtn.innerHTML = originalHtml;
                if (data.success) {
                    showToast('Backup Completed', data.message, 'success');
                    const lastBackupEl = document.getElementById('headerLastBackupText');
                    if (lastBackupEl) {
                        lastBackupEl.textContent = 'Last: Just now';
                    }
                } else {
                    showToast('Backup Notice', data.message, 'warning');
                }
            })
            .catch(err => {
                quickBackupBtn.disabled = false;
                quickBackupBtn.innerHTML = originalHtml;
                showToast('Backup Error', 'Could not complete backup: ' + err, 'danger');
            });
        });
    }
});

/**
 * Universal Toast Notification Helper
 */
function showToast(title, message, type = 'info') {
    let container = document.getElementById('toastNotificationContainer');
    if (!container) {
        container = document.createElement('div');
        container.id = 'toastNotificationContainer';
        container.className = 'toast-container position-fixed bottom-0 end-0 p-3';
        container.style.zIndex = '9999';
        document.body.appendChild(container);
    }

    const toastId = 'toast_' + Date.now();
    const bgClass = (type === 'success') ? 'text-bg-success' : ((type === 'danger') ? 'text-bg-danger' : 'text-bg-dark');

    const html = `
        <div id="${toastId}" class="toast align-items-center ${bgClass} border-0 shadow-lg" role="alert" aria-live="assertive" aria-atomic="true">
            <div class="d-flex">
                <div class="toast-body">
                    <strong>${title}</strong><br>
                    <small>${message}</small>
                </div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
            </div>
        </div>
    `;

    container.insertAdjacentHTML('beforeend', html);
    const toastEl = document.getElementById(toastId);
    const bsToast = new bootstrap.Toast(toastEl, { delay: 4500 });
    bsToast.show();
    toastEl.addEventListener('hidden.bs.toast', () => toastEl.remove());
}
