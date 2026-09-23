<?php
/**
 * QID Bridge configuration.
 *
 * Copy this file to bridge_config.php on your hosting and set BRIDGE_KEY to a
 * long random string. The SAME key goes into tools/bridge_config_agent.php on
 * the XAMPP PC - it is what proves to the bridge that your PC, and only your
 * PC, is allowed to pick up jobs.
 *
 * Generate one by running this on the PC:
 *     php -r "echo bin2hex(random_bytes(24)), PHP_EOL;"
 */

define('BRIDGE_KEY', 'CHANGE-ME');
