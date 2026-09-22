import 'dart:io';
import 'package:flutter/material.dart';
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

  @override
  void initState() {
    super.initState();
    _loadDetails();
  }

  Future<void> _loadDetails() async {
    final name = await StorageService.getFullName();
    final user = await StorageService.getUsername();
    final url = await StorageService.getServerUrl();
    setState(() {
      _fullName = name;
      _username = user;
      _serverUrl = url;
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
                const Text('Device Platform:', style: TextStyle(color: Color(0xFF64748B), fontSize: 11)),
                const SizedBox(height: 2),
                Text(
                  Platform.isIOS ? 'Apple iOS (iPhone)' : 'Google Android',
                  style: const TextStyle(color: Color(0xFFCBD5E1), fontSize: 13),
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
