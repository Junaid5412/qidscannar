import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:intl/intl.dart';
import 'package:mobile_scanner/mobile_scanner.dart';

import '../services/api_service.dart';
import '../services/storage_service.dart';
import '../widgets/manual_entry_dialog.dart';
import '../widgets/scanner_overlay.dart';
import 'settings_screen.dart';

class ScannerScreen extends StatefulWidget {
  const ScannerScreen({Key? key}) : super(key: key);

  @override
  State<ScannerScreen> createState() => _ScannerScreenState();
}

class _ScannerScreenState extends State<ScannerScreen> {
  final MobileScannerController _controller = MobileScannerController(
    detectionSpeed: DetectionSpeed.noDuplicates,
    formats: [
      BarcodeFormat.code128,
      BarcodeFormat.code39,
      BarcodeFormat.pdf417,
      BarcodeFormat.qrCode,
      BarcodeFormat.all,
    ],
  );

  bool _isTorchOn = false;
  bool _isPaused = false;
  bool _isSyncing = false;
  String _staffName = '';
  String _staffUsername = '';

  // Bottom Result Card State
  Map<String, dynamic>? _lastResult;
  String? _lastQid;
  String? _syncTime;

  static final RegExp _qidRegex = RegExp(r'\b(\d{11})\b');

  @override
  void initState() {
    super.initState();
    _loadStaffInfo();
  }

  Future<void> _loadStaffInfo() async {
    final name = await StorageService.getFullName();
    final user = await StorageService.getUsername();
    setState(() {
      _staffName = name;
      _staffUsername = user;
    });
  }

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  void _onDetect(BarcodeCapture capture) {
    if (_isPaused || _isSyncing) return;

    for (final barcode in capture.barcodes) {
      final rawValue = barcode.rawValue;
      if (rawValue == null || rawValue.trim().isEmpty) continue;

      final qid = _extractQid(rawValue.trim());
      if (qid.isNotEmpty) {
        _handleScan(qid, 'barcode');
        break;
      }
    }
  }

  String _extractQid(String raw) {
    final match = _qidRegex.firstMatch(raw);
    if (match != null) {
      return match.group(1)!;
    }
    final digits = raw.replaceAll(RegExp(r'[^0-9]'), '');
    if (digits.length == 11) return digits;
    if (digits.length > 11) return digits.substring(0, 11);
    if (digits.length >= 8) return digits;
    return raw;
  }

  Future<void> _handleScan(String qid, String scanType) async {
    setState(() {
      _isPaused = true;
      _isSyncing = true;
    });

    // Provide haptic vibration and sound feedback
    HapticFeedback.heavyImpact();
    SystemSound.play(SystemSoundType.click);

    final res = await ApiService.pushScan(qidNumber: qid, scanType: scanType);

    setState(() {
      _isSyncing = false;
      _lastQid = qid;
      _syncTime = DateFormat('hh:mm a').format(DateTime.now());
      _lastResult = res;
    });

    if (res['success'] != true) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(res['message'] ?? 'Sync failed.'),
          backgroundColor: Colors.red,
        ),
      );
      _resumeScanning();
    }
  }

  void _resumeScanning() {
    setState(() {
      _lastResult = null;
      _lastQid = null;
      _isPaused = false;
    });
  }

  void _toggleTorch() async {
    await _controller.toggleTorch();
    setState(() {
      _isTorchOn = !_isTorchOn;
    });
  }

  void _showManualEntry() {
    showDialog(
      context: context,
      builder: (_) => ManualEntryDialog(
        onSubmit: (qid) => _handleScan(qid, 'manual'),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final scanWindow = Rect.fromCenter(
      center: Offset(MediaQuery.of(context).size.width / 2, MediaQuery.of(context).size.height * 0.38),
      width: 290,
      height: 170,
    );

    return Scaffold(
      backgroundColor: const Color(0xFF0F172A),
      body: Stack(
        children: [
          // 1. Fullscreen Camera Viewfinder
          MobileScanner(
            controller: _controller,
            onDetect: _onDetect,
            scanWindow: scanWindow,
          ),

          // 2. Custom Reticle Overlay
          ScannerOverlay(scanWindow: scanWindow),

          // 3. Top Header Bar
          SafeArea(
            child: Container(
              padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 10),
              color: const Color(0xFF0F172A).withOpacity(0.85),
              child: Row(
                children: [
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        const Text(
                          'QID Sync Scanner',
                          style: TextStyle(
                            color: Colors.white,
                            fontWeight: FontWeight.bold,
                            fontSize: 16,
                          ),
                        ),
                        Text(
                          '● Connected as $_staffName ($_staffUsername)',
                          style: const TextStyle(
                            color: Color(0xFF10B981),
                            fontSize: 11,
                          ),
                        ),
                      ],
                    ),
                  ),

                  // Torch button
                  IconButton(
                    icon: Icon(
                      _isTorchOn ? Icons.flash_on_rounded : Icons.flash_off_rounded,
                      color: _isTorchOn ? Colors.amber : Colors.white,
                    ),
                    onPressed: _toggleTorch,
                    tooltip: 'Toggle Flashlight',
                  ),

                  // Manual Entry button
                  IconButton(
                    icon: const Icon(Icons.keyboard_rounded, color: Colors.white),
                    onPressed: _showManualEntry,
                    tooltip: 'Manual QID Entry',
                  ),

                  // Settings button
                  IconButton(
                    icon: const Icon(Icons.settings_rounded, color: Colors.white),
                    onPressed: () {
                      Navigator.of(context).push(
                        MaterialPageRoute(builder: (_) => const SettingsScreen()),
                      );
                    },
                    tooltip: 'Settings',
                  ),
                ],
              ),
            ),
          ),

          // 4. Loading Spinner while syncing
          if (_isSyncing)
            const Center(
              child: CircularProgressIndicator(
                color: Color(0xFF10B981),
                strokeWidth: 3,
              ),
            ),

          // 5. Bottom Result Card
          if (_lastResult != null && _lastResult!['success'] == true)
            Align(
              alignment: Alignment.bottomCenter,
              child: _buildResultCard(),
            ),
        ],
      ),
    );
  }

  Widget _buildResultCard() {
    final recordFound = _lastResult!['record_found'] == true;
    final personName = _lastResult!['person_name'] ?? 'Unregistered QID';
    final balanceDue = _lastResult!['balance_due'] ?? '0.00 QR';
    final expiryStatus = _lastResult!['expiry_status'] ?? 'Valid';

    return Container(
      margin: const EdgeInsets.all(16),
      padding: const EdgeInsets.all(18),
      decoration: BoxDecoration(
        color: const Color(0xFF1E293B),
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: const Color(0xFF334155)),
        boxShadow: [
          BoxShadow(
            color: Colors.black.withOpacity(0.4),
            blurRadius: 16,
            offset: const Offset(0, 4),
          ),
        ],
      ),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          // Tag row
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                decoration: BoxDecoration(
                  color: (recordFound ? const Color(0xFF10B981) : const Color(0xFFF59E0B))
                      .withOpacity(0.15),
                  borderRadius: BorderRadius.circular(20),
                  border: Border.all(
                    color: recordFound ? const Color(0xFF10B981) : const Color(0xFFF59E0B),
                  ),
                ),
                child: Text(
                  recordFound ? '✓ Synced with Desktop' : '✓ Opened Add Form on Desktop',
                  style: TextStyle(
                    color: recordFound ? const Color(0xFF10B981) : const Color(0xFFF59E0B),
                    fontSize: 11,
                    fontWeight: FontWeight.bold,
                  ),
                ),
              ),
              Text(
                _syncTime ?? 'Just now',
                style: const TextStyle(color: Color(0xFF64748B), fontSize: 11),
              ),
            ],
          ),
          const SizedBox(height: 12),

          // Person Name & QID
          Text(
            personName,
            style: const TextStyle(
              color: Colors.white,
              fontSize: 18,
              fontWeight: FontWeight.bold,
            ),
          ),
          const SizedBox(height: 2),
          Text(
            'QID: $_lastQid',
            style: const TextStyle(
              color: Color(0xFF94A3B8),
              fontFamily: 'monospace',
              fontSize: 13,
            ),
          ),
          const SizedBox(height: 14),

          // Financial & Status Tiles
          if (recordFound)
            Container(
              padding: const EdgeInsets.all(12),
              decoration: BoxDecoration(
                color: const Color(0xFF0F172A),
                borderRadius: BorderRadius.circular(10),
              ),
              child: Row(
                children: [
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        const Text(
                          'BALANCE DUE',
                          style: TextStyle(
                            color: Color(0xFF64748B),
                            fontSize: 10,
                            fontWeight: FontWeight.bold,
                          ),
                        ),
                        const SizedBox(height: 2),
                        Text(
                          balanceDue,
                          style: const TextStyle(
                            color: Color(0xFFEF4444),
                            fontSize: 15,
                            fontWeight: FontWeight.bold,
                          ),
                        ),
                      ],
                    ),
                  ),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        const Text(
                          'CARD STATUS',
                          style: TextStyle(
                            color: Color(0xFF64748B),
                            fontSize: 10,
                            fontWeight: FontWeight.bold,
                          ),
                        ),
                        const SizedBox(height: 2),
                        Text(
                          expiryStatus,
                          style: const TextStyle(
                            color: Color(0xFF10B981),
                            fontSize: 15,
                            fontWeight: FontWeight.bold,
                          ),
                        ),
                      ],
                    ),
                  ),
                ],
              ),
            ),
          const SizedBox(height: 14),

          // Rescan Button
          SizedBox(
            width: double.infinity,
            height: 46,
            child: ElevatedButton.icon(
              onPressed: _resumeScanning,
              icon: const Icon(Icons.qr_code_scanner_rounded, size: 18),
              label: const Text('Scan Next Card', style: TextStyle(fontWeight: FontWeight.bold)),
              style: ElevatedButton.styleFrom(
                backgroundColor: const Color(0xFF8A1538),
                foregroundColor: Colors.white,
                shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
              ),
            ),
          ),
        ],
      ),
    );
  }
}
