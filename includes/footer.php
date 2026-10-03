        </main> <!-- End Main Container -->

        <footer class="mt-auto py-3 bg-white border-top text-muted small footer-custom">
            <div class="container-fluid px-lg-4 d-flex flex-column flex-sm-row justify-content-between align-items-center gap-2">
                <div>
                    <strong><?= htmlspecialchars(get_setting('app_name', 'QID Management System')) ?></strong> &copy; <?= date('Y') ?> &bull; Qatar Resident ID & Finance Tracker
                </div>
                <div class="d-flex align-items-center gap-3">
                    <span id="headerLastBackupText">
                        <i class="fa-regular fa-clock me-1"></i>
                        Last Backup: <?= !empty($last_backup) ? format_datetime($last_backup) : 'None yet' ?>
                    </span>
                    <span class="text-secondary">&bull;</span>
                    <span class="badge bg-light text-dark border">v1.0.0</span>
                </div>
            </div>
        </footer>
    </div> <!-- End #mainContentWrapper -->
</div> <!-- End #appLayout -->

<!-- Bootstrap 5 Bundle JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<!-- Main App Script -->
<script src="assets/js/app.js"></script>
<!-- Real-Time Mobile Scanner Synchronizer -->
<script src="assets/js/scanner_listener.js"></script>

<!-- ===== Shared "No Records Found" Popup ===== -->
<style>
    #noResultsModal .modal-content { border: 0; border-radius: 20px; overflow: hidden; box-shadow: 0 25px 60px rgba(15, 23, 42, .25); }
    #noResultsModal .nr-hero { background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 50%, #ec4899 100%); padding: 34px 20px 58px; position: relative; text-align: center; }
    #noResultsModal .nr-hero::after { content: ""; position: absolute; left: 0; right: 0; bottom: -1px; height: 40px; background: #fff; border-radius: 50% 50% 0 0 / 100% 100% 0 0; }
    #noResultsModal .nr-icon { width: 92px; height: 92px; margin: 0 auto; border-radius: 50%; background: rgba(255,255,255,.18); display: flex; align-items: center; justify-content: center; position: relative; z-index: 1; animation: nrFloat 2.6s ease-in-out infinite; }
    #noResultsModal .nr-icon::before { content: ""; position: absolute; inset: -10px; border-radius: 50%; border: 2px solid rgba(255,255,255,.35); animation: nrPulse 2s ease-out infinite; }
    #noResultsModal .nr-icon i { font-size: 2.6rem; color: #fff; }
    #noResultsModal .nr-term { display: inline-block; max-width: 100%; background: #f1f5f9; border: 1px dashed #c7d2fe; color: #4f46e5; padding: 4px 14px; border-radius: 999px; font-weight: 600; word-break: break-all; }
    #noResultsModal .nr-tips li { margin-bottom: 4px; }
    #noResultsModal.fade .modal-dialog { transform: scale(.85); transition: transform .3s cubic-bezier(.34,1.56,.64,1); }
    #noResultsModal.show .modal-dialog { transform: scale(1); }
    @keyframes nrFloat { 0%,100% { transform: translateY(0); } 50% { transform: translateY(-8px); } }
    @keyframes nrPulse { 0% { transform: scale(.9); opacity: 1; } 100% { transform: scale(1.35); opacity: 0; } }
</style>

<div class="modal fade" id="noResultsModal" tabindex="-1" aria-labelledby="noResultsTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width: 430px;">
        <div class="modal-content">
            <div class="nr-hero">
                <button type="button" class="btn-close btn-close-white position-absolute top-0 end-0 m-3" data-bs-dismiss="modal" aria-label="Close" style="z-index:2"></button>
                <div class="nr-icon"><i class="fa-solid fa-magnifying-glass-minus"></i></div>
            </div>
            <div class="modal-body text-center px-4 pt-0 pb-4">
                <h4 class="fw-bold text-dark mb-2" id="noResultsTitle">No Record Found</h4>
                <p class="text-muted mb-3" id="noResultsMessage">We couldn't find anything matching your search.</p>
                <div class="mb-3" id="noResultsTermWrap">
                    <span class="nr-term"><i class="fa-solid fa-quote-left me-1 small"></i><span id="noResultsTerm"></span><i class="fa-solid fa-quote-right ms-1 small"></i></span>
                </div>
                <div class="text-start bg-light rounded-3 p-3 mb-4 small text-muted">
                    <div class="fw-semibold text-dark mb-1"><i class="fa-regular fa-lightbulb text-warning me-1"></i>Try this:</div>
                    <ul class="nr-tips mb-0 ps-3">
                        <li>Check the spelling or type fewer letters</li>
                        <li>Search by QID number, name or phone</li>
                        <li>Clear the filters and search again</li>
                    </ul>
                </div>
                <div class="d-flex gap-2 justify-content-center flex-wrap">
                    <a href="#" id="noResultsClearBtn" class="btn btn-outline-secondary px-4 rounded-pill">
                        <i class="fa-solid fa-rotate-left me-1"></i> Clear Search
                    </a>
                    <a href="record_add.php" id="noResultsAddBtn" class="btn btn-primary px-4 rounded-pill" style="background: linear-gradient(135deg,#6366f1,#8b5cf6); border: 0;">
                        <i class="fa-solid fa-user-plus me-1"></i> Add New Person
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
/**
 * Show the shared "No Record Found" popup.
 * opts: { term, message, clearUrl, showAdd }
 */
window.showNoResultsPopup = function (opts) {
    opts = opts || {};
    const modalEl = document.getElementById('noResultsModal');
    if (!modalEl || typeof bootstrap === 'undefined') return;

    const term = (opts.term || '').trim();
    document.getElementById('noResultsTerm').textContent = term;
    document.getElementById('noResultsTermWrap').style.display = term ? '' : 'none';
    document.getElementById('noResultsMessage').textContent =
        opts.message || (term ? "We couldn't find anything matching your search." : "No records match the selected filters.");

    const clearBtn = document.getElementById('noResultsClearBtn');
    if (opts.clearUrl) {
        clearBtn.setAttribute('href', opts.clearUrl);
        clearBtn.onclick = null;
    } else {
        clearBtn.setAttribute('href', '#');
        clearBtn.onclick = function (e) {
            e.preventDefault();
            if (typeof opts.onClear === 'function') opts.onClear();
            bootstrap.Modal.getInstance(modalEl).hide();
        };
    }
    document.getElementById('noResultsAddBtn').style.display = (opts.showAdd === false) ? 'none' : '';

    bootstrap.Modal.getOrCreateInstance(modalEl).show();
};

<?php if (!empty($no_results_popup) && is_array($no_results_popup)): ?>
document.addEventListener('DOMContentLoaded', function () {
    window.showNoResultsPopup(<?= json_encode($no_results_popup, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>);
});
<?php endif; ?>
</script>
</body>
</html>
