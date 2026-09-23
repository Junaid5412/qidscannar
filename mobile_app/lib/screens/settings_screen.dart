import 'dart:io';
import 'package:flutter/material.dart';
import '../services/network_service.dart';
import '../services/storage_service.dart';
import 'login_screen.dart';

class SettingsScreen extends StatefulWidget {
  const SettingsScreen({Key? key}) : super(key: key);

  @override
  State<SettingsScreen> createState() => _SettingsScreenState();
}

class _SettingsScreenState extends State<SettingsScreen> {
  String _fullName = '';
  String _username = '';
  String _serverUrl = '';
  String _networkKey = '';
  bool _isRedetecting = false;
  String? _redetectStatus;

  @override
  void initState() {
    super.initState();
    _loadDetails();
  }

  Future<void> _loadDetails() async {
    final name = await StorageService.getFullName();
    final user = await StorageService.getUsername();
    final url = await StorageService.getServerUrl();
    final network = await NetworkService.currentNetworkKey();
    if (!mounted) return;
    setState(() {
      _fullName = name;
      _username = user;
      _serverUrl = url;
      _networkKey = network ?? '';
    });
  }

  /// Forces a fresh sweep of the current Wi-Fi. The manual escape hatch for when
  /// the PC moved and the background watchdog has not caught up yet.
  Future<void> _redetectServer() async {
    setState(() {
      _isRedetecting = true;
      _redetectStatus = 'Scanning Wi-Fi...';
    });

    final url = await NetworkService.ensureServerUrl(
      force: true,
      onProgress: (status) {
        if (mounted) setState(() => _redetectStatus = status);
      },
    );

    if (!mounted) return;

    setState(() {
      _isRedetecting = false;
      _redetectStatus = url != null
          ? 'Connected to $url'
          : 'No QID server found. Check that the PC is on this Wi-Fi, XAMPP is running, and Apache is allowed through Windows Firewall.';
      if (url != null) _serverUrl = url;
    });
  }

  Future<void> _logout() async {
    await StorageService.clearSession();
    if (!mounted) return;
    Navigator.of(context).pushAndRemoveUntil(
      MaterialPageRoute(builder: (_) => const LoginScreen()),
      (route) => false,
    );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: const Color(0xFF0F172A),
      appBar: AppBar(
        title: const Text('Scanner Settings', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 18)),
        backgroundColor: const Color(0xFF1E293B),
        elevation: 0,
        foregroundColor: Colors.white,
      ),
      body: ListView(
        padding: const EdgeInsets.all(20),
        children: [
          // Active User
          const Text(
            'LOGGED-IN STAFF',
            style: TextStyle(color: Color(0xFF94A3B8), fontSize: 11, fontWeight: FontWeight.bold),
          ),
          const SizedBox(height: 8),
          Container(
            padding: const EdgeInsets.all(16),
            decoration: BoxDecoration(
              color: const Color(0xFF1E293B),
              borderRadius: BorderRadius.circular(12),
              border: Border.all(color: const Color(0xFF334155)),
            ),
            child: Row(
              children: [
                CircleAvatar(
                  backgroundColor: const Color(0xFF8A1538).withOpacity(0.2),
                  child: const Icon(Icons.person, color: Color(0xFF8A1538)),
                ),
                const SizedBox(width: 14),
                Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      _fullName.isNotEmpty ? _fullName : 'Staff Member',
                      style: const TextStyle(color: Colors.white, fontWeight: FontWeight.bold, fontSize: 15),
                    ),
                    const SizedBox(height: 2),
                    Text(
                      'Username: $_username',
                      style: const TextStyle(color: Color(0xFF10B981), fontSize: 12),
                    ),
                  ],
                ),
              ],
            ),
          ),
          const SizedBox(height: 24),

          // Server Endpoint
          const Text(
            'SERVER SYNCHRONIZATION',
            style: TextStyle(color: Color(0xFF94A3B8), fontSize: 11, fontWeight: FontWeight.bold),
          ),
          const SizedBox(height: 8),
          Container(
            padding: const EdgeInsets.all(16),
            decoration: BoxDecoration(
              color: const Color(0xFF1E293B),
              borderRadius: BorderRadius.circular(12),
              border: Border.all(color: const Color(0xFF334155)),
            ),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                const Text('Connected Endpoint:', style: TextStyle(color: Color(0xFF64748B), fontSize: 11)),
                const SizedBox(height: 2),
                Text(
                  _serverUrl,
                  style: const TextStyle(
                    color: Color(0xFFCBD5E1),
                    fontFamily: 'monospace',
                    fontSize: 13,
                  ),
                ),
                const SizedBox(height: 12),
                const Text('Current Wi-Fi Network:', style: TextStyle(color: Color(0xFF64748B), fontSize: 11)),
                const SizedBox(height: 2),
                Text(
                  _networkKey.isNotEmpty ? '$_networkKey*' : 'Not connected to Wi-Fi',
                  style: const TextStyle(
                    color: Color(0xFFCBD5E1),
                    fontFamily: 'monospace',
                    fontSize: 13,
                  ),
                ),
                const SizedBox(height: 12),
                const Text('Device Platform:', style: TextStyle(color: Color(0xFF64748B), fontSize: 11)),
                const SizedBox(height: 2),
                Text(
                  Platform.isIOS ? 'Apple iOS (iPhone)' : 'Google Android',
                  style: const TextStyle(color: Color(0xFFCBD5E1), fontSize: 13),
                ),
                const SizedBox(height: 16),

                SizedBox(
                  width: double.infinity,
                  child: ElevatedButton.icon(
                    onPressed: _isRedetecting ? null : _redetectServer,
                    icon: _isRedetecting
                        ? const SizedBox(
                            width: 14,
                            height: 14,
                            child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white),
                          )
                        : const Icon(Icons.wifi_find_rounded, size: 18),
                    label: Text(
                      _isRedetecting ? 'Scanning...' : 'Re-Detect Server IP',
                      style: const TextStyle(fontSize: 13, fontWeight: FontWeight.bold),
                    ),
                    style: ElevatedButton.styleFrom(
                      backgroundColor: const Color(0xFF0D9488),
                      foregroundColor: Colors.white,
                      padding: const EdgeInsets.symmetric(vertical: 12),
                      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
                    ),
                  ),
                ),

                if (_redetectStatus != null) ...[
                  const SizedBox(height: 8),
                  Text(
                    _redetectStatus!,
                    style: const TextStyle(color: Color(0xFF2DD4BF), fontSize: 11),
                  ),
                ],

                const SizedBox(height: 4),
                const Text(
                  'The server IP changes on every Wi-Fi network. The app re-detects it automatically; use this if it falls behind.',
                  style: TextStyle(color: Color(0xFF64748B), fontSize: 10.5),
                ),
              ],
            ),
          ),
          const SizedBox(height: 32),

          // Logout Button
          OutlinedButton.icon(
            onPressed: _logout,
            icon: const Icon(Icons.logout_rounded, color: Color(0xFFEF4444)),
            label: const Text('Logout Session', style: TextStyle(color: Color(0xFFEF4444), fontWeight: FontWeight.bold)),
            style: OutlinedButton.styleFrom(
              padding: const EdgeInsets.symmetric(vertical: 14),
              side: const BorderSide(color: Color(0xFFEF4444)),
              shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
            ),
          ),
        ],
      ),
    );
  }
}
