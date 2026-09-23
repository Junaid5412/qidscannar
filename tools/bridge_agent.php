<?php
/**
 * QID Management System - Online Bridge (PC agent half)
 * -----------------------------------------------------
 * Run with PHP CLI on the XAMPP machine: tools\start_bridge_agent.bat
 *
 * Holds one long-lived OUTBOUND connection to your hosting and waits to be given
 * work. Because it only ever dials out, it is unaffected by Wi-Fi client
 * isolation, by the PC having no fixed IP, and by there being no port forwarded
 * on the router. Nothing can connect *to* this PC; it does the connecting.
 *
 *   loop:
 *     ask the bridge for a job   (blocks ~20s, costs nothing while idle)
 *     run it against http://localhost/QID
 *     post the response back
 *
 * Every record and the whole database stay on this machine. The bridge only ever
 * sees one request and its reply, for the moment it takes to pass them along.
 */

declare(ticks = 1);

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("This script must be run from the command line.\n");
}

if (!function_exists('curl_init')) {
    fwrite(STDERR, "ERROR: the PHP 'curl' extension is not enabled.\n");
    exit(1);
}

$config_file = __DIR__ . '/bridge_config_agent.php';
if (!file_exists($config_file)) {
    fwrite(STDERR, "ERROR: tools/bridge_config_agent.php is missing.\n");
    fwrite(STDERR, "Copy bridge_config_agent.sample.php to bridge_config_agent.php and fill it in.\n");
    exit(1);
}
require_once $config_file;

foreach (array('BRIDGE_URL', 'BRIDGE_KEY', 'LOCAL_BASE_URL') as $required) {
    if (!defined($required) || constant($required) === '' || constant($required) === 'CHANGE-ME') {
        fwrite(STDERR, "ERROR: $required is not set in tools/bridge_config_agent.php\n");
        exit(1);
    }
}

/** Seconds to wait on a poll before reconnecting. Matches the bridge's own window. */
const AGENT_POLL_TIMEOUT = 35;

/** Ceiling for one local request. Longer than any QID API call should ever take. */
const AGENT_LOCAL_TIMEOUT = 25;

/** Backoff after a connection failure, so a dropped internet link does not spin. */
const AGENT_RETRY_DELAY = 5;

function agent_log($message)
{
    echo '[' . date('H:i:s') . '] ' . $message . PHP_EOL;
}

function agent_bridge_url($action, array $extra = array())
{
    $params = array_merge(array('__agent' => $action, 'key' => BRIDGE_KEY), $extra);
    return rtrim(BRIDGE_URL, '/') . '/bridge.php?' . http_build_query($params);
}

/**
 * Asks the bridge for the next job. Returns the job array, null when the wait
 * expired with nothing queued, or false when the bridge could not be reached.
 */
function agent_poll()
{
    $ch = curl_init(agent_bridge_url('poll'));
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => AGENT_POLL_TIMEOUT,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT      => 'QID-Bridge-Agent/1.0',
    ));

    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        agent_log('Bridge unreachable: ' . $error);
        return false;
    }

    if ($status === 403) {
        fwrite(STDERR, "ERROR: the bridge rejected this key.\n");
        fwrite(STDERR, "BRIDGE_KEY here must match BRIDGE_KEY in bridge_config.php on the host.\n");
        exit(1);
    }

    if ($status !== 200) {
        agent_log('Bridge returned HTTP ' . $status);
        return false;
    }

    $data = json_decode($body, true);
    if (!is_array($data)) {
        agent_log('Bridge sent a malformed reply.');
        return false;
    }

    return isset($data['job']) && is_array($data['job']) ? $data['job'] : null;
}

/**
 * Replays one relayed request against the local XAMPP install and captures the
 * status, headers and body exactly as Apache produced them.
 */
function agent_run_locally(array $job)
{
    $path = isset($job['path']) ? $job['path'] : '/';
    $query = isset($job['query']) ? $job['query'] : '';
    $url = rtrim(LOCAL_BASE_URL, '/') . $path . ($query !== '' ? '?' . $query : '');

    $headers = array();
    if (!empty($job['headers']) && is_array($job['headers'])) {
        foreach ($job['headers'] as $name => $value) {
            $headers[] = $name . ': ' . $value;
        }
    }

    $body = base64_decode(isset($job['body']) ? $job['body'] : '');
    $method = isset($job['method']) ? strtoupper($job['method']) : 'GET';

    $response_headers = array();

    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => AGENT_LOCAL_TIMEOUT,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
        // The QID API replies with plain JSON; following redirects here would
        // hide a 302 that the app is meant to see.
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$response_headers) {
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $response_headers[trim($parts[0])] = trim($parts[1]);
            }
            return strlen($line);
        },
    ));

    if ($method !== 'GET' && $method !== 'HEAD') {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }

    $out = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($out === false) {
        return array(
            'status'  => 502,
            'headers' => array('Content-Type' => 'application/json'),
            'body'    => base64_encode(json_encode(array(
                'success' => false,
                'message' => 'The bridge agent could not reach XAMPP on this PC: ' . $error,
            ))),
        );
    }

    return array(
        'status'  => $status ?: 200,
        'headers' => $response_headers,
        'body'    => base64_encode($out),
    );
}

function agent_send_reply($job_id, array $response)
{
    $ch = curl_init(agent_bridge_url('reply', array('job' => $job_id)));
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($response),
        CURLOPT_HTTPHEADER     => array('Content-Type: application/json'),
        CURLOPT_USERAGENT      => 'QID-Bridge-Agent/1.0',
    ));
    $ok = curl_exec($ch) !== false;
    curl_close($ch);
    return $ok;
}

// ───────────────────────────────────────────────────────────────────────────
// Startup check
// ───────────────────────────────────────────────────────────────────────────

agent_log('QID bridge agent starting');
agent_log('Bridge : ' . BRIDGE_URL);
agent_log('Local  : ' . LOCAL_BASE_URL);

$probe = curl_init(rtrim(LOCAL_BASE_URL, '/') . '/api/discovery.php');
curl_setopt_array($probe, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10));
$probe_body = curl_exec($probe);
$probe_status = (int) curl_getinfo($probe, CURLINFO_HTTP_CODE);
curl_close($probe);

if ($probe_status !== 200) {
    agent_log('WARNING: ' . LOCAL_BASE_URL . '/api/discovery.php returned HTTP ' . $probe_status);
    agent_log('         Is XAMPP Apache running? The agent will keep trying.');
} else {
    agent_log('Local QID system OK');
}

$ping = curl_init(agent_bridge_url('ping'));
curl_setopt_array($ping, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20));
$ping_body = curl_exec($ping);
$ping_status = (int) curl_getinfo($ping, CURLINFO_HTTP_CODE);
curl_close($ping);

if ($ping_status === 403) {
    fwrite(STDERR, "ERROR: the bridge rejected this key. Check BRIDGE_KEY on both sides.\n");
    exit(1);
}
if ($ping_status !== 200) {
    agent_log('WARNING: bridge ping returned HTTP ' . $ping_status . ' - will keep retrying.');
} else {
    agent_log('Bridge reachable');
}

agent_log('Connected. In the app, set Server URL to: ' . rtrim(BRIDGE_URL, '/'));
agent_log('Waiting for requests... (close this window to stop)');

// ───────────────────────────────────────────────────────────────────────────
// Serve
// ───────────────────────────────────────────────────────────────────────────

while (true) {
    $job = agent_poll();

    if ($job === false) {
        sleep(AGENT_RETRY_DELAY);
        continue;
    }

    if ($job === null) {
        continue; // poll window expired with nothing queued - reconnect immediately
    }

    $id = isset($job['id']) ? $job['id'] : '';
    if ($id === '') {
        continue;
    }

    $started = microtime(true);
    $response = agent_run_locally($job);
    $ms = round((microtime(true) - $started) * 1000);

    agent_send_reply($id, $response);

    agent_log(sprintf(
        '%s %s -> %d (%dms)',
        isset($job['method']) ? $job['method'] : '?',
        isset($job['path']) ? $job['path'] : '?',
        $response['status'],
        $ms
    ));
}
