<?php
/**
 * QID Management System - UDP Broadcast Discovery Daemon
 *
 * Run with PHP CLI (see start_discovery_daemon.bat). Listens for a one-word UDP
 * broadcast from the mobile app and answers with this PC's URL.
 *
 * Why this exists
 * ---------------
 * The app's fallback is to TCP-probe all 254 addresses of the subnet, which takes
 * seconds and fails whenever the PC sits outside the probed range. A broadcast is
 * how printers and media devices are found: the phone shouts once, only the right
 * machine answers, and the whole exchange takes about 100 milliseconds.
 *
 * Apache cannot do this itself - it speaks TCP/HTTP only and has no way to hold a
 * UDP socket open between requests - so this runs as its own small process.
 *
 * Protocol
 * --------
 *   Phone  -> 255.255.255.255:45454   "QID_DISCOVER"
 *   PC     -> <phone ip>:<phone port>  {"app":"qid_scanner", "recommended_url":...}
 *
 * The reply is the same shape as api/discovery.php, so the app parses one format.
 */

declare(ticks = 1);

require_once __DIR__ . '/../includes/lan_ip.php';

const QID_DISCOVERY_PORT = 45454;
const QID_PROBE_MESSAGE  = 'QID_DISCOVER';

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("This script must be run from the command line.\n");
}

if (!extension_loaded('sockets')) {
    fwrite(STDERR, "ERROR: the PHP 'sockets' extension is not enabled.\n");
    fwrite(STDERR, "Enable extension=sockets in php.ini and try again.\n");
    exit(1);
}

/**
 * Web path this install is served from. __DIR__ is <install>/tools, so the folder
 * name one level up is the htdocs folder the app must request.
 */
function qid_daemon_base_path()
{
    $folder = basename(dirname(__DIR__));
    return '/' . $folder;
}

/**
 * Builds the reply for a phone at $client_ip.
 *
 * The PC may hold several addresses (Wi-Fi, Ethernet, VM adapters). The only one
 * useful to this phone is the one on the phone's own subnet, so pick that.
 */
function qid_build_reply($client_ip)
{
    $lan_ips   = qid_collect_lan_ips();
    $base_path = qid_daemon_base_path();

    $client_prefix = implode('.', array_slice(explode('.', $client_ip), 0, 3)) . '.';

    $best = null;
    foreach ($lan_ips as $ip) {
        $prefix = implode('.', array_slice(explode('.', $ip), 0, 3)) . '.';
        if ($prefix === $client_prefix) {
            $best = $ip;
            break;
        }
    }

    // No shared subnet: answer with the best guess anyway and let the app verify
    // over HTTP. A wrong guess costs the app one failed request, not a failure.
    if ($best === null) {
        $best = !empty($lan_ips) ? $lan_ips[0] : '127.0.0.1';
    }

    return json_encode(array(
        'status'          => 'ok',
        'app'             => 'qid_scanner',
        'system'          => 'QID Management System',
        'version'         => '2.1',
        'server_id'       => qid_server_id(),
        'hostname'        => gethostname(),
        'server_ip'       => $best,
        'lan_ips'         => $lan_ips,
        'port'            => 80,
        'base_path'       => $base_path,
        'recommended_url' => 'http://' . $best . $base_path,
        'via'             => 'udp_broadcast',
        'timestamp'       => time(),
    ), JSON_UNESCAPED_SLASHES);
}

function qid_log($message)
{
    echo '[' . date('H:i:s') . '] ' . $message . PHP_EOL;
}

// ── Bind ────────────────────────────────────────────────────────────────────

$socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
if ($socket === false) {
    fwrite(STDERR, 'ERROR: socket_create failed: ' . socket_strerror(socket_last_error()) . PHP_EOL);
    exit(1);
}

socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1);
socket_set_option($socket, SOL_SOCKET, SO_BROADCAST, 1);

if (!@socket_bind($socket, '0.0.0.0', QID_DISCOVERY_PORT)) {
    $err = socket_strerror(socket_last_error($socket));
    fwrite(STDERR, 'ERROR: cannot bind UDP port ' . QID_DISCOVERY_PORT . ': ' . $err . PHP_EOL);
    fwrite(STDERR, "Another copy of this daemon may already be running.\n");
    exit(1);
}

qid_log('QID discovery daemon listening on UDP ' . QID_DISCOVERY_PORT);
qid_log('This PC: ' . implode(', ', qid_collect_lan_ips()));
qid_log('Serving: ' . qid_daemon_base_path());
qid_log('Waiting for phones... (close this window to stop)');

// ── Serve ───────────────────────────────────────────────────────────────────

while (true) {
    $buffer = '';
    $client_ip = '';
    $client_port = 0;

    // Blocks until a datagram arrives; costs no CPU while idle.
    $bytes = @socket_recvfrom($socket, $buffer, 1024, 0, $client_ip, $client_port);

    if ($bytes === false) {
        // Windows raises an error here if an earlier reply was unreachable.
        // It is not fatal for the listener, so keep serving.
        socket_clear_error($socket);
        continue;
    }

    if (trim($buffer) !== QID_PROBE_MESSAGE) {
        continue; // stray traffic on the port
    }

    $reply = qid_build_reply($client_ip);
    @socket_sendto($socket, $reply, strlen($reply), 0, $client_ip, $client_port);

    qid_log('Answered ' . $client_ip . ':' . $client_port);
}
