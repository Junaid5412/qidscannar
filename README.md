# Qatar ID Management System & Real-Time Mobile Scanner

An enterprise document management, cost/receivable accounting, and real-time scanning platform designed specifically for Qatar Resident IDs (QID).

Includes a **PHP/MySQL web portal** and a **Flutter cross-platform mobile application** (iOS & Android) that scans physical Qatar ID barcodes and instantly opens the client's full personal and financial records on the staff member's desktop browser.

---

## 🚀 Key Features

### 1. Web Application (PHP & MySQL)
- **Executive Dashboard**: Expiry reminders, collection due alerts, real-time KPI metrics.
- **Client Ledger & Accounting**:
  - Charge amount (receivable), processing cost, net profit margin.
  - Installment payments with exact date and time timestamps.
  - Running balance calculation (Remaining Due).
- **Official Portrait PDF Statement**: Printable bilingual A4 portrait statements with stamp & signature fields.
- **Automated Cloud Backup**: Local MySQL dump engine + Google Drive OAuth 2.0 & Service Account synchronization.
- **Corporate Authentication**: Dedicated login portal, session management, and per-user scan isolation.

### 2. Mobile Scanner (Flutter - iOS & Android)
- **Universal Barcode Scanning**: Google ML Kit (Android) & Apple Vision (iOS) to scan **Code 128, Code 39, PDF417, and QR codes** from the back of Qatar ID cards.
- **Sub-100ms Real-Time Push**: Scans push directly to your desktop browser via private Server-Sent Events (SSE).
- **Strict Per-User Privacy**: User A's scans appear **only** on User A's desktop screen.
- **Damaged Card Fallback**: On-screen manual 11-digit keypad to push unreadable cards directly to desktop.
- **Unregistered Card Workflow**: Automatically opens the "Add Record" page on desktop with the 11-digit QID pre-filled.

---

## 🛠️ Automated Cloud Builds via GitHub Actions

This repository is configured with automated CI/CD workflows under `.github/workflows/build_app.yml`.

Every time you push or trigger the workflow:
1. **Android APK**: GitHub Actions compiles `qid-scanner-release.apk` and `qid-scanner-debug.apk`.
2. **iOS Application**: GitHub Actions builds the iOS Runner app archive.
3. Download the compiled artifacts directly from the **Actions** tab on GitHub!

---

## 📦 Project Structure

```
├── api/
│   ├── app_login.php          # Mobile staff login & Bearer token generator
│   ├── scan_push.php          # Ingests scans from mobile app & enriches data
│   ├── scan_stream.php        # Real-time SSE push stream for desktop
│   └── scan_poll.php          # High-speed polling fallback
├── assets/
│   ├── css/style.css          # Qatar Maroon & Slate corporate theme
│   └── js/
│       ├── app.js             # Form calculations, AJAX backups
│       └── scanner_listener.js # Desktop SSE receiver, Web Audio chime & popup modal
├── includes/
│   ├── auth.php               # Session security & access control
│   ├── db.php                 # PDO database singleton
│   ├── functions.php          # Accounting formulas & auto-migration
│   ├── header.php             # Responsive topbar with scanner sync indicator
│   └── footer.php             # Footer & scripts
├── mobile_app/                # Flutter Cross-Platform Mobile Application
│   ├── lib/                   # Dart source code (screens, widgets, services)
│   ├── android/               # Android runner (APK)
│   └── ios/                   # iOS runner (iPhone)
├── database.sql               # Complete MySQL database schema
├── config.php                 # Database credentials & configuration
├── install.php                # 1-Click web installer
├── index.php                  # Executive dashboard
├── records.php                # Master QID directory
├── record_add.php             # Register new client
├── record_detail.php          # Profile, financial ledger & payments
├── payments.php               # Master payments ledger
├── profit_report.php          # Monthly & lifetime profit report
├── settings.php               # System settings, Google Drive, & Mobile Server URL
└── statement.php              # A4 Portrait statement generator
```

---

## 💻 Web Installation & Setup

1. Copy files to your web server (e.g. `htdocs/QID` on XAMPP or `public_html` on live hosting).
2. Create a MySQL database (e.g. `qid_management_db`).
3. Navigate to `install.php` in your browser to run the 1-click database setup.
4. Default Administrator login:
   - **Username**: `admin`
   - **Password**: `admin123`
