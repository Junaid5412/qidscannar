import 'dart:async';
import 'dart:io';
import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:intl/intl.dart';
import 'package:camera/camera.dart';
import 'package:google_mlkit_text_recognition/google_mlkit_text_recognition.dart';
import 'package:google_mlkit_barcode_scanning/google_mlkit_barcode_scanning.dart';

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
  CameraController? _cameraController;
  final TextRecognizer _textRecognizer = TextRecognizer(script: TextRecognitionScript.latin);
  final BarcodeScanner _barcodeScanner = BarcodeScanner(formats: [BarcodeFormat.all]);
  
  bool _isTorchOn = false;
  bool _isPaused = false;
  bool _isSyncing = false;
  bool _isBusy = false;
  
  String _staffName = '';
  String _staffUsername = '';

  Map<String, dynamic>? _lastResult;
  String? _lastQid;
  String? _syncTime;

  static final RegExp _qidRegex = RegExp(r'\b(\d{11})\b');

  @override
  void initState() {
    super.initState();
    _loadStaffInfo();
    _initializeCamera();
  }

  Future<void> _loadStaffInfo() async {
    final name = await StorageService.getFullName();
    final user = await StorageService.getUsername();
    if (mounted) {
      setState(() {
        _staffName = name;
        _staffUsername = user;
      });
    }
  }

  Future<void> _initializeCamera() async {
    try {
      final cameras = await availableCameras();
      final backCamera = cameras.firstWhere(
        (c) => c.lensDirection == CameraLensDirection.back,
        orElse: () => cameras.first,
      );

      _cameraController = CameraController(
        backCamera,
        ResolutionPreset.high,
        enableAudio: false,
        imageFormatGroup: Platform.isAndroid ? ImageFormatGroup.nv21 : ImageFormatGroup.bgra8888,
      );

      await _cameraController!.initialize();
      if (!mounted) return;

      setState(() {});

      _cameraController!.startImageStream(_processCameraImage);
    } catch (e) {
      if (kDebugMode) print('Error initializing camera: $e');
    }
  }

  @override
  void dispose() {
    _cameraController?.stopImageStream();
    _cameraController?.dispose();
    _textRecognizer.close();
    _barcodeScanner.close();
    super.dispose();
  }

  InputImage? _inputImageFromCameraImage(CameraImage image) {
    if (_cameraController == null) return null;

    final camera = _cameraController!.description;
    final sensorOrientation = camera.sensorOrientation;
    
    final InputImageRotation? rotation = InputImageRotationValue.fromRawValue(sensorOrientation);
    if (rotation == null) return null;

    final format = InputImageFormatValue.fromRawValue(image.format.raw);
    if (format == null ||
        (Platform.isAndroid && format != InputImageFormat.nv21) ||
        (Platform.isIOS && format != InputImageFormat.bgra8888)) {
      return null;
    }

    if (image.planes.isEmpty) return null;

    final WriteBuffer allBytes = WriteBuffer();
    for (final Plane plane in image.planes) {
      allBytes.putUint8List(plane.bytes);
    }
    final bytes = allBytes.done().buffer.asUint8List();

    final Size imageSize = Size(image.width.toDouble(), image.height.toDouble());
    
    final inputImageData = InputImageMetadata(
      size: imageSize,
      rotation: rotation,
      format: format,
      bytesPerRow: image.planes[0].bytesPerRow,
    );

    return InputImage.fromBytes(bytes: bytes, metadata: inputImageData);
  }

  Future<void> _processCameraImage(CameraImage image) async {
    if (_isBusy || _isPaused || _isSyncing) return;
    _isBusy = true;

    try {
      final inputImage = _inputImageFromCameraImage(image);
      if (inputImage == null) {
        _isBusy = false;
        return;
      }

      String extractedQid = '';
      String extractedName = '';
      String extractedNationality = '';
      String extractedJob = '';
      String extractedExpiry = '';
      String scanType = '';

      // 1. Barcode Scanning (Fastest)
      final barcodes = await _barcodeScanner.processImage(inputImage);
      for (final barcode in barcodes) {
        final raw = barcode.rawValue;
        if (raw != null) {
          final digits = raw.replaceAll(RegExp(r'[^0-9]'), '');
          if (digits.length == 11) {
            extractedQid = digits;
            scanType = 'barcode';
            break;
          }
        }
      }

      // 2. OCR Scanning (if barcode didn't find anything)
      if (extractedQid.isEmpty) {
        final recognizedText = await _textRecognizer.processImage(inputImage);
        final lines = recognizedText.blocks.expand((b) => b.lines).map((l) => l.text).toList();
        final commonNationalities = ['INDIA', 'PAKISTAN', 'BANGLADESH', 'NEPAL', 'PHILIPPINES', 'SRI LANKA', 'EGYPT', 'SUDAN', 'SYRIA', 'JORDAN', 'LEBANON', 'KENYA', 'UGANDA', 'MOROCCO', 'TUNISIA', 'ALGERIA', 'YEMEN', 'INDONESIA', 'MALAYSIA', 'TURKEY', 'NIGERIA', 'GHANA'];
        
        for (int i = 0; i < lines.length; i++) {
          final line = lines[i].trim();
          final lineUpper = line.toUpperCase();
          
          if (extractedQid.isEmpty) {
             final qidMatch = _qidRegex.firstMatch(line);
             if (qidMatch != null) {
               extractedQid = qidMatch.group(1)!;
               scanType = 'ocr';
             } else {
               final noSpaceLine = line.replaceAll(' ', '');
               final serialMatch = RegExp(r'[A-Z0-9]+(\d{11})$').firstMatch(noSpaceLine);
               if (serialMatch != null) {
                 extractedQid = serialMatch.group(1)!;
                 scanType = 'ocr';
               }
             }
          }

          if (extractedExpiry.isEmpty) {
              final dateMatches = RegExp(r'\b(\d{2}[-/]\d{2}[-/]\d{4}|\d{4}[-/]\d{2}[-/]\d{2})\b').allMatches(line);
              for (final m in dateMatches) {
                 final d = m.group(1)!;
                 if (d.contains('202') || d.contains('203')) {
                    if (RegExp(r'^\d{2}[-/]\d{2}[-/]\d{4}$').hasMatch(d)) {
                       final parts = d.split(RegExp(r'[-/]'));
                       extractedExpiry = '${parts[2]}-${parts[1]}-${parts[0]}';
                    } else if (RegExp(r'^\d{4}[-/]\d{2}[-/]\d{2}$').hasMatch(d)) {
                       extractedExpiry = d.replaceAll('/', '-');
                    }
                 }
              }
          }

          if (extractedNationality.isEmpty) {
             for (final nat in commonNationalities) {
                if (lineUpper.contains(nat)) {
                   extractedNationality = nat;
                   break;
                }
             }
          }

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
      }

      // 3. Process matched QID
      if (extractedQid.isNotEmpty && extractedQid.length == 11) {
        await _handleScan(extractedQid, scanType, {
          'name': extractedName,
          'nationality': extractedNationality,
          'job': extractedJob,
          'expiry': extractedExpiry,
        });
      }

    } catch (e) {
      if (kDebugMode) print('Frame processing error: $e');
    }

    if (mounted) {
      _isBusy = false;
    }
  }

  Future<void> _handleScan(String qid, String scanType, [Map<String, String> cardData = const {}]) async {
    setState(() {
      _isPaused = true;
      _isSyncing = true;
    });

    HapticFeedback.heavyImpact();
    SystemSound.play(SystemSoundType.click);

    final res = await ApiService.pushScan(qidNumber: qid, scanType: scanType, cardData: cardData);

    if (mounted) {
      setState(() {
        _isSyncing = false;
        _lastResult = res;
        _lastQid = qid;
        _syncTime = DateFormat('hh:mm a').format(DateTime.now());
      });

      if (res['success'] == true) {
        HapticFeedback.vibrate();
        SystemSound.play(SystemSoundType.click);
      } else {
        HapticFeedback.vibrate();
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(res['message'] ?? 'Sync failed'),
            backgroundColor: Colors.redAccent,
          ),
        );
      }
    }
  }

  void _resumeScanning() {
    setState(() {
      _lastResult = null;
      _isPaused = false;
      _isBusy = false;
    });
  }

  void _toggleTorch() {
    if (_cameraController == null) return;
    setState(() {
      _isTorchOn = !_isTorchOn;
      _cameraController!.setFlashMode(_isTorchOn ? FlashMode.torch : FlashMode.off);
    });
  }

  void _showManualEntry() {
    showDialog(
      context: context,
      builder: (ctx) => ManualEntryDialog(
        onSubmit: (qid) {
          Navigator.pop(ctx);
          _handleScan(qid, 'manual');
        },
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final screenW = MediaQuery.of(context).size.width;
    final scanWindow = Rect.fromCenter(
      center: Offset(screenW / 2, MediaQuery.of(context).size.height / 2 - 40),
      width: screenW * 0.85,
      height: screenW * 0.55,
    );

    return Scaffold(
      backgroundColor: Colors.black,
      body: Stack(
        children: [
          // 1. Camera Preview
          if (_cameraController != null && _cameraController!.value.isInitialized)
            Positioned.fill(
              child: CameraPreview(_cameraController!),
            )
          else
            const Center(child: CircularProgressIndicator(color: Color(0xFF10B981))),

          // 2. Custom Overlay
          ScannerOverlay(scanWindow: scanWindow),

          // 3. Top Control Bar
          Positioned(
            top: MediaQuery.of(context).padding.top + 10,
            left: 16,
            right: 16,
            child: Container(
              padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 10),
              decoration: BoxDecoration(
                color: Colors.black.withOpacity(0.65),
                borderRadius: BorderRadius.circular(30),
              ),
              child: Row(
                children: [
                  CircleAvatar(
                    backgroundColor: const Color(0xFF10B981),
                    radius: 16,
                    child: Text(
                      _staffName.isNotEmpty ? _staffName[0].toUpperCase() : 'U',
                      style: const TextStyle(
                        color: Colors.white,
                        fontWeight: FontWeight.bold,
                      ),
                    ),
                  ),
                  const SizedBox(width: 10),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          _staffName,
                          style: const TextStyle(
                            color: Colors.white,
                            fontWeight: FontWeight.bold,
                            fontSize: 14,
                          ),
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                        ),
                        const Text(
                          'Ready to Scan',
                          style: TextStyle(
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
