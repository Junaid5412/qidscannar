<?php
// QID Management System - Database & System Configuration Sample
// Rename to config.php or run install.php to generate automatically.

define('DB_HOST', 'localhost');
define('DB_USER', 'your_db_user');
define('DB_PASS', 'your_db_password');
define('DB_NAME', 'your_db_name');

define('APP_NAME', 'QID Management System');
define('APP_VERSION', '1.0.0');

// Base URL helper
$script_dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));
$base_url = rtrim($script_dir, '/');
define('BASE_URL', $base_url);

// Timezone (Default: Qatar / Asia/Qatar UTC+3)
date_default_timezone_set('Asia/Qatar');
