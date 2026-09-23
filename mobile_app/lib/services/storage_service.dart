import 'dart:convert';

import 'package:shared_preferences/shared_preferences.dart';

class StorageService {
  static const String _keyServerUrl = 'server_url';
  static const String _keyServerId = 'server_id';

  /// Maps a Wi-Fi subnet prefix (e.g. "192.168.0.") to the server URL that last
  /// worked there, so rejoining a known network reconnects without scanning.
  static const String _keyServerMap = 'server_url_by_network';
  static const String _keyAuthToken = 'auth_token';
  static const String _keyUserId = 'user_id';
  static const String _keyUsername = 'username';
  static const String _keyFullName = 'full_name';
  static const String _keyCompany = 'company_name';
  static const String _keyCurrency = 'currency';

  static Future<bool> isLoggedIn() async {
    final prefs = await SharedPreferences.getInstance();
    final token = prefs.getString(_keyAuthToken);
    return token != null && token.trim().isNotEmpty;
  }

  static Future<void> saveSession({
    required String serverUrl,
    required String token,
    required int userId,
    required String username,
    required String fullName,
    String? company,
    String? currency,
  }) async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString(_keyServerUrl, cleanUrl(serverUrl));
    await prefs.setString(_keyAuthToken, token);
    await prefs.setInt(_keyUserId, userId);
    await prefs.setString(_keyUsername, username);
    await prefs.setString(_keyFullName, fullName);
    await prefs.setString(_keyCompany, company ?? 'Qatar Business Solutions');
    await prefs.setString(_keyCurrency, currency ?? 'QR');
  }

  static Future<void> clearSession() async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.remove(_keyAuthToken);
  }

  static Future<void> setServerUrl(String serverUrl) async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString(_keyServerUrl, cleanUrl(serverUrl));
  }

  static Future<String> getServerUrl() async {
    final prefs = await SharedPreferences.getInstance();
    return prefs.getString(_keyServerUrl) ?? '';
  }

  static Future<void> setServerId(String serverId) async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString(_keyServerId, serverId);
  }

  static Future<String> getServerId() async {
    final prefs = await SharedPreferences.getInstance();
    return prefs.getString(_keyServerId) ?? '';
  }

  static Future<Map<String, String>> _readServerMap() async {
    final prefs = await SharedPreferences.getInstance();
    final raw = prefs.getString(_keyServerMap);
    if (raw == null || raw.isEmpty) return {};
    try {
      final decoded = jsonDecode(raw);
      if (decoded is Map) {
        return decoded.map((k, v) => MapEntry(k.toString(), v.toString()));
      }
    } catch (_) {
      // A corrupt cache is not worth surfacing - discovery just rescans.
    }
    return {};
  }

  /// Records that [url] reached the QID server while on the [networkKey] subnet.
  static Future<void> rememberServerForNetwork(String networkKey, String url) async {
    final prefs = await SharedPreferences.getInstance();
    final map = await _readServerMap();
    map[networkKey] = cleanUrl(url);
    await prefs.setString(_keyServerMap, jsonEncode(map));
  }

  static Future<String?> getServerForNetwork(String networkKey) async {
    final map = await _readServerMap();
    return map[networkKey];
  }

  /// Every server URL seen on any network, most useful first. Discovery mines the
  /// host numbers out of these to guess the PC's address on an unseen network.
  static Future<List<String>> getAllKnownServerUrls() async {
    final urls = <String>[];

    final current = await getServerUrl();
    if (current.isNotEmpty) urls.add(current);

    for (final url in (await _readServerMap()).values) {
      if (!urls.contains(url)) urls.add(url);
    }

    return urls;
  }

  static Future<Map<String, String>> getKnownNetworks() async => _readServerMap();

  static Future<void> forgetNetworks() async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.remove(_keyServerMap);
  }

  static Future<String> getAuthToken() async {
    final prefs = await SharedPreferences.getInstance();
    return prefs.getString(_keyAuthToken) ?? '';
  }

  static Future<String> getUsername() async {
    final prefs = await SharedPreferences.getInstance();
    return prefs.getString(_keyUsername) ?? 'Staff';
  }

  static Future<String> getFullName() async {
    final prefs = await SharedPreferences.getInstance();
    return prefs.getString(_keyFullName) ?? 'Staff Member';
  }

  static String cleanUrl(String raw) {
    var url = raw.trim();
    if (!url.startsWith('http://') && !url.startsWith('https://')) {
      url = 'http://$url';
    }
    if (url.endsWith('/')) {
      url = url.substring(0, url.length - 1);
    }
    return url;
  }
}
