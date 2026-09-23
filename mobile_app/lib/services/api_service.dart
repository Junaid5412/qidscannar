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
  /// Scans common Wi-Fi IP ranges and tests the discovery endpoint.
  static Future<String?> discoverLocalServer({
    Function(String status)? onProgress,
  }) async {
    onProgress?.call('Detecting Wi-Fi subnet...');

    final Set<String> subnetPrefixes = {};

    try {
      final interfaces = await NetworkInterface.list(
        includeLoopback: false,
        type: InternetAddressType.IPv4,
      );

      for (final iface in interfaces) {
        for (final addr in iface.addresses) {
          final ip = addr.address;
          final parts = ip.split('.');
          if (parts.length == 4) {
            final prefix = '${parts[0]}.${parts[1]}.${parts[2]}.';
            subnetPrefixes.add(prefix);
          }
        }
      }
    } catch (_) {}

    // Check Android emulator loopback host first
    for (final host in ['10.0.2.2', '127.0.0.1']) {
      final verified = await _verifyQidServer(host);
      if (verified != null) return verified;
    }

    // Default common subnets if no interface was detected
    if (subnetPrefixes.isEmpty) {
      subnetPrefixes.addAll(['192.168.0.', '192.168.1.', '192.168.100.', '10.0.0.']);
    }

    for (final prefix in subnetPrefixes) {
      onProgress?.call('Scanning Wi-Fi subnet ${prefix}0/24...');

      // Smart probe ordering:
      // 1. Common DHCP high range (150-165, where Windows PC 192.168.0.153/158 sits)
      // 2. Common gateway & static server IPs (1-20)
      // 3. Common DHCP midrange (100-149, 21-99, 166-254)
      final List<int> hostOrder = [];
      for (int i = 150; i <= 165; i++) {
        hostOrder.add(i);
      }
      for (int i = 1; i <= 20; i++) {
        if (!hostOrder.contains(i)) hostOrder.add(i);
      }
      for (int i = 100; i <= 149; i++) {
        if (!hostOrder.contains(i)) hostOrder.add(i);
      }
      for (int i = 21; i <= 99; i++) {
        if (!hostOrder.contains(i)) hostOrder.add(i);
      }
      for (int i = 166; i <= 254; i++) {
        if (!hostOrder.contains(i)) hostOrder.add(i);
      }

      const int batchSize = 35;
      for (int i = 0; i < hostOrder.length; i += batchSize) {
        final end = (i + batchSize < hostOrder.length) ? i + batchSize : hostOrder.length;
        final batch = hostOrder.sublist(i, end);

        onProgress?.call('Probing ${prefix}${batch.first} - ${prefix}${batch.last}...');

        final probeResults = await Future.wait(batch.map((hostNum) async {
          final targetIp = '$prefix$hostNum';
          final isOpen = await _checkPort(targetIp, 80, timeoutMs: 320);
          return isOpen ? targetIp : null;
        }));

        final openIps = probeResults.whereType<String>().toList();
        for (final openIp in openIps) {
          onProgress?.call('Testing server at $openIp...');
          final verified = await _verifyQidServer(openIp);
          if (verified != null) {
            return verified;
          }
        }
      }
    }

    return null;
  }

  /// Fast TCP socket probe to check if HTTP port is open
  static Future<bool> _checkPort(String ip, int port, {int timeoutMs = 320}) async {
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

  /// Verifies if a given IP is indeed running the QID Management System
  static Future<String?> _verifyQidServer(String ip, {int port = 80}) async {
    final portSuffix = (port == 80) ? '' : ':$port';
    final candidateUrls = [
      'http://$ip$portSuffix/QID',
      'http://$ip$portSuffix',
      'http://$ip$portSuffix/qid',
    ];

    for (final candidate in candidateUrls) {
      // 1. Try discovery.php endpoint
      try {
        final uri = Uri.parse('$candidate/api/discovery.php');
        final response = await http.get(uri).timeout(const Duration(milliseconds: 1400));
        if (response.statusCode == 200) {
          final dynamic data = jsonDecode(response.body);
          if (data is Map && data['app'] == 'qid_scanner') {
            final recUrl = data['recommended_url'] as String?;
            if (recUrl != null && recUrl.isNotEmpty) {
              return recUrl;
            }
            return candidate;
          }
        }
      } catch (_) {}

      // 2. Fallback to app_login.php
      try {
        final uri = Uri.parse('$candidate/api/app_login.php');
        final response = await http.post(uri, body: {}).timeout(const Duration(milliseconds: 1200));
        if (response.statusCode == 200 || response.statusCode == 400 || response.statusCode == 401) {
          return candidate;
        }
      } catch (_) {}
    }
    return null;
  }
}
