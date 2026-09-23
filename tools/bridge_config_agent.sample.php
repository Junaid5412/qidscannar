<?php
/**
 * QID Bridge - PC agent configuration.
 *
 * Copy this file to bridge_config_agent.php (same folder) and fill it in.
 * bridge_config_agent.php is git-ignored so your key never leaves this PC.
 */

// Where you uploaded the bridge folder on your hosting. No trailing slash.
define('BRIDGE_URL', 'https://yourdomain.com/qidbridge');

// Must match BRIDGE_KEY in bridge_config.php on the hosting, exactly.
// Generate one with:  php -r "echo bin2hex(random_bytes(24)), PHP_EOL;"
define('BRIDGE_KEY', 'CHANGE-ME');

// The QID install on this PC. Localhost is correct - the agent runs here.
define('LOCAL_BASE_URL', 'http://localhost/QID');
