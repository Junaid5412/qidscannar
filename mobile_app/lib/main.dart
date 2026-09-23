import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'screens/login_screen.dart';
import 'screens/scanner_screen.dart';
import 'services/biometric_service.dart';
import 'services/storage_service.dart';

void main() async {
  WidgetsFlutterBinding.ensureInitialized();

  // Dark System UI
  SystemChrome.setSystemUIOverlayStyle(
    const SystemUiOverlayStyle(
      statusBarColor: Colors.transparent,
      statusBarIconBrightness: Brightness.light,
      systemNavigationBarColor: Color(0xFF0F172A),
      systemNavigationBarIconBrightness: Brightness.light,
    ),
  );

  final loggedIn = await StorageService.isLoggedIn();
  final lockWithBiometrics = loggedIn && await StorageService.isBiometricEnabled();

  runApp(QidScannerApp(
    initialLoggedIn: loggedIn,
    lockWithBiometrics: lockWithBiometrics,
  ));
}

class QidScannerApp extends StatelessWidget {
  final bool initialLoggedIn;
  final bool lockWithBiometrics;

  const QidScannerApp({
    Key? key,
    required this.initialLoggedIn,
    this.lockWithBiometrics = false,
  }) : super(key: key);

  Widget get _home {
    if (!initialLoggedIn) return const LoginScreen();
    if (lockWithBiometrics) return const _UnlockGate();
    return const ScannerScreen();
  }

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'QID Scanner',
      debugShowCheckedModeBanner: false,
      theme: ThemeData(
        brightness: Brightness.dark,
        scaffoldBackgroundColor: const Color(0xFF0F172A),
        primaryColor: const Color(0xFF8A1538),
        colorScheme: const ColorScheme.dark(
          primary: Color(0xFF8A1538),
          secondary: Color(0xFF10B981),
          surface: Color(0xFF1E293B),
          background: Color(0xFF0F172A),
        ),
        fontFamily: 'Roboto',
      ),
      home: _home,
    );
  }
}

/// Stands in front of an existing session when biometric unlock is on.
///
/// The session is already valid, so this is a lock on the device rather than a
/// second login: passing it goes straight to the scanner, and there is always a
/// way through with the password if the sensor will not cooperate.
class _UnlockGate extends StatefulWidget {
  const _UnlockGate({Key? key}) : super(key: key);

  @override
  State<_UnlockGate> createState() => _UnlockGateState();
}

class _UnlockGateState extends State<_UnlockGate> {
  bool _checking = true;
  String? _error;
  String _label = 'Fingerprint';

  @override
  void initState() {
    super.initState();
    _unlock();
  }

  Future<void> _unlock() async {
    setState(() {
      _checking = true;
      _error = null;
    });

    final label = await BiometricService.methodLabel();
    final result = await BiometricService.authenticate(reason: 'Unlock QID Scanner');

    if (!mounted) return;

    if (result.ok) {
      Navigator.of(context).pushReplacement(
        MaterialPageRoute(builder: (_) => const ScannerScreen()),
      );
      return;
    }

    setState(() {
      _checking = false;
      _label = label;
      _error = result.error;
    });
  }

  void _usePassword() {
    Navigator.of(context).pushReplacement(
      MaterialPageRoute(builder: (_) => const LoginScreen(skipBiometricPrompt: true)),
    );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: const Color(0xFF0F172A),
      body: Center(
        child: Padding(
          padding: const EdgeInsets.all(32),
          child: Column(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              const Icon(Icons.fingerprint, size: 72, color: Color(0xFF2DD4BF)),
              const SizedBox(height: 20),
              Text(
                _checking ? 'Waiting for $_label...' : 'QID Scanner is locked',
                style: const TextStyle(
                    color: Colors.white, fontSize: 17, fontWeight: FontWeight.bold),
              ),
              if (_error != null) ...[
                const SizedBox(height: 10),
                Text(
                  _error!,
                  textAlign: TextAlign.center,
                  style: const TextStyle(color: Color(0xFFEF4444), fontSize: 12.5),
                ),
              ],
              const SizedBox(height: 28),
              if (!_checking) ...[
                SizedBox(
                  width: double.infinity,
                  height: 46,
                  child: ElevatedButton.icon(
                    onPressed: _unlock,
                    icon: const Icon(Icons.fingerprint, size: 20),
                    label: Text('Try $_label again',
                        style: const TextStyle(fontWeight: FontWeight.bold)),
                    style: ElevatedButton.styleFrom(
                      backgroundColor: const Color(0xFF0D9488),
                      foregroundColor: Colors.white,
                      shape: RoundedRectangleBorder(
                          borderRadius: BorderRadius.circular(10)),
                    ),
                  ),
                ),
                const SizedBox(height: 10),
                TextButton(
                  onPressed: _usePassword,
                  style: TextButton.styleFrom(
                      foregroundColor: const Color(0xFF94A3B8)),
                  child: const Text('Use password instead'),
                ),
              ] else
                const CircularProgressIndicator(color: Color(0xFF0D9488)),
            ],
          ),
        ),
      ),
    );
  }
}
