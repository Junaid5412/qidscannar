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
</body>
</html>
