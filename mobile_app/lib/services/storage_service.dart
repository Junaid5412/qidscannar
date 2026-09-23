import 'package:shared_preferences/shared_preferences.dart';

class StorageService {
  static const String _keyServerUrl = 'server_url';
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
