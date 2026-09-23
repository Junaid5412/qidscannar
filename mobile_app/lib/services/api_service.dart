import 'dart:async';
import 'dart:convert';
import 'dart:io';
import 'package:http/http.dart' as http;
import 'storage_service.dart';

/// Talks to the QID system at whichever address is configured.
///
/// The app no longer searches the local network for the PC. It connects to one
/// fixed address - the online bridge - which works on any Wi-Fi and on mobile
/// data, so there is nothing to detect and nothing to go stale.
class ApiService {
  /// Requests through the bridge cross the internet twice (phone to host, host
  /// to PC), so budgets are generous compared with a direct LAN call.
  static const Duration _checkTimeout = Duration(seconds: 20);
  static const Duration _loginTimeout = Duration(seconds: 25);
  static const Duration _scanTimeout = Duration(seconds: 25);

  /// Confirms the configured address reaches a live QID system.
  ///
  /// Reports the PC's own name on success, which is what makes this useful:
  /// it proves the request travelled all the way to the right machine rather
  /// than merely reaching the hosting.
  static Future<Map<String, dynamic>> checkServer([String? serverUrl]) async {
    final baseUrl = StorageService.cleanUrl(
      serverUrl ?? await StorageService.getServerUrl(),
    );

    try {
      final response = await http
          .get(Uri.parse(StorageService.apiUrl(baseUrl, '/api/discovery.php')))
          .timeout(_checkTimeout);

      final dynamic data = jsonDecode(response.body);

      if (data is Map && data['app'] == 'qid_scanner') {
        final host = data['hostname'] ?? 'the QID PC';
        return {'success': true, 'message': 'Connected to $host.'};
      }

      // The bridge answers in the app's own JSON shape when it cannot hand the
      // request on, so its explanation is the most useful thing to show.
      if (data is Map && data['message'] != null) {
        return {'success': false, 'message': data['message'].toString()};
      }

      return {
        'success': false,
        'message': 'That address answered, but it is not a QID server '
            '(HTTP ${response.statusCode}).'
      };
    } on SocketException catch (e) {
      return {'success': false, 'message': 'No internet or wrong address: ${e.message}'};
    } on TimeoutException {
      return {'success': false, 'message': 'The server did not answer in time.'};
    } on FormatException {
      return {'success': false, 'message': 'That address did not return QID data. Check the link.'};
    } catch (e) {
      return {'success': false, 'message': 'Cannot reach server: $e'};
    }
  }

  /// Kept for the login screen's own wording.
  static Future<Map<String, dynamic>> testConnection(String serverUrl) =>
      checkServer(serverUrl);

  static Future<Map<String, dynamic>> login({
    required String serverUrl,
    required String username,
    required String password,
  }) async {
    final baseUrl = StorageService.cleanUrl(serverUrl);

    try {
      final response = await http.post(
        Uri.parse(StorageService.apiUrl(baseUrl, '/api/app_login.php')),
        body: {
          'username': username.trim(),
          'password': password.trim(),
          'device_name': Platform.isIOS ? 'Apple iPhone' : 'Android Device',
        },
      ).timeout(_loginTimeout);

      final data = jsonDecode(response.body) as Map<String, dynamic>;

      if (response.statusCode == 200 && data['success'] == true) {
        final user = data['user'] as Map<String, dynamic>;

        await StorageService.saveSession(
          serverUrl: baseUrl,
          token: data['token'] as String,
          userId: user['id'] as int,
          username: user['username'] as String,
          fullName: user['full_name'] as String,
          company: data['company_name'] as String?,
          currency: data['currency'] as String?,
        );

        return {'success': true, 'data': data};
      }

      return {
        'success': false,
        'message': data['message'] ?? 'Authentication rejected.'
      };
    } on SocketException catch (e) {
      return {'success': false, 'message': 'No internet: ${e.message}'};
    } on TimeoutException {
      return {'success': false, 'message': 'Login timed out. Is the PC connector running?'};
    } on FormatException {
      return {'success': false, 'message': 'The server did not return a valid response.'};
    } catch (e) {
      return {'success': false, 'message': 'Login failed: $e'};
    }
  }

  static Future<Map<String, dynamic>> pushScan({
    required String qidNumber,
    String scanType = 'barcode',
    Map<String, String> cardData = const {},
  }) async {
    final token = await StorageService.getAuthToken();
    final baseUrl = await StorageService.getServerUrl();

    if (baseUrl.isEmpty || token.isEmpty) {
      return {'success': false, 'message': 'No active session. Please log in again.'};
    }

    final body = {
      'qid_number': qidNumber.trim(),
      'scan_type': scanType,
      'device_name': Platform.isIOS ? 'Apple iPhone' : 'Android Device',
      'auth_token': token, // fallback for servers that drop the header
    };

    // Inject OCR extracted fields
    if (cardData.containsKey('name')) body['card_data[name]'] = cardData['name']!;
    if (cardData.containsKey('nationality')) body['card_data[nationality]'] = cardData['nationality']!;
    if (cardData.containsKey('job')) body['card_data[job]'] = cardData['job']!;
    if (cardData.containsKey('expiry')) body['card_data[expiry]'] = cardData['expiry']!;

    try {
      final response = await http.post(
        Uri.parse(StorageService.apiUrl(baseUrl, '/api/scan_push.php')),
        headers: {
          'Authorization': 'Bearer $token',
          'X-Auth-Token': token,
        },
        body: body,
      ).timeout(_scanTimeout);

      return jsonDecode(response.body) as Map<String, dynamic>;
    } on SocketException catch (e) {
      return {'success': false, 'message': 'No internet: ${e.message}'};
    } on TimeoutException {
      return {
        'success': false,
        'message': 'Sync timed out. Check the PC is on and the connector is running.'
      };
    } on FormatException {
      return {'success': false, 'message': 'The server returned an unreadable response.'};
    } catch (e) {
      return {'success': false, 'message': 'Sync failed: $e'};
    }
  }
}
