<?php
/**
 * QID Management System - Online Bridge (relay half)
 * ---------------------------------------------------
 * Upload this folder to your hosting, e.g. https://yourdomain.com/qidbridge
 *
 * WHY THIS EXISTS
 * The phone cannot open a connection to the XAMPP PC when the Wi-Fi blocks
 * client-to-client traffic (AP isolation), or when the PC sits behind NAT on a
 * different network entirely. Both devices *can* reach the internet, so this
 * script is the meeting point: the phone posts a request here, the PC's agent
 * (tools/bridge_agent.php) is already waiting here for work, runs the request
 * against its own localhost, and posts the answer back.
 *
 *     phone  --POST /api/scan_push.php-->  [ this file ]  <--long poll-- agent
 *                                                                          |
 *                                                     http://localhost/QID --+
 *
 * Nothing is stored here. Job files exist only for the seconds a request is in
 * flight and are deleted as soon as it completes. The database, the records and
 * every file stay on the XAMPP PC.
 *
 * REQUIREMENTS: PHP 7.0+, a writable jobs/ directory. No database, no cron, no
 * shell access - it works on ordinary shared cPanel hosting.
 */

@set_time_limit(0);
ignore_user_abort(false);

$config_file = __DIR__ . '/bridge_config.php';
if (!file_exists($config_file)) {
    header('Content-Type: application/json');
    http_response_code(500);
    exit(json_encode(array(
        'success' => false,
        'message' => 'bridge_config.php is missing. Copy bridge_config.sample.php to bridge_config.php and set BRIDGE_KEY.',
    )));
}
require_once $config_file;

define('QID_JOB_DIR', __DIR__ . '/jobs');

// How long a phone waits for the PC to answer. Kept under the execution limit
// that shared hosts impose, with room to spare for the response to be written.
define('QID_CLIENT_WAIT', 25);

// How long the agent holds a poll open before being told "nothing yet". Shorter
// than the client wait so a job never expires while the agent is between polls.
define('QID_AGENT_WAIT', 20);

// Poll granularity. 50ms keeps relay latency invisible next to network time.
define('QID_TICK_US', 50000);

// Abandoned jobs are swept after this long.
define('QID_JOB_TTL', 120);

// ───────────────────────────────────────────────────────────────────────────
// Helpers
// ───────────────────────────────────────────────────────────────────────────

function qid_json_out($data, $status = 200)
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

function qid_job_path($id, $ext)
{
    return QID_JOB_DIR . '/' . $id . '.' . $ext;
}

/** Writes a file so readers never observe a half-written job. */
function qid_atomic_write($path, $contents)
{
    $tmp = $path . '.' . getmypid() . '.tmp';
    if (file_put_contents($tmp, $contents, LOCK_EX) === false) {
        return false;
    }
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        return false;
    }
    return true;
}

/** Removes jobs left behind when a phone or the agent disappeared mid-request. */
function qid_sweep_stale()
{
    $cutoff = time() - QID_JOB_TTL;
    foreach ((array) @glob(QID_JOB_DIR . '/*') as $file) {
        if (is_file($file) && @filemtime($file) < $cutoff) {
            @unlink($file);
        }
    }
}

/** True while the PC agent has polled recently enough to be considered connected. */
function qid_agent_is_online()
{
    $seen = @file_get_contents(QID_JOB_DIR . '/.agent_seen');
    return ($seen !== false && (time() - (int) $seen) < 60);
}

function qid_require_agent_key()
{
    $supplied = isset($_GET['key']) ? $_GET['key'] : '';
    if (!defined('BRIDGE_KEY') || BRIDGE_KEY === '' || BRIDGE_KEY === 'CHANGE-ME') {
        qid_json_out(array(
            'success' => false,
            'message' => 'BRIDGE_KEY is not set in bridge_config.php.',
        ), 500);
    }
    // Length-safe comparison so the key cannot be guessed a character at a time.
    if (!hash_equals(BRIDGE_KEY, (string) $supplied)) {
        qid_json_out(array('success' => false, 'message' => 'Invalid agent key.'), 403);
    }
}

// ───────────────────────────────────────────────────────────────────────────
// Bootstrap
// ───────────────────────────────────────────────────────────────────────────

if (!is_dir(QID_JOB_DIR)) {
    @mkdir(QID_JOB_DIR, 0775, true);
}
if (!is_writable(QID_JOB_DIR)) {
    qid_json_out(array(
        'success' => false,
        'message' => 'The jobs/ directory is not writable. Set it to 0775 in cPanel File Manager.',
    ), 500);
}

qid_sweep_stale();

$agent_action = isset($_GET['__agent']) ? $_GET['__agent'] : '';

// ───────────────────────────────────────────────────────────────────────────
// Agent endpoint: status
// ───────────────────────────────────────────────────────────────────────────

if ($agent_action === 'ping') {
    qid_require_agent_key();
    qid_atomic_write(QID_JOB_DIR . '/.agent_seen', (string) time());
    qid_json_out(array('success' => true, 'message' => 'Bridge reachable.', 'time' => time()));
}

// ───────────────────────────────────────────────────────────────────────────
// Agent endpoint: claim the next job (long poll)
// ───────────────────────────────────────────────────────────────────────────

if ($agent_action === 'poll') {
    qid_require_agent_key();

    // Doubles as the liveness marker the phone path reads to tell "PC is offline"
    // apart from "PC is busy".
    qid_atomic_write(QID_JOB_DIR . '/.agent_seen', (string) time());

    $deadline = microtime(true) + QID_AGENT_WAIT;

    while (microtime(true) < $deadline) {
        clearstatcache();
        $pending = (array) @glob(QID_JOB_DIR . '/*.req');
        sort($pending); // oldest id first - ids are time-prefixed

        foreach ($pending as $file) {
            $id = basename($file, '.req');
            // rename() is atomic, so exactly one agent poll can win a given job
            // even if several are running.
            if (@rename($file, qid_job_path($id, 'claimed'))) {
                $payload = @file_get_contents(qid_job_path($id, 'claimed'));
                $job = json_decode($payload, true);
                if (is_array($job)) {
                    qid_json_out(array('success' => true, 'job' => $job));
                }
                @unlink(qid_job_path($id, 'claimed'));
            }
        }

        usleep(QID_TICK_US);
        qid_atomic_write(QID_JOB_DIR . '/.agent_seen', (string) time());
    }

    qid_json_out(array('success' => true, 'job' => null));
}

// ───────────────────────────────────────────────────────────────────────────
// Agent endpoint: hand back the answer
// ───────────────────────────────────────────────────────────────────────────

if ($agent_action === 'reply') {
    qid_require_agent_key();

    $id = isset($_GET['job']) ? $_GET['job'] : '';
    if (!preg_match('/^[a-f0-9]{8,64}$/', $id)) {
        qid_json_out(array('success' => false, 'message' => 'Bad job id.'), 400);
    }

    $raw = file_get_contents('php://input');
    $response = json_decode($raw, true);
    if (!is_array($response)) {
        qid_json_out(array('success' => false, 'message' => 'Bad response payload.'), 400);
    }

    qid_atomic_write(qid_job_path($id, 'res'), json_encode($response));
    @unlink(qid_job_path($id, 'claimed'));

    qid_json_out(array('success' => true));
}

// ───────────────────────────────────────────────────────────────────────────
// Phone path: relay one request to the PC and wait for its answer
// ───────────────────────────────────────────────────────────────────────────

$method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';

if ($method === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, HEAD, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-Auth-Token');
    http_response_code(204);
    exit;
}

/**
 * The path the phone asked for, relative to this bridge folder.
 * .htaccess rewrites every unmatched request here, so REQUEST_URI still carries
 * the original path such as /qidbridge/api/scan_push.php.
 */
$request_uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/';
$request_path = parse_url($request_uri, PHP_URL_PATH);
$bridge_base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');

$relative = $request_path;
if ($bridge_base !== '' && strpos($request_path, $bridge_base) === 0) {
    $relative = substr($request_path, strlen($bridge_base));
}
$relative = '/' . ltrim($relative, '/');

// Requesting bridge.php itself with no agent action is a human in a browser.
if ($relative === '/bridge.php' || $relative === '/') {
    qid_json_out(array(
        'status' => 'ok',
        'app' => 'qid_bridge',
        'message' => 'QID bridge is online. Point the mobile app at this URL.',
        'agent_online' => qid_agent_is_online(),
    ));
}

// Only the mobile API is relayed. The admin site is never exposed through the
// bridge, so a leaked URL cannot reach the web portal or its pages.
if (!preg_match('#^/api/[A-Za-z0-9_\-]+\.php$#', $relative)) {
    qid_json_out(array(
        'success' => false,
        'message' => 'This bridge only relays /api/ endpoints.',
    ), 404);
}

if (!qid_agent_is_online()) {
    qid_json_out(array(
        'success' => false,
        'message' => 'The QID PC is not connected to the bridge. '
            . 'On the PC, run tools\\start_bridge_agent.bat and leave it open.',
    ), 503);
}

// Forward the headers the QID API actually reads. Hop-by-hop and host headers
// are deliberately dropped - the agent rebuilds those for its localhost call.
$forward_headers = array();
foreach ($_SERVER as $name => $value) {
    if (strpos($name, 'HTTP_') !== 0) continue;
    $header = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))));
    if (in_array(strtolower($header), array('host', 'connection', 'content-length', 'accept-encoding'), true)) {
        continue;
    }
    $forward_headers[$header] = $value;
}
if (!empty($_SERVER['CONTENT_TYPE'])) {
    $forward_headers['Content-Type'] = $_SERVER['CONTENT_TYPE'];
}

$id = bin2hex(random_bytes(12));

$job = array(
    'id'      => $id,
    'ts'      => time(),
    'method'  => $method,
    'path'    => $relative,
    'query'   => isset($_SERVER['QUERY_STRING']) ? $_SERVER['QUERY_STRING'] : '',
    'headers' => $forward_headers,
    // base64 so binary bodies and uploads survive the JSON round trip intact.
    'body'    => base64_encode(file_get_contents('php://input')),
);

if (!qid_atomic_write(qid_job_path($id, 'req'), json_encode($job))) {
    qid_json_out(array('success' => false, 'message' => 'Bridge could not queue the request.'), 500);
}

// Wait for the agent to come back with the answer.
$deadline = microtime(true) + QID_CLIENT_WAIT;
$res_file = qid_job_path($id, 'res');

while (microtime(true) < $deadline) {
    clearstatcache();
    if (is_file($res_file)) {
        $payload = @file_get_contents($res_file);
        @unlink($res_file);

        $response = json_decode($payload, true);
        if (!is_array($response)) break;

        http_response_code(isset($response['status']) ? (int) $response['status'] : 200);

        if (!empty($response['headers']) && is_array($response['headers'])) {
            foreach ($response['headers'] as $hname => $hvalue) {
                // Let the relay control transport framing itself.
                if (in_array(strtolower($hname), array('transfer-encoding', 'content-length', 'connection'), true)) {
                    continue;
                }
                header($hname . ': ' . $hvalue);
            }
        }
        header('Access-Control-Allow-Origin: *');

        echo base64_decode(isset($response['body']) ? $response['body'] : '');
        exit;
    }
    usleep(QID_TICK_US);
}

// Nothing came back: clean up so the agent does not run a request nobody awaits.
@unlink(qid_job_path($id, 'req'));
@unlink(qid_job_path($id, 'claimed'));

qid_json_out(array(
    'success' => false,
    'message' => 'The QID PC did not answer in time. Check that XAMPP Apache is '
        . 'running and the bridge agent window is still open.',
), 504);
