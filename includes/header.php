<?php
/**
 * Common Navigation Layout with Responsive Sidebar
 * Designed for both Desktop (PC) and Mobile (Android/iOS)
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/auth.php';

// Protect all pages using header.php
require_login();
$current_user = get_current_user_profile();

$current_page = basename($_SERVER['PHP_SELF']);
$flash = get_flash();
$gdrive_active = (get_setting('gdrive_enabled', '0') == '1' && (!empty(get_setting('gdrive_client_email')) || !empty(get_setting('gdrive_refresh_token'))));
$last_backup = get_setting('last_backup_time', '');
$company_name = get_setting('company_name', 'Qatar Business Solutions');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?= htmlspecialchars($page_title ?? 'QID Management System') ?></title>
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome 6 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <!-- Google Fonts: Plus Jakarta Sans -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <!-- Custom CSS -->
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>

<div id="appLayout">
    <!-- =========================================================
         SIDEBAR NAVIGATION (Fixed on PC, Off-canvas on Mobile)
         ========================================================= -->
    <aside id="sidebar">
        <!-- Sidebar Brand / Logo -->
        <div class="sidebar-header">
            <a href="index.php" class="sidebar-brand">
                <span class="brand-icon"><i class="fa-solid fa-id-card"></i></span>
                <div>
                    <span class="fw-bold">QID<span class="text-info">Track</span></span>
                    <span class="d-block text-white-50" style="font-size: 0.68rem; font-weight: normal; letter-spacing: 0.05em;">MANAGEMENT SYSTEM</span>
                </div>
            </a>
            <!-- Mobile Close Button (Android / Phones) -->
            <button type="button" class="sidebar-close-btn d-lg-none" id="sidebarCloseBtn" aria-label="Close menu">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <!-- Sidebar Menu Items -->
        <div class="sidebar-menu">
            <div class="sidebar-heading">Main Navigation</div>
            <ul class="sidebar-nav-list">
                <li class="sidebar-nav-item">
                    <a href="index.php" class="sidebar-nav-link <?= ($current_page === 'index.php') ? 'active' : '' ?>">
                        <i class="fa-solid fa-gauge-high"></i>
                        <span>Dashboard</span>
                    </a>
                </li>
                <li class="sidebar-nav-item">
                    <a href="records.php" class="sidebar-nav-link <?= (in_array($current_page, ['records.php', 'record_detail.php', 'record_edit.php'])) ? 'active' : '' ?>">
                        <i class="fa-solid fa-users"></i>
                        <span>QID Records</span>
                    </a>
                </li>
                <li class="sidebar-nav-item">
                    <a href="record_add.php" class="sidebar-nav-link <?= ($current_page === 'record_add.php') ? 'active' : '' ?>">
                        <i class="fa-solid fa-user-plus"></i>
                        <span>Add New QID</span>
                    </a>
                </li>
                <li class="sidebar-nav-item">
                    <a href="payments.php" class="sidebar-nav-link <?= ($current_page === 'payments.php') ? 'active' : '' ?>">
                        <i class="fa-solid fa-receipt"></i>
                        <span>Payment History</span>
                    </a>
                </li>
                <li class="sidebar-nav-item">
                    <a href="profit_report.php" class="sidebar-nav-link <?= ($current_page === 'profit_report.php') ? 'active' : '' ?>">
                        <i class="fa-solid fa-chart-pie"></i>
                        <span>Profit Report</span>
                    </a>
                </li>
            </ul>

            <div class="sidebar-heading mt-3">Preferences & Cloud</div>
            <ul class="sidebar-nav-list">
                <?php if (is_admin()): ?>
                <li class="sidebar-nav-item">
                    <a href="users.php" class="sidebar-nav-link <?= ($current_page === 'users.php') ? 'active' : '' ?>">
                        <i class="fa-solid fa-users-gear"></i>
                        <span>User Management</span>
                    </a>
                </li>
                <?php endif; ?>
                <li class="sidebar-nav-item">
                    <a href="settings.php" class="sidebar-nav-link <?= ($current_page === 'settings.php') ? 'active' : '' ?>">
                        <i class="fa-solid fa-sliders"></i>
                        <span>Settings & Backup</span>
                    </a>
                </li>
            </ul>

            <!-- Quick Backup & Google Drive Sync Widget in Sidebar -->
            <div class="sidebar-widget">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="small text-white-50 text-uppercase fw-bold" style="font-size: 0.7rem;">Cloud Sync</span>
                    <?php if ($gdrive_active): ?>
                        <span class="badge bg-success-subtle text-success border border-success-subtle" style="font-size: 0.68rem;">
                            <i class="fa-brands fa-google-drive me-1"></i> Active
                        </span>
                    <?php else: ?>
                        <span class="badge bg-secondary-subtle text-light border border-secondary" style="font-size: 0.68rem;">
                            <i class="fa-solid fa-hard-drive me-1"></i> Local Only
                        </span>
                    <?php endif; ?>
                </div>
                <div class="small text-white-50 mb-3" style="font-size: 0.78rem;">
                    Backups auto-upload on every entry.
                </div>
                <button type="button" class="btn btn-sm btn-primary w-100 d-flex align-items-center justify-content-center gap-1" id="btnQuickBackup">
                    <i class="fa-solid fa-cloud-arrow-up"></i>
                    <span>Backup Database Now</span>
                </button>
            </div>
        </div>

        <!-- Sidebar User & Session Box -->
        <div class="px-3 mb-2">
            <div class="d-flex align-items-center justify-content-between p-2 rounded" style="background: rgba(255,255,255,0.06); border: 1px solid rgba(255,255,255,0.08);">
                <div class="d-flex align-items-center gap-2 overflow-hidden">
                    <div class="rounded-circle bg-primary text-white d-flex align-items-center justify-content-center fw-bold" style="width: 32px; height: 32px; font-size: 0.82rem; flex-shrink: 0;">
                        <i class="fa-solid fa-user-shield"></i>
                    </div>
                    <div class="overflow-hidden">
                        <div class="text-white small fw-bold text-truncate" style="font-size: 0.78rem;"><?= htmlspecialchars($current_user['full_name'] ?? 'Administrator') ?></div>
                        <div class="text-white-50 text-truncate" style="font-size: 0.68rem;">@<?= htmlspecialchars($current_user['username'] ?? 'admin') ?></div>
                    </div>
                </div>
                <a href="logout.php" class="btn btn-outline-danger btn-sm p-1 px-2 border-0" title="Sign Out" onclick="return confirm('Are you sure you want to sign out?');" style="font-size: 0.75rem; color: #fda4af;">
                    <i class="fa-solid fa-right-from-bracket"></i>
                </a>
            </div>
        </div>

        <!-- Sidebar Bottom Footer Info -->
        <div class="sidebar-footer-box text-white-50 small">
            <div class="text-truncate fw-semibold text-white"><?= htmlspecialchars($company_name) ?></div>
            <div class="d-flex justify-content-between align-items-center mt-1" style="font-size: 0.74rem;">
                <span><i class="fa-regular fa-clock me-1"></i> Qatar (UTC+3)</span>
                <span class="badge bg-dark text-white-50 border border-secondary">v1.0.0</span>
            </div>
        </div>
    </aside>

    <!-- Mobile Overlay Backdrop -->
    <div id="sidebarBackdrop"></div>

    <!-- =========================================================
         MAIN CONTENT WRAPPER
         ========================================================= -->
    <div id="mainContentWrapper">
        <!-- Top Navigation Bar (Mobile Toggle + Breadcrumb + Quick Action) -->
        <header class="topbar-header d-flex align-items-center justify-content-between">
            <div class="d-flex align-items-center gap-3">
                <!-- Mobile Hamburger Button (Shows only on Android / Mobile screens) -->
                <button type="button" class="mobile-nav-toggle d-lg-none" id="sidebarToggle" aria-label="Open navigation menu">
                    <i class="fa-solid fa-bars"></i>
                </button>

                <!-- Page Header Title / Breadcrumb -->
                <div>
                    <h5 class="fw-bold mb-0 text-dark d-none d-sm-block">
                        <?= htmlspecialchars($page_title ?? 'QID Management System') ?>
                    </h5>
                    <span class="fw-bold text-dark d-block d-sm-none">
                        <span class="text-primary">QID</span>Track
                    </span>
                </div>
            </div>

            <!-- Topbar Right Actions -->
            <div class="d-flex align-items-center gap-2">
                <!-- Mobile Scanner Real-Time Status Pill -->
                <div class="d-none d-sm-block">
                    <span id="desktopScannerPill" class="badge bg-light text-secondary border d-inline-flex align-items-center gap-1 py-1 px-2 text-decoration-none" style="font-size: 0.72rem; cursor: pointer;" title="Real-time Mobile Scanner is active for your account">
                        <i class="fa-solid fa-mobile-screen-button text-primary"></i>
                        <span>Scanner Live</span>
                    </span>
                </div>

                <!-- Google Drive Pill (Desktop) -->
                <div class="d-none d-md-block">
                    <?php if ($gdrive_active): ?>
                        <span class="header-badge badge-gdrive-active" data-bs-toggle="tooltip" title="Auto-uploading to Google Drive on every entry">
                            <i class="fa-brands fa-google-drive"></i> G-Drive Synced
                        </span>
                    <?php else: ?>
                        <a href="settings.php#backupSection" class="text-decoration-none">
                            <span class="header-badge badge-gdrive-inactive" data-bs-toggle="tooltip" title="Backups saved locally. Click to configure Google Drive">
                                <i class="fa-solid fa-hard-drive"></i> Local Backup
                            </span>
                        </a>
                    <?php endif; ?>
                </div>

                <!-- Add New QID Button -->
                <a href="record_add.php" class="btn btn-primary btn-sm d-flex align-items-center gap-1">
                    <i class="fa-solid fa-plus"></i>
                    <span class="d-none d-sm-inline">New QID</span>
                </a>

                <!-- User Account Dropdown -->
                <div class="dropdown">
                    <button class="btn btn-outline-secondary btn-sm dropdown-toggle d-flex align-items-center gap-2" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="fa-solid fa-circle-user text-primary"></i>
                        <span class="d-none d-md-inline small fw-semibold"><?= htmlspecialchars($current_user['username'] ?? 'admin') ?></span>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end shadow border-0 mt-1" style="min-width: 200px;">
                        <li>
                            <div class="px-3 py-2 border-bottom">
                                <div class="fw-bold small text-dark"><?= htmlspecialchars($current_user['full_name'] ?? 'Administrator') ?></div>
                                <div class="text-muted" style="font-size: 0.72rem;">Role: <strong><?= strtoupper(htmlspecialchars($current_user['role'] ?? 'admin')) ?></strong></div>
                            </div>
                        </li>
                        <li>
                            <a class="dropdown-item small py-2" href="settings.php#accountSection">
                                <i class="fa-solid fa-user-gear me-2 text-muted"></i> Account Security
                            </a>
                        </li>
                        <li><hr class="dropdown-divider my-1"></li>
                        <li>
                            <a class="dropdown-item small py-2 text-danger" href="logout.php" onclick="return confirm('Are you sure you want to sign out?');">
                                <i class="fa-solid fa-right-from-bracket me-2"></i> Sign Out
                            </a>
                        </li>
                    </ul>
                </div>
            </div>
        </header>

        <!-- Page Main Container -->
        <main class="container-fluid px-lg-4 py-4 flex-grow-1">
            <?php if ($flash): ?>
                <div class="alert alert-<?= htmlspecialchars($flash['type']) ?> alert-dismissible fade show d-flex align-items-center shadow-sm" role="alert">
                    <i class="fa-solid <?= ($flash['type'] === 'success') ? 'fa-circle-check text-success' : 'fa-circle-exclamation text-danger' ?> me-2 fs-5"></i>
                    <div><?= htmlspecialchars($flash['text']) ?></div>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>
