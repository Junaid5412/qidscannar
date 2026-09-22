<?php
/**
 * QID Management System - Settings & Backup Configuration
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/backup_service.php';

$page_title = 'Settings & Cloud Backup';
$db = getDB();

$error = '';
$success = '';

// Handle Google OAuth 2.0 Return Authorization Code
if (isset($_GET['code'])) {
    $code = trim($_GET['code']);
    $client_id = get_setting('gdrive_oauth_client_id', '');
    $client_secret = get_setting('gdrive_oauth_client_secret', '');
    $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
    $redirect_uri = $scheme . '://' . $_SERVER['HTTP_HOST'] . strtok($_SERVER['REQUEST_URI'], '?');

    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'code'          => $code,
        'client_id'     => $client_id,
        'client_secret' => $client_secret,
        'redirect_uri'  => $redirect_uri,
        'grant_type'    => 'authorization_code'
    ]));
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $response = curl_exec($ch);
    $data = json_decode($response, true);
    curl_close($ch);

    if (!empty($data['refresh_token'])) {
        update_setting('gdrive_auth_method', 'oauth');
        update_setting('gdrive_refresh_token', $data['refresh_token']);
        update_setting('gdrive_enabled', '1');
        set_flash('success', 'Google Drive successfully connected via OAuth! Automated cloud backups are now fully enabled using your personal Drive storage quota.');
    } elseif (!empty($data['access_token'])) {
        update_setting('gdrive_auth_method', 'oauth');
        update_setting('gdrive_enabled', '1');
        set_flash('success', 'Google Drive linked successfully!');
    } else {
        $err = $data['error_description'] ?? ($data['error'] ?? 'Authentication failed');
        set_flash('danger', 'Google OAuth authorization error: ' . $err);
    }
    header("Location: settings.php#backupSection");
    exit;
}

// Handle Settings Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'save_oauth_settings') {
        $client_id = trim($_POST['gdrive_oauth_client_id'] ?? '');
        $client_secret = trim($_POST['gdrive_oauth_client_secret'] ?? '');
        $folder_id = trim($_POST['gdrive_folder_id'] ?? '');
        $auto_backup = isset($_POST['auto_backup_on_entry']) ? '1' : '0';

        if (isset($_FILES['oauth_json_file']) && $_FILES['oauth_json_file']['error'] === UPLOAD_ERR_OK) {
            $json_raw = file_get_contents($_FILES['oauth_json_file']['tmp_name']);
            $json_arr = json_decode($json_raw, true);
            $web = $json_arr['web'] ?? ($json_arr['installed'] ?? []);
            if (!empty($web['client_id']) && !empty($web['client_secret'])) {
                $client_id = $web['client_id'];
                $client_secret = $web['client_secret'];
            }
        }

        update_setting('gdrive_oauth_client_id', $client_id);
        update_setting('gdrive_oauth_client_secret', $client_secret);
        update_setting('gdrive_folder_id', $folder_id);
        update_setting('auto_backup_on_entry', $auto_backup);
        update_setting('gdrive_auth_method', 'oauth');

        set_flash('success', 'OAuth credentials saved. Now click "Connect with Google Drive" to authorize access.');
        header("Location: settings.php#backupSection");
        exit;
    }

    if ($action === 'disconnect_gdrive') {
        update_setting('gdrive_refresh_token', '');
        update_setting('gdrive_enabled', '0');
        set_flash('success', 'Google Drive account disconnected successfully.');
        header("Location: settings.php#backupSection");
        exit;
    }

    if ($action === 'save_general_settings') {
        update_setting('app_name', trim($_POST['app_name'] ?? 'QID Management System'));
        update_setting('currency', trim($_POST['currency'] ?? 'QR'));
        update_setting('expiry_alert_days', (int)($_POST['expiry_alert_days'] ?? 30));
        update_setting('due_alert_days', (int)($_POST['due_alert_days'] ?? 7));
        update_setting('company_name', trim($_POST['company_name'] ?? ''));
        update_setting('company_phone', trim($_POST['company_phone'] ?? ''));
        $admin_del_pass = trim($_POST['admin_delete_password'] ?? '');
        if (!empty($admin_del_pass)) {
            update_setting('admin_delete_password', $admin_del_pass);
        }

        set_flash('success', 'General system settings updated successfully.');
        header("Location: settings.php");
        exit;
    }

    if ($action === 'save_admin_account') {
        $user_id = (int)($_SESSION['qid_user_id'] ?? 0);
        $new_username = trim($_POST['username'] ?? '');
        $new_fullname = trim($_POST['full_name'] ?? '');
        $current_pass = $_POST['current_password'] ?? '';
        $new_pass = $_POST['new_password'] ?? '';
        $confirm_pass = $_POST['confirm_password'] ?? '';

        if (empty($new_username) || empty($new_fullname)) {
            set_flash('danger', 'Username and display name are required.');
            header("Location: settings.php#accountSection");
            exit;
        }

        $stmt = $db->prepare("SELECT id, password_hash FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch();

        if (!$user) {
            set_flash('danger', 'Admin user account record not found.');
            header("Location: settings.php#accountSection");
            exit;
        }

        $check_stmt = $db->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
        $check_stmt->execute([$new_username, $user_id]);
        if ($check_stmt->fetch()) {
            set_flash('danger', 'Username "' . htmlspecialchars($new_username) . '" is already in use.');
            header("Location: settings.php#accountSection");
            exit;
        }

        if (!empty($new_pass)) {
            if (!password_verify($current_pass, $user['password_hash'])) {
                set_flash('danger', 'Current password was incorrect. Password was not changed.');
                header("Location: settings.php#accountSection");
                exit;
            }
            if ($new_pass !== $confirm_pass) {
                set_flash('danger', 'New password and confirmation do not match.');
                header("Location: settings.php#accountSection");
                exit;
            }
            if (strlen($new_pass) < 6) {
                set_flash('danger', 'New password must be at least 6 characters long.');
                header("Location: settings.php#accountSection");
                exit;
            }

            $new_hash = password_hash($new_pass, PASSWORD_BCRYPT);
            $up = $db->prepare("UPDATE users SET username = ?, full_name = ?, password_hash = ? WHERE id = ?");
            $up->execute([$new_username, $new_fullname, $new_hash, $user_id]);
        } else {
            $up = $db->prepare("UPDATE users SET username = ?, full_name = ? WHERE id = ?");
            $up->execute([$new_username, $new_fullname, $user_id]);
        }

        $_SESSION['qid_user_name'] = $new_username;
        $_SESSION['qid_full_name'] = $new_fullname;

        set_flash('success', 'Admin login credentials updated successfully.');
        header("Location: settings.php#accountSection");
        exit;
    }

    if ($action === 'save_gdrive_settings') {
        $gdrive_enabled = isset($_POST['gdrive_enabled']) ? '1' : '0';
        $auto_backup_on_entry = isset($_POST['auto_backup_on_entry']) ? '1' : '0';
        $folder_id = trim($_POST['gdrive_folder_id'] ?? '');
        $client_email = trim($_POST['gdrive_client_email'] ?? '');
        $private_key = trim($_POST['gdrive_private_key'] ?? '');

        // Check if a JSON key file was uploaded
        if (isset($_FILES['json_file']) && $_FILES['json_file']['error'] === UPLOAD_ERR_OK) {
            $json_raw = file_get_contents($_FILES['json_file']['tmp_name']);
            $json_arr = json_decode($json_raw, true);
            if (!empty($json_arr['client_email']) && !empty($json_arr['private_key'])) {
                $client_email = $json_arr['client_email'];
                $private_key = $json_arr['private_key'];
            } else {
                $error = 'Uploaded file was not a valid Google Service Account JSON key.';
            }
        }

        if (empty($error)) {
            update_setting('gdrive_enabled', $gdrive_enabled);
            update_setting('auto_backup_on_entry', $auto_backup_on_entry);
            update_setting('gdrive_folder_id', $folder_id);
            update_setting('gdrive_client_email', $client_email);
            if (!empty($private_key)) {
                update_setting('gdrive_private_key', $private_key);
            }

            set_flash('success', 'Google Drive backup settings saved successfully.');
            header("Location: settings.php#backupSection");
            exit;
        }
    }

    if ($action === 'manual_backup') {
        $res = run_system_backup('manual');
        if ($res['success']) {
            set_flash('success', $res['message']);
        } else {
            set_flash('danger', 'Backup error: ' . $res['message']);
        }
        header("Location: settings.php#backupSection");
        exit;
    }

    if ($action === 'delete_backup_file' && !empty($_POST['backup_filename'])) {
        $del_filename = basename($_POST['backup_filename']);
        $del_path = __DIR__ . '/backups/' . $del_filename;
        if (file_exists($del_path)) {
            @unlink($del_path);
            set_flash('success', "Backup file {$del_filename} deleted.");
        }
        header("Location: settings.php#backupSection");
        exit;
    }
}

// Fetch recent backup records
$backup_logs = [];
try {
    $stmt = $db->query("SELECT * FROM backups_log ORDER BY id DESC LIMIT 15");
    $backup_logs = $stmt->fetchAll();
} catch (Exception $e) {
    //
}

// Get local backup files
$local_backups = glob(__DIR__ . '/backups/qid_backup_*.sql');
if ($local_backups) {
    usort($local_backups, function($a, $b) {
        return filemtime($b) - filemtime($a);
    });
} else {
    $local_backups = [];
}

$current_admin = null;
if (isset($_SESSION['qid_user_id'])) {
    $u_stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
    $u_stmt->execute([(int)$_SESSION['qid_user_id']]);
    $current_admin = $u_stmt->fetch();
}

$scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
$redirect_uri = $scheme . '://' . $_SERVER['HTTP_HOST'] . strtok($_SERVER['REQUEST_URI'], '?');
$oauth_client_id = get_setting('gdrive_oauth_client_id', '');
$oauth_client_secret = get_setting('gdrive_oauth_client_secret', '');
$oauth_refresh_token = get_setting('gdrive_refresh_token', '');
$oauth_connected = !empty($oauth_refresh_token);

$oauth_auth_url = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
    'client_id'     => $oauth_client_id,
    'redirect_uri'  => $redirect_uri,
    'response_type' => 'code',
    'scope'         => 'https://www.googleapis.com/auth/drive.file',
    'access_type'   => 'offline',
    'prompt'        => 'consent'
]);

include __DIR__ . '/includes/header.php';
?>

<div class="row justify-content-center">
    <div class="col-lg-10 col-xl-9">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h3 class="fw-bold mb-1 text-dark">System Settings & Backups</h3>
                <p class="text-muted mb-0 small">Configure reminder dates, currency, and automatic Google Drive backups</p>
            </div>
        </div>

        <?php if (!empty($error)): ?>
            <div class="alert alert-danger d-flex align-items-center mb-4" role="alert">
                <i class="fa-solid fa-circle-exclamation me-2 fs-5"></i>
                <div><?= htmlspecialchars($error) ?></div>
            </div>
        <?php endif; ?>

        <!-- 1. General & Reminder Settings Form -->
        <div class="card shadow-sm mb-4">
            <div class="card-header-clean">
                <h5><i class="fa-solid fa-bell text-warning"></i> Reminder Thresholds & Business Info</h5>
            </div>
            <div class="card-body p-4">
                <form method="POST" action="settings.php">
                    <input type="hidden" name="action" value="save_general_settings">

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label">System / Company Name</label>
                            <input type="text" name="company_name" class="form-control" value="<?= htmlspecialchars(get_setting('company_name', 'Qatar Business Solutions')) ?>">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Contact Phone / WhatsApp</label>
                            <input type="text" name="company_phone" class="form-control" value="<?= htmlspecialchars(get_setting('company_phone', '+974 5500 0000')) ?>">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">System Currency</label>
                            <input type="text" name="currency" class="form-control fw-bold" value="<?= htmlspecialchars(get_setting('currency', 'QR')) ?>">
                            <div class="form-text">e.g. <code>QR</code> or <code>QAR</code></div>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">QID Expiry Alert Days</label>
                            <div class="input-group">
                                <input type="number" name="expiry_alert_days" class="form-control" min="1" max="180" value="<?= htmlspecialchars(get_setting('expiry_alert_days', 30)) ?>">
                                <span class="input-group-text bg-light">Days</span>
                            </div>
                            <div class="form-text">Alerts on Dashboard when QID expires in &le; this days.</div>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Payment Due Date Alert Days</label>
                            <div class="input-group">
                                <input type="number" name="due_alert_days" class="form-control" min="1" max="90" value="<?= htmlspecialchars(get_setting('due_alert_days', 7)) ?>">
                                <span class="input-group-text bg-light">Days</span>
                            </div>
                            <div class="form-text">Alerts when pending collection due date is within this days.</div>
                        </div>

                        <div class="col-md-12">
                            <label class="form-label text-danger fw-bold"><i class="fa-solid fa-lock me-1"></i> Record Deletion Security Password</label>
                            <div class="input-group">
                                <span class="input-group-text bg-light text-danger"><i class="fa-solid fa-key"></i></span>
                                <input type="password" name="admin_delete_password" class="form-control font-monospace" placeholder="Enter record deletion security password" value="<?= htmlspecialchars(get_setting('admin_delete_password', 'admin123')) ?>">
                            </div>
                            <div class="form-text">This security password is required whenever someone attempts to delete a QID record to prevent accidental data loss.</div>
                        </div>
                    </div>

                    <div class="d-flex justify-content-end">
                        <button type="submit" class="btn btn-primary">
                            <i class="fa-solid fa-check me-1"></i> Save Reminder Settings
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Mobile Scanner App & Real-Time Sync Section -->
        <div class="card shadow-sm mb-4" id="mobileAppSection" style="border-left: 4px solid #8a1538;">
            <div class="card-header-clean d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="fa-solid fa-mobile-screen-button text-danger"></i> Mobile Scanner App (Real-Time Desktop Synchronizer)</h5>
                <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1"><i class="fa-solid fa-bolt me-1"></i>Live Sync Active</span>
            </div>
            <div class="card-body p-4">
                <div class="row align-items-center g-3">
                    <div class="col-md-8">
                        <h6 class="fw-bold text-dark mb-1">Instant QID Barcode Scanning from Android Phone</h6>
                        <p class="text-muted small mb-2">
                            Staff can scan physical Qatar ID cards with their Android phone camera. Scans are immediately transmitted via private SSE stream directly into this desktop dashboard in under 100ms.
                        </p>
                        <div class="d-flex flex-wrap gap-2 pt-1">
                            <span class="badge bg-light text-dark border"><i class="fa-solid fa-lock text-success me-1"></i> Per-User Privacy Isolated</span>
                            <span class="badge bg-light text-dark border"><i class="fa-solid fa-camera text-primary me-1"></i> Google ML Kit Barcode Vision</span>
                            <span class="badge bg-light text-dark border"><i class="fa-solid fa-network-wired text-info me-1"></i> Sub-Second Push</span>
                        </div>
                    </div>
                    <div class="col-md-4 text-md-end">
                        <div class="p-3 bg-light rounded border text-start">
                            <div class="small fw-bold text-dark mb-1"><i class="fa-solid fa-link text-primary me-1"></i> Mobile App Server URL:</div>
                            <code class="d-block text-break small p-1 bg-white border rounded mb-2"><?= htmlspecialchars($scheme . '://' . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['REQUEST_URI']), '/\\')) ?></code>
                            <div class="small text-muted">Android source code located in <code>mobile_app/</code> with automated GitHub Actions CI/CD.</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- 2. Admin Account & Login Security Credentials -->
        <div class="card shadow-sm mb-4" id="accountSection">
            <div class="card-header-clean">
                <h5><i class="fa-solid fa-user-shield text-primary"></i> Administrator Account & Login Security</h5>
            </div>
            <div class="card-body p-4">
                <form method="POST" action="settings.php">
                    <input type="hidden" name="action" value="save_admin_account">

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Login Username <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text bg-light"><i class="fa-solid fa-at text-muted"></i></span>
                                <input type="text" name="username" class="form-control" value="<?= htmlspecialchars($current_admin['username'] ?? 'admin') ?>" required>
                            </div>
                            <div class="form-text">The username used to sign in to the system.</div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Display Full Name <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text bg-light"><i class="fa-solid fa-user text-muted"></i></span>
                                <input type="text" name="full_name" class="form-control" value="<?= htmlspecialchars($current_admin['full_name'] ?? 'Administrator') ?>" required>
                            </div>
                            <div class="form-text">Displayed on the sidebar profile badge and official records.</div>
                        </div>
                    </div>

                    <div class="p-3 bg-light rounded-3 mb-3 border">
                        <h6 class="fw-bold text-dark mb-1"><i class="fa-solid fa-key me-1 text-secondary"></i> Change Login Password</h6>
                        <p class="small text-muted mb-3">Leave blank if you do not want to change your login password.</p>
                        
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label small fw-semibold">Current Password</label>
                                <input type="password" name="current_password" class="form-control font-monospace" placeholder="Current password">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small fw-semibold">New Password</label>
                                <input type="password" name="new_password" class="form-control font-monospace" placeholder="Min. 6 characters">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small fw-semibold">Confirm New Password</label>
                                <input type="password" name="confirm_password" class="form-control font-monospace" placeholder="Re-type new password">
                            </div>
                        </div>
                    </div>

                    <div class="d-flex justify-content-end">
                        <button type="submit" class="btn btn-primary">
                            <i class="fa-solid fa-floppy-disk me-1"></i> Update Admin Credentials
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- 2. Google Drive Cloud Backup Configuration -->
        <div class="card shadow-sm mb-4" id="backupSection">
            <div class="card-header-clean bg-white">
                <h5>
                    <i class="fa-brands fa-google-drive text-success"></i>
                    <span>Google Drive Automatic Cloud Backup</span>
                </h5>
                <form method="POST" action="settings.php" class="m-0">
                    <input type="hidden" name="action" value="manual_backup">
                    <button type="submit" class="btn btn-sm btn-outline-primary">
                        <i class="fa-solid fa-cloud-arrow-up me-1"></i> Trigger Backup Now
                    </button>
                </form>
            </div>
            <div class="card-body p-4">
                <!-- Quota explanation notice -->
                <div class="alert alert-info d-flex align-items-start gap-3 mb-4 rounded-3 border-0 shadow-sm">
                    <i class="fa-solid fa-circle-info fs-4 text-primary mt-1"></i>
                    <div class="small">
                        <strong class="d-block text-dark mb-1">Important Note on Google Drive Storage Quota:</strong>
                        Google has changed their policy so that <strong>Service Accounts have 0 bytes storage quota on personal (@gmail.com) accounts</strong>.
                        To upload backups using your personal Google Drive storage, use <strong>Method 1 (OAuth 2.0)</strong> below. 
                        If your company uses <strong>Google Workspace with a Shared Drive</strong>, you can use <strong>Method 2 (Service Account)</strong>.
                    </div>
                </div>

                <!-- Navigation Tabs -->
                <ul class="nav nav-pills mb-4 gap-2" id="gdriveTabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link <?= empty(get_setting('gdrive_client_email')) || $oauth_connected ? 'active' : '' ?> fw-semibold" id="oauth-tab" data-bs-toggle="tab" data-bs-target="#oauth-pane" type="button" role="tab">
                            <i class="fa-brands fa-google me-1"></i> Method 1: Personal Google Drive (OAuth 2.0 - Recommended)
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link <?= (!empty(get_setting('gdrive_client_email')) && !$oauth_connected) ? 'active' : '' ?> fw-semibold" id="sa-tab" data-bs-toggle="tab" data-bs-target="#sa-pane" type="button" role="tab">
                            <i class="fa-solid fa-robot me-1"></i> Method 2: Service Account (Workspace Shared Drives)
                        </button>
                    </li>
                </ul>

                <div class="tab-content" id="gdriveTabContent">
                    <!-- =========================================================
                         TAB 1: Personal Google Drive (OAuth 2.0)
                         ========================================================= -->
                    <div class="tab-pane fade <?= empty(get_setting('gdrive_client_email')) || $oauth_connected ? 'show active' : '' ?>" id="oauth-pane" role="tabpanel">
                        <?php if ($oauth_connected): ?>
                            <div class="p-3 bg-success-subtle rounded-3 border border-success-subtle mb-4 d-flex align-items-center justify-content-between flex-wrap gap-3">
                                <div class="d-flex align-items-center gap-3">
                                    <div class="p-2 bg-success text-white rounded-circle"><i class="fa-solid fa-check fs-5"></i></div>
                                    <div>
                                        <h6 class="fw-bold text-success-emphasis mb-0">Google Drive Successfully Connected!</h6>
                                        <small class="text-success-emphasis">Automated backups are linked directly to your personal Google Drive quota.</small>
                                    </div>
                                </div>
                                <form method="POST" action="settings.php" class="m-0" onsubmit="return confirm('Disconnect this Google Drive account?');">
                                    <input type="hidden" name="action" value="disconnect_gdrive">
                                    <button type="submit" class="btn btn-sm btn-outline-danger">
                                        <i class="fa-solid fa-link-slash me-1"></i> Disconnect Drive
                                    </button>
                                </form>
                            </div>
                        <?php elseif (!empty($oauth_client_id) && !empty($oauth_client_secret)): ?>
                            <div class="p-4 text-center bg-light rounded-3 border mb-4">
                                <div class="mb-2 text-primary fs-1"><i class="fa-brands fa-google"></i></div>
                                <h5 class="fw-bold text-dark">One Step Remaining!</h5>
                                <p class="text-muted small mb-3">Your OAuth Client credentials are saved. Click below to grant access to your Google Drive.</p>
                                <a href="<?= htmlspecialchars($oauth_auth_url) ?>" class="btn btn-primary px-4 py-2 fw-bold shadow-sm">
                                    <i class="fa-brands fa-google me-2"></i> Connect with Google Drive
                                </a>
                            </div>
                        <?php endif; ?>

                        <!-- Step by step instructions toggle -->
                        <div class="mb-4">
                            <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#oauthSetupGuide">
                                <i class="fa-solid fa-book-open me-1 text-primary"></i> View 3-Minute OAuth Setup Guide (Click to read)
                            </button>
                            <div class="collapse mt-3" id="oauthSetupGuide">
                                <div class="p-3 bg-light rounded-3 border small">
                                    <h6 class="fw-bold text-primary mb-2">How to get your OAuth Client ID & Secret in Google Cloud:</h6>
                                    <ol class="ps-3 mb-2" style="line-height: 1.7;">
                                        <li>In <a href="https://console.cloud.google.com/" target="_blank" class="fw-bold">Google Cloud Console</a>, go to <strong>APIs & Services &rarr; Credentials</strong>.</li>
                                        <li>If you haven't configured the <strong>OAuth Consent Screen</strong>: Click it on the left &rarr; Choose <strong>External</strong> &rarr; Enter app name (e.g. <code>QID Backup</code>) and your email &rarr; Click <strong>Save and Continue</strong> until finished.</li>
                                        <li>Click <strong>+ CREATE CREDENTIALS</strong> at the top &rarr; Select <strong>OAuth client ID</strong>.</li>
                                        <li>Application type: Choose <strong>Web application</strong>. Name: <code>QID Web App</code>.</li>
                                        <li>Under <strong>"Authorized redirect URIs"</strong>, click <strong>ADD URI</strong> and paste:
                                            <div class="input-group my-2" style="max-width: 500px;">
                                                <input type="text" class="form-control form-control-sm font-monospace" id="redirectUriInput" value="<?= htmlspecialchars($redirect_uri) ?>" readonly>
                                                <button class="btn btn-sm btn-outline-primary" type="button" onclick="navigator.clipboard.writeText(document.getElementById('redirectUriInput').value); alert('Copied Redirect URI!');">
                                                    <i class="fa-regular fa-copy me-1"></i> Copy
                                                </button>
                                            </div>
                                        </li>
                                        <li>Click <strong>CREATE</strong>. Copy your <strong>Client ID</strong> and <strong>Client Secret</strong> (or download the JSON) and paste them below!</li>
                                    </ol>
                                </div>
                            </div>
                        </div>

                        <!-- OAuth Form -->
                        <form method="POST" action="settings.php" enctype="multipart/form-data">
                            <input type="hidden" name="action" value="save_oauth_settings">

                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" name="auto_backup_on_entry" id="oauth_auto_backup" value="1" <?= (get_setting('auto_backup_on_entry', '1') == '1') ? 'checked' : '' ?>>
                                <label class="form-check-label fw-bold text-dark" for="oauth_auto_backup">
                                    Automatically upload backup whenever an entry or payment is made
                                </label>
                            </div>

                            <div class="row g-3 mb-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Google Drive Folder ID (Optional)</label>
                                    <input type="text" name="gdrive_folder_id" class="form-control font-monospace" placeholder="e.g. 1a2B3c4D5e6F7g8H9..." value="<?= htmlspecialchars(get_setting('gdrive_folder_id', '')) ?>">
                                    <div class="form-text">Leave empty to save in Root or paste your folder ID from Google Drive URL.</div>
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Upload OAuth Credentials JSON (Optional)</label>
                                    <input type="file" name="oauth_json_file" class="form-control" accept=".json">
                                    <div class="form-text">Upload your <code>client_secret_*.json</code> file to auto-fill Client ID and Secret.</div>
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">OAuth Client ID <span class="text-danger">*</span></label>
                                    <input type="text" name="gdrive_oauth_client_id" class="form-control font-monospace" placeholder="e.g. 123456789-xxx.apps.googleusercontent.com" value="<?= htmlspecialchars($oauth_client_id) ?>" required>
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">OAuth Client Secret <span class="text-danger">*</span></label>
                                    <input type="password" name="gdrive_oauth_client_secret" class="form-control font-monospace" placeholder="e.g. GOCSPX-..." value="<?= htmlspecialchars($oauth_client_secret) ?>" required>
                                </div>
                            </div>

                            <div class="d-flex justify-content-end gap-2">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fa-solid fa-floppy-disk me-1"></i> Save Credentials
                                </button>
                                <?php if (!empty($oauth_client_id) && !empty($oauth_client_secret)): ?>
                                    <a href="<?= htmlspecialchars($oauth_auth_url) ?>" class="btn btn-success fw-semibold">
                                        <i class="fa-brands fa-google me-1"></i> Connect with Google Drive
                                    </a>
                                <?php endif; ?>
                            </div>
                        </form>
                    </div>

                    <!-- =========================================================
                         TAB 2: Service Account (Google Workspace Shared Drives)
                         ========================================================= -->
                    <div class="tab-pane fade <?= (!empty(get_setting('gdrive_client_email')) && !$oauth_connected) ? 'show active' : '' ?>" id="sa-pane" role="tabpanel">
                        <div class="p-3 bg-light rounded-3 mb-4 border">
                            <h6 class="fw-bold text-dark mb-1"><i class="fa-solid fa-building-columns me-1 text-primary"></i> For Google Workspace Shared Drives</h6>
                            <p class="small text-muted mb-0">
                                If using a Service Account, your target folder <strong>must be inside a Google Workspace Shared Drive</strong> (Team Drive) with your Service Account added as a Content Manager member.
                            </p>
                        </div>

                        <form method="POST" action="settings.php" enctype="multipart/form-data">
                            <input type="hidden" name="action" value="save_gdrive_settings">

                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" name="gdrive_enabled" id="gdrive_enabled" value="1" <?= (get_setting('gdrive_enabled', '0') == '1' && empty($oauth_refresh_token)) ? 'checked' : '' ?>>
                                <label class="form-check-label fw-bold text-dark" for="gdrive_enabled">
                                    Enable Service Account Cloud Sync
                                </label>
                            </div>

                            <div class="form-check form-switch mb-4">
                                <input class="form-check-input" type="checkbox" name="auto_backup_on_entry" id="auto_backup_on_entry" value="1" <?= (get_setting('auto_backup_on_entry', '1') == '1') ? 'checked' : '' ?>>
                                <label class="form-check-label fw-bold text-dark" for="auto_backup_on_entry">
                                    Automatically upload backup whenever an entry or payment is made
                                </label>
                            </div>

                            <div class="row g-3 mb-3">
                                <div class="col-md-6">
                                    <label class="form-label">Shared Drive Folder ID <span class="text-danger">*</span></label>
                                    <input type="text" name="gdrive_folder_id" class="form-control font-monospace" placeholder="e.g. 1a2B3c4D5e6F7g8H9..." value="<?= htmlspecialchars(get_setting('gdrive_folder_id', '')) ?>">
                                    <div class="form-text">The ID of your Shared Drive or folder inside the Shared Drive.</div>
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label">Upload Service Account JSON Key</label>
                                    <input type="file" name="json_file" class="form-control" accept=".json">
                                    <div class="form-text">Upload your Google Cloud Service Account JSON file directly.</div>
                                </div>

                                <div class="col-md-12">
                                    <label class="form-label">Google Service Account Client Email</label>
                                    <input type="text" name="gdrive_client_email" class="form-control font-monospace" placeholder="example-service-account@project-id.iam.gserviceaccount.com" value="<?= htmlspecialchars(get_setting('gdrive_client_email', '')) ?>">
                                </div>

                                <div class="col-md-12">
                                    <label class="form-label">Service Account Private Key (PEM format)</label>
                                    <textarea name="gdrive_private_key" class="form-control font-monospace small" rows="3" placeholder="-----BEGIN PRIVATE KEY-----&#10;...&#10;-----END PRIVATE KEY-----"><?= htmlspecialchars(get_setting('gdrive_private_key', '')) ?></textarea>
                                </div>
                            </div>

                            <div class="d-flex justify-content-end">
                                <button type="submit" class="btn btn-success">
                                    <i class="fa-solid fa-floppy-disk me-1"></i> Save Service Account Settings
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <!-- 3. Local Backups & Audit History -->
        <div class="card shadow-sm mb-4">
            <div class="card-header-clean">
                <h5><i class="fa-solid fa-clock-rotate-left text-primary"></i> Local SQL Backups & Activity Log</h5>
                <span class="badge bg-light text-dark border"><?= count($local_backups) ?> Backup files saved</span>
            </div>
            <div class="card-body p-0">
                <?php if (empty($local_backups)): ?>
                    <div class="text-center py-4 text-muted">
                        <p class="mb-0">No backup files created yet. Click "Trigger Backup Now" above to generate your first backup.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-custom align-middle">
                            <thead>
                                <tr>
                                    <th>Backup Filename</th>
                                    <th>File Size</th>
                                    <th>Created Date & Time</th>
                                    <th class="text-end">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach (array_slice($local_backups, 0, 10) as $file): 
                                    $b_name = basename($file);
                                    $b_size = round(filesize($file) / 1024, 2);
                                    $b_time = date('d M Y, h:i A', filemtime($file));
                                ?>
                                    <tr>
                                        <td>
                                            <div class="fw-bold font-monospace text-dark">
                                                <i class="fa-solid fa-database me-1 text-secondary"></i> <?= htmlspecialchars($b_name) ?>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="badge bg-light text-secondary border"><?= $b_size ?> KB</span>
                                        </td>
                                        <td>
                                            <small class="text-muted"><?= $b_time ?></small>
                                        </td>
                                        <td class="text-end">
                                            <div class="btn-group btn-group-sm">
                                                <a href="backup_service.php?action=download&file=<?= urlencode($b_name) ?>" class="btn btn-outline-primary" title="Download SQL Dump">
                                                    <i class="fa-solid fa-download me-1"></i> Download
                                                </a>
                                                <form method="POST" action="settings.php" onsubmit="return confirm('Delete this local backup file?');" style="display:inline;">
                                                    <input type="hidden" name="action" value="delete_backup_file">
                                                    <input type="hidden" name="backup_filename" value="<?= htmlspecialchars($b_name) ?>">
                                                    <button type="submit" class="btn btn-outline-danger" title="Delete">
                                                        <i class="fa-solid fa-trash"></i>
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
