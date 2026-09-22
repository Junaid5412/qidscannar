import 'package:flutter/material.dart';

class ManualEntryDialog extends StatefulWidget {
  final Function(String qid) onSubmit;

  const ManualEntryDialog({Key? key, required this.onSubmit}) : super(key: key);

  @override
  State<ManualEntryDialog> createState() => _ManualEntryDialogState();
}

class _ManualEntryDialogState extends State<ManualEntryDialog> {
  final _controller = TextEditingController();
  String? _error;

  void _submit() {
    final text = _controller.text.trim();
    if (text.length < 8) {
      setState(() {
        _error = 'Please enter a valid Qatar ID (11 digits)';
      });
      return;
    }
    Navigator.of(context).pop();
    widget.onSubmit(text);
  }

  @override
  Widget build(BuildContext context) {
    return Dialog(
      backgroundColor: const Color(0xFF1E293B),
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
      child: Padding(
        padding: const EdgeInsets.all(20.0),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Text(
              'Manual QID Entry',
              style: TextStyle(
                color: Colors.white,
                fontSize: 18,
                fontWeight: FontWeight.bold,
              ),
            ),
            const SizedBox(height: 6),
            const Text(
              'If the barcode is scratched or unreadable, enter the 11 digits to pop up client details on your desktop.',
              style: TextStyle(color: Color(0xFF94A3B8), fontSize: 12),
            ),
            const SizedBox(height: 16),
            TextField(
              controller: _controller,
              keyboardType: TextInputType.number,
              maxLength: 11,
              autofocus: true,
              style: const TextStyle(
                color: Colors.white,
                fontFamily: 'monospace',
                fontSize: 16,
                letterSpacing: 1.2,
              ),
              decoration: InputDecoration(
                hintText: '28535603456',
                hintStyle: const TextStyle(color: Color(0xFF64748B)),
                errorText: _error,
                filled: true,
                fillColor: const Color(0xFF0F172A),
                counterStyle: const TextStyle(color: Color(0xFF64748B)),
                border: OutlineInputBorder(
                  borderRadius: BorderRadius.circular(10),
                  borderSide: const BorderSide(color: Color(0xFF334155)),
                ),
                focusedBorder: OutlineInputBorder(
                  borderRadius: BorderRadius.circular(10),
                  borderSide: const BorderSide(color: Color(0xFF8A1538), width: 2),
                ),
              ),
            ),
            const SizedBox(height: 16),
            Row(
              mainAxisAlignment: MainAxisAlignment.end,
              children: [
                TextButton(
                  onPressed: () => Navigator.of(context).pop(),
                  child: const Text('Cancel', style: TextStyle(color: Color(0xFF94A3B8))),
                ),
                const SizedBox(width: 8),
                ElevatedButton.icon(
                  onPressed: _submit,
                  icon: const Icon(Icons.send_rounded, size: 16),
                  label: const Text('Push to Desktop'),
                  style: ElevatedButton.styleFrom(
                    backgroundColor: const Color(0xFF8A1538),
                    foregroundColor: Colors.white,
                    shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
                  ),
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }
}
