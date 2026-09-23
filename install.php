<?php
/**
 * QID Management System - Installer
 * Web-based 1-click installer for XAMPP / Apache / MySQL
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

$config_file = __DIR__ . '/config.php';
$is_installed = file_exists($config_file);

$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'install') {
    $db_host = trim($_POST['db_host'] ?? 'localhost');
    $db_user = trim($_POST['db_user'] ?? 'root');
    $db_pass = $_POST['db_pass'] ?? '';
    $db_name = trim($_POST['db_name'] ?? 'qid_management_db');
    $load_sample = isset($_POST['load_sample']) ? true : false;

    try {
        // 1. Test server connection
        $pdo = new PDO("mysql:host={$db_host};charset=utf8mb4", $db_user, $db_pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);

        // 2. Create database
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$db_name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("USE `{$db_name}`");

        // 3. Run database.sql schema
        $sql_schema_file = __DIR__ . '/database.sql';
        if (!file_exists($sql_schema_file)) {
            throw new Exception("Schema file database.sql not found in root directory.");
        }

        $schema_sql = file_get_contents($sql_schema_file);
        $pdo->exec($schema_sql);

        // 4. Optionally insert sample demo data
        if ($load_sample) {
            $stmt = $pdo->prepare("
                INSERT INTO `qid_records` (`id`, `full_name`, `qid_number`, `phone_number`, `company_name`, `job_title`, `nationality`, `expiry_date`, `charge_amount`, `actual_cost`, `status`, `notes`) 
                VALUES 
                (1, 'Mohammed Al-Kuwari', '28863401234', '+974 5511 2233', 'Al Noor Trading W.L.L', 'Sales Executive', 'Qatari', DATE_ADD(CURDATE(), INTERVAL 14 DAY), 2500.00, 1220.00, 'Active', 'Example customer: Charge 2500 QR, Actual cost 1220 QR'),
                (2, 'Ahmed Tariq', '29258602345', '+974 6622 3344', 'Gulf Falcon Contracting', 'Civil Engineer', 'Egyptian', DATE_SUB(CURDATE(), INTERVAL 3 DAY), 1800.00, 1000.00, 'Expired', 'QID Expired recently - urgent renewal needed'),
                (3, 'Rajesh Patel', '28535603456', '+974 7733 4455', 'Doha Express Logistics', 'Operations Supervisor', 'Indian', DATE_ADD(CURDATE(), INTERVAL 45 DAY), 3200.00, 1600.00, 'Active', 'VIP Client renewal package')
            ");
            $stmt->execute();

            // Insert payments for Mohammed Al-Kuwari (Matches user's exact specification)
            // 500 QR on 04 Sep + 600 QR on 10 Sep
            $pay_stmt = $pdo->prepare("
                INSERT INTO `payments` (`qid_record_id`, `amount`, `payment_date`, `payment_time`, `payment_method`, `receipt_no`, `notes`)
                VALUES 
                (1, 500.00, '2026-09-04', '10:30:00', 'Cash', 'RCP-2026-001', 'First installment received in cash'),
                (1, 600.00, '2026-09-10', '14:15:00', 'Bank Transfer', 'RCP-2026-002', 'Second installment received via bank transfer'),
                (2, 1800.00, DATE_SUB(CURDATE(), INTERVAL 10 DAY), '11:00:00', 'Card', 'RCP-2026-003', 'Full payment received'),
                (3, 1000.00, DATE_SUB(CURDATE(), INTERVAL 5 DAY), '09:45:00', 'Cash', 'RCP-2026-004', 'Initial advance deposit')
            ");
            $pay_stmt->execute();
        }

        // 5. Generate config.php
        $config_content = "<?php
// QID Management System - Database & System Configuration
// Generated automatically by install.php on " . date('Y-m-d H:i:s') . "

define('DB_HOST', " . var_export($db_host, true) . ");
define('DB_USER', " . var_export($db_user, true) . ");
define('DB_PASS', " . var_export($db_pass, true) . ");
define('DB_NAME', " . var_export($db_name, true) . ");

define('APP_NAME', 'QID Management System');
define('APP_VERSION', '1.0.0');

// Base URL helper
\$script_dir = str_replace('\\\\', '/', dirname(\$_SERVER['SCRIPT_NAME']));
\$base_url = rtrim(\$script_dir, '/');
define('BASE_URL', \$base_url);

// Timezone (Default: Qatar / Asia/Qatar UTC+3)
date_default_timezone_set('Asia/Qatar');
";

        if (file_put_contents($config_file, $config_content) === false) {
            throw new Exception("Could not write to config.php. Please check folder permissions.");
        }

        $message = 'Installation completed successfully! You can now access your dashboard.';
        $message_type = 'success';
        $is_installed = true;

    } catch (Exception $e) {
        $message = 'Installation failed: ' . $e->getMessage();
        $message_type = 'danger';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Setup & Installation - QID Management System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            color: #334155;
        }
        .setup-card {
            background: #ffffff;
            border-radius: 16px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.35);
            max-width: 580px;
            width: 100%;
            overflow: hidden;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        .setup-header {
            background: linear-gradient(135deg, #1e3a8a 0%, #0284c7 100%);
            color: #ffffff;
            padding: 32px 30px;
            text-align: center;
        }
        .setup-body {
            padding: 32px 30px;
        }
        .form-control, .form-select {
            border-radius: 8px;
            padding: 10px 14px;
            border: 1px solid #cbd5e1;
            font-size: 0.95rem;
        }
        .form-control:focus {
            border-color: #0284c7;
            box-shadow: 0 0 0 3px rgba(2, 132, 199, 0.15);
        }
        .btn-primary-custom {
            background: linear-gradient(135deg, #0284c7 0%, #0369a1 100%);
            border: none;
            color: #ffffff;
            font-weight: 600;
            padding: 12px 20px;
            border-radius: 8px;
            width: 100%;
            transition: all 0.2s ease;
        }
        .btn-primary-custom:hover {
            background: linear-gradient(135deg, #0369a1 0%, #075985 100%);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(2, 132, 199, 0.35);
        }
        .badge-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 14px;
            border-radius: 50px;
            background: rgba(255, 255, 255, 0.2);
            font-size: 0.8rem;
            letter-spacing: 0.5px;
            text-transform: uppercase;
            font-weight: 700;
        }
    </style>
</head>
<body>

<div class="setup-card">
    <div class="setup-header">
        <div class="badge-pill mb-3">
            <i class="fa-solid fa-id-card"></i> Qatar ID Management
        </div>
        <h3 class="fw-bold mb-1">System Installation</h3>
        <p class="text-white-50 mb-0 small">Fast 1-Click Database Setup & Configuration</p>
    </div>

    <div class="setup-body">
        <?php if (!empty($message)): ?>
            <div class="alert alert-<?= $message_type ?> d-flex align-items-center mb-4" role="alert">
                <i class="fa-solid <?= $message_type === 'success' ? 'fa-circle-check' : 'fa-triangle-exclamation' ?> me-2 fs-5"></i>
                <div><?= htmlspecialchars($message) ?></div>
            </div>
        <?php endif; ?>

        <?php if ($is_installed && empty($_GET['reinstall'])): ?>
            <div class="text-center py-4">
                <div class="mb-3 text-success">
                    <i class="fa-solid fa-circle-check fa-4x"></i>
                </div>
                <h4 class="fw-bold text-dark">System is Configured & Ready!</h4>
                <p class="text-muted small mb-4">
                    The QID Management System has already been installed. You can go straight to your dashboard or re-run setup if needed.
                </p>
                <div class="d-grid gap-2">
                    <a href="login.php" class="btn btn-primary-custom py-2 fs-6">
                        <i class="fa-solid fa-arrow-right-to-bracket me-2"></i> Go to Login Portal
                    </a>
                    <a href="install.php?reinstall=1" class="btn btn-outline-secondary btn-sm mt-2">
                        <i class="fa-solid fa-rotate-right me-1"></i> Reinstall / Reset Database
                    </a>
                </div>
            </div>
        <?php else: ?>
            <form method="POST" action="install.php">
                <input type="hidden" name="action" value="install">

                <div class="mb-3">
                    <label class="form-label fw-semibold small text-muted text-uppercase">Database Host</label>
                    <div class="input-group">
                        <span class="input-group-text bg-light text-muted"><i class="fa-solid fa-server"></i></span>
                        <input type="text" name="db_host" class="form-control" value="localhost" required>
                    </div>
                    <div class="form-text">Usually <code>localhost</code> for XAMPP.</div>
                </div>

                <div class="row g-2 mb-3">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold small text-muted text-uppercase">Database Username</label>
                        <div class="input-group">
                            <span class="input-group-text bg-light text-muted"><i class="fa-solid fa-user"></i></span>
                            <input type="text" name="db_user" class="form-control" value="root" required>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold small text-muted text-uppercase">Database Password</label>
                        <div class="input-group">
                            <span class="input-group-text bg-light text-muted"><i class="fa-solid fa-key"></i></span>
                            <input type="password" name="db_pass" class="form-control" placeholder="(Empty for XAMPP default)">
                        </div>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold small text-muted text-uppercase">Database Name</label>
                    <div class="input-group">
                        <span class="input-group-text bg-light text-muted"><i class="fa-solid fa-database"></i></span>
                        <input type="text" name="db_name" class="form-control" value="qid_management_db" required>
                    </div>
                    <div class="form-text">Will be created automatically if it does not exist.</div>
                </div>

                <div class="p-3 bg-light rounded-3 mb-4 border">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="load_sample" id="load_sample" value="1" checked>
                        <label class="form-check-label fw-semibold text-dark" for="load_sample">
                            Load Demo Data (Example Cases)
                        </label>
                        <div class="text-muted small mt-1">
                            Includes sample client with <strong>2,500 QR Charge</strong>, <strong>1,220 QR Cost</strong>, and installment entries (500 QR on 04 Sep + 600 QR on 10 Sep) to test profit & remaining balance calculations.
                        </div>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary-custom">
                    <i class="fa-solid fa-wand-magic-sparkles me-2"></i> Install & Initialize System
                </button>
            </form>
        <?php endif; ?>
    </div>
</div>

</body>
</html>
