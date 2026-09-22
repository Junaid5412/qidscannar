<?php
/**
 * QID Management System - User Management
 * Admin-only page to manage system users (Desktop + Mobile App)
 */

$page_title = 'User Management';
require_once __DIR__ . '/includes/header.php';

// Only admins can access user management
if (($current_user['role'] ?? '') !== 'admin') {
    set_flash('danger', 'Access denied. Only administrators can manage users.');
    header('Location: index.php');
    exit;
}

$db = getDB();

// One-time migration: add status column if missing (for existing installs)
$migration_marker = sys_get_temp_dir() . '/qid_users_status_migrated.flag';
if (!file_exists($migration_marker)) {
    try {
        $db->exec("ALTER TABLE `users` ADD COLUMN `status` ENUM('active','inactive') NOT NULL DEFAULT 'active' AFTER `role`");
    } catch (PDOException $e) {
        // Column already exists - ignore
    }
    @file_put_contents($migration_marker, date('Y-m-d H:i:s'));
}

// Handle form actions
$action = $_POST['action'] ?? $_GET['action'] ?? '';
$msg = '';
$msg_type = 'success';

// ── ADD USER ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'add_user') {
    $username  = trim($_POST['username'] ?? '');
    $full_name = trim($_POST['full_name'] ?? '');
    $email     = trim($_POST['email'] ?? '');
    $password  = $_POST['password'] ?? '';
    $role      = $_POST['role'] ?? 'staff';
    $status    = $_POST['status'] ?? 'active';

    if (empty($username) || empty($full_name) || empty($password)) {
        $msg = 'Username, full name, and password are required.';
        $msg_type = 'danger';
    } elseif (strlen($password) < 6) {
        $msg = 'Password must be at least 6 characters.';
        $msg_type = 'danger';
    } elseif (!in_array($role, ['admin', 'staff', 'viewer'])) {
        $msg = 'Invalid role selected.';
        $msg_type = 'danger';
    } else {
        // Check if username already exists
        $check = $db->prepare("SELECT COUNT(*) FROM `users` WHERE `username` = ?");
        $check->execute([$username]);
        if ((int)$check->fetchColumn() > 0) {
            $msg = "Username '$username' already exists. Choose a different one.";
            $msg_type = 'danger';
        } else {
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $ins = $db->prepare("INSERT INTO `users` (`username`, `password_hash`, `full_name`, `email`, `role`, `status`) VALUES (?, ?, ?, ?, ?, ?)");
            $ins->execute([$username, $hash, $full_name, $email ?: null, $role, $status]);
            $msg = "User <strong>$username</strong> created successfully!";
            $msg_type = 'success';
        }
    }
}

// ── EDIT USER ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'edit_user') {
    $user_id   = (int)($_POST['user_id'] ?? 0);
    $full_name = trim($_POST['full_name'] ?? '');
    $email     = trim($_POST['email'] ?? '');
    $role      = $_POST['role'] ?? 'staff';
    $status    = $_POST['status'] ?? 'active';
    $password  = $_POST['password'] ?? '';

    if ($user_id <= 0 || empty($full_name)) {
        $msg = 'Invalid user data.';
        $msg_type = 'danger';
    } else {
        // Prevent demoting the last admin
        if ($role !== 'admin') {
            $admin_count = $db->prepare("SELECT COUNT(*) FROM `users` WHERE `role` = 'admin' AND `id` != ? AND `status` = 'active'");
            $admin_count->execute([$user_id]);
            $existing_user = $db->prepare("SELECT `role` FROM `users` WHERE `id` = ?");
            $existing_user->execute([$user_id]);
            $old_role = $existing_user->fetchColumn();

            if ($old_role === 'admin' && (int)$admin_count->fetchColumn() === 0) {
                $msg = 'Cannot change role. This is the last active admin account.';
                $msg_type = 'danger';
            }
        }

        if (empty($msg)) {
            if (!empty($password)) {
                if (strlen($password) < 6) {
                    $msg = 'Password must be at least 6 characters.';
                    $msg_type = 'danger';
                } else {
                    $hash = password_hash($password, PASSWORD_BCRYPT);
                    $upd = $db->prepare("UPDATE `users` SET `full_name` = ?, `email` = ?, `role` = ?, `status` = ?, `password_hash` = ? WHERE `id` = ?");
                    $upd->execute([$full_name, $email ?: null, $role, $status, $hash, $user_id]);
                    $msg = "User updated successfully (password changed).";
                }
            } else {
                $upd = $db->prepare("UPDATE `users` SET `full_name` = ?, `email` = ?, `role` = ?, `status` = ? WHERE `id` = ?");
                $upd->execute([$full_name, $email ?: null, $role, $status, $user_id]);
                $msg = "User updated successfully.";
            }
        }
    }
}

// ── DELETE USER ──
if ($action === 'delete_user' && isset($_GET['id'])) {
    $del_id = (int)$_GET['id'];
    if ($del_id === (int)($current_user['id'] ?? 0)) {
        $msg = 'You cannot delete your own account.';
        $msg_type = 'danger';
    } else {
        // Prevent deleting last admin
        $target = $db->prepare("SELECT `role` FROM `users` WHERE `id` = ?");
        $target->execute([$del_id]);
        $target_role = $target->fetchColumn();

        if ($target_role === 'admin') {
            $admin_count = $db->prepare("SELECT COUNT(*) FROM `users` WHERE `role` = 'admin' AND `id` != ?");
            $admin_count->execute([$del_id]);
            if ((int)$admin_count->fetchColumn() === 0) {
                $msg = 'Cannot delete the last admin account.';
                $msg_type = 'danger';
            }
        }

        if (empty($msg)) {
            // Delete app tokens for this user
            try {
                $db->prepare("DELETE FROM `app_tokens` WHERE `user_id` = ?")->execute([$del_id]);
            } catch (PDOException $e) { /* table may not exist */ }

            $db->prepare("DELETE FROM `users` WHERE `id` = ?")->execute([$del_id]);
            $msg = 'User deleted successfully.';
        }
    }
}

// ── TOGGLE STATUS ──
if ($action === 'toggle_status' && isset($_GET['id'])) {
    $toggle_id = (int)$_GET['id'];
    if ($toggle_id === (int)($current_user['id'] ?? 0)) {
        $msg = 'You cannot deactivate your own account.';
        $msg_type = 'danger';
    } else {
        $stmt = $db->prepare("SELECT `status`, `role` FROM `users` WHERE `id` = ?");
        $stmt->execute([$toggle_id]);
        $u = $stmt->fetch();

        if ($u) {
            $new_status = ($u['status'] === 'active') ? 'inactive' : 'active';

            // Prevent deactivating last admin
            if ($u['role'] === 'admin' && $new_status === 'inactive') {
                $admin_count = $db->prepare("SELECT COUNT(*) FROM `users` WHERE `role` = 'admin' AND `status` = 'active' AND `id` != ?");
                $admin_count->execute([$toggle_id]);
                if ((int)$admin_count->fetchColumn() === 0) {
                    $msg = 'Cannot deactivate the last active admin.';
                    $msg_type = 'danger';
                }
            }

            if (empty($msg)) {
                $db->prepare("UPDATE `users` SET `status` = ? WHERE `id` = ?")->execute([$new_status, $toggle_id]);
                $msg = "User status changed to <strong>$new_status</strong>.";
            }
        }
    }
}

// Fetch all users
$users = $db->query("SELECT * FROM `users` ORDER BY `created_at` ASC")->fetchAll();

// Count active app tokens per user
$token_counts = [];
try {
    $tk = $db->query("SELECT `user_id`, COUNT(*) as cnt FROM `app_tokens` GROUP BY `user_id`");
    while ($row = $tk->fetch()) {
        $token_counts[(int)$row['user_id']] = (int)$row['cnt'];
    }
} catch (PDOException $e) { /* table may not exist yet */ }

$role_labels = [
    'admin'  => ['Admin', 'bg-danger'],
    'staff'  => ['Staff', 'bg-primary'],
    'viewer' => ['Viewer', 'bg-secondary'],
];
?>

<!-- Page Header -->
<div class="d-flex flex-column flex-sm-row justify-content-between align-items-start align-items-sm-center gap-3 mb-4">
    <div>
        <h4 class="fw-bold mb-1"><i class="fa-solid fa-users-gear text-primary me-2"></i>User Management</h4>
        <p class="text-muted small mb-0">Manage accounts for Desktop dashboard & Mobile Scanner App login</p>
    </div>
    <button class="btn btn-primary d-flex align-items-center gap-2" data-bs-toggle="modal" data-bs-target="#addUserModal">
        <i class="fa-solid fa-user-plus"></i>
        <span>Add New User</span>
    </button>
</div>

<?php if (!empty($msg)): ?>
    <div class="alert alert-<?= $msg_type ?> alert-dismissible fade show d-flex align-items-center shadow-sm" role="alert">
        <i class="fa-solid <?= ($msg_type === 'success') ? 'fa-circle-check text-success' : 'fa-circle-exclamation text-danger' ?> me-2 fs-5"></i>
        <div><?= $msg ?></div>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<!-- Role Info Cards -->
<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body text-center py-3">
                <div class="fs-2 text-danger mb-1"><i class="fa-solid fa-crown"></i></div>
                <h6 class="fw-bold mb-1">Admin</h6>
                <p class="text-muted small mb-0">Full access: manage users, settings, records, and mobile app</p>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body text-center py-3">
                <div class="fs-2 text-primary mb-1"><i class="fa-solid fa-id-badge"></i></div>
                <h6 class="fw-bold mb-1">Staff</h6>
                <p class="text-muted small mb-0">Add/edit records, use mobile scanner app, view reports</p>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body text-center py-3">
                <div class="fs-2 text-secondary mb-1"><i class="fa-solid fa-eye"></i></div>
                <h6 class="fw-bold mb-1">Viewer</h6>
                <p class="text-muted small mb-0">Read-only access to dashboard and records (no edits)</p>
            </div>
        </div>
    </div>
</div>

<!-- Users Table -->
<div class="card border-0 shadow-sm">
    <div class="card-header bg-white border-bottom d-flex justify-content-between align-items-center py-3">
        <h6 class="fw-bold mb-0"><i class="fa-solid fa-list me-2 text-muted"></i>All Users (<?= count($users) ?>)</h6>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3" style="width: 40px;">#</th>
                        <th>User</th>
                        <th>Role</th>
                        <th>Status</th>
                        <th class="text-center">App Devices</th>
                        <th>Last Login</th>
                        <th>Created</th>
                        <th class="text-end pe-3">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $i => $u): 
                        $rl = $role_labels[$u['role']] ?? ['Unknown', 'bg-dark'];
                        $is_current = ((int)$u['id'] === (int)($current_user['id'] ?? 0));
                        $is_inactive = (($u['status'] ?? 'active') === 'inactive');
                    ?>
                    <tr class="<?= $is_inactive ? 'opacity-50' : '' ?>">
                        <td class="ps-3 text-muted small"><?= $i + 1 ?></td>
                        <td>
                            <div class="d-flex align-items-center gap-2">
                                <div class="rounded-circle <?= $rl[1] ?> text-white d-flex align-items-center justify-content-center fw-bold" style="width: 36px; height: 36px; font-size: 0.8rem; flex-shrink: 0;">
                                    <?= strtoupper(substr($u['full_name'], 0, 2)) ?>
                                </div>
                                <div>
                                    <div class="fw-semibold small"><?= htmlspecialchars($u['full_name']) ?>
                                        <?php if ($is_current): ?>
                                            <span class="badge bg-success-subtle text-success border border-success-subtle ms-1" style="font-size: 0.65rem;">YOU</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="text-muted" style="font-size: 0.72rem;">@<?= htmlspecialchars($u['username']) ?>
                                        <?php if (!empty($u['email'])): ?>
                                            &bull; <?= htmlspecialchars($u['email']) ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </td>
                        <td><span class="badge <?= $rl[1] ?> rounded-pill" style="font-size: 0.72rem;"><?= $rl[0] ?></span></td>
                        <td>
                            <?php if ($is_inactive): ?>
                                <span class="badge bg-warning-subtle text-warning border border-warning-subtle" style="font-size: 0.72rem;"><i class="fa-solid fa-ban me-1"></i>Inactive</span>
                            <?php else: ?>
                                <span class="badge bg-success-subtle text-success border border-success-subtle" style="font-size: 0.72rem;"><i class="fa-solid fa-circle-check me-1"></i>Active</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <?php $tc = $token_counts[(int)$u['id']] ?? 0; ?>
                            <?php if ($tc > 0): ?>
                                <span class="badge bg-info-subtle text-info border border-info-subtle" style="font-size: 0.72rem;"><i class="fa-solid fa-mobile-screen me-1"></i><?= $tc ?></span>
                            <?php else: ?>
                                <span class="text-muted small">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="small text-muted"><?= !empty($u['last_login']) ? date('d M Y, H:i', strtotime($u['last_login'])) : '<span class="text-muted">Never</span>' ?></td>
                        <td class="small text-muted"><?= date('d M Y', strtotime($u['created_at'])) ?></td>
                        <td class="text-end pe-3">
                            <div class="btn-group btn-group-sm">
                                <!-- Edit Button -->
                                <button class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editUserModal"
                                    data-id="<?= $u['id'] ?>"
                                    data-username="<?= htmlspecialchars($u['username']) ?>"
                                    data-fullname="<?= htmlspecialchars($u['full_name']) ?>"
                                    data-email="<?= htmlspecialchars($u['email'] ?? '') ?>"
                                    data-role="<?= $u['role'] ?>"
                                    data-status="<?= $u['status'] ?? 'active' ?>"
                                    title="Edit">
                                    <i class="fa-solid fa-pen-to-square"></i>
                                </button>

                                <!-- Toggle Status -->
                                <?php if (!$is_current): ?>
                                <a href="?action=toggle_status&id=<?= $u['id'] ?>" 
                                   class="btn btn-outline-warning" 
                                   title="<?= $is_inactive ? 'Activate' : 'Deactivate' ?>"
                                   onclick="return confirm('<?= $is_inactive ? 'Activate' : 'Deactivate' ?> this user?');">
                                    <i class="fa-solid <?= $is_inactive ? 'fa-toggle-off' : 'fa-toggle-on' ?>"></i>
                                </a>

                                <!-- Delete -->
                                <a href="?action=delete_user&id=<?= $u['id'] ?>" 
                                   class="btn btn-outline-danger" 
                                   title="Delete" 
                                   onclick="return confirm('Permanently delete user @<?= htmlspecialchars($u['username']) ?>? This cannot be undone.');">
                                    <i class="fa-solid fa-trash"></i>
                                </a>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Login Credentials Quick Reference -->
<div class="card border-0 shadow-sm mt-4">
    <div class="card-header bg-white border-bottom py-3">
        <h6 class="fw-bold mb-0"><i class="fa-solid fa-circle-info text-info me-2"></i>How to Connect</h6>
    </div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-6">
                <div class="border rounded-3 p-3">
                    <h6 class="fw-bold mb-2"><i class="fa-solid fa-desktop text-primary me-2"></i>Desktop Dashboard</h6>
                    <p class="small text-muted mb-2">Open the website URL in your browser and login with your username & password.</p>
                    <div class="bg-light rounded p-2 small font-monospace">
                        URL: <strong>http://<?= $_SERVER['HTTP_HOST'] ?>/QID/</strong><br>
                        Login: <strong>Your username & password</strong>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="border rounded-3 p-3">
                    <h6 class="fw-bold mb-2"><i class="fa-solid fa-mobile-screen text-success me-2"></i>Mobile Scanner App</h6>
                    <p class="small text-muted mb-2">Enter the server URL and login with the same username & password in the app.</p>
                    <div class="bg-light rounded p-2 small font-monospace">
                        Server: <strong>http://<?= $_SERVER['HTTP_HOST'] ?>/QID</strong><br>
                        Login: <strong>Same username & password</strong>
                    </div>
                </div>
            </div>
        </div>
        <div class="alert alert-info small mt-3 mb-0 d-flex align-items-start gap-2">
            <i class="fa-solid fa-lightbulb mt-1"></i>
            <div>
                <strong>Each user's scans appear on their own desktop dashboard.</strong> When a staff member scans a QID with the mobile app, the result popup appears only on the desktop where that same user is logged in — not on other users' dashboards.
            </div>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════ -->
<!-- ADD USER MODAL -->
<!-- ═══════════════════════════════════════════════════════ -->
<div class="modal fade" id="addUserModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" class="modal-content border-0 shadow">
            <input type="hidden" name="action" value="add_user">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="fa-solid fa-user-plus me-2"></i>Add New User</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label fw-semibold small">Username <span class="text-danger">*</span></label>
                    <input type="text" name="username" class="form-control" required pattern="[a-zA-Z0-9_]+" 
                           placeholder="e.g. john_doe" title="Letters, numbers, underscores only" maxlength="50">
                    <div class="form-text">Used for login on both desktop and mobile app.</div>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold small">Full Name <span class="text-danger">*</span></label>
                    <input type="text" name="full_name" class="form-control" required placeholder="e.g. John Doe" maxlength="100">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold small">Email</label>
                    <input type="email" name="email" class="form-control" placeholder="john@company.com" maxlength="100">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold small">Password <span class="text-danger">*</span></label>
                    <input type="password" name="password" class="form-control" required minlength="6" placeholder="Minimum 6 characters">
                </div>
                <div class="row g-3">
                    <div class="col-6">
                        <label class="form-label fw-semibold small">Role</label>
                        <select name="role" class="form-select">
                            <option value="staff" selected>Staff</option>
                            <option value="admin">Admin</option>
                            <option value="viewer">Viewer</option>
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label fw-semibold small">Status</label>
                        <select name="status" class="form-select">
                            <option value="active" selected>Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-user-plus me-1"></i> Create User</button>
            </div>
        </form>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════ -->
<!-- EDIT USER MODAL -->
<!-- ═══════════════════════════════════════════════════════ -->
<div class="modal fade" id="editUserModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" class="modal-content border-0 shadow">
            <input type="hidden" name="action" value="edit_user">
            <input type="hidden" name="user_id" id="editUserId">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="fa-solid fa-pen-to-square me-2"></i>Edit User — <span id="editUserTitle"></span></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label fw-semibold small">Username</label>
                    <input type="text" class="form-control" id="editUsername" disabled>
                    <div class="form-text">Username cannot be changed after creation.</div>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold small">Full Name <span class="text-danger">*</span></label>
                    <input type="text" name="full_name" id="editFullName" class="form-control" required maxlength="100">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold small">Email</label>
                    <input type="email" name="email" id="editEmail" class="form-control" maxlength="100">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold small">New Password</label>
                    <input type="password" name="password" class="form-control" minlength="6" placeholder="Leave blank to keep current password">
                </div>
                <div class="row g-3">
                    <div class="col-6">
                        <label class="form-label fw-semibold small">Role</label>
                        <select name="role" id="editRole" class="form-select">
                            <option value="staff">Staff</option>
                            <option value="admin">Admin</option>
                            <option value="viewer">Viewer</option>
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label fw-semibold small">Status</label>
                        <select name="status" id="editStatus" class="form-select">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk me-1"></i> Save Changes</button>
            </div>
        </form>
    </div>
</div>

<script>
// Populate edit modal with user data
const editModal = document.getElementById('editUserModal');
if (editModal) {
    editModal.addEventListener('show.bs.modal', function(event) {
        const btn = event.relatedTarget;
        document.getElementById('editUserId').value = btn.dataset.id;
        document.getElementById('editUsername').value = btn.dataset.username;
        document.getElementById('editUserTitle').textContent = '@' + btn.dataset.username;
        document.getElementById('editFullName').value = btn.dataset.fullname;
        document.getElementById('editEmail').value = btn.dataset.email || '';
        document.getElementById('editRole').value = btn.dataset.role;
        document.getElementById('editStatus').value = btn.dataset.status || 'active';
    });
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
