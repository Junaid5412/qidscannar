import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'screens/login_screen.dart';
import 'screens/scanner_screen.dart';
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

  runApp(QidScannerApp(initialLoggedIn: loggedIn));
}

class QidScannerApp extends StatelessWidget {
  final bool initialLoggedIn;

  const QidScannerApp({Key? key, required this.initialLoggedIn}) : super(key: key);

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
      home: initialLoggedIn ? const ScannerScreen() : const LoginScreen(),
    );
  }
}
