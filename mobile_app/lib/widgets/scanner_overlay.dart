import 'package:flutter/material.dart';

class ScannerOverlay extends StatelessWidget {
  final Rect scanWindow;

  const ScannerOverlay({Key? key, required this.scanWindow}) : super(key: key);

  @override
  Widget build(BuildContext context) {
    return Stack(
      children: [
        // Cutout background
        ColorFiltered(
          colorFilter: ColorFilter.mode(
            Colors.black.withOpacity(0.55),
            BlendMode.srcOut,
          ),
          child: Stack(
            children: [
              Container(
                decoration: const BoxDecoration(
                  color: Colors.transparent,
                  backgroundBlendMode: BlendMode.dstOut,
                ),
              ),
              Align(
                alignment: Alignment(0, -0.15),
                child: Container(
                  width: scanWindow.width,
                  height: scanWindow.height,
                  decoration: BoxDecoration(
                    color: Colors.red,
                    borderRadius: BorderRadius.circular(16),
                  ),
                ),
              ),
            ],
          ),
        ),

        // Glowing Reticle Border
        Align(
          alignment: Alignment(0, -0.15),
          child: Container(
            width: scanWindow.width,
            height: scanWindow.height,
            decoration: BoxDecoration(
              border: Border.all(color: const Color(0xFF10B981), width: 2.5),
              borderRadius: BorderRadius.circular(16),
              boxShadow: [
                BoxShadow(
                  color: const Color(0xFF10B981).withOpacity(0.3),
                  blurRadius: 12,
                  spreadRadius: 2,
                ),
              ],
            ),
          ),
        ),

        // Helper label
        Align(
          alignment: Alignment(0, 0.25),
          child: Container(
            padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
            decoration: BoxDecoration(
              color: Colors.black.withOpacity(0.65),
              borderRadius: BorderRadius.circular(20),
            ),
            child: const Text(
              'Align Qatar ID barcode inside frame',
              style: TextStyle(color: Colors.white70, fontSize: 12),
            ),
          ),
        ),
      ],
    );
  }
}
