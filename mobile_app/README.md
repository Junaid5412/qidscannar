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
   - **Server URL**: leave it alone. On a local XAMPP install the app finds the PC
     by itself and fills this in (see *Local Wi-Fi auto-detection* below). Only type
     an address here when connecting to a live website (e.g. `https://yourdomain.com`).
   - **Username**: Enter your staff username (e.g. `admin`).
   - **Password**: Enter your staff password.
   - Tap **Login & Connect**.

---

## 📶 Local Wi-Fi Auto-Detection (XAMPP)

A XAMPP PC gets its IP address from the router by DHCP, so it is different on every
Wi-Fi network — `192.168.0.158` at one office, `192.168.1.42` at the next. The app
never asks you to keep up with that:

| When | What the app does | Typical time |
|---|---|---|
| App launch | Re-checks the saved address is still live | < 1s |
| Rejoining a Wi-Fi it has seen before | Reconnects to the address remembered for that network | < 1s |
| A brand-new Wi-Fi | Sweeps the subnet in parallel, trying the host numbers that worked on other networks first | 1–4s |
| Wi-Fi switches while scanning | Background watchdog relocates the PC and shows "Wi-Fi changed — reconnected to …" | ~5s |
| A scan fails because the IP moved | Re-discovers and retries that scan automatically | ~2s |

Detection works by probing `<host>/api/discovery.php`, which returns a
`qid_scanner` fingerprint plus a stable `server_id`, so the app will never latch
onto a router or printer that merely has port 80 open.

If you ever need to force it: **Settings → Re-Detect Server IP**.

### One-time setup on the XAMPP PC

Windows Firewall blocks incoming connections to Apache by default, which makes the
PC invisible to the phone no matter how well discovery works. Fix it once:

> Right-click `tools\allow_wifi_access.bat` → **Run as administrator**

That adds an inbound rule for TCP 80/8080 scoped to `remoteip=localsubnet`, so only
devices on your own Wi-Fi can connect — the PC is not exposed to the internet.
`tools\remove_wifi_access.bat` undoes it.

### Connection methods, fastest first

The app tries these in order and uses the first that answers:

| # | Method | Speed | Needs |
|---|---|---|---|
| 1 | Saved address re-verified | <1s | nothing |
| 2 | Address remembered for this Wi-Fi | <1s | connected here before |
| 3 | **UDP broadcast** | **~4ms** | `tools\start_discovery_daemon.bat` running |
| 4 | Parallel subnet sweep | 1–8s | nothing |
| 5 | Slow retry of likely hosts | ~12s | nothing |

**Method 3 is the one worth setting up.** Apache speaks only TCP/HTTP and cannot
hold a UDP socket open between requests, so the broadcast responder runs as its own
small PHP CLI process. The phone shouts `QID_DISCOVER` once to the subnet broadcast
address; only this PC answers, with the same JSON `api/discovery.php` returns. It
finds the PC wherever it sits in the address range, rather than hoping it falls
early in the probe order.

Start it: double-click `tools\start_discovery_daemon.bat` and leave the window open.
Auto-start it: put a shortcut to that file in `shell:startup`.

### If the LAN itself is blocked

If the phone cannot reach the PC at all, **no discovery method can help** — scanning,
broadcast, QR codes and typing the IP by hand all send packets to the same address.
The usual causes are router AP/client isolation, or the phone being on a different
SSID/subnet than the PC.

The fix that sidesteps this completely is a mesh VPN such as **Tailscale**: install it
on the PC and the phones, and the PC gets an address like `100.x.y.z` that never
changes on any network and works over mobile data too. Set the app's Server URL to
`http://100.x.y.z/QID` once and it never needs detection again.

### If the phone still cannot find the PC

1. Phone and PC must be on the **same** Wi-Fi (not guest Wi-Fi, and phone not on mobile data).
2. XAMPP **Apache** must be running.
3. Some routers have **AP/client isolation** enabled, which blocks device-to-device
   traffic entirely. Turn it off in the router settings.
4. Confirm from the phone's browser: open `http://<PC-IP>/QID` — the desktop's
   Settings page shows the current IP under *Local Wi-Fi Server IP*.
4. Point your camera at any Qatar ID card barcode:
   - Your phone will vibrate and click.
   - Your desktop screen will immediately chime and open the customer's complete financial ledger and profile!
