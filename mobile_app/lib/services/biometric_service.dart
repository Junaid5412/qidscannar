import 'package:flutter/services.dart';
import 'package:local_auth/local_auth.dart';

/// Fingerprint / face unlock, wrapped so the rest of the app never has to deal
/// with the platform's failure modes.
///
/// Biometrics are a convenience over the saved login, not a second account:
/// a successful prompt unlocks credentials this device already holds. It is
/// therefore always safe to fall back to typing the password.
class BiometricService {
  static final LocalAuthentication _auth = LocalAuthentication();

  /// True when this device has hardware set up and at least one fingerprint or
  /// face enrolled. False on an emulator, or when the user has enrolled nothing.
  static Future<bool> isAvailable() async {
    try {
      if (!await _auth.isDeviceSupported()) return false;
      if (!await _auth.canCheckBiometrics) return false;
      final enrolled = await _auth.getAvailableBiometrics();
      return enrolled.isNotEmpty;
    } on PlatformException {
      return false;
    } catch (_) {
      return false;
    }
  }

  /// Names the enrolled method so the UI can say "Fingerprint" or "Face" rather
  /// than something generic.
  static Future<String> methodLabel() async {
    try {
      final available = await _auth.getAvailableBiometrics();
      if (available.contains(BiometricType.face)) return 'Face Unlock';
      if (available.contains(BiometricType.fingerprint)) return 'Fingerprint';
      if (available.contains(BiometricType.iris)) return 'Iris';
      return 'Biometric';
    } catch (_) {
      return 'Biometric';
    }
  }

  /// Shows the system prompt. Returns true only on a confirmed match.
  ///
  /// Every failure is reported as a plain message rather than thrown, because
  /// there is nothing the user can do about an error code and the fallback is
  /// always the same: log in with the password.
  static Future<({bool ok, String? error})> authenticate({
    String reason = 'Unlock QID Scanner',
  }) async {
    try {
      final ok = await _auth.authenticate(
        localizedReason: reason,
        options: const AuthenticationOptions(
          biometricOnly: true,
          // Survives the app being backgrounded by the prompt itself.
          stickyAuth: true,
          useErrorDialogs: true,
        ),
      );
      return (ok: ok, error: ok ? null : 'Not recognised.');
    } on PlatformException catch (e) {
      return (ok: false, error: _describe(e));
    } catch (e) {
      return (ok: false, error: 'Could not start the biometric prompt.');
    }
  }

  static String _describe(PlatformException e) {
    switch (e.code) {
      case 'NotAvailable':
        return 'Biometrics are not available on this device.';
      case 'NotEnrolled':
        return 'No fingerprint or face is set up. Add one in phone Settings first.';
      case 'LockedOut':
        return 'Too many attempts. Wait a moment and try again.';
      case 'PermanentlyLockedOut':
        return 'Locked out. Unlock the phone with your PIN or pattern first.';
      case 'PasscodeNotSet':
        return 'Set a screen lock on the phone before using biometrics.';
      default:
        return e.message ?? 'Biometric check failed.';
    }
  }
}
