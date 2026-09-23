import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'package:http/http.dart' as http;

import 'storage_service.dart';

/// Result of verifying that a host really is the QID server.
class QidServerInfo {
  final String url;
  final String serverId;
  final String hostname;
  final List<String> alternateUrls;

  const QidServerInfo({
    required this.url,
    required this.serverId,
    required this.hostname,
    required this.alternateUrls,
  });
}

/// Finds the XAMPP machine on whatever Wi-Fi network the phone is currently on.
///
/// The PC's IP is handed out by DHCP, so it changes every time the phone (or the
/// PC) joins a different router. Rather than asking the user to retype the
/// address, this service:
///
///  1. remembers a working URL per Wi-Fi subnet, so rejoining a known network
///     reconnects in well under a second;
///  2. when the subnet is new, tries the host numbers that worked on *other*
///     networks first (routers commonly reissue the same last octet);
///  3. otherwise sweeps the whole subnet in parallel and stops the instant a
///     real QID server answers;
///  4. watches for the phone changing network and re-runs itself, so a running
///     scan session heals without the user logging out.
class NetworkService {
  /// Ports XAMPP realistically listens on, most likely first. Each extra port is
  /// another full sweep when the server is absent, so this stays short.
  static const List<int> _ports = [80, 8080];

  /// UDP port the PC's discovery daemon listens on (tools/discovery_daemon.php).
  static const int _broadcastPort = 45454;
  static const String _broadcastProbe = 'QID_DISCOVER';

  /// Folder names this system is typically installed under.
  static const List<String> _basePaths = ['/QID', '/qid', '', '/qid_management'];

  /// TCP connect budget. A LAN handshake completes in a few milliseconds; this is
  /// already very generous, and keeping it tight is what makes a full sweep fast.
  static const Duration _tcpTimeout = Duration(milliseconds: 700);

  /// HTTP budget for reading /api/discovery.php off a host with an open port.
  static const Duration _httpTimeout = Duration(milliseconds: 1800);

  /// Budget for a fixed remote address. The online bridge adds an internet hop
  /// plus the PC agent's own round trip, so it needs far more room than a LAN
  /// probe - and there is only ever one such request, so patience costs nothing.
  static const Duration _remoteHttpTimeout = Duration(seconds: 15);

  static final RegExp _bareIpv4 = RegExp(r'^\d{1,3}(\.\d{1,3}){3}$');

  /// True when [url] names a fixed address - the online bridge or a live site -
  /// rather than a LAN IP that DHCP is free to move around.
  static bool isFixedRemoteUrl(String url) {
    final host = Uri.tryParse(StorageService.cleanUrl(url))?.host ?? '';
    if (host.isEmpty) return false;
    return !_bareIpv4.hasMatch(host);
  }

  /// Simultaneous probes in flight. High enough to sweep 254 hosts in a handful
  /// of waves, low enough to stay well clear of the per-process socket limit.
  static const int _concurrency = 48;

  /// Gentler retry pass. A burst of 48 simultaneous SYNs can thrash a phone's
  /// ARP cache badly enough to lose the reply from the one host that matters, so
  /// a failed fast sweep is followed by a slower, more patient one.
  static const int _retryConcurrency = 10;
  static const Duration _retryTcpTimeout = Duration(milliseconds: 2000);

  /// Cap on the patient pass: 60 hosts at 10-wide and 2s is ~12s, which is the
  /// most waiting that can be justified after the fast sweep already missed.
  static const int _retryHostLimit = 60;

  /// How many hosts of the last sweep answered on the HTTP port. Zero means the
  /// phone could not reach *anything* - a router/subnet problem, not our problem.
  static int _openHostCount = 0;

  /// Human-readable account of the last failed discovery, shown to the user.
  static String lastDiagnostics = '';

  static Timer? _watchTimer;
  static String? _watchedNetworkKey;

  /// Shared so that a manual "Re-Detect" tap and the background watchdog join the
  /// same sweep instead of racing (or one wrongly reporting "not found").
  static Future<QidServerInfo?>? _inFlightDiscovery;

  // ─────────────────────────────────────────────────────────────────────────
  // Network identity
  // ─────────────────────────────────────────────────────────────────────────

  /// Every usable private IPv4 address this device currently holds.
  static Future<List<String>> localIpv4s() async {
    final ips = <String>[];
    try {
      final interfaces = await NetworkInterface.list(
        includeLoopback: false,
        type: InternetAddressType.IPv4,
      );
      for (final iface in interfaces) {
        for (final addr in iface.addresses) {
          if (_isPrivateIpv4(addr.address)) ips.add(addr.address);
        }
      }
    } catch (_) {
      // Interface enumeration can fail on locked-down devices; fall through.
    }
    return ips;
  }

  static bool _isPrivateIpv4(String ip) {
    final parts = ip.split('.');
    if (parts.length != 4) return false;
    final a = int.tryParse(parts[0]) ?? -1;
    final b = int.tryParse(parts[1]) ?? -1;
    if (a == 192 && b == 168) return true;
    if (a == 10) return true;
    if (a == 172 && b >= 16 && b <= 31) return true;
    return false;
  }

  /// Subnet prefix of the Wi-Fi the phone is on right now, e.g. `192.168.0.`.
  ///
  /// This doubles as the cache key. It needs no location permission, unlike
  /// reading the SSID, and changes exactly when the usable network changes.
  static Future<String?> currentNetworkKey() async {
    final ips = await localIpv4s();
    if (ips.isEmpty) return null;

    // Prefer 192.168.x.x - the overwhelmingly common home/office Wi-Fi range.
    ips.sort((a, b) {
      final aw = a.startsWith('192.168.') ? 0 : 1;
      final bw = b.startsWith('192.168.') ? 0 : 1;
      return aw.compareTo(bw);
    });

    return _prefixOf(ips.first);
  }

  static String _prefixOf(String ip) {
    final parts = ip.split('.');
    return '${parts[0]}.${parts[1]}.${parts[2]}.';
  }

  static final RegExp _ipv4Pattern =
      RegExp(r'(\d{1,3})\.(\d{1,3})\.(\d{1,3})\.(\d{1,3})');

  static int? _lastOctetOf(String ipOrUrl) {
    final match = _ipv4Pattern.firstMatch(ipOrUrl);
    if (match == null) return null;
    return int.tryParse(match.group(4)!);
  }

  /// Subnet prefix of the IPv4 address embedded in a URL, e.g.
  /// `http://192.168.0.153/QID` -> `192.168.0.`. Null when the URL uses a name.
  static String? _prefixOfUrl(String url) {
    final match = _ipv4Pattern.firstMatch(url);
    if (match == null) return null;
    return '${match.group(1)}.${match.group(2)}.${match.group(3)}.';
  }

  // ─────────────────────────────────────────────────────────────────────────
  // Public entry point
  // ─────────────────────────────────────────────────────────────────────────

  /// Returns a base URL that is confirmed reachable right now, or null.
  ///
  /// Cheap by design: the common case (nothing changed since the last scan) is a
  /// single sub-second request and no scanning at all. Pass [force] to skip the
  /// saved URL and rediscover from scratch.
  static Future<String?> ensureServerUrl({
    bool force = false,
    void Function(String status)? onProgress,
  }) async {
    final saved = await StorageService.getServerUrl();

    // 0. A fixed remote address (the online bridge, or a live site) is pinned.
    //    It cannot drift with DHCP, so scanning the LAN for it is meaningless -
    //    and worse, a stray LAN match would overwrite the address the user
    //    deliberately configured. Verify it and stop either way.
    if (saved.isNotEmpty && isFixedRemoteUrl(saved)) {
      onProgress?.call('Checking your server address...');
      if (await _verifyUrl(saved, timeout: _remoteHttpTimeout) != null) {
        return saved;
      }
      lastDiagnostics =
          'Could not reach $saved\n\n'
          'This is a fixed address, so the app will not scan the Wi-Fi for it.\n\n'
          '• If this is your online bridge, check that the PC is running\n'
          '  tools\\start_bridge_agent.bat and that the window is still open\n'
          '• Check this phone has internet\n'
          '• To go back to local Wi-Fi mode, clear the Server URL field';
      return null;
    }

    // 1. The URL we are already using - verify and we are done.
    if (!force && saved.isNotEmpty) {
      onProgress?.call('Checking saved server...');
      if (await _verifyUrl(saved) != null) return saved;
    }

    // 2. A URL that previously worked on a subnet this device is currently on.
    //    Checking every local subnet (not just the preferred one) matters when
    //    the phone holds more than one address, e.g. Wi-Fi plus a VPN.
    for (final ip in await localIpv4s()) {
      final prefix = _prefixOf(ip);
      final remembered = await StorageService.getServerForNetwork(prefix);
      if (remembered == null || remembered.isEmpty) continue;

      onProgress?.call('Reconnecting to known server for this Wi-Fi...');
      if (await _verifyUrl(remembered) != null) {
        await _remember(prefix, remembered);
        return remembered;
      }
    }

    // 3. Nothing known works - sweep the network.
    final found = await discover(onProgress: onProgress);
    return found?.url;
  }

  /// Full discovery sweep of the current Wi-Fi network.
  static Future<QidServerInfo?> discover({
    void Function(String status)? onProgress,
  }) {
    // Join an already-running sweep rather than starting a competing one.
    final existing = _inFlightDiscovery;
    if (existing != null) return existing;

    final run = _runDiscovery(onProgress: onProgress);
    _inFlightDiscovery = run;
    return run.whenComplete(() => _inFlightDiscovery = null);
  }

  static Future<QidServerInfo?> _runDiscovery({
    void Function(String status)? onProgress,
  }) async {
    _openHostCount = 0;

    final deviceIps = await localIpv4s();

    if (deviceIps.isEmpty) {
      lastDiagnostics = 'Phone has no private Wi-Fi address. Is Wi-Fi on, and is '
          'it a normal network (not a captive/guest portal)?';
      onProgress?.call('No Wi-Fi connection detected on this phone.');
      return null;
    }

    final subnets = <String>[];
    for (final ip in deviceIps) {
      final prefix = _prefixOf(ip);
      if (!subnets.contains(prefix)) {
        // 192.168.x.x first - it is where a home/office XAMPP box almost always sits.
        if (prefix.startsWith('192.168.')) {
          subnets.insert(0, prefix);
        } else {
          subnets.add(prefix);
        }
      }
    }

    // Ask the network directly before resorting to brute force. When the PC runs
    // tools/start_discovery_daemon.bat this answers in a few milliseconds and
    // finds the PC wherever it sits in the range, instead of hoping it falls in
    // the part of the subnet we probe first.
    onProgress?.call('Asking the network for the QID server...');
    final announced = await _discoverByBroadcast();
    if (announced != null) {
      final prefix = _prefixOfUrl(announced.url) ?? await currentNetworkKey() ?? '';
      await _remember(prefix, announced.url, serverId: announced.serverId);
      onProgress?.call('Found ${announced.hostname} at ${announced.url}');
      return announced;
    }

    final hints = await _hostNumberHints();

    for (final prefix in subnets) {
      final ownOctets = deviceIps
          .where((ip) => _prefixOf(ip) == prefix)
          .map(_lastOctetOf)
          .whereType<int>()
          .toSet();

      final candidates = _buildCandidateOrder(hints: hints, ownOctets: ownOctets);

      for (final port in _ports) {
        onProgress?.call(
          port == 80 ? 'Scanning $prefix* ...' : 'Scanning $prefix* on port $port...',
        );

        final result = await _sweep(
          prefix: prefix,
          hosts: candidates,
          port: port,
          onProgress: onProgress,
        );

        if (result != null) {
          await _remember(prefix, result.url, serverId: result.serverId);
          onProgress?.call('Found ${result.hostname} at ${result.url}');
          return result;
        }
      }

      // Slow pass on port 80 only. Wi-Fi drops packets under a heavy probe burst,
      // so a miss on the fast sweep is not proof the PC is absent.
      //
      // Only the highest-priority candidates: at this concurrency and timeout the
      // full 254 would take close to a minute and the app would look frozen.
      final patientHosts = candidates.take(_retryHostLimit).toList();
      onProgress?.call('Retrying ${patientHosts.length} likely hosts slowly...');

      final patient = await _sweep(
        prefix: prefix,
        hosts: patientHosts,
        port: 80,
        onProgress: onProgress,
        concurrency: _retryConcurrency,
        tcpTimeout: _retryTcpTimeout,
      );

      if (patient != null) {
        await _remember(prefix, patient.url, serverId: patient.serverId);
        onProgress?.call('Found ${patient.hostname} at ${patient.url}');
        return patient;
      }
    }

    // Android emulator host loopback - only reachable when running in an emulator.
    onProgress?.call('Checking emulator host...');
    final emulator = await _probeAndVerify('10.0.2.2', 80);
    if (emulator != null) return emulator;

    lastDiagnostics = _buildDiagnostics(deviceIps, subnets);
    onProgress?.call('No QID server responded on ${subnets.join(", ")}*');
    return null;
  }

  /// Turns a failed sweep into something that actually points at the cause.
  ///
  /// The decisive fact is whether *any* host answered on the HTTP port: none at
  /// all means the phone's traffic never reached the LAN (router client
  /// isolation, or the phone is on a different network than the PC), which no
  /// amount of retrying in the app can solve.
  static String _buildDiagnostics(List<String> deviceIps, List<String> subnets) {
    final phoneIp = deviceIps.isEmpty ? 'none' : deviceIps.join(', ');
    final scanned = subnets.map((s) => '$s*').join(', ');

    if (_openHostCount == 0) {
      return 'Phone IP: $phoneIp\n'
          'Scanned: $scanned\n'
          'No device on this Wi-Fi answered on port 80 - not even the router.\n\n'
          'That means the phone cannot reach the PC at all:\n'
          '• The Wi-Fi may block device-to-device traffic (AP isolation)\n'
          '• Or the PC is on a different Wi-Fi / subnet than this phone\n\n'
          'Check: open the PC address in this phone\'s browser. If that also '
          'fails, it is the network, not the app.\n\n'
          'Fastest fix: on the PC run tools\\start_discovery_daemon.bat, then '
          'tap Auto-Detect again. If that still fails, the network is blocking '
          'the phone and only a VPN such as Tailscale will help.';
    }

    return 'Phone IP: $phoneIp\n'
        'Scanned: $scanned\n'
        '$_openHostCount device(s) answered on port 80, but none was a QID server.\n\n'
        'The phone can reach the network, so check the PC:\n'
        '• XAMPP Apache must be running\n'
        '• The QID folder must be at htdocs\\QID\n'
        '• Confirm http://<PC-IP>/QID/api/discovery.php opens in this phone\'s browser';
  }

  // ─────────────────────────────────────────────────────────────────────────
  // Broadcast discovery
  // ─────────────────────────────────────────────────────────────────────────

  /// Shouts once on the local network and waits for the QID PC to answer.
  ///
  /// This is how printers and media devices are found, and it beats probing the
  /// subnet on every count: it takes milliseconds rather than seconds, and it
  /// locates the PC wherever it sits in the address range instead of depending on
  /// the probe order. It needs `tools/start_discovery_daemon.bat` running on the
  /// PC; when that is not running this returns null and the sweep takes over.
  ///
  /// Whatever the daemon claims is still verified over HTTP before it is trusted.
  static Future<QidServerInfo?> _discoverByBroadcast({
    Duration timeout = const Duration(milliseconds: 1500),
  }) async {
    final RawDatagramSocket bound;
    try {
      bound = await RawDatagramSocket.bind(InternetAddress.anyIPv4, 0);
    } catch (_) {
      return null; // some networks forbid binding a datagram socket
    }

    try {
      bound.broadcastEnabled = true;
    } catch (_) {
      bound.close();
      return null;
    }

    final completer = Completer<String?>();
    final payload = utf8.encode(_broadcastProbe);

    // Global broadcast plus each interface's directed broadcast: some Wi-Fi
    // drivers and routers silently drop 255.255.255.255 but pass 192.168.0.255.
    final targets = <InternetAddress>[InternetAddress('255.255.255.255')];
    for (final ip in await localIpv4s()) {
      try {
        targets.add(InternetAddress('${_prefixOf(ip)}255'));
      } catch (_) {
        // Malformed address - skip this interface.
      }
    }

    final sub = bound.listen((event) {
      if (event != RawSocketEvent.read) return;

      final datagram = bound.receive();
      if (datagram == null) return;

      try {
        final dynamic data = jsonDecode(utf8.decode(datagram.data));
        if (data is Map && data['app'] == 'qid_scanner') {
          final url = (data['recommended_url'] as String?)?.trim();
          if (url != null && url.isNotEmpty && !completer.isCompleted) {
            completer.complete(url);
          }
        }
      } catch (_) {
        // Not our reply - ignore and keep listening.
      }
    });

    try {
      // UDP is lossy and Wi-Fi drops broadcasts freely, so ask a few times
      // rather than concluding "absent" from a single unanswered probe.
      final deadline = DateTime.now().add(timeout);
      while (!completer.isCompleted && DateTime.now().isBefore(deadline)) {
        for (final target in targets) {
          try {
            bound.send(payload, target, _broadcastPort);
          } catch (_) {
            // One unreachable target must not abort the others.
          }
        }
        await Future.any<String?>([
          completer.future,
          Future<String?>.delayed(const Duration(milliseconds: 300)),
        ]);
      }

      if (!completer.isCompleted) return null;

      final url = await completer.future;
      if (url == null || url.isEmpty) return null;

      // Trust nothing on the wire: confirm the address actually serves QID.
      return _fetchDiscovery(StorageService.cleanUrl(url));
    } finally {
      await sub.cancel();
      bound.close();
    }
  }

  // ─────────────────────────────────────────────────────────────────────────
  // Scanning
  // ─────────────────────────────────────────────────────────────────────────

  /// Host numbers worth trying before the rest, newest knowledge first.
  ///
  /// A router on a different network very often hands the same PC the same last
  /// octet, so the address that worked on the office Wi-Fi is a strong guess for
  /// the home Wi-Fi. This is what usually turns a "scan" into an instant hit.
  static Future<List<int>> _hostNumberHints() async {
    final hints = <int>[];

    for (final url in await StorageService.getAllKnownServerUrls()) {
      final octet = _lastOctetOf(url);
      if (octet != null && !hints.contains(octet)) hints.add(octet);
    }

    return hints;
  }

  /// Orders 1-254 so the most likely hosts are probed in the very first wave.
  static List<int> _buildCandidateOrder({
    required List<int> hints,
    required Set<int> ownOctets,
  }) {
    final ordered = <int>[];
    final seen = <int>{};

    void add(int host) {
      if (host < 1 || host > 254) return;
      if (ownOctets.contains(host)) return; // the phone itself
      if (seen.add(host)) ordered.add(host);
    }

    // 1. Addresses this PC has used before, on any network.
    for (final h in hints) {
      add(h);
    }

    // 2. Neighbours of the phone's own address - DHCP hands out a contiguous pool,
    //    so the PC is usually within a few numbers of the phone.
    for (final own in ownOctets) {
      for (int delta = 1; delta <= 12; delta++) {
        add(own - delta);
        add(own + delta);
      }
    }

    // 3. Typical static / server addresses.
    for (final h in [1, 2, 100, 101, 102, 110, 150, 200, 250, 254]) {
      add(h);
    }

    // 4. Everything else.
    for (int h = 1; h <= 254; h++) {
      add(h);
    }

    return ordered;
  }

  /// Probes [hosts] on [prefix]:[port] with bounded concurrency, returning the
  /// first confirmed QID server and abandoning the remaining probes.
  static Future<QidServerInfo?> _sweep({
    required String prefix,
    required List<int> hosts,
    required int port,
    void Function(String status)? onProgress,
    int concurrency = _concurrency,
    Duration tcpTimeout = _tcpTimeout,
  }) async {
    if (hosts.isEmpty) return null;

    final completer = Completer<QidServerInfo?>();
    var next = 0;
    var scanned = 0;

    // Fixed pool of workers pulling from a shared cursor. Each worker stops as
    // soon as any of them has found the server, so the sweep ends on the first
    // hit instead of running to the end of the subnet.
    Future<void> worker() async {
      while (!completer.isCompleted) {
        final index = next++;
        if (index >= hosts.length) return;

        QidServerInfo? info;
        try {
          info = await _probeAndVerify('$prefix${hosts[index]}', port, tcpTimeout);
        } catch (_) {
          // A worker must never die on one bad host: if it did, the pool's
          // Future.wait would reject and this sweep would never complete.
          info = null;
        }

        if (info != null) {
          if (!completer.isCompleted) completer.complete(info);
          return;
        }

        scanned++;
        // Report roughly once per wave: often enough to look alive, rare enough
        // not to rebuild the UI 254 times.
        if (scanned % concurrency == 0) {
          onProgress?.call('Scanned $scanned of ${hosts.length} on $prefix*');
        }
      }
    }

    final poolSize = hosts.length < concurrency ? hosts.length : concurrency;

    void finishEmpty(Object? _) {
      if (!completer.isCompleted) completer.complete(null);
    }

    unawaited(
      Future.wait(List.generate(poolSize, (_) => worker()))
          .then(finishEmpty)
          .catchError(finishEmpty),
    );

    return completer.future;
  }

  /// TCP-knocks a host and, only if something is listening, asks whether it is us.
  static Future<QidServerInfo?> _probeAndVerify(
    String ip,
    int port, [
    Duration tcpTimeout = _tcpTimeout,
  ]) async {
    Socket? socket;
    try {
      socket = await Socket.connect(ip, port, timeout: tcpTimeout);
    } catch (_) {
      return null; // nothing listening - by far the common case
    } finally {
      socket?.destroy();
    }

    // Counted for diagnostics: "nothing at all answered" and "plenty answered but
    // none was QID" are completely different problems with different fixes.
    _openHostCount++;

    return _verifyHost(ip, port);
  }

  /// Asks a host with an open HTTP port whether it is running QID.
  ///
  /// All candidate install paths are tried at once: a printer or router with port
  /// 80 open would otherwise cost four sequential timeouts and stall the sweep.
  static Future<QidServerInfo?> _verifyHost(String ip, int port) async {
    final suffix = (port == 80) ? '' : ':$port';

    final attempts = _basePaths.map((path) async {
      return _fetchDiscovery('http://$ip$suffix$path');
    }).toList();

    final results = await Future.wait(attempts);
    for (final info in results) {
      if (info != null) return info;
    }
    return null;
  }

  /// Verifies an already-known base URL is still live. Used for the fast path.
  static Future<QidServerInfo?> _verifyUrl(String baseUrl, {Duration? timeout}) {
    return _fetchDiscovery(StorageService.cleanUrl(baseUrl), timeout: timeout);
  }

  /// Reads `<baseUrl>/api/discovery.php` and parses the QID fingerprint.
  static Future<QidServerInfo?> _fetchDiscovery(
    String baseUrl, {
    Duration? timeout,
  }) async {
    try {
      final uri = Uri.parse('$baseUrl/api/discovery.php');
      final response = await http.get(uri).timeout(timeout ?? _httpTimeout);

      if (response.statusCode != 200) return null;

      final dynamic data = jsonDecode(response.body);
      if (data is! Map || data['app'] != 'qid_scanner') return null;

      final alternates = (data['alternate_urls'] as List?)
              ?.whereType<String>()
              .toList() ??
          const <String>[];

      return QidServerInfo(
        // Deliberately NOT data['recommended_url']: when the app talks through
        // the online bridge, the PC answers with its own LAN address, which the
        // phone cannot reach. The address we just used is the one proven to
        // work, so that is the one we keep.
        url: baseUrl,
        serverId: (data['server_id'] as String?) ?? '',
        hostname: (data['hostname'] as String?) ?? 'QID Server',
        alternateUrls: alternates,
      );
    } catch (_) {
      return null;
    }
  }

  // ─────────────────────────────────────────────────────────────────────────
  // Persistence
  // ─────────────────────────────────────────────────────────────────────────

  static Future<void> _remember(
    String networkKey,
    String url, {
    String? serverId,
  }) async {
    await StorageService.setServerUrl(url);
    await StorageService.rememberServerForNetwork(networkKey, url);
    if (serverId != null && serverId.isNotEmpty) {
      await StorageService.setServerId(serverId);
    }
  }

  // ─────────────────────────────────────────────────────────────────────────
  // Network-change watchdog
  // ─────────────────────────────────────────────────────────────────────────

  /// Polls for the phone moving to a different Wi-Fi network and rediscovers the
  /// server when it does, so an open scanner session keeps working.
  ///
  /// Polling the interface list is deliberate: it needs no extra plugin and no
  /// extra Android permission, and at this interval the cost is negligible.
  static Future<void> startWatching({
    required void Function(String url) onReconnected,
    void Function()? onLost,
  }) async {
    stopWatching();

    // Establish the baseline before the first tick, otherwise the initial
    // null -> "192.168.0." transition would look like a network change.
    _watchedNetworkKey = await currentNetworkKey();

    _watchTimer = Timer.periodic(const Duration(seconds: 5), (_) async {
      // A fixed address does not move when the phone changes network, so there
      // is nothing to re-discover and nothing to tell the user about.
      final configured = await StorageService.getServerUrl();
      if (configured.isNotEmpty && isFixedRemoteUrl(configured)) return;

      final key = await currentNetworkKey();
      if (key == null || key == _watchedNetworkKey) return;

      _watchedNetworkKey = key;

      final url = await ensureServerUrl(force: true);
      if (url != null) {
        onReconnected(url);
      } else {
        onLost?.call();
      }
    });
  }

  static void stopWatching() {
    _watchTimer?.cancel();
    _watchTimer = null;
  }
}
