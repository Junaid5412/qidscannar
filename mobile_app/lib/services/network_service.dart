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

  /// Folder names this system is typically installed under.
  static const List<String> _basePaths = ['/QID', '/qid', '', '/qid_management'];

  /// TCP connect budget. A LAN handshake completes in a few milliseconds; this is
  /// already very generous, and keeping it tight is what makes a full sweep fast.
  static const Duration _tcpTimeout = Duration(milliseconds: 700);

  /// HTTP budget for reading /api/discovery.php off a host with an open port.
  static const Duration _httpTimeout = Duration(milliseconds: 1800);

  /// Simultaneous probes in flight. High enough to sweep 254 hosts in a handful
  /// of waves, low enough to stay well clear of the per-process socket limit.
  static const int _concurrency = 48;

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

  static int? _lastOctetOf(String ipOrUrl) {
    final match = RegExp(r'(\d{1,3})\.(\d{1,3})\.(\d{1,3})\.(\d{1,3})').firstMatch(ipOrUrl);
    if (match == null) return null;
    return int.tryParse(match.group(4)!);
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
    // 1. The URL we are already using - verify and we are done.
    if (!force) {
      final saved = await StorageService.getServerUrl();
      if (saved.isNotEmpty) {
        onProgress?.call('Checking saved server...');
        if (await _verifyUrl(saved) != null) return saved;
      }
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
    final deviceIps = await localIpv4s();

    if (deviceIps.isEmpty) {
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
    }

    // Android emulator host loopback - only reachable when running in an emulator.
    onProgress?.call('Checking emulator host...');
    final emulator = await _probeAndVerify('10.0.2.2', 80);
    if (emulator != null) return emulator;

    onProgress?.call('No QID server responded on ${subnets.join(", ")}*');
    return null;
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
          info = await _probeAndVerify('$prefix${hosts[index]}', port);
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
        if (scanned % _concurrency == 0) {
          onProgress?.call('Scanned $scanned of ${hosts.length} on $prefix*');
        }
      }
    }

    final poolSize = hosts.length < _concurrency ? hosts.length : _concurrency;

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
  static Future<QidServerInfo?> _probeAndVerify(String ip, int port) async {
    Socket? socket;
    try {
      socket = await Socket.connect(ip, port, timeout: _tcpTimeout);
    } catch (_) {
      return null; // nothing listening - by far the common case
    } finally {
      socket?.destroy();
    }

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
  static Future<QidServerInfo?> _verifyUrl(String baseUrl) async {
    return _fetchDiscovery(StorageService.cleanUrl(baseUrl));
  }

  /// Reads `<baseUrl>/api/discovery.php` and parses the QID fingerprint.
  static Future<QidServerInfo?> _fetchDiscovery(String baseUrl) async {
    try {
      final uri = Uri.parse('$baseUrl/api/discovery.php');
      final response = await http.get(uri).timeout(_httpTimeout);

      if (response.statusCode != 200) return null;

      final dynamic data = jsonDecode(response.body);
      if (data is! Map || data['app'] != 'qid_scanner') return null;

      final recommended = (data['recommended_url'] as String?)?.trim();
      final alternates = (data['alternate_urls'] as List?)
              ?.whereType<String>()
              .toList() ??
          const <String>[];

      return QidServerInfo(
        // recommended_url echoes back the host we used, so it already carries the
        // right IP; fall back to what we asked for if the server omitted it.
        url: (recommended != null && recommended.isNotEmpty) ? recommended : baseUrl,
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
