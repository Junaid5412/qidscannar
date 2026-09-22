<?php
/**
 * QID Management System - Database & Google Drive Backup Engine
 * Exports SQL database and uploads to Google Drive using native PHP
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

/**
 * Handle direct AJAX / POST backup requests
 */
if (basename($_SERVER['PHP_SELF']) === 'backup_service.php') {
    if (!is_logged_in()) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthorized. Please log in.']);
        exit;
    }
    header('Content-Type: application/json');
    $action = $_REQUEST['action'] ?? '';

    if ($action === 'backup_now') {
        $result = run_system_backup('manual');
        echo json_encode($result);
        exit;
    } elseif ($action === 'download' && !empty($_GET['file'])) {
        $filename = basename($_GET['file']);
        $filepath = __DIR__ . '/backups/' . $filename;
        if (file_exists($filepath)) {
            header('Content-Description: File Transfer');
            header('Content-Type: application/sql');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Expires: 0');
            header('Cache-Control: must-revalidate');
            header('Pragma: public');
            header('Content-Length: ' . filesize($filepath));
            readfile($filepath);
            exit;
        } else {
            http_response_code(404);
            die("File not found.");
        }
    }
}

/**
 * Main Backup Orchestrator
 */
function run_system_backup($type = 'manual') {
    $db = getDB();
    $backup_dir = __DIR__ . '/backups/';
    if (!is_dir($backup_dir)) {
        @mkdir($backup_dir, 0777, true);
    }

    $timestamp = date('Y-m-d_H-i-s');
    $filename = 'qid_backup_' . $timestamp . '.sql';
    $filepath = $backup_dir . $filename;

    // 1. Generate SQL dump
    $dump_success = export_mysql_dump($filepath);
    if (!$dump_success) {
        return [
            'success' => false,
            'message' => 'Failed to generate database dump.'
        ];
    }

    $filesize = filesize($filepath);
    $gdrive_synced = false;
    $gdrive_file_id = null;
    $status = 'local_saved';
    $message = 'Database backup saved locally.';

    // 2. Upload to Google Drive if configured
    $gdrive_enabled = get_setting('gdrive_enabled', '0');
    if ($gdrive_enabled == '1') {
        $gdrive_res = upload_to_google_drive($filepath, $filename);
        if ($gdrive_res['success']) {
            $gdrive_synced = true;
            $gdrive_file_id = $gdrive_res['file_id'];
            $status = 'gdrive_synced';
            $message = 'Backup saved locally and synced to Google Drive!';
        } else {
            $status = 'gdrive_error';
            $message = 'Backup saved locally, but Google Drive sync failed: ' . $gdrive_res['error'];
        }
    } else {
        $message = 'Backup saved locally (Google Drive sync is not configured or disabled).';
    }

    // 3. Log to backups_log
    try {
        $stmt = $db->prepare("
            INSERT INTO backups_log (filename, file_size, backup_type, status, gdrive_file_id, message)
            VALUES (:filename, :size, :btype, :status, :gid, :msg)
        ");
        $stmt->execute([
            ':filename' => $filename,
            ':size'     => $filesize,
            ':btype'    => $type,
            ':status'   => $status,
            ':gid'      => $gdrive_file_id,
            ':msg'      => $message
        ]);

        update_setting('last_backup_time', date('Y-m-d H:i:s'));
    } catch (Exception $e) {
        // Continue even if logging fails
    }

    // 4. Prune old local backups (keep last 25)
    prune_old_backups($backup_dir, 25);

    return [
        'success'       => true,
        'filename'      => $filename,
        'file_size'     => $filesize,
        'status'        => $status,
        'gdrive_synced' => $gdrive_synced,
        'message'       => $message,
        'timestamp'     => date('Y-m-d H:i:s')
    ];
}

/**
 * Pure PHP MySQL Exporter (No mysqldump binary required)
 */
function export_mysql_dump($target_filepath) {
    $db = getDB();
    $tables = ['settings', 'qid_records', 'payments', 'backups_log'];

    $sql = "-- ========================================================\n";
    $sql .= "-- QID Management System - Database Backup\n";
    $sql .= "-- Generated: " . date('Y-m-d H:i:s') . "\n";
    $sql .= "-- ========================================================\n\n";
    $sql .= "SET FOREIGN_KEY_CHECKS=0;\n";
    $sql .= "SET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";\n\n";

    foreach ($tables as $table) {
        try {
            // Table structure
            $stmt = $db->query("SHOW CREATE TABLE `{$table}`");
            $row = $stmt->fetch(PDO::FETCH_NUM);
            if ($row) {
                $sql .= "DROP TABLE IF EXISTS `{$table}`;\n";
                $sql .= $row[1] . ";\n\n";
            }

            // Table data
            $stmt = $db->query("SELECT * FROM `{$table}`");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (!empty($rows)) {
                $columns = array_keys($rows[0]);
                $col_names = implode('`, `', $columns);

                $sql .= "INSERT INTO `{$table}` (`{$col_names}`) VALUES\n";
                $val_lines = [];

                foreach ($rows as $r) {
                    $vals = [];
                    foreach ($r as $val) {
                        if ($val === null) {
                            $vals[] = "NULL";
                        } else {
                            $vals[] = $db->quote($val);
                        }
                    }
                    $val_lines[] = "(" . implode(", ", $vals) . ")";
                }

                $sql .= implode(",\n", $val_lines) . ";\n\n";
            }
        } catch (Exception $e) {
            // ignore non-existent tables
        }
    }

    $sql .= "SET FOREIGN_KEY_CHECKS=1;\n";

    return (file_put_contents($target_filepath, $sql) !== false);
}

/**
 * Keep the last N backups
 */
function prune_old_backups($dir, $keep = 25) {
    $files = glob($dir . 'qid_backup_*.sql');
    if ($files && count($files) > $keep) {
        usort($files, function($a, $b) {
            return filemtime($b) - filemtime($a);
        });
        $to_delete = array_slice($files, $keep);
        foreach ($to_delete as $del_file) {
            @unlink($del_file);
        }
    }
}

/**
 * Google Drive API v3 Native Upload via Service Account JWT
 */
function upload_to_google_drive($filepath, $filename) {
    $folder_id = get_setting('gdrive_folder_id', '');
    $refresh_token = get_setting('gdrive_refresh_token', '');
    $oauth_client_id = get_setting('gdrive_oauth_client_id', '');
    $oauth_client_secret = get_setting('gdrive_oauth_client_secret', '');
    $access_token = null;

    // METHOD 1: OAuth 2.0 User Token (For Personal @gmail.com - Uses personal storage quota with ZERO errors!)
    if (!empty($refresh_token) && !empty($oauth_client_id) && !empty($oauth_client_secret)) {
        $ch = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            'client_id'     => $oauth_client_id,
            'client_secret' => $oauth_client_secret,
            'refresh_token' => $refresh_token,
            'grant_type'    => 'refresh_token'
        ]));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $response = curl_exec($ch);
        $curl_error = curl_error($ch);
        curl_close($ch);

        if ($curl_error) {
            return ['success' => false, 'error' => 'cURL Error getting OAuth token: ' . $curl_error];
        }

        $token_data = json_decode($response, true);
        if (empty($token_data['access_token'])) {
            $err = $token_data['error_description'] ?? ($token_data['error'] ?? 'Token refresh failed');
            return ['success' => false, 'error' => 'OAuth Auth Failed: ' . $err];
        }

        $access_token = $token_data['access_token'];
    } 
    // METHOD 2: Service Account JWT (For Google Workspace Shared Drives)
    else {
        $client_email = get_setting('gdrive_client_email', '');
        $private_key = get_setting('gdrive_private_key', '');

        if (empty($client_email) || empty($private_key)) {
            return ['success' => false, 'error' => 'Neither OAuth 2.0 nor Service Account credentials are configured in Settings.'];
        }

        // Standardize private key formatting
        $private_key = str_replace(['\n', "\r"], ["\n", ""], $private_key);
        if (!str_contains($private_key, '-----BEGIN PRIVATE KEY-----')) {
            $private_key = "-----BEGIN PRIVATE KEY-----\n" . wordwrap(trim($private_key), 64, "\n", true) . "\n-----END PRIVATE KEY-----";
        }

        // Step 1: Create JWT Assertion
        $header = base64_url_encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $now = time();
        $payload = base64_url_encode(json_encode([
            'iss'   => $client_email,
            'scope' => 'https://www.googleapis.com/auth/drive',
            'aud'   => 'https://oauth2.googleapis.com/token',
            'exp'   => $now + 3600,
            'iat'   => $now
        ]));

        $signature_input = $header . '.' . $payload;
        $signature = '';
        $pkey_res = openssl_pkey_get_private($private_key);

        if (!$pkey_res) {
            return ['success' => false, 'error' => 'Invalid OpenSSL private key provided.'];
        }

        if (!openssl_sign($signature_input, $signature, $pkey_res, OPENSSL_ALGO_SHA256)) {
            return ['success' => false, 'error' => 'Could not sign JWT token.'];
        }

        $jwt = $signature_input . '.' . base64_url_encode($signature);

        // Step 2: Request OAuth2 Access Token
        $ch = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => $jwt
        ]));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $response = curl_exec($ch);
        $curl_error = curl_error($ch);
        curl_close($ch);

        if ($curl_error) {
            return ['success' => false, 'error' => 'cURL Error getting Google token: ' . $curl_error];
        }

        $token_data = json_decode($response, true);
        if (empty($token_data['access_token'])) {
            $err = $token_data['error_description'] ?? ($token_data['error'] ?? 'Unknown token exchange failure');
            return ['success' => false, 'error' => 'Google Auth Failed: ' . $err];
        }

        $access_token = $token_data['access_token'];
    }

    // Step 3: Upload File via Drive v3 Multipart API (supportsAllDrives=true enables Shared Drives)
    $metadata = [
        'name' => $filename,
        'mimeType' => 'application/sql'
    ];
    if (!empty($folder_id)) {
        $metadata['parents'] = [$folder_id];
    }

    $boundary = '-------' . md5(time());
    $delimiter = "\r\n--" . $boundary . "\r\n";
    $close_delim = "\r\n--" . $boundary . "--";

    $file_content = file_get_contents($filepath);

    $post_data = $delimiter
        . "Content-Type: application/json; charset=UTF-8\r\n\r\n"
        . json_encode($metadata)
        . $delimiter
        . "Content-Type: application/sql\r\n\r\n"
        . $file_content
        . $close_delim;

    $ch2 = curl_init('https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart&supportsAllDrives=true');
    curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch2, CURLOPT_POST, true);
    curl_setopt($ch2, CURLOPT_POSTFIELDS, $post_data);
    curl_setopt($ch2, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $access_token,
        'Content-Type: multipart/related; boundary=' . $boundary,
        'Content-Length: ' . strlen($post_data)
    ]);
    curl_setopt($ch2, CURLOPT_SSL_VERIFYPEER, false);
    $up_response = curl_exec($ch2);
    $up_error = curl_error($ch2);
    $http_code = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
    curl_close($ch2);

    if ($up_error) {
        return ['success' => false, 'error' => 'cURL upload error: ' . $up_error];
    }

    $up_data = json_decode($up_response, true);
    if ($http_code >= 200 && $http_code < 300 && !empty($up_data['id'])) {
        return [
            'success' => true,
            'file_id' => $up_data['id']
        ];
    } else {
        $error_msg = $up_data['error']['message'] ?? 'Upload failed with HTTP code ' . $http_code;
        return ['success' => false, 'error' => $error_msg];
    }
}

function base64_url_encode($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}
