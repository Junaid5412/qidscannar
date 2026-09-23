<?php
/**
 * QID Management System - PC Connector
 * ====================================
 * Run with tools\qid_connect.bat. Leave the window open.
 *
 * Reads the bridge link you saved in QID Settings, then holds one outbound
 * connection to it and waits to be given work:
 *
 *     ask the bridge for a request   (waits ~20s, idle costs nothing)
 *     run it against http://localhost/QID
 *     send the answer back
 *
 * Because it only ever dials out, it is unaffected by Wi-Fi blocking
 * device-to-device traffic, by this PC's address changing, and by there being no
 * port forwarded on the router. Nothing can connect in to this machine.
 *
 * Every record and the whole database stay here.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("Run this from tools\\qid_connect.bat\n");
}

if (!function_exists('curl_init')) {
    fwrite(STDERR, "ERROR: the PHP 'curl' extension is not enabled.\n");
    exit(1);
}

/** Where QID Settings saves the bridge link. */
const LINK_FILE = __DIR__ . '/../data/bridge_link.txt';

/** This PC's own QID install. The connector runs here, so localhost is right. */
const LOCAL_BASE = 'http://localhost/QID';

const POLL_TIMEOUT  = 35; // must exceed the bridge's own wait
const LOCAL_TIMEOUT = 25;
const RETRY_DELAY   = 5;

function say($msg)
{
    echo '[' . date('H:i:s') . '] ' . $msg . PHP_EOL;
}

function read_link()
{
    if (!file_exists(LINK_FILE)) return '';
    return trim((string) @file_get_contents(LINK_FILE));
}

/** Adds query parameters to the bridge link, whichever form the link takes. */
function bridge_url($link, array $params)
{
    $separator = (strpos($link, '?') === false) ? '?' : '&';
    return $link . $separator . http_build_query($params);
}

function http_get($url, $timeout)
{
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT      => 'QID-Connector/1.0',
    ));
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    return array($body, $status, $err);
}

/** Replays one relayed request against the local XAMPP install. */
function run_locally(array $job)
{
    $url = rtrim(LOCAL_BASE, '/') . (isset($job['path']) ? $job['path'] : '/');

    $headers = array();
    foreach ((array) ($job['headers'] ?? array()) as $n => $v) {
        $headers[] = $n . ': ' . $v;
    }

    $method = strtoupper($job['method'] ?? 'GET');
    $response_headers = array();

    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => LOCAL_TIMEOUT,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
        // A 302 from the API is meaningful to the app; do not swallow it here.
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$response_headers) {
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) $response_headers[trim($parts[0])] = trim($parts[1]);
            return strlen($line);
        },
    ));

    if ($method !== 'GET' && $method !== 'HEAD') {
        curl_setopt($ch, CURLOPT_POSTFIELDS, base64_decode($job['body'] ?? ''));
    }

    $out = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($out === false) {
        return array(
            'status'  => 502,
            'headers' => array('Content-Type' => 'application/json'),
            'body'    => base64_encode(json_encode(array(
                'success' => false,
                'message' => 'The connector could not reach XAMPP on this PC: ' . $err,
            ))),
        );
    }

    return array(
        'status'  => $status ?: 200,
        'headers' => $response_headers,
        'body'    => base64_encode($out),
    );
}

function send_reply($link, $id, array $response)
{
    $ch = curl_init(bridge_url($link, array('agent' => 'reply', 'job' => $id)));
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($response),
        CURLOPT_HTTPHEADER     => array('Content-Type: application/json'),
    ));
    curl_exec($ch);
    curl_close($ch);
}

// ───────────────────────────────────────────────────────────────────────────

say('QID connector starting');

$link = read_link();

if ($link === '') {
    fwrite(STDERR, "\n  No bridge link saved yet.\n\n");
    fwrite(STDERR, "  1. Open your bridge file in a browser to get the link\n");
    fwrite(STDERR, "  2. Open QID -> Settings -> Online Bridge Link, paste it, Save\n");
    fwrite(STDERR, "  3. Run this again\n\n");
    exit(1);
}

say('Bridge : ' . preg_replace('#(/|k=)[a-f0-9]{16,}#', '$1****', $link));
say('Local  : ' . LOCAL_BASE);

list($probe_body, $probe_status,) = http_get(LOCAL_BASE . '/api/discovery.php', 10);
if ($probe_status !== 200) {
    say('WARNING: localhost/QID returned HTTP ' . $probe_status . ' - is XAMPP Apache running?');
} else {
    say('Local QID system OK');
}

list($ping_body, $ping_status,) = http_get(bridge_url($link, array('agent' => 'ping')), 20);
if ($ping_status === 403) {
    fwrite(STDERR, "\n  ERROR: the bridge rejected this link.\n");
    fwrite(STDERR, "  Re-copy it from the bridge page and save it again in QID Settings.\n\n");
    exit(1);
}
if ($ping_status !== 200) {
    say('WARNING: bridge returned HTTP ' . $ping_status . ' - will keep retrying.');
} else {
    say('Bridge reachable');
}

say('Connected. Paste the SAME link into the app\'s Server URL.');
say('Waiting for the app... (close this window to stop)');

// ───────────────────────────────────────────────────────────────────────────

while (true) {
    // Re-read each cycle so changing the link in Settings takes effect without
    // the user having to restart this window.
    $current = read_link();
    if ($current !== '' && $current !== $link) {
        $link = $current;
        say('Bridge link changed in Settings - now using the new one.');
    }

    list($body, $status, $err) = http_get(bridge_url($link, array('agent' => 'poll')), POLL_TIMEOUT);

    if ($body === false) {
        say('Bridge unreachable (' . $err . ') - retrying in ' . RETRY_DELAY . 's');
        sleep(RETRY_DELAY);
        continue;
    }

    if ($status !== 200) {
        say('Bridge returned HTTP ' . $status . ' - retrying in ' . RETRY_DELAY . 's');
        sleep(RETRY_DELAY);
        continue;
    }

    $data = json_decode($body, true);
    if (!is_array($data) || empty($data['job']) || !is_array($data['job'])) {
        continue; // nothing queued in that window - reconnect straight away
    }

    $job = $data['job'];
    $id = $job['id'] ?? '';
    if ($id === '') continue;

    $started = microtime(true);
    $response = run_locally($job);
    send_reply($link, $id, $response);

    say(sprintf(
        '%s %s -> %d (%dms)',
        $job['method'] ?? '?',
        $job['path'] ?? '?',
        $response['status'],
        round((microtime(true) - $started) * 1000)
    ));
}
