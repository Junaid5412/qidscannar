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
  /// Optimized: parallel probing, filtered interfaces, fast verification.
  static Future<String?> discoverLocalServer({
    Function(String status)? onProgress,
  }) async {
    onProgress?.call('Detecting Wi-Fi network...');

    final Set<String> subnetPrefixes = {};
    final Set<String> ownIps = {};

    // 1. Detect device's local IPv4 addresses (Wi-Fi only)
    try {
      final interfaces = await NetworkInterface.list(
        includeLoopback: false,
        type: InternetAddressType.IPv4,
      );

      for (final iface in interfaces) {
        // Skip cellular/mobile data interfaces
        final name = iface.name.toLowerCase();
        if (name.contains('rmnet') ||
            name.contains('ccmni') ||
            name.contains('pdp') ||
            name.contains('clat') ||
            name.contains('v4-rmnet')) {
          continue;
        }

        for (final addr in iface.addresses) {
          final ip = addr.address;
          // Skip link-local and APIPA addresses
          if (ip.startsWith('169.254.')) continue;
          // Skip carrier IPs (they tend to be in 10.x or unusual ranges)
          // but keep 10.0.2.x for emulators and 10.0.0.x/10.0.1.x for common LANs
          ownIps.add(ip);
          final parts = ip.split('.');
          if (parts.length == 4) {
            final prefix = '${parts[0]}.${parts[1]}.${parts[2]}.';
            subnetPrefixes.add(prefix);
          }
        }
      }
    } catch (_) {}

    onProgress?.call('Found ${subnetPrefixes.length} subnet(s), scanning...');

    // 2. Add common home/office subnets as fallback
    if (subnetPrefixes.isEmpty) {
      subnetPrefixes.addAll([
        '192.168.0.',
        '192.168.1.',
        '192.168.10.',
        '192.168.100.',
        '10.0.0.',
      ]);
    }

    // 3. Scan each subnet
    for (final prefix in subnetPrefixes) {
      onProgress?.call('Scanning ${prefix}0/24...');

      // Smart host ordering: prioritize where DHCP typically assigns PCs
      final List<int> hostOrder = [];

      // a) Common DHCP high range (where your PC at .153/.158 sits)
      for (int i = 100; i <= 200; i++) {
        hostOrder.add(i);
      }
      // b) Gateway & static server IPs
      for (int i = 1; i <= 20; i++) {
        if (!hostOrder.contains(i)) hostOrder.add(i);
      }
      // c) Remaining range
      for (int i = 201; i <= 254; i++) {
        if (!hostOrder.contains(i)) hostOrder.add(i);
      }
      for (int i = 21; i <= 99; i++) {
        if (!hostOrder.contains(i)) hostOrder.add(i);
      }

      // Remove our own device IPs from scan targets
      hostOrder.removeWhere((h) => ownIps.contains('$prefix$h'));

      // Scan in batches of 50 (parallel TCP probes)
      const int batchSize = 50;
      for (int i = 0; i < hostOrder.length; i += batchSize) {
        final end = (i + batchSize < hostOrder.length) ? i + batchSize : hostOrder.length;
        final batch = hostOrder.sublist(i, end);

        onProgress?.call('Probing $prefix${batch.first} – $prefix${batch.last}...');

        // Parallel TCP port 80 check
        final probeResults = await Future.wait(batch.map((hostNum) async {
          final targetIp = '$prefix$hostNum';
          final isOpen = await _checkPort(targetIp, 80, timeoutMs: 500);
          return isOpen ? targetIp : null;
        }));

        final openIps = probeResults.whereType<String>().toList();
        if (openIps.isEmpty) continue;

        onProgress?.call('Found ${openIps.length} hosts, verifying...');

        // Verify ALL open hosts in PARALLEL (not sequentially!)
        final verifyResults = await Future.wait(openIps.map((openIp) async {
          return await _verifyQidServer(openIp);
        }));

        for (final result in verifyResults) {
          if (result != null) return result;
        }
      }
    }

    // 4. Last resort: try Android emulator host (only with fast port check)
    if (await _checkPort('10.0.2.2', 80, timeoutMs: 300)) {
      final result = await _verifyQidServer('10.0.2.2');
      if (result != null) return result;
    }

    return null;
  }

  /// Fast TCP socket probe to check if HTTP port is open
  static Future<bool> _checkPort(String ip, int port, {int timeoutMs = 500}) async {
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
  /// Fast: tries /QID/api/discovery.php first (most likely path),
  /// then falls back to root, with short timeouts.
  static Future<String?> _verifyQidServer(String ip, {int port = 80}) async {
    final portSuffix = (port == 80) ? '' : ':$port';

    // Try the known paths in priority order — /QID is the standard XAMPP path
    final pathsToTry = ['/QID', '/qid', ''];

    for (final path in pathsToTry) {
      final baseUrl = 'http://$ip$portSuffix$path';

      // Primary check: discovery.php (fastest, designed for this purpose)
      try {
        final uri = Uri.parse('$baseUrl/api/discovery.php');
        final response = await http
            .get(uri)
            .timeout(const Duration(milliseconds: 800));
        if (response.statusCode == 200) {
          final dynamic data = jsonDecode(response.body);
          if (data is Map && data['app'] == 'qid_scanner') {
            // Use the server's own recommended URL if available
            final recUrl = data['recommended_url'] as String?;
            if (recUrl != null && recUrl.isNotEmpty) {
              return recUrl;
            }
            return baseUrl;
          }
        }
      } catch (_) {}
    }

    // Last fallback: check app_login.php on /QID only
    try {
      final uri = Uri.parse('http://$ip$portSuffix/QID/api/app_login.php');
      final response = await http
          .post(uri, body: {})
          .timeout(const Duration(milliseconds: 800));
      if (response.statusCode == 200 ||
          response.statusCode == 400 ||
          response.statusCode == 401) {
        return 'http://$ip$portSuffix/QID';
      }
    } catch (_) {}

    return null;
  }
}

