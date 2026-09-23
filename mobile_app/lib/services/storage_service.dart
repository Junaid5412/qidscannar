import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:shared_preferences/shared_preferences.dart';

class StorageService {
  /// The online bridge this app ships pointed at.
  ///
  /// The bridge gives the PC a fixed address that does not change with Wi-Fi,
  /// DHCP or location, which is why the app no longer hunts for the PC on the
  /// local network. It is editable in Settings for a different install.
  static const String defaultServerUrl =
      'https://sstqa.com/qidbridge/bridge.php/7fdbfd2eed51e7bc14729978';

  static const String _keyServerUrl = 'server_url';
  static const String _keyAuthToken = 'auth_token';
  static const String _keyUserId = 'user_id';
  static const String _keyUsername = 'username';
  static const String _keyFullName = 'full_name';
  static const String _keyCompany = 'company_name';
  static const String _keyCurrency = 'currency';
  static const String _keySaveLogin = 'save_login';
  static const String _keyBiometric = 'biometric_enabled';

  /// Credentials live in the platform keystore, never in plain preferences.
  static final _secure = FlutterSecureStorage(
    aOptions: AndroidOptions(encryptedSharedPreferences: true),
  );
  static const String _secUsername = 'saved_username';
  static const String _secPassword = 'saved_password';

  // ── Session ──────────────────────────────────────────────────────────────

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

  /// Logging out must also drop anything that could sign back in silently,
  /// otherwise "Logout" would not really log the device out.
  static Future<void> forgetEverything() async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.remove(_keyAuthToken);
    await prefs.setBool(_keySaveLogin, false);
    await prefs.setBool(_keyBiometric, false);
    await clearCredentialsQuietly();
  }

  // ── Server address ───────────────────────────────────────────────────────

  static Future<void> setServerUrl(String serverUrl) async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString(_keyServerUrl, cleanUrl(serverUrl));
  }

  static Future<String> getServerUrl() async {
    final prefs = await SharedPreferences.getInstance();
    final saved = prefs.getString(_keyServerUrl);
    if (saved == null || saved.trim().isEmpty) return defaultServerUrl;
    return saved;
  }

  static Future<void> resetServerUrl() async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.remove(_keyServerUrl);
  }

  // ── Saved login ──────────────────────────────────────────────────────────

  static Future<bool> isSaveLoginEnabled() async {
    final prefs = await SharedPreferences.getInstance();
    return prefs.getBool(_keySaveLogin) ?? false;
  }

  static Future<void> saveCredentials(String username, String password) async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.setBool(_keySaveLogin, true);
    await _secure.write(key: _secUsername, value: username);
    await _secure.write(key: _secPassword, value: password);
  }

  static Future<void> clearCredentialsQuietly() async {
    try {
      await _secure.delete(key: _secUsername);
      await _secure.delete(key: _secPassword);
    } catch (_) {
      // Keystore can be unavailable right after a restore or an OS upgrade.
      // Nothing useful to tell the user here; the app just asks them to log in.
    }
  }

  static Future<void> forgetCredentials() async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.setBool(_keySaveLogin, false);
    await prefs.setBool(_keyBiometric, false); // fingerprint needs them to work
    await clearCredentialsQuietly();
  }

  static Future<String?> getSavedUsername() async {
    try {
      return await _secure.read(key: _secUsername);
    } catch (_) {
      return null;
    }
  }

  static Future<String?> getSavedPassword() async {
    try {
      return await _secure.read(key: _secPassword);
    } catch (_) {
      return null;
    }
  }

  static Future<bool> hasSavedCredentials() async {
    final u = await getSavedUsername();
    final p = await getSavedPassword();
    return u != null && u.isNotEmpty && p != null && p.isNotEmpty;
  }

  // ── Fingerprint ──────────────────────────────────────────────────────────

  static Future<bool> isBiometricEnabled() async {
    final prefs = await SharedPreferences.getInstance();
    return prefs.getBool(_keyBiometric) ?? false;
  }

  static Future<void> setBiometricEnabled(bool enabled) async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.setBool(_keyBiometric, enabled);
  }

  // ── Profile ──────────────────────────────────────────────────────────────

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

  // ── URLs ─────────────────────────────────────────────────────────────────

  /// Builds the URL for an API endpoint from whichever address is saved.
  ///
  /// A direct install is reached as `<base>/api/scan_push.php`. The bridge link
  /// carries its key in the path, so appending the endpoint works there too and
  /// needs no special case. The older `?k=` bridge link put the key in the query
  /// string, where a plain append would corrupt it - that form is handled here
  /// so an app configured before the change keeps working.
  static String apiUrl(String baseUrl, String endpointPath) {
    final base = cleanUrl(baseUrl);

    if (base.contains('?')) {
      return '$base&p=${Uri.encodeQueryComponent(endpointPath)}';
    }

    return '$base$endpointPath';
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
