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
}
