package com.qid.scanner

import io.flutter.embedding.android.FlutterFragmentActivity

// FlutterFragmentActivity (not FlutterActivity) is required by local_auth:
// the biometric prompt is a Fragment and cannot attach to a plain Activity.
class MainActivity: FlutterFragmentActivity() {
}
