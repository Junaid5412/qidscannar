import 'dart:io';
import 'package:flutter/material.dart';
import '../services/api_service.dart';
import '../services/biometric_service.dart';
import '../services/storage_service.dart';
import 'login_screen.dart';

class SettingsScreen extends StatefulWidget {
  const SettingsScreen({Key? key}) : super(key: key);

  @override
  State<SettingsScreen> createState() => _SettingsScreenState();
}

class _SettingsScreenState extends State<SettingsScreen> {
  final _linkController = TextEditingController();

  String _fullName = '';
  String _username = '';

  bool _isChecking = false;
  String? _checkMessage;
  bool _checkOk = false;

  bool _saveLoginEnabled = false;
  bool _biometricEnabled = false;
  bool _biometricAvailable = false;
  String _biometricLabel = 'Fingerprint';

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _linkController.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    final name = await StorageService.getFullName();
    final user = await StorageService.getUsername();
    final url = await StorageService.getServerUrl();
    final saveLogin = await StorageService.isSaveLoginEnabled();
    final bioOn = await StorageService.isBiometricEnabled();
    final bioAvailable = await BiometricService.isAvailable();
    final label = await BiometricService.methodLabel();

    if (!mounted) return;
    setState(() {
      _fullName = name;
      _username = user;
      _linkController.text = url;
      _saveLoginEnabled = saveLogin;
      _biometricEnabled = bioOn;
      _biometricAvailable = bioAvailable;
      _biometricLabel = label;
    });
  }

  void _toast(String message, {bool good = true}) {
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        content: Text(message),
        backgroundColor: good ? const Color(0xFF0D9488) : Colors.redAccent,
        duration: const Duration(seconds: 3),
      ),
    );
  }

  Future<void> _saveLink() async {
    final link = _linkController.text.trim();

    if (link.isEmpty) {
      _toast('Enter a server link first.', good: false);
      return;
    }

    await StorageService.setServerUrl(link);
    if (!mounted) return;
    _toast('Server link saved.');
    _checkServer();
  }

  Future<void> _resetLink() async {
    await StorageService.resetServerUrl();
    final url = await StorageService.getServerUrl();
    if (!mounted) return;
    setState(() => _linkController.text = url);
    _toast('Reset to the default link.');
  }

  Future<void> _checkServer() async {
    setState(() {
      _isChecking = true;
      _checkMessage = null;
    });

    // Check what is typed, not just what is saved, so the button is useful
    // while editing the link.
    final res = await ApiService.checkServer(_linkController.text.trim());

    if (!mounted) return;
    setState(() {
      _isChecking = false;
      _checkOk = res['success'] == true;
      _checkMessage = res['message'];
    });
  }

  Future<void> _toggleBiometric(bool enable) async {
    if (!enable) {
      await StorageService.setBiometricEnabled(false);
      if (!mounted) return;
      setState(() => _biometricEnabled = false);
      _toast('$_biometricLabel login turned off.');
      return;
    }

    // Biometrics unlock the stored credentials, so without them there would be
    // nothing to unlock and the switch would be a lie.
    if (!await StorageService.hasSavedCredentials()) {
      if (!mounted) return;
      _toast(
        'Tick "Save login" when you sign in, then turn this on.',
        good: false,
      );
      return;
    }

    final result = await BiometricService.authenticate(
      reason: 'Confirm to enable $_biometricLabel login',
    );

    if (!mounted) return;

    if (!result.ok) {
      _toast(result.error ?? 'Could not verify.', good: false);
      return;
    }

    await StorageService.setBiometricEnabled(true);
    if (!mounted) return;
    setState(() => _biometricEnabled = true);
    _toast('$_biometricLabel login turned on.');
  }

  Future<void> _forgetSavedLogin() async {
    await StorageService.forgetCredentials();
    if (!mounted) return;
    setState(() {
      _saveLoginEnabled = false;
      _biometricEnabled = false;
    });
    _toast('Saved login removed from this phone.');
  }

  Future<void> _logout() async {
    await StorageService.forgetEverything();
    if (!mounted) return;
    Navigator.of(context).pushAndRemoveUntil(
      MaterialPageRoute(builder: (_) => const LoginScreen(skipBiometricPrompt: true)),
      (route) => false,
    );
  }

  // ── UI helpers ───────────────────────────────────────────────────────────

  Widget _sectionLabel(String text) => Padding(
        padding: const EdgeInsets.only(bottom: 8),
        child: Text(
          text,
          style: const TextStyle(
            color: Color(0xFF94A3B8),
            fontSize: 11,
            fontWeight: FontWeight.bold,
          ),
        ),
      );

  Widget _card({required Widget child}) => Container(
        padding: const EdgeInsets.all(16),
        decoration: BoxDecoration(
          color: const Color(0xFF1E293B),
          borderRadius: BorderRadius.circular(12),
          border: Border.all(color: const Color(0xFF334155)),
        ),
        child: child,
      );

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: const Color(0xFF0F172A),
      appBar: AppBar(
        title: const Text('Scanner Settings',
            style: TextStyle(fontWeight: FontWeight.bold, fontSize: 18)),
        backgroundColor: const Color(0xFF1E293B),
        elevation: 0,
        foregroundColor: Colors.white,
      ),
      body: ListView(
        padding: const EdgeInsets.all(20),
        children: [
          // ── Staff ──────────────────────────────────────────────────────
          _sectionLabel('LOGGED-IN STAFF'),
          _card(
            child: Row(
              children: [
                CircleAvatar(
                  backgroundColor: const Color(0xFF8A1538).withOpacity(0.2),
                  child: const Icon(Icons.person, color: Color(0xFF8A1538)),
                ),
                const SizedBox(width: 14),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        _fullName.isNotEmpty ? _fullName : 'Staff Member',
                        style: const TextStyle(
                            color: Colors.white,
                            fontWeight: FontWeight.bold,
                            fontSize: 15),
                      ),
                      const SizedBox(height: 2),
                      Text('Username: $_username',
                          style: const TextStyle(
                              color: Color(0xFF10B981), fontSize: 12)),
                    ],
                  ),
                ),
              ],
            ),
          ),
          const SizedBox(height: 24),

          // ── Server link ────────────────────────────────────────────────
          _sectionLabel('SERVER LINK'),
          _card(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                const Text(
                  'The bridge link from your QID system. The same link goes in '
                  'QID Settings on the PC.',
                  style: TextStyle(color: Color(0xFF94A3B8), fontSize: 12, height: 1.4),
                ),
                const SizedBox(height: 12),
                TextField(
                  controller: _linkController,
                  style: const TextStyle(
                      color: Colors.white, fontSize: 12, fontFamily: 'monospace'),
                  keyboardType: TextInputType.url,
                  maxLines: 3,
                  minLines: 1,
                  decoration: InputDecoration(
                    hintText: 'https://yourdomain.com/bridge.php/...',
                    hintStyle: const TextStyle(color: Color(0xFF64748B), fontSize: 12),
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
                    contentPadding:
                        const EdgeInsets.symmetric(horizontal: 12, vertical: 12),
                  ),
                ),
                const SizedBox(height: 12),
                Row(
                  children: [
                    Expanded(
                      child: SizedBox(
                        height: 40,
                        child: ElevatedButton.icon(
                          onPressed: _saveLink,
                          icon: const Icon(Icons.save_outlined, size: 17),
                          label: const Text('Save',
                              style: TextStyle(
                                  fontSize: 13, fontWeight: FontWeight.bold)),
                          style: ElevatedButton.styleFrom(
                            backgroundColor: const Color(0xFF8A1538),
                            foregroundColor: Colors.white,
                            shape: RoundedRectangleBorder(
                                borderRadius: BorderRadius.circular(8)),
                          ),
                        ),
                      ),
                    ),
                    const SizedBox(width: 8),
                    Expanded(
                      child: SizedBox(
                        height: 40,
                        child: ElevatedButton.icon(
                          onPressed: _isChecking ? null : _checkServer,
                          icon: _isChecking
                              ? const SizedBox(
                                  width: 13,
                                  height: 13,
                                  child: CircularProgressIndicator(
                                      strokeWidth: 2, color: Colors.white),
                                )
                              : const Icon(Icons.cloud_done_outlined, size: 17),
                          label: Text(_isChecking ? 'Checking' : 'Check Server',
                              style: const TextStyle(
                                  fontSize: 13, fontWeight: FontWeight.bold)),
                          style: ElevatedButton.styleFrom(
                            backgroundColor: const Color(0xFF0D9488),
                            foregroundColor: Colors.white,
                            shape: RoundedRectangleBorder(
                                borderRadius: BorderRadius.circular(8)),
                          ),
                        ),
                      ),
                    ),
                  ],
                ),
                if (_checkMessage != null) ...[
                  const SizedBox(height: 12),
                  Container(
                    width: double.infinity,
                    padding: const EdgeInsets.all(10),
                    decoration: BoxDecoration(
                      color: const Color(0xFF0F172A),
                      borderRadius: BorderRadius.circular(8),
                      border: Border.all(
                        color: _checkOk
                            ? const Color(0xFF10B981)
                            : const Color(0xFFEF4444),
                      ),
                    ),
                    child: Row(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Icon(
                          _checkOk ? Icons.check_circle : Icons.error_outline,
                          size: 16,
                          color: _checkOk
                              ? const Color(0xFF10B981)
                              : const Color(0xFFEF4444),
                        ),
                        const SizedBox(width: 8),
                        Expanded(
                          child: Text(
                            _checkMessage!,
                            style: TextStyle(
                              color: _checkOk
                                  ? const Color(0xFF10B981)
                                  : const Color(0xFFEF4444),
                              fontSize: 12,
                              height: 1.35,
                            ),
                          ),
                        ),
                      ],
                    ),
                  ),
                ],
                const SizedBox(height: 4),
                Align(
                  alignment: Alignment.centerLeft,
                  child: TextButton(
                    onPressed: _resetLink,
                    style: TextButton.styleFrom(
                      foregroundColor: const Color(0xFF64748B),
                      padding: EdgeInsets.zero,
                      minimumSize: const Size(0, 30),
                    ),
                    child: const Text('Reset to default link',
                        style: TextStyle(fontSize: 12)),
                  ),
                ),
              ],
            ),
          ),
          const SizedBox(height: 24),

          // ── Security ───────────────────────────────────────────────────
          _sectionLabel('LOGIN & SECURITY'),
          _card(
            child: Column(
              children: [
                SwitchListTile(
                  contentPadding: EdgeInsets.zero,
                  value: _biometricEnabled,
                  onChanged: _biometricAvailable ? _toggleBiometric : null,
                  activeColor: const Color(0xFF0D9488),
                  title: Text(
                    '$_biometricLabel Login',
                    style: const TextStyle(
                        color: Colors.white,
                        fontSize: 14,
                        fontWeight: FontWeight.w600),
                  ),
                  subtitle: Text(
                    _biometricAvailable
                        ? 'Unlock the app without typing your password.'
                        : 'Not available - no fingerprint or face is set up on this phone.',
                    style: const TextStyle(color: Color(0xFF94A3B8), fontSize: 11.5),
                  ),
                  secondary: Icon(
                    Icons.fingerprint,
                    color: _biometricAvailable
                        ? const Color(0xFF2DD4BF)
                        : const Color(0xFF475569),
                  ),
                ),
                const Divider(color: Color(0xFF334155), height: 20),
                Row(
                  children: [
                    Icon(
                      _saveLoginEnabled ? Icons.lock_outline : Icons.lock_open_outlined,
                      size: 20,
                      color: const Color(0xFF94A3B8),
                    ),
                    const SizedBox(width: 12),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          const Text('Saved Login',
                              style: TextStyle(
                                  color: Colors.white,
                                  fontSize: 14,
                                  fontWeight: FontWeight.w600)),
                          const SizedBox(height: 2),
                          Text(
                            _saveLoginEnabled
                                ? 'Stored securely on this phone.'
                                : 'Not saved. Tick "Save login" when you sign in.',
                            style: const TextStyle(
                                color: Color(0xFF94A3B8), fontSize: 11.5),
                          ),
                        ],
                      ),
                    ),
                    if (_saveLoginEnabled)
                      TextButton(
                        onPressed: _forgetSavedLogin,
                        style: TextButton.styleFrom(
                            foregroundColor: const Color(0xFFEF4444)),
                        child: const Text('Forget', style: TextStyle(fontSize: 12)),
                      ),
                  ],
                ),
              ],
            ),
          ),
          const SizedBox(height: 24),

          // ── Device ─────────────────────────────────────────────────────
          _sectionLabel('DEVICE'),
          _card(
            child: Row(
              children: [
                const Icon(Icons.phone_android, size: 20, color: Color(0xFF94A3B8)),
                const SizedBox(width: 12),
                Text(
                  Platform.isIOS ? 'Apple iOS (iPhone)' : 'Google Android',
                  style: const TextStyle(color: Color(0xFFCBD5E1), fontSize: 13),
                ),
              ],
            ),
          ),
          const SizedBox(height: 32),

          OutlinedButton.icon(
            onPressed: _logout,
            icon: const Icon(Icons.logout_rounded, color: Color(0xFFEF4444)),
            label: const Text('Logout Session',
                style: TextStyle(
                    color: Color(0xFFEF4444), fontWeight: FontWeight.bold)),
            style: OutlinedButton.styleFrom(
              padding: const EdgeInsets.symmetric(vertical: 14),
              side: const BorderSide(color: Color(0xFFEF4444)),
              shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
            ),
          ),
          const SizedBox(height: 8),
          const Text(
            'Logging out also removes the saved login and turns off biometric unlock.',
            textAlign: TextAlign.center,
            style: TextStyle(color: Color(0xFF64748B), fontSize: 11),
          ),
        ],
      ),
    );
  }
}
