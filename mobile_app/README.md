# QID Mobile Scanner (Flutter Cross-Platform: Android & iOS)

Real-time, cross-platform mobile scanner application built with **Flutter**.
Enables staff to scan Qatar ID card barcodes using any **Android phone** or **Apple iPhone**, instantaneously displaying the customer's full profile and financial statement on their desktop dashboard screen in under 100 milliseconds.

---

## ⚡ Key Highlights
- **Single Cross-Platform Codebase**: One unified Flutter application running seamlessly on both **Android (APK)** and **iOS (Apple iPhone)**.
- **Sub-Second Real-Time Push**: Directly feeds into the desktop browser in under 100 milliseconds using Server-Sent Events (SSE).
- **Strict Per-User Privacy**: Scans made by your phone appear **only** on your desktop screen. Different staff members are completely isolated.
- **Universal Barcode Detection**: Powered by `mobile_scanner` (Google ML Kit on Android & Apple Vision on iOS). Instantly scans **Code 128, Code 39, PDF417, and QR codes** on Qatar ID cards.
- **Auditory & Haptic Feedback**: Haptic pulse and sound effects triggered on both phone and desktop upon successful scan.
- **Manual Entry Fallback**: If a card's barcode is damaged, staff can tap the keyboard icon to type the 11 digits manually and push it to desktop instantly.
- **Unregistered Card Workflow**: If an unregistered card is scanned, the desktop automatically opens the "Add Record" page with the 11-digit QID pre-filled.

---

## 🛠️ Automated Cloud Builds via GitHub Actions

You **do not** need Android Studio, Xcode, Flutter, or a Mac installed on your computer.

### Step 1: Push Code to GitHub
From your project directory (`f:\xampp\htdocs\QID`):
```bash
git add .
git commit -m "Add QID Management System with Flutter iOS & Android Scanner"
```

If you haven't linked your repository yet:
```bash
git remote add origin https://github.com/YOUR_USERNAME/YOUR_REPOSITORY.git
git branch -M main
git push -u origin main
```

### Step 2: Download Your Built Apps
1. Open your repository on GitHub and click on the **Actions** tab.
2. The workflow **Build QID Scanner (Android APK & iOS)** will trigger automatically.
3. Once completed:
   - **For Android Users**: Download the **`QID-Scanner-Android-APK`** artifact to get `qid-scanner-debug.apk` and `qid-scanner-release.apk`. Transfer to your phone and install!
   - **For iPhone / iOS Users**: Download the **`QID-Scanner-iOS-App`** artifact containing `Runner.app`.

---

## 📱 How to Use

1. Open the QID Management system in your desktop browser (live URL or local `http://localhost/QID`) and log in.
2. In the top navigation bar, ensure the green badge is pulsing:
   > 🟢 **Mobile Scanner Active**
3. Open the **QID Scanner** app on your iPhone or Android phone:
   - **Server URL**: Enter your live website address (e.g. `https://yourdomain.com`).
   - **Username**: Enter your staff username (e.g. `admin`).
   - **Password**: Enter your staff password.
   - Tap **Test Server Connection**, then **Login & Connect**.
4. Point your camera at any Qatar ID card barcode:
   - Your phone will vibrate and click.
   - Your desktop screen will immediately chime and open the customer's complete financial ledger and profile!
