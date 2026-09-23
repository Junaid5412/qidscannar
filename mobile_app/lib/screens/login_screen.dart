import 'package:flutter/material.dart';
import '../services/api_service.dart';
import '../services/biometric_service.dart';
import '../services/storage_service.dart';
import 'scanner_screen.dart';

class LoginScreen extends StatefulWidget {
  /// Set when the user arrives here after a failed unlock, so the screen does
  /// not immediately offer the same prompt again.
  final bool skipBiometricPrompt;

  const LoginScreen({Key? key, this.skipBiometricPrompt = false}) : super(key: key);

  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen> {
  final _usernameController = TextEditingController();
  final _passwordController = TextEditingController();

  bool _obscurePassword = true;
  bool _isLoading = false;
  bool _isChecking = false;
  bool _saveLogin = false;

  bool _biometricEnabled = false;
  bool _biometricAvailable = false;
  String _biometricLabel = 'Fingerprint';

  String? _statusMessage;
  bool _isStatusPositive = true;

  @override
  void initState() {
    super.initState();
    _restoreState();
  }

  @override
  void dispose() {
    _usernameController.dispose();
    _passwordController.dispose();
    super.dispose();
  }

  Future<void> _restoreState() async {
    final saveLogin = await StorageService.isSaveLoginEnabled();
    final savedUsername = await StorageService.getSavedUsername();
    final biometricEnabled = await StorageService.isBiometricEnabled();
    final hasCreds = await StorageService.hasSavedCredentials();
    final available = await BiometricService.isAvailable();
    final label = await BiometricService.methodLabel();

    if (!mounted) return;

    setState(() {
      _saveLogin = saveLogin;
      _usernameController.text = savedUsername ?? '';
      _biometricAvailable = available;
      _biometricEnabled = biometricEnabled && hasCreds && available;
      _biometricLabel = label;
    });

    // Offer the unlock straight away - that is the point of enabling it.
    if (_biometricEnabled && !widget.skipBiometricPrompt) {
      _loginWithBiometrics();
    }
  }

  Future<void> _loginWithBiometrics() async {
    final result = await BiometricService.authenticate(
      reason: 'Unlock QID Scanner',
    );

    if (!mounted) return;

    if (!result.ok) {
      if (result.error != null) _setStatus(result.error!, false);
      return;
    }

    final username = await StorageService.getSavedUsername();
    final password = await StorageService.getSavedPassword();

    if (username == null || password == null || username.isEmpty || password.isEmpty) {
      _setStatus('No saved login found. Please sign in once with your password.', false);
      return;
    }

    _usernameController.text = username;
    _passwordController.text = password;
    await _login(fromBiometrics: true);
  }

  Future<void> _checkServer() async {
    setState(() {
      _isChecking = true;
      _statusMessage = 'Checking server...';
      _isStatusPositive = true;
    });

    final res = await ApiService.checkServer();

    if (!mounted) return;
    setState(() {
      _isChecking = false;
      _statusMessage = res['message'];
      _isStatusPositive = res['success'] == true;
    });
  }

  Future<void> _login({bool fromBiometrics = false}) async {
    final username = _usernameController.text.trim();
    final password = _passwordController.text.trim();

    if (username.isEmpty || password.isEmpty) {
      _setStatus('Please enter your username and password.', false);
      return;
    }

    setState(() {
      _isLoading = true;
      _statusMessage = null;
    });

    final serverUrl = await StorageService.getServerUrl();
    final res = await ApiService.login(
      serverUrl: serverUrl,
      username: username,
      password: password,
    );

    if (!mounted) return;
    setState(() => _isLoading = false);

    if (res['success'] != true) {
      // Stored credentials that no longer work would silently fail on every
      // launch, so drop them and let the user sign in again.
      if (fromBiometrics) await StorageService.forgetCredentials();
      _setStatus(res['message'] ?? 'Login failed.', false);
      return;
    }

    if (_saveLogin) {
      await StorageService.saveCredentials(username, password);
    } else {
      await StorageService.forgetCredentials();
    }

    if (!mounted) return;
    Navigator.of(context).pushReplacement(
      MaterialPageRoute(builder: (_) => const ScannerScreen()),
    );
  }

  void _setStatus(String msg, bool positive) {
    setState(() {
      _statusMessage = msg;
      _isStatusPositive = positive;
    });
  }

  InputDecoration _fieldDecoration(String hint, {Widget? suffix}) {
    return InputDecoration(
      hintText: hint,
      hintStyle: const TextStyle(color: Color(0xFF64748B)),
      filled: true,
      fillColor: const Color(0xFF0F172A),
      border: OutlineInputBorder(
        borderRadius: BorderRadius.circular(10),
        borderSide: const BorderSide(color: Color(0xFF334155)),
      ),
      focusedBorder: OutlineInputBorder(
        borderRadius: BorderRadius.circular(10),
        borderSide: const BorderSide(color: Color(0xFF8A1538), width: 2),
      ),
      suffixIcon: suffix,
      contentPadding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
    );
  }

  Widget _label(String text) => Text(
        text,
        style: const TextStyle(
          color: Color(0xFF94A3B8),
          fontSize: 11,
          fontWeight: FontWeight.bold,
        ),
      );

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: const Color(0xFF0F172A),
      body: SafeArea(
        child: Center(
          child: SingleChildScrollView(
            padding: const EdgeInsets.all(24.0),
            child: Column(
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                Container(
                  width: 72,
                  height: 72,
                  decoration: BoxDecoration(
                    color: const Color(0xFF8A1538).withOpacity(0.15),
                    shape: BoxShape.circle,
                    border: Border.all(color: const Color(0xFF8A1538), width: 2),
                  ),
                  child: const Icon(
                    Icons.qr_code_scanner_rounded,
                    size: 38,
                    color: Color(0xFF8A1538),
                  ),
                ),
                const SizedBox(height: 16),
                const Text(
                  'QID Sync Scanner',
                  style: TextStyle(
                    color: Colors.white,
                    fontSize: 22,
                    fontWeight: FontWeight.bold,
                  ),
                ),
                const SizedBox(height: 4),
                const Text(
                  'Real-Time Cross-Platform Desktop Sync',
                  style: TextStyle(color: Color(0xFF94A3B8), fontSize: 13),
                ),
                const SizedBox(height: 32),

                Container(
                  padding: const EdgeInsets.all(20),
                  decoration: BoxDecoration(
                    color: const Color(0xFF1E293B),
                    borderRadius: BorderRadius.circular(16),
                    border: Border.all(color: const Color(0xFF334155)),
                  ),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      _label('USERNAME'),
                      const SizedBox(height: 6),
                      TextField(
                        controller: _usernameController,
                        style: const TextStyle(color: Colors.white, fontSize: 14),
                        textInputAction: TextInputAction.next,
                        decoration: _fieldDecoration('Enter staff username'),
                      ),
                      const SizedBox(height: 16),

                      _label('PASSWORD'),
                      const SizedBox(height: 6),
                      TextField(
                        controller: _passwordController,
                        obscureText: _obscurePassword,
                        style: const TextStyle(color: Colors.white, fontSize: 14),
                        textInputAction: TextInputAction.done,
                        onSubmitted: (_) => _isLoading ? null : _login(),
                        decoration: _fieldDecoration(
                          'Enter staff password',
                          suffix: IconButton(
                            icon: Icon(
                              _obscurePassword ? Icons.visibility_off : Icons.visibility,
                              color: const Color(0xFF64748B),
                              size: 18,
                            ),
                            onPressed: () =>
                                setState(() => _obscurePassword = !_obscurePassword),
                          ),
                        ),
                      ),
                      const SizedBox(height: 6),

                      // Save login
                      Row(
                        children: [
                          SizedBox(
                            width: 28,
                            height: 28,
                            child: Checkbox(
                              value: _saveLogin,
                              activeColor: const Color(0xFF0D9488),
                              side: const BorderSide(color: Color(0xFF64748B)),
                              onChanged: (v) => setState(() => _saveLogin = v ?? false),
                            ),
                          ),
                          const SizedBox(width: 8),
                          Expanded(
                            child: GestureDetector(
                              onTap: () => setState(() => _saveLogin = !_saveLogin),
                              child: const Text(
                                'Save login on this phone',
                                style: TextStyle(color: Color(0xFFCBD5E1), fontSize: 13),
                              ),
                            ),
                          ),
                        ],
                      ),
                      const SizedBox(height: 14),

                      SizedBox(
                        width: double.infinity,
                        height: 48,
                        child: ElevatedButton(
                          onPressed: _isLoading ? null : () => _login(),
                          style: ElevatedButton.styleFrom(
                            backgroundColor: const Color(0xFF8A1538),
                            foregroundColor: Colors.white,
                            shape: RoundedRectangleBorder(
                                borderRadius: BorderRadius.circular(10)),
                            elevation: 2,
                          ),
                          child: _isLoading
                              ? const SizedBox(
                                  width: 20,
                                  height: 20,
                                  child: CircularProgressIndicator(
                                      strokeWidth: 2, color: Colors.white),
                                )
                              : const Text(
                                  'Login & Connect',
                                  style: TextStyle(
                                      fontSize: 15, fontWeight: FontWeight.bold),
                                ),
                        ),
                      ),

                      if (_biometricEnabled) ...[
                        const SizedBox(height: 10),
                        SizedBox(
                          width: double.infinity,
                          height: 44,
                          child: OutlinedButton.icon(
                            onPressed: _isLoading ? null : _loginWithBiometrics,
                            icon: const Icon(Icons.fingerprint, size: 22),
                            label: Text(
                              'Login with $_biometricLabel',
                              style: const TextStyle(
                                  fontSize: 14, fontWeight: FontWeight.bold),
                            ),
                            style: OutlinedButton.styleFrom(
                              foregroundColor: const Color(0xFF2DD4BF),
                              side: const BorderSide(color: Color(0xFF0D9488)),
                              shape: RoundedRectangleBorder(
                                  borderRadius: BorderRadius.circular(10)),
                            ),
                          ),
                        ),
                      ],

                      const SizedBox(height: 10),
                      SizedBox(
                        width: double.infinity,
                        height: 40,
                        child: TextButton.icon(
                          onPressed: _isChecking ? null : _checkServer,
                          icon: _isChecking
                              ? const SizedBox(
                                  width: 13,
                                  height: 13,
                                  child: CircularProgressIndicator(
                                      strokeWidth: 2, color: Color(0xFF94A3B8)),
                                )
                              : const Icon(Icons.cloud_done_outlined, size: 17),
                          label: Text(
                            _isChecking ? 'Checking...' : 'Check Server',
                            style: const TextStyle(fontSize: 13),
                          ),
                          style: TextButton.styleFrom(
                              foregroundColor: const Color(0xFF94A3B8)),
                        ),
                      ),

                      if (_statusMessage != null) ...[
                        const SizedBox(height: 12),
                        Container(
                          width: double.infinity,
                          padding: const EdgeInsets.all(10),
                          decoration: BoxDecoration(
                            color: const Color(0xFF0F172A),
                            borderRadius: BorderRadius.circular(8),
                            border: Border.all(
                              color: _isStatusPositive
                                  ? const Color(0xFF10B981)
                                  : const Color(0xFFEF4444),
                            ),
                          ),
                          child: Text(
                            _statusMessage!,
                            textAlign: TextAlign.center,
                            style: TextStyle(
                              color: _isStatusPositive
                                  ? const Color(0xFF10B981)
                                  : const Color(0xFFEF4444),
                              fontSize: 12,
                              height: 1.35,
                              fontWeight: FontWeight.w600,
                            ),
                          ),
                        ),
                      ],
                    ],
                  ),
                ),

                const SizedBox(height: 20),
                Text(
                  _biometricAvailable
                      ? 'Tip: tick "Save login", then turn on $_biometricLabel in Settings.'
                      : 'The server address is set in Settings.',
                  textAlign: TextAlign.center,
                  style: const TextStyle(color: Color(0xFF64748B), fontSize: 11),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
