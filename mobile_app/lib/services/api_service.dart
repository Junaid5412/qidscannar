import 'dart:async';
import 'dart:convert';
import 'dart:io';
import 'package:http/http.dart' as http;
import 'storage_service.dart';

class ApiService {
  static Future<Map<String, dynamic>> testConnection(String serverUrl) async {
    final baseUrl = StorageService.cleanUrl(serverUrl);
    final uri = Uri.parse('$baseUrl/api/app_login.php');

    try {
      final response = await http
          .post(uri, body: {})
          .timeout(const Duration(seconds: 6));

      // 200, 400, or 401 proves the PHP endpoint is responding
      if (response.statusCode == 200 ||
          response.statusCode == 400 ||
          response.statusCode == 401) {
        return {'success': true, 'message': 'Server is online & reachable!'};
      } else {
        return {
          'success': false,
          'message': 'Server returned HTTP ${response.statusCode}'
        };
      }
    } on SocketException catch (e) {
      return {'success': false, 'message': 'Network error: ${e.message}'};
    } on TimeoutException {
      return {'success': false, 'message': 'Connection timed out (6s).'};
    } catch (e) {
      return {'success': false, 'message': 'Cannot reach server: $e'};
    }
  }

  static Future<Map<String, dynamic>> login({
    required String serverUrl,
    required String username,
    required String password,
  }) async {
    final baseUrl = StorageService.cleanUrl(serverUrl);
    final uri = Uri.parse('$baseUrl/api/app_login.php');

    try {
      final response = await http.post(uri, body: {
        'username': username.trim(),
        'password': password.trim(),
        'device_name': Platform.isIOS ? 'Apple iPhone' : 'Android Device',
      }).timeout(const Duration(seconds: 8));

      final data = jsonDecode(response.body) as Map<String, dynamic>;
      if (response.statusCode == 200 && data['success'] == true) {
        final token = data['token'] as String;
        final user = data['user'] as Map<String, dynamic>;
        final company = data['company_name'] as String?;
        final currency = data['currency'] as String?;

        await StorageService.saveSession(
          serverUrl: baseUrl,
          token: token,
          userId: user['id'] as int,
          username: user['username'] as String,
          fullName: user['full_name'] as String,
          company: company,
          currency: currency,
        );

        return {'success': true, 'data': data};
      } else {
        return {
          'success': false,
          'message': data['message'] ?? 'Authentication rejected.'
        };
      }
    } on SocketException catch (e) {
      return {'success': false, 'message': 'Network unreachable: ${e.message}'};
    } on TimeoutException {
      return {'success': false, 'message': 'Login request timed out.'};
    } catch (e) {
      return {'success': false, 'message': 'Login failed: $e'};
    }
  }

  static Future<Map<String, dynamic>> pushScan({
    required String qidNumber,
    String scanType = 'barcode',
    Map<String, String> cardData = const {},
  }) async {
    final baseUrl = await StorageService.getServerUrl();
    final token = await StorageService.getAuthToken();

    if (baseUrl.isEmpty || token.isEmpty) {
      return {'success': false, 'message': 'No active session or server URL.'};
    }

    final uri = Uri.parse('$baseUrl/api/scan_push.php');

    try {
      final requestBody = {
        'qid_number': qidNumber.trim(),
        'scan_type': scanType,
        'device_name': Platform.isIOS ? 'Apple iPhone' : 'Android Device',
        'auth_token': token, // Fallback parameter
      };

      // Inject OCR extracted fields
      if (cardData.containsKey('name')) requestBody['card_data[name]'] = cardData['name']!;
      if (cardData.containsKey('nationality')) requestBody['card_data[nationality]'] = cardData['nationality']!;
      if (cardData.containsKey('job')) requestBody['card_data[job]'] = cardData['job']!;
      if (cardData.containsKey('expiry')) requestBody['card_data[expiry]'] = cardData['expiry']!;

      final response = await http.post(
        uri,
        headers: {
          'Authorization': 'Bearer $token',
          'X-Auth-Token': token,
        },
        body: requestBody,
      ).timeout(const Duration(seconds: 8));

      final data = jsonDecode(response.body) as Map<String, dynamic>;
      return data;
    } on SocketException catch (e) {
      return {'success': false, 'message': 'Cannot reach server: ${e.message}'};
    } on TimeoutException {
      return {'success': false, 'message': 'Scan push timed out.'};
    } catch (e) {
      return {'success': false, 'message': 'Sync push failed: $e'};
    }
  }

  /// Automatically discovers the QID server on the local Wi-Fi subnet.
  /// Returns the detected server URL or null.
  /// [onProgress] receives live diagnostic messages shown to the user.
  static Future<String?> discoverLocalServer({
    Function(String status)? onProgress,
  }) async {
    onProgress?.call('Detecting network interfaces...');

    // ── Step 1: Gather ALL device IPv4 addresses ──
    final List<String> allDeviceIps = [];
    final List<String> interfaceLog = [];

    try {
      final interfaces = await NetworkInterface.list(
        includeLoopback: false,
        type: InternetAddressType.IPv4,
      );

      for (final iface in interfaces) {
        for (final addr in iface.addresses) {
          final ip = addr.address;
          if (ip.startsWith('169.254.')) continue; // skip APIPA
          allDeviceIps.add(ip);
          interfaceLog.add('${iface.name}=$ip');
        }
      }
    } catch (e) {
      interfaceLog.add('ERROR: $e');
    }

    onProgress?.call('Interfaces: ${interfaceLog.isEmpty ? "none found" : interfaceLog.join(", ")}');

    // Short pause so user can read the interface info
    await Future.delayed(const Duration(milliseconds: 600));

    // ── Step 2: Build subnet list from device IPs (prefer private ranges) ──
    final List<String> subnetsToScan = [];
    final Set<String> seenSubnets = {};

    for (final ip in allDeviceIps) {
      final parts = ip.split('.');
      if (parts.length != 4) continue;

      final prefix = '${parts[0]}.${parts[1]}.${parts[2]}.';

      // Only scan private network ranges (skip carrier IPs like 100.x.x.x)
      final first = int.tryParse(parts[0]) ?? 0;
      final second = int.tryParse(parts[1]) ?? 0;
      final isPrivate = (first == 192 && second == 168) ||
          (first == 10) ||
          (first == 172 && second >= 16 && second <= 31);

      if (isPrivate && !seenSubnets.contains(prefix)) {
        seenSubnets.add(prefix);
        // Prioritize 192.168.x.x (most common Wi-Fi)
        if (first == 192) {
          subnetsToScan.insert(0, prefix);
        } else {
          subnetsToScan.add(prefix);
        }
      }
    }

    // Add common fallback subnets if we found nothing
    if (subnetsToScan.isEmpty) {
      onProgress?.call('No Wi-Fi subnet detected, trying common ranges...');
      for (final fb in ['192.168.0.', '192.168.1.', '192.168.10.', '192.168.100.', '10.0.0.']) {
        if (!seenSubnets.contains(fb)) {
          subnetsToScan.add(fb);
          seenSubnets.add(fb);
        }
      }
    }

    // ── Step 3: For each subnet, do a phased scan ──
    for (final prefix in subnetsToScan) {
      onProgress?.call('Scanning $prefix*  (phase 1: quick targets)...');

      // Phase 1: DIRECT quick-probe of the most likely host IPs first
      // These are common DHCP assignments for Windows PCs on home/office Wi-Fi
      final quickTargets = <int>[
        // Typical Windows DHCP range on most routers
        153, 154, 150, 151, 152, 155, 156, 157, 158, 159, 160,
        // Common static/server IPs
        100, 101, 102, 103, 104, 105,
        // Gateway IPs (some XAMPP setups bind to gateway)
        1, 2, 254,
      ];

      // Remove own IPs
      quickTargets.removeWhere((h) => allDeviceIps.contains('$prefix$h'));

      // Try quick targets first – small batch, generous timeout
      final quickResult = await _scanBatch(prefix, quickTargets, onProgress, 1200);
      if (quickResult != null) return quickResult;

      // Phase 2: Full sequential scan of ALL remaining hosts (1-254)
      onProgress?.call('Scanning $prefix*  (phase 2: full range)...');

      final List<int> fullRange = [];
      for (int h = 1; h <= 254; h++) {
        if (!quickTargets.contains(h) && !allDeviceIps.contains('$prefix$h')) {
          fullRange.add(h);
        }
      }

      // Scan in small safe batches of 20 with generous 1.2s timeout
      const batchSize = 20;
      for (int i = 0; i < fullRange.length; i += batchSize) {
        final end = (i + batchSize < fullRange.length) ? i + batchSize : fullRange.length;
        final batch = fullRange.sublist(i, end);

        onProgress?.call('Probing $prefix${batch.first} – $prefix${batch.last}...');

        final batchResult = await _scanBatch(prefix, batch, onProgress, 1200);
        if (batchResult != null) return batchResult;
      }
    }

    // ── Step 4: Last resort – Android emulator ──
    if (await _checkPort('10.0.2.2', 80, timeoutMs: 500)) {
      final result = await _verifyQidServer('10.0.2.2');
      if (result != null) return result;
    }

    return null;
  }

  /// Scans a batch of host numbers on the given subnet prefix.
  /// Returns the first verified QID server URL, or null.
  static Future<String?> _scanBatch(
    String prefix,
    List<int> hostNumbers,
    Function(String status)? onProgress,
    int timeoutMs,
  ) async {
    // Parallel TCP port 80 check
    final probeResults = await Future.wait(hostNumbers.map((h) async {
      final ip = '$prefix$h';
      final isOpen = await _checkPort(ip, 80, timeoutMs: timeoutMs);
      return isOpen ? ip : null;
    }));

    final openIps = probeResults.whereType<String>().toList();
    if (openIps.isEmpty) return null;

    onProgress?.call('Port 80 open on: ${openIps.join(", ")} – verifying...');

    // Verify each open host in parallel
    final verifyResults = await Future.wait(openIps.map((ip) async {
      return await _verifyQidServer(ip);
    }));

    for (final result in verifyResults) {
      if (result != null) return result;
    }

    return null;
  }

  /// Fast TCP socket probe to check if HTTP port is open.
  static Future<bool> _checkPort(String ip, int port, {int timeoutMs = 1200}) async {
    try {
      final socket = await Socket.connect(
        ip,
        port,
        timeout: Duration(milliseconds: timeoutMs),
      );
      socket.destroy();
      return true;
    } catch (_) {
      return false;
    }
  }

  /// Verifies if a given IP is running the QID Management System.
  /// Tries /QID/api/discovery.php first, then /qid, then root, then app_login fallback.
  static Future<String?> _verifyQidServer(String ip, {int port = 80}) async {
    final portSuffix = (port == 80) ? '' : ':$port';

    // Try discovery.php on known paths
    for (final path in ['/QID', '/qid', '']) {
      try {
        final uri = Uri.parse('http://$ip$portSuffix$path/api/discovery.php');
        final response = await http
            .get(uri)
            .timeout(const Duration(seconds: 2));
        if (response.statusCode == 200) {
          final dynamic data = jsonDecode(response.body);
          if (data is Map && data['app'] == 'qid_scanner') {
            final recUrl = data['recommended_url'] as String?;
            if (recUrl != null && recUrl.isNotEmpty) {
              return recUrl;
            }
            return 'http://$ip$portSuffix$path';
          }
        }
      } catch (_) {}
    }

    // Fallback: check app_login.php on /QID
    try {
      final uri = Uri.parse('http://$ip$portSuffix/QID/api/app_login.php');
      final response = await http
          .post(uri, body: {})
          .timeout(const Duration(seconds: 2));
      if (response.statusCode == 200 ||
          response.statusCode == 400 ||
          response.statusCode == 401) {
        return 'http://$ip$portSuffix/QID';
      }
    } catch (_) {}

    return null;
  }
}


