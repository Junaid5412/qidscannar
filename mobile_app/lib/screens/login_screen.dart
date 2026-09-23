import 'package:flutter/material.dart';
import '../services/api_service.dart';
import '../services/storage_service.dart';
import 'scanner_screen.dart';

class LoginScreen extends StatefulWidget {
  const LoginScreen({Key? key}) : super(key: key);

  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen> {
  final _serverController = TextEditingController();
  final _usernameController = TextEditingController();
  final _passwordController = TextEditingController();

  bool _obscurePassword = true;
  bool _isLoading = false;
  bool _isTesting = false;
  bool _isDiscovering = false;
  String? _discoveryProgress;
  String? _statusMessage;
  bool _isStatusPositive = true;

  @override
  void initState() {
    super.initState();
    _initServerUrl();
  }

  Future<void> _initServerUrl() async {
    final savedUrl = await StorageService.getServerUrl();
    if (savedUrl.isNotEmpty) {
      if (mounted) {
        setState(() {
          _serverController.text = savedUrl;
        });
      }
      // If saved URL points to localhost or is unreachable, attempt auto-discovery
      if (savedUrl.contains('localhost') || savedUrl.contains('127.0.0.1')) {
        _autoDiscoverServer(silent: true);
      }
    } else {
      // Auto-discover Wi-Fi server on first launch
      _autoDiscoverServer(silent: true);
    }
  }

  Future<void> _autoDiscoverServer({bool silent = false}) async {
    if (_isDiscovering) return;

    setState(() {
      _isDiscovering = true;
      _discoveryProgress = 'Scanning local Wi-Fi network...';
      if (!silent) {
        _statusMessage = null;
      }
    });

    String lastProgress = '';

    try {
      final detectedUrl = await ApiService.discoverLocalServer(
        onProgress: (status) {
          lastProgress = status;
          if (mounted) {
            setState(() {
              _discoveryProgress = status;
            });
          }
        },
      );

      if (!mounted) return;

      if (detectedUrl != null && detectedUrl.isNotEmpty) {
        setState(() {
          _serverController.text = detectedUrl;
          _isDiscovering = false;
          _discoveryProgress = null;
          _statusMessage = '✓ Auto-detected QID Server:\n$detectedUrl';
          _isStatusPositive = true;
        });
        await StorageService.setServerUrl(detectedUrl);
      } else {
        setState(() {
          _isDiscovering = false;
          _discoveryProgress = null;
          if (!silent) {
            _statusMessage =
                'Could not find QID server.\n'
                'Make sure phone & PC are on same Wi-Fi.\n'
                'Last scan: $lastProgress';
            _isStatusPositive = false;
          }
        });
      }
    } catch (e) {
      if (!mounted) return;
      setState(() {
        _isDiscovering = false;
        _discoveryProgress = null;
        if (!silent) {
          _statusMessage = 'Discovery error: $e\nLast: $lastProgress';
          _isStatusPositive = false;
        }
      });
    }
  }

  Future<void> _testConnection() async {
    final url = _serverController.text.trim();
    if (url.isEmpty) {
      _setStatus('Please enter or auto-detect your Server URL first.', false);
      return;
    }

    setState(() {
      _isTesting = true;
      _statusMessage = 'Pinging server...';
      _isStatusPositive = true;
    });

    final res = await ApiService.testConnection(url);

    setState(() {
      _isTesting = false;
      _statusMessage = res['message'];
      _isStatusPositive = res['success'] == true;
    });
  }

  Future<void> _login() async {
    final url = _serverController.text.trim();
    final username = _usernameController.text.trim();
    final password = _passwordController.text.trim();

    if (url.isEmpty || username.isEmpty || password.isEmpty) {
      _setStatus('Please fill in all fields.', false);
      return;
    }

    setState(() {
      _isLoading = true;
      _statusMessage = null;
    });

    final res = await ApiService.login(
      serverUrl: url,
      username: username,
      password: password,
    );

    setState(() {
      _isLoading = false;
    });

    if (res['success'] == true) {
      if (!mounted) return;
      Navigator.of(context).pushReplacement(
        MaterialPageRoute(builder: (_) => const ScannerScreen()),
      );
    } else {
      _setStatus(res['message'] ?? 'Login failed.', false);
    }
  }

  void _setStatus(String msg, bool positive) {
    setState(() {
      _statusMessage = msg;
      _isStatusPositive = positive;
    });
  }

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
                // Top Brand Icon
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

                // Form Container
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
                      // Server URL
                      const Text(
                        'SERVER URL',
                        style: TextStyle(
                          color: Color(0xFF94A3B8),
                          fontSize: 11,
                          fontWeight: FontWeight.bold,
                        ),
                      ),
                      const SizedBox(height: 6),
                      TextField(
                        controller: _serverController,
                        style: const TextStyle(color: Colors.white, fontSize: 14),
                        keyboardType: TextInputType.url,
                        decoration: InputDecoration(
                          hintText: 'https://yourdomain.com/QID',
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
                          contentPadding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
                        ),
                      ),
                      const SizedBox(height: 8),

                      // Auto-Detect & Test Connection Buttons
                      Row(
                        children: [
                          // Auto-Detect Local Server
                          Expanded(
                            flex: 3,
                            child: SizedBox(
                              height: 38,
                              child: ElevatedButton.icon(
                                onPressed: (_isDiscovering || _isLoading)
                                    ? null
                                    : () => _autoDiscoverServer(silent: false),
                                icon: _isDiscovering
                                    ? const SizedBox(
                                        width: 14,
                                        height: 14,
                                        child: CircularProgressIndicator(
                                            strokeWidth: 2, color: Colors.white),
                                      )
                                    : const Icon(Icons.wifi_find_rounded, size: 18),
                                label: Text(
                                  _isDiscovering ? 'Scanning...' : 'Auto-Detect Wi-Fi',
                                  style: const TextStyle(
                                      fontSize: 12, fontWeight: FontWeight.bold),
                                ),
                                style: ElevatedButton.styleFrom(
                                  backgroundColor: const Color(0xFF0D9488), // Emerald/Teal
                                  foregroundColor: Colors.white,
                                  elevation: 1,
                                  shape: RoundedRectangleBorder(
                                      borderRadius: BorderRadius.circular(8)),
                                ),
                              ),
                            ),
                          ),
                          const SizedBox(width: 8),
                          // Manual Ping Test
                          Expanded(
                            flex: 2,
                            child: SizedBox(
                              height: 38,
                              child: OutlinedButton.icon(
                                onPressed: (_isTesting || _isLoading) ? null : _testConnection,
                                icon: _isTesting
                                    ? const SizedBox(
                                        width: 12,
                                        height: 12,
                                        child: CircularProgressIndicator(
                                            strokeWidth: 2, color: Colors.white),
                                      )
                                    : const Icon(Icons.check_circle_outline, size: 15),
                                label: Text(
                                  _isTesting ? 'Pinging...' : 'Test',
                                  style: const TextStyle(fontSize: 12),
                                ),
                                style: OutlinedButton.styleFrom(
                                  foregroundColor: const Color(0xFFCBD5E1),
                                  side: const BorderSide(color: Color(0xFF334155)),
                                  shape: RoundedRectangleBorder(
                                      borderRadius: BorderRadius.circular(8)),
                                ),
                              ),
                            ),
                          ),
                        ],
                      ),

                      // Live Discovery Progress Text
                      if (_discoveryProgress != null) ...[
                        const SizedBox(height: 8),
                        Row(
                          children: [
                            const SizedBox(
                              width: 12,
                              height: 12,
                              child: CircularProgressIndicator(
                                strokeWidth: 1.5,
                                color: Color(0xFF2DD4BF),
                              ),
                            ),
                            const SizedBox(width: 8),
                            Expanded(
                              child: Text(
                                _discoveryProgress!,
                                style: const TextStyle(
                                  color: Color(0xFF2DD4BF),
                                  fontSize: 11,
                                  fontStyle: FontStyle.italic,
                                ),
                                maxLines: 1,
                                overflow: TextOverflow.ellipsis,
                              ),
                            ),
                          ],
                        ),
                      ],
                      const SizedBox(height: 16),

                      // Username
                      const Text(
                        'USERNAME',
                        style: TextStyle(
                          color: Color(0xFF94A3B8),
                          fontSize: 11,
                          fontWeight: FontWeight.bold,
                        ),
                      ),
                      const SizedBox(height: 6),
                      TextField(
                        controller: _usernameController,
                        style: const TextStyle(color: Colors.white, fontSize: 14),
                        decoration: InputDecoration(
                          hintText: 'Enter staff username',
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
                          contentPadding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
                        ),
                      ),
                      const SizedBox(height: 16),

                      // Password
                      const Text(
                        'PASSWORD',
                        style: TextStyle(
                          color: Color(0xFF94A3B8),
                          fontSize: 11,
                          fontWeight: FontWeight.bold,
                        ),
                      ),
                      const SizedBox(height: 6),
                      TextField(
                        controller: _passwordController,
                        obscureText: _obscurePassword,
                        style: const TextStyle(color: Colors.white, fontSize: 14),
                        decoration: InputDecoration(
                          hintText: 'Enter staff password',
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
                          suffixIcon: IconButton(
                            icon: Icon(
                              _obscurePassword ? Icons.visibility_off : Icons.visibility,
                              color: const Color(0xFF64748B),
                              size: 18,
                            ),
                            onPressed: () => setState(() => _obscurePassword = !_obscurePassword),
                          ),
                          contentPadding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
                        ),
                      ),
                      const SizedBox(height: 24),

                      // Login Button
                      SizedBox(
                        width: double.infinity,
                        height: 48,
                        child: ElevatedButton(
                          onPressed: _isLoading ? null : _login,
                          style: ElevatedButton.styleFrom(
                            backgroundColor: const Color(0xFF8A1538),
                            foregroundColor: Colors.white,
                            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
                            elevation: 2,
                          ),
                          child: _isLoading
                              ? const SizedBox(
                                  width: 20,
                                  height: 20,
                                  child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white),
                                )
                              : const Text(
                                  'Login & Connect',
                                  style: TextStyle(fontSize: 15, fontWeight: FontWeight.bold),
                                ),
                        ),
                      ),

                      // Status message
                      if (_statusMessage != null) ...[
                        const SizedBox(height: 14),
                        Container(
                          constraints: const BoxConstraints(maxHeight: 100),
                          child: SingleChildScrollView(
                            child: Text(
                              _statusMessage!,
                              textAlign: TextAlign.center,
                              style: TextStyle(
                                color: _isStatusPositive
                                    ? const Color(0xFF10B981)
                                    : const Color(0xFFEF4444),
                                fontSize: _isStatusPositive ? 12 : 11,
                                fontWeight: FontWeight.w600,
                              ),
                            ),
                          ),
                        ),
                      ],
                    ],
                  ),
                ),

                const SizedBox(height: 24),
                const Text(
                  'Fast Per-User Sync: Login with the same staff account as your desktop browser session.',
                  textAlign: TextAlign.center,
                  style: TextStyle(color: Color(0xFF64748B), fontSize: 11),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
