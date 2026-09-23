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
  Map<String, String>? _lastExtractedData;
  String? _lastQid;
  String? _syncTime;

  /// Kept so the last scan can be sent again without re-reading the card.
  /// A push can reach the PC yet not reach the desktop browser, and re-scanning
  /// a card just to retry the upload is wasted work for the person holding it.
  String _lastScanType = 'barcode';
  bool _isResending = false;
  int _sendAttempts = 0;

  Map<String, int> _qidDetectionCounts = {};
  DateTime _lastDetectionReset = DateTime.now();

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

      // 2. OCR Scanning
      if (extractedQid.isEmpty) {
        final recognizedText = await _textRecognizer.processImage(inputImage);
        final lines = recognizedText.blocks.expand((b) => b.lines).map((l) => l.text).toList();
        final commonNationalities = ['INDIA', 'PAKISTAN', 'BANGLADESH', 'NEPAL', 'PHILIPPINES', 'SRI LANKA', 'EGYPT', 'SUDAN', 'SYRIA', 'JORDAN', 'LEBANON', 'KENYA', 'UGANDA', 'MOROCCO', 'TUNISIA', 'ALGERIA', 'YEMEN', 'INDONESIA', 'MALAYSIA', 'TURKEY', 'NIGERIA', 'GHANA'];
        final exclusions = ['STATE OF QATAR', 'RESIDENCY PERMIT', 'RESIDENCE PERMIT', 'ID.NO', 'D.O.B', 'EXPIRY', 'NATIONALITY', 'OCCUPATION', 'PASSPORT', 'SERIAL NO', 'EMPLOYER', 'DIRECTOR', 'GENERAL', 'SIGNATURE', 'BLOOD', 'PAYMENT', 'COLLECTION', 'REMINDER', 'MINISTRY', 'INTERIOR', 'DATE'];
        
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

          if (lineUpper.startsWith('NAME:') || lineUpper.startsWith('NAME ')) {
             extractedName = lineUpper.replaceFirst(RegExp(r'^NAME\s*[:\-]*\s*'), '').trim();
          } else if (extractedName.isEmpty && RegExp(r'^[A-Z\s\-]+$').hasMatch(lineUpper) && lineUpper.length > 8) {
             bool isExcluded = false;
             for (final ex in exclusions) {
                 if (lineUpper.contains(ex)) {
                     isExcluded = true;
                     break;
                 }
             }
             if (!isExcluded && lineUpper != extractedNationality) {
                 extractedName = line;
             }
          }
        }
      }

      // 3. Process matched QID with Debounce Buffer
      if (extractedQid.isNotEmpty && extractedQid.length == 11) {
          // Reset buffer if it's been more than 2 seconds since last detection
          if (DateTime.now().difference(_lastDetectionReset).inSeconds > 2) {
              _qidDetectionCounts.clear();
          }
          _lastDetectionReset = DateTime.now();

          _qidDetectionCounts[extractedQid] = (_qidDetectionCounts[extractedQid] ?? 0) + 1;
          
          // Require at least 2 consecutive frames of the same QID to prevent fast/incorrect scans
          if (_qidDetectionCounts[extractedQid]! >= 2) {
              _qidDetectionCounts.clear();
              await _handleScan(extractedQid, scanType, {
                'name': extractedName,
                'nationality': extractedNationality,
                'job': extractedJob,
                'expiry': extractedExpiry,
              });
          }
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
      _lastQid = qid;
      _lastScanType = scanType;
      _lastExtractedData = cardData;
      _sendAttempts = 0;
    });

    HapticFeedback.heavyImpact();
    SystemSound.play(SystemSoundType.click);

    await _sendToServer();
  }

  /// Pushes the scan currently held on screen. Used for the first automatic
  /// send and for every manual "Send Data" afterwards.
  Future<void> _sendToServer({bool manual = false}) async {
    final qid = _lastQid;
    if (qid == null || qid.isEmpty) return;

    setState(() {
      if (manual) {
        _isResending = true;
      } else {
        _isSyncing = true;
      }
    });

    final res = await ApiService.pushScan(
      qidNumber: qid,
      scanType: _lastScanType,
      cardData: _lastExtractedData ?? const {},
    );

    if (!mounted) return;

    setState(() {
      _isSyncing = false;
      _isResending = false;
      _lastResult = res;
      _sendAttempts++;
      _syncTime = DateFormat('hh:mm a').format(DateTime.now());
    });

    HapticFeedback.vibrate();

    if (res['success'] == true) {
      SystemSound.play(SystemSoundType.click);
      if (manual) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(
            content: Text('Sent to desktop again.'),
            backgroundColor: Color(0xFF0D9488),
            duration: Duration(seconds: 2),
          ),
        );
      }
    } else {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(res['message'] ?? 'Sync failed'),
          backgroundColor: Colors.redAccent,
          duration: const Duration(seconds: 4),
        ),
      );
    }
  }

  void _resumeScanning() {
    setState(() {
      _lastResult = null;
      _lastQid = null;
      _lastExtractedData = null;
      _sendAttempts = 0;
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

          // 5. Bottom Result Card - shown for failures too, otherwise a failed
          //    send would leave the scanner paused with nothing to act on.
          if (_lastResult != null)
            Align(
              alignment: Alignment.bottomCenter,
              child: _lastResult!['success'] == true
                  ? _buildResultCard()
                  : _buildFailureCard(),
            ),
        ],
      ),
    );
  }

  Widget _buildResultCard() {
    final recordFound = _lastResult!['record_found'] == true;
    
    // If the server doesn't know the name, fall back to what we extracted
    String extractedName = _lastExtractedData?['name'] ?? '';
    final personName = (recordFound && _lastResult!['person_name'] != null)
        ? _lastResult!['person_name']
        : (extractedName.isNotEmpty ? extractedName : 'Unregistered QID');
        
    final balanceDue = _lastResult!['balance_due'] ?? '0.00 QR';
    final expiryStatus = _lastResult!['expiry_status'] ?? 'Valid';

    String nat = _lastExtractedData?['nationality'] ?? '';
    String exp = _lastExtractedData?['expiry'] ?? '';

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
          
          if (nat.isNotEmpty || exp.isNotEmpty) ...[
             const SizedBox(height: 8),
             Text(
               [if (nat.isNotEmpty) 'Nationality: $nat', if (exp.isNotEmpty) 'Expiry: $exp'].join('  |  '),
               style: const TextStyle(
                 color: Color(0xFFCBD5E1),
                 fontSize: 12,
               ),
             ),
          ],
          
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
          _buildActionButtons(),
        ],
      ),
    );
  }

  /// Send Data + Scan Next Card.
  ///
  /// Send Data exists because a successful push is not proof the desktop showed
  /// it - the browser can miss the live update - and re-scanning the card just
  /// to retry the upload wastes the cardholder's time.
  Widget _buildActionButtons({bool emphasiseSend = false}) {
    final busy = _isResending || _isSyncing;

    return Row(
      children: [
        Expanded(
          child: SizedBox(
            height: 46,
            child: ElevatedButton.icon(
              onPressed: busy ? null : () => _sendToServer(manual: true),
              icon: busy
                  ? const SizedBox(
                      width: 15,
                      height: 15,
                      child: CircularProgressIndicator(
                          strokeWidth: 2, color: Colors.white),
                    )
                  : const Icon(Icons.send_rounded, size: 17),
              label: Text(
                busy ? 'Sending...' : 'Send Data',
                style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 13.5),
              ),
              style: ElevatedButton.styleFrom(
                backgroundColor: emphasiseSend
                    ? const Color(0xFFEF4444)
                    : const Color(0xFF0D9488),
                foregroundColor: Colors.white,
                disabledBackgroundColor: const Color(0xFF334155),
                shape:
                    RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
              ),
            ),
          ),
        ),
        const SizedBox(width: 10),
        Expanded(
          child: SizedBox(
            height: 46,
            child: ElevatedButton.icon(
              onPressed: busy ? null : _resumeScanning,
              icon: const Icon(Icons.qr_code_scanner_rounded, size: 17),
              label: const Text('Scan Next',
                  style: TextStyle(fontWeight: FontWeight.bold, fontSize: 13.5)),
              style: ElevatedButton.styleFrom(
                backgroundColor: emphasiseSend
                    ? const Color(0xFF475569)
                    : const Color(0xFF8A1538),
                foregroundColor: Colors.white,
                disabledBackgroundColor: const Color(0xFF334155),
                shape:
                    RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
              ),
            ),
          ),
        ),
      ],
    );
  }

  /// Shown when the push did not get through. Keeps the scanned QID and any
  /// extracted fields on screen so Send Data can retry the very same data.
  Widget _buildFailureCard() {
    final message = _lastResult?['message']?.toString() ??
        'The scan did not reach the desktop.';
    final extractedName = _lastExtractedData?['name'] ?? '';

    return Container(
      margin: const EdgeInsets.all(16),
      padding: const EdgeInsets.all(18),
      decoration: BoxDecoration(
        color: const Color(0xFF1E293B),
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: const Color(0xFFEF4444)),
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
                  color: const Color(0xFFEF4444).withOpacity(0.15),
                  borderRadius: BorderRadius.circular(20),
                  border: Border.all(color: const Color(0xFFEF4444)),
                ),
                child: const Text(
                  'Not sent to desktop',
                  style: TextStyle(
                    color: Color(0xFFEF4444),
                    fontSize: 11,
                    fontWeight: FontWeight.bold,
                  ),
                ),
              ),
              if (_sendAttempts > 1)
                Text(
                  '$_sendAttempts attempts',
                  style: const TextStyle(color: Color(0xFF64748B), fontSize: 11),
                ),
            ],
          ),
          const SizedBox(height: 12),

          if (extractedName.isNotEmpty) ...[
            Text(
              extractedName,
              style: const TextStyle(
                  color: Colors.white, fontSize: 17, fontWeight: FontWeight.bold),
            ),
            const SizedBox(height: 2),
          ],
          Text(
            'QID: ${_lastQid ?? '-'}',
            style: const TextStyle(
              color: Color(0xFF94A3B8),
              fontFamily: 'monospace',
              fontSize: 13,
            ),
          ),
          const SizedBox(height: 10),

          Container(
            width: double.infinity,
            padding: const EdgeInsets.all(10),
            decoration: BoxDecoration(
              color: const Color(0xFF0F172A),
              borderRadius: BorderRadius.circular(8),
            ),
            child: Text(
              message,
              style: const TextStyle(
                  color: Color(0xFFFCA5A5), fontSize: 12, height: 1.35),
            ),
          ),
          const SizedBox(height: 8),
          const Text(
            'The scan is still held here - tap Send Data to try again without re-scanning the card.',
            style: TextStyle(color: Color(0xFF64748B), fontSize: 11, height: 1.3),
          ),

          const SizedBox(height: 14),
          _buildActionButtons(emphasiseSend: true),
        ],
      ),
    );
  }
}
