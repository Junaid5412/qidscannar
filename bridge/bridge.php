<?php
/**
 * QID Management System - Online Bridge
 * =====================================
 * ONE file. Upload it anywhere on your hosting, open it in a browser, and it
 * shows you the single link to paste into QID Settings and into the app.
 *
 * WHY IT EXISTS
 * The phone cannot open a connection to the XAMPP PC when the Wi-Fi blocks
 * device-to-device traffic, or when the PC is on another network entirely. Both
 * machines can reach the internet though, so this file is the meeting point:
 * the phone posts a request here, the PC's connector is already waiting here for
 * work, runs the request against its own localhost, and posts the answer back.
 *
 *   phone --request--> [ this file ] <--waiting-- PC connector --> localhost/QID
 *                                                                  MySQL + records
 *
 * Neither side accepts an incoming connection, which is why this works when
 * nothing on the local network does.
 *
 * Your database, uploads and records never leave the PC. This file holds one
 * request and its reply for the second it takes to pass them on, then deletes
 * them. Nothing is kept.
 *
 * NEEDS: PHP 7+ and a writable folder. No database, no cron, no .htaccess,
 * no configuration file, no shell access. Ordinary shared hosting is enough.
 */

@set_time_limit(0);

// How long the phone waits for the PC to answer, in seconds. Comfortably under
// the execution limit shared hosts impose.
const WAIT_FOR_PC = 25;

// How long the PC connector holds one request open waiting for work. Shorter
// than WAIT_FOR_PC so a job never expires while the connector is reconnecting.
const WAIT_FOR_WORK = 20;

// Anything older than this was abandoned by a phone or connector that vanished.
const JOB_TTL = 120;

const TICK_US = 50000; // 50ms polling granularity

// ───────────────────────────────────────────────────────────────────────────
// Storage - created automatically, no setup
// ───────────────────────────────────────────────────────────────────────────

function store_dir()
{
    static $dir = null;
    if ($dir !== null) return $dir;

    $preferred = __DIR__ . '/qid_bridge_data';
    if (!is_dir($preferred)) {
        @mkdir($preferred, 0755, true);
        // Best-effort: hide it from the web even without an .htaccess of our own.
        @file_put_contents($preferred . '/index.html', '');
        @file_put_contents($preferred . '/.htaccess', "Require all denied\n");
    }

    if (is_dir($preferred) && is_writable($preferred)) {
        $dir = $preferred;
        return $dir;
    }

    // Some hosts forbid writing beside the script; the temp folder always works.
    $fallback = sys_get_temp_dir() . '/qid_bridge';
    if (!is_dir($fallback)) @mkdir($fallback, 0700, true);
    $dir = $fallback;
    return $dir;
}

function job_file($id, $ext)
{
    return store_dir() . '/' . $id . '.' . $ext;
}

/** Writes so a reader never sees a half-written file. */
function write_atomic($path, $data)
{
    $tmp = $path . '.' . getmypid() . '.tmp';
    if (@file_put_contents($tmp, $data, LOCK_EX) === false) return false;
    if (!@rename($tmp, $path)) { @unlink($tmp); return false; }
    return true;
}

function sweep_old()
{
    $cutoff = time() - JOB_TTL;
    foreach ((array) @glob(store_dir() . '/*.{req,res,claimed}', GLOB_BRACE) as $f) {
        if (@filemtime($f) < $cutoff) @unlink($f);
    }
}

/**
 * The bridge's own secret, created once on first visit and then shown to you as
 * part of the link. Generating it here means there is no key for you to invent,
 * copy between machines, or keep in a configuration file.
 */
function bridge_token()
{
    $file = store_dir() . '/token.txt';
    $token = @file_get_contents($file);

    if ($token === false || strlen(trim($token)) < 16) {
        $token = bin2hex(random_bytes(12));
        write_atomic($file, $token);
    }

    return trim($token);
}

function pc_last_seen()
{
    $seen = @file_get_contents(store_dir() . '/pc_seen.txt');
    return $seen === false ? 0 : (int) $seen;
}

function pc_is_connected()
{
    return (time() - pc_last_seen()) < 60;
}

function mark_pc_seen()
{
    write_atomic(store_dir() . '/pc_seen.txt', (string) time());
}

function send_json($data, $status = 200)
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    header('Access-Control-Allow-Origin: *');
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

function my_link()
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'yourdomain.com';
    $path = isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '/bridge.php';

    // The key goes in the PATH, not the query string, and this matters: the app
    // builds every call as <saved link> + "/api/whatever.php". Appended to a
    // query the endpoint would land inside the key and corrupt it; appended to a
    // path it simply extends the path, which is exactly what we want to read.
    return $scheme . '://' . $host . $path . '/' . bridge_token();
}

/**
 * Splits the trailing path into the key and the endpoint being asked for.
 *
 *   /bridge.php/<key>                       -> key, ''
 *   /bridge.php/<key>/api/scan_push.php     -> key, '/api/scan_push.php'
 *
 * PATH_INFO is the normal source. Some hosts do not populate it for
 * script.php/extra/path, so fall back to slicing SCRIPT_NAME off REQUEST_URI.
 */
function key_and_path()
{
    $info = isset($_SERVER['PATH_INFO']) ? $_SERVER['PATH_INFO'] : '';

    if ($info === '') {
        $uri = parse_url(isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '', PHP_URL_PATH);
        $script = isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '';
        if ($script !== '' && $uri !== null && strpos($uri, $script) === 0) {
            $info = substr($uri, strlen($script));
        }
    }

    $info = trim((string) $info, '/');
    if ($info === '') {
        return array('', '');
    }

    $parts = explode('/', $info, 2);
    $key = $parts[0];
    $path = isset($parts[1]) && $parts[1] !== '' ? '/' . $parts[1] : '';

    return array($key, $path);
}

// ───────────────────────────────────────────────────────────────────────────

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, HEAD, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-Auth-Token');
    http_response_code(204);
    exit;
}

if (!is_writable(store_dir())) {
    send_json(array(
        'success' => false,
        'message' => 'The bridge cannot write next to itself. In your hosting File '
            . 'Manager, set this folder\'s permissions to 0755.',
    ), 500);
}

sweep_old();

list($key, $path) = key_and_path();

// Query-string forms are still honoured, so an older link or a hand-built test
// URL keeps working.
if ($key === '' && isset($_GET['k'])) $key = $_GET['k'];
if ($path === '' && isset($_GET['p'])) $path = $_GET['p'];

$action = isset($_GET['agent']) ? $_GET['agent'] : '';

// ───────────────────────────────────────────────────────────────────────────
// No key: show the setup page with the one link to copy
// ───────────────────────────────────────────────────────────────────────────

if ($key === '') {
    $link = my_link();
    $connected = pc_is_connected();
    header('Content-Type: text/html; charset=UTF-8');
    ?><!DOCTYPE html>
<html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>QID Bridge</title><style>
 body{font:15px/1.6 system-ui,Segoe UI,Arial,sans-serif;background:#0f172a;color:#e2e8f0;margin:0;padding:24px}
 .box{max-width:640px;margin:0 auto}
 h1{font-size:20px;margin:0 0 4px} .sub{color:#94a3b8;font-size:13px;margin-bottom:20px}
 .link{background:#1e293b;border:1px solid #334155;border-radius:8px;padding:14px;
       font-family:ui-monospace,Consolas,monospace;font-size:13px;word-break:break-all;color:#5eead4}
 .ok{color:#10b981;font-weight:600} .no{color:#ef4444;font-weight:600}
 ol{padding-left:20px;color:#cbd5e1} li{margin:8px 0}
 .status{background:#1e293b;border:1px solid #334155;border-radius:8px;padding:14px;margin:18px 0}
 button{background:#0d9488;color:#fff;border:0;border-radius:6px;padding:9px 14px;font-size:13px;cursor:pointer;margin-top:10px}
</style></head><body><div class="box">
<h1>QID Bridge</h1>
<div class="sub">Connects the mobile app to your XAMPP PC through this hosting.</div>

<div class="status">
  PC connector: <?= $connected ? '<span class="ok">CONNECTED</span>' : '<span class="no">NOT CONNECTED</span>' ?>
  <?php if (!$connected): ?>
    <div style="color:#94a3b8;font-size:13px;margin-top:6px">
      Run <code>tools\qid_connect.bat</code> on the PC and leave the window open.
    </div>
  <?php endif; ?>
</div>

<p style="color:#cbd5e1;margin-bottom:6px"><strong>Your link</strong> &mdash; paste this in both places:</p>
<div class="link" id="lnk"><?= htmlspecialchars($link) ?></div>
<button onclick="navigator.clipboard.writeText(document.getElementById('lnk').innerText);this.innerText='Copied'">Copy link</button>

<ol style="margin-top:22px">
  <li>On the PC: open QID &rarr; <strong>Settings</strong>, paste the link into <strong>Online Bridge Link</strong>, Save.</li>
  <li>On the PC: run <code>tools\qid_connect.bat</code> and leave it open.</li>
  <li>In the app: paste the same link into <strong>Server URL</strong>, then log in.</li>
</ol>

<p style="color:#64748b;font-size:12px;margin-top:22px">
  Keep this link private &mdash; it is what identifies your bridge. Your records and
  database stay on the PC; this page only passes requests along.
</p>
</div></body></html><?php
    exit;
}

if (!hash_equals(bridge_token(), $key)) {
    send_json(array('success' => false, 'message' => 'Wrong bridge link.'), 403);
}

// ───────────────────────────────────────────────────────────────────────────
// PC connector: wait for work
// ───────────────────────────────────────────────────────────────────────────

if ($action === 'poll') {
    mark_pc_seen();
    $deadline = microtime(true) + WAIT_FOR_WORK;

    while (microtime(true) < $deadline) {
        clearstatcache();
        $waiting = (array) @glob(store_dir() . '/*.req');
        sort($waiting); // ids are time-ordered, so oldest first

        foreach ($waiting as $file) {
            $id = basename($file, '.req');
            // rename() is atomic: only one connector can ever win a given job.
            if (@rename($file, job_file($id, 'claimed'))) {
                $job = json_decode(@file_get_contents(job_file($id, 'claimed')), true);
                if (is_array($job)) send_json(array('success' => true, 'job' => $job));
                @unlink(job_file($id, 'claimed'));
            }
        }

        usleep(TICK_US);
        mark_pc_seen();
    }

    send_json(array('success' => true, 'job' => null));
}

// ───────────────────────────────────────────────────────────────────────────
// PC connector: hand back the answer
// ───────────────────────────────────────────────────────────────────────────

if ($action === 'reply') {
    mark_pc_seen();

    $id = isset($_GET['job']) ? $_GET['job'] : '';
    if (!preg_match('/^[a-f0-9]{8,64}$/', $id)) {
        send_json(array('success' => false, 'message' => 'Bad job id.'), 400);
    }

    $response = json_decode(file_get_contents('php://input'), true);
    if (!is_array($response)) {
        send_json(array('success' => false, 'message' => 'Bad reply.'), 400);
    }

    write_atomic(job_file($id, 'res'), json_encode($response));
    @unlink(job_file($id, 'claimed'));
    send_json(array('success' => true));
}

// ───────────────────────────────────────────────────────────────────────────
// PC connector / app: health check
// ───────────────────────────────────────────────────────────────────────────

if ($action === 'ping' || $path === '') {
    send_json(array(
        'status' => 'ok',
        'app' => 'qid_bridge',
        'pc_connected' => pc_is_connected(),
        'time' => time(),
    ));
}

// ───────────────────────────────────────────────────────────────────────────
// App: relay one request to the PC
// ───────────────────────────────────────────────────────────────────────────

// Only the mobile API is relayed, so this link can never open the admin portal.
if (!preg_match('#^/api/[A-Za-z0-9_\-]+\.php$#', $path)) {
    send_json(array('success' => false, 'message' => 'Only /api/ endpoints are relayed.'), 404);
}

if (!pc_is_connected()) {
    send_json(array(
        'success' => false,
        'message' => 'The QID PC is not connected. On the PC, run tools\\qid_connect.bat '
            . 'and leave the window open.',
    ), 503);
}

$headers = array();
foreach ($_SERVER as $name => $value) {
    if (strpos($name, 'HTTP_') !== 0) continue;
    $h = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))));
    if (in_array(strtolower($h), array('host', 'connection', 'content-length', 'accept-encoding'), true)) continue;
    $headers[$h] = $value;
}
if (!empty($_SERVER['CONTENT_TYPE'])) $headers['Content-Type'] = $_SERVER['CONTENT_TYPE'];

$id = bin2hex(random_bytes(12));

$job = array(
    'id'      => $id,
    'method'  => $_SERVER['REQUEST_METHOD'] ?? 'GET',
    'path'    => $path,
    'headers' => $headers,
    // base64 so any body, including binary uploads, survives the JSON round trip.
    'body'    => base64_encode(file_get_contents('php://input')),
);

if (!write_atomic(job_file($id, 'req'), json_encode($job))) {
    send_json(array('success' => false, 'message' => 'Bridge could not queue the request.'), 500);
}

$deadline = microtime(true) + WAIT_FOR_PC;
$res = job_file($id, 'res');

while (microtime(true) < $deadline) {
    clearstatcache();
    if (is_file($res)) {
        $payload = @file_get_contents($res);
        @unlink($res);

        $response = json_decode($payload, true);
        if (!is_array($response)) break;

        http_response_code(isset($response['status']) ? (int) $response['status'] : 200);

        if (!empty($response['headers']) && is_array($response['headers'])) {
            foreach ($response['headers'] as $hn => $hv) {
                // Transport framing is this server's business, not the PC's.
                if (in_array(strtolower($hn), array('transfer-encoding', 'content-length', 'connection'), true)) continue;
                header($hn . ': ' . $hv);
            }
        }
        header('Access-Control-Allow-Origin: *');

        echo base64_decode($response['body'] ?? '');
        exit;
    }
    usleep(TICK_US);
}

@unlink(job_file($id, 'req'));
@unlink(job_file($id, 'claimed'));

send_json(array(
    'success' => false,
    'message' => 'The PC did not answer in time. Check that XAMPP Apache is running '
        . 'and the connector window is still open.',
), 504);
