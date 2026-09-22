import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:intl/intl.dart';
import 'package:mobile_scanner/mobile_scanner.dart';
import 'package:google_mlkit_text_recognition/google_mlkit_text_recognition.dart';
import 'package:image_picker/image_picker.dart';
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
      // Strictly ensure the QID is valid before triggering the API
      if (qid.isNotEmpty && qid.length == 11) {
        _handleScan(qid, 'barcode');
        break; // Stop processing other barcodes in this frame once a valid QID is found
      }
    }
  }

  String _extractQid(String raw) {
    // Try to find exactly 11 digits bounded by non-word characters
    final match = _qidRegex.firstMatch(raw);
    if (match != null) {
      return match.group(1)!;
    }
    
    // Fallback: Strip all non-numeric characters from the scanned data
    final digits = raw.replaceAll(RegExp(r'[^0-9]'), '');
    
    // STRICT VALIDATION: Qatar ID must be EXACTLY 11 digits.
    // If it's less than 11, it's a partial scan (camera hasn't focused fully).
    // If it's more than 11, it's invalid barcode data.
    if (digits.length == 11) {
      return digits;
    }
    
    // Returning empty forces the scanner to ignore this frame and keep scanning
    // until the camera properly focuses and reads the full 11 digits.
    return '';
  }

  Future<void> _scanOcr() async {
    try {
      final picker = ImagePicker();
      final XFile? image = await picker.pickImage(
        source: ImageSource.camera, 
        imageQuality: 100,
        preferredCameraDevice: CameraDevice.rear,
      );
      
      if (image == null) return;
      
      setState(() {
        _isPaused = true;
        _isSyncing = true;
      });

      final inputImage = InputImage.fromFilePath(image.path);
      final textRecognizer = TextRecognizer(script: TextRecognitionScript.latin);
      final RecognizedText recognizedText = await textRecognizer.processImage(inputImage);
      
      String extractedQid = '';
      String extractedName = '';
      String extractedNationality = '';
      String extractedJob = '';
      String extractedExpiry = '';

      // MULTI-MATCH HEURISTICS FOR QATAR ID & ALL DATA FIELDS
      final lines = recognizedText.blocks.expand((b) => b.lines).map((l) => l.text).toList();
      final commonNationalities = ['INDIA', 'PAKISTAN', 'BANGLADESH', 'NEPAL', 'PHILIPPINES', 'SRI LANKA', 'EGYPT', 'SUDAN', 'SYRIA', 'JORDAN', 'LEBANON', 'KENYA', 'UGANDA', 'MOROCCO', 'TUNISIA', 'ALGERIA', 'YEMEN', 'INDONESIA', 'MALAYSIA', 'TURKEY', 'NIGERIA', 'GHANA'];
      
      for (int i = 0; i < lines.length; i++) {
        final line = lines[i].trim();
        final lineUpper = line.toUpperCase();
        
        // 1. Check for QID or Serial Number ending in QID
        if (extractedQid.isEmpty) {
           final qidMatch = RegExp(r'(?<!\d)(\d{11})(?!\d)').firstMatch(line);
           if (qidMatch != null) {
             extractedQid = qidMatch.group(1)!;
           } else {
             final noSpaceLine = line.replaceAll(' ', '');
             final serialMatch = RegExp(r'[A-Z0-9]+(\d{11})$').firstMatch(noSpaceLine);
             if (serialMatch != null) {
               extractedQid = serialMatch.group(1)!;
             }
           }
        }

        // 2. Expiry Date (YYYY-MM-DD, DD/MM/YYYY, DD-MM-YYYY)
        if (extractedExpiry.isEmpty) {
            final dateMatches = RegExp(r'\b(\d{2}[-/]\d{2}[-/]\d{4}|\d{4}[-/]\d{2}[-/]\d{2})\b').allMatches(line);
            for (final m in dateMatches) {
               final d = m.group(1)!;
               // Expiry usually >= 2023. DOB usually < 2010.
               if (d.contains('202') || d.contains('203')) {
                  if (RegExp(r'^\d{2}[-/]\d{2}[-/]\d{4}$').hasMatch(d)) {
                     final parts = d.split(RegExp(r'[-/]'));
                     extractedExpiry = '${parts[2]}-${parts[1]}-${parts[0]}'; // Convert to YYYY-MM-DD
                  } else if (RegExp(r'^\d{4}[-/]\d{2}[-/]\d{2}$').hasMatch(d)) {
                     extractedExpiry = d.replaceAll('/', '-');
                  }
               }
            }
        }

        // 3. Nationality (match against known common countries)
        if (extractedNationality.isEmpty) {
           for (final nat in commonNationalities) {
              if (lineUpper.contains(nat)) {
                 extractedNationality = nat;
                 break;
              }
           }
        }

        // 4. Name and Job heuristics (All caps English words)
        if (RegExp(r'^[A-Z\s\-]+$').hasMatch(lineUpper) && lineUpper.length > 5) {
           if (!lineUpper.contains('STATE OF QATAR') && 
               !lineUpper.contains('ID NUMBER') && 
               !lineUpper.contains('DOB') &&
               !lineUpper.contains('DATE') &&
               !lineUpper.contains('BLOOD') &&
               !lineUpper.contains('MINISTRY')) {
               
               if (extractedName.isEmpty) {
                   extractedName = line;
               } else if (extractedJob.isEmpty && lineUpper != extractedNationality) {
                   extractedJob = line;
               }
           }
        }
      }

      await textRecognizer.close();

      if (extractedQid.isNotEmpty) {
        await _handleScan(extractedQid, 'ocr', {
          'name': extractedName,
          'nationality': extractedNationality,
          'job': extractedJob,
          'expiry': extractedExpiry,
        });
      } else {
        setState(() => _isSyncing = false);
        if (!mounted) return;
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(
            content: Text('Could not detect a valid 11-digit QID from the photo. Please try again.'),
            backgroundColor: Colors.orange,
          )
        );
        _resumeScanning();
      }
    } catch (e) {
      setState(() => _isSyncing = false);
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text('OCR Error: $e')));
      _resumeScanning();
    }
  }

  Future<void> _handleScan(String qid, String scanType, [Map<String, String> cardData = const {}]) async {
    setState(() {
      _isPaused = true;
      _isSyncing = true;
    });

    // Provide haptic vibration and sound feedback
    HapticFeedback.heavyImpact();
    SystemSound.play(SystemSoundType.click);

    final res = await ApiService.pushScan(qidNumber: qid, scanType: scanType, cardData: cardData);

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

                  // OCR Scan button
                  IconButton(
                    icon: const Icon(Icons.document_scanner_rounded, color: Colors.white),
                    onPressed: _scanOcr,
                    tooltip: 'Scan Card Text (OCR)',
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
