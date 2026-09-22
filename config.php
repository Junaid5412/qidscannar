<?php
// QID Management System - Database & System Configuration
// Generated automatically by install.php on 2026-09-16 13:34:03

define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'qid_management_db');

define('APP_NAME', 'QID Management System');
define('APP_VERSION', '1.0.0');

// Base URL helper
$script_dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));
$base_url = rtrim($script_dir, '/');
define('BASE_URL', $base_url);

// Timezone (Default: Qatar / Asia/Qatar UTC+3)
date_default_timezone_set('Asia/Qatar');
