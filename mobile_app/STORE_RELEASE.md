# QID Scanner — Google Play & App Store release guide

App ID (both stores): **com.qid.scanner** · Name: **QID Scanner** · Version: see `pubspec.yaml` (build number = GitHub run number)
Privacy policy: **https://q.sstqa.com/privacy** · Support / contact: **smartstepgps@gmail.com**

## What is already done in the project

| Item | Status |
|---|---|
| Play upload key (`F:\QID-App-Signing\upload-keystore.jks`, valid to 2054) | created — back it up |
| Android release signing (local `key.properties` or GitHub secrets) | `android/app/build.gradle` |
| Play bundle (.aab) + APK, target Android 16 (API 36), version code grows every build | `.github/workflows/build_app.yml` |
| iOS: iPhone only, privacy manifest in the bundle, camera + Face ID texts, HTTPS only, export-compliance answer | `ios/Runner/Info.plist`, `ci/configure_ios_project.rb` |
| iOS signing with Apple Distribution certificate + App Store profile, upload to TestFlight | workflow (needs the secrets below) |
| App icon (iOS, Android adaptive) + Play 512 icon + 1024×500 feature graphic | `assets/icon/`, `store_assets/` |
| Public privacy policy incl. retention, deletion, "not a government app" | website `/privacy` |
| 29 automated tests run before every Android build | `test/` |

## 1. Accounts (one time)

- **Google Play Console** — https://play.google.com/console (USD 25 once). Register as an **Organization** (needs a D-U-N-S number): personal accounts created after Nov 2023 must run a closed test with 12 testers for 14 days before they may publish.
- **Apple Developer Program** — https://developer.apple.com/programs (USD 99/year). Enrol as an **Organization** (D-U-N-S number) so the store shows the company name.

## 2. Android signing in GitHub (one time)

GitHub → `Junaid5412/qidscannar` → Settings → Secrets and variables → Actions → **New repository secret**.
Create the four secrets exactly as written in `F:\QID-App-Signing\github-secrets.txt`:
`ANDROID_KEYSTORE_BASE64`, `ANDROID_KEYSTORE_PASSWORD`, `ANDROID_KEY_ALIAS`, `ANDROID_KEY_PASSWORD`.

## 3. iOS signing on Windows (one time)

Use Git Bash in `F:\QID-App-Signing\ios` (create the folder). Replace the e-mail.

1. Private key + certificate request:
   ```bash
   openssl genrsa -out distribution.key 2048
   openssl req -new -key distribution.key -out distribution.csr -subj "/emailAddress=smartstepgps@gmail.com/CN=Smart Step/C=QA"
   ```
2. developer.apple.com → Certificates, IDs & Profiles:
   - **Identifiers → +** App ID, explicit bundle ID `com.qid.scanner`, no extra capabilities.
   - **Certificates → +** **Apple Distribution** → upload `distribution.csr` → download `distribution.cer` into the same folder.
   - **Profiles → +** **App Store Connect** → App ID `com.qid.scanner` → that certificate → name it `QID Scanner App Store` → download.
3. Make the `.p12` (choose a password when asked; it becomes `IOS_CERT_PASSWORD`):
   ```bash
   openssl x509 -in distribution.cer -inform DER -out distribution.pem
   openssl pkcs12 -export -legacy -inkey distribution.key -in distribution.pem -out distribution.p12
   ```
4. **App Store Connect API key** (lets the build upload to TestFlight): App Store Connect → Users and Access → Integrations → App Store Connect API → **+**, access **App Manager** → download `AuthKey_XXXX.p8` (only once!) and note the **Key ID** and **Issuer ID**.
5. GitHub secrets (get the base64 text with `base64 -w0 <file>`):
   | Secret | Value |
   |---|---|
   | `IOS_CERT_P12_BASE64` | `base64 -w0 distribution.p12` |
   | `IOS_CERT_PASSWORD` | the .p12 password |
   | `IOS_PROVISION_BASE64` | `base64 -w0 "QID_Scanner_App_Store.mobileprovision"` |
   | `ASC_KEY_ID` | Key ID |
   | `ASC_ISSUER_ID` | Issuer ID |
   | `ASC_KEY_P8_BASE64` | `base64 -w0 AuthKey_XXXX.p8` |

Keep everything in `F:\QID-App-Signing` backed up and private.

## 4. Build

GitHub → Actions → **Build QID Scanner (Android & iOS)** → **Run workflow** (or merge to `main`).
- Artifact **QID-Scanner-Android** → `qid-scanner-N-play.aab` (upload to Play) and `qid-scanner-N.apk` (direct install).
- iOS: with all secrets set the build appears in App Store Connect → **TestFlight** about 10–30 min later. Artifact **QID-Scanner-iOS** holds the `.ipa`.
- For the next release raise `version:` in `pubspec.yaml` (e.g. `1.0.2+1`); the build number is set automatically.

## 5. Review account (both stores need it)

Reviewers must be able to sign in. On **https://q.sstqa.com** → Users → Add user: username `appreview`, role **Staff**, a strong password.
Add one sample client with a made-up QID, e.g. `29035600001` (name "Review Test Client", any charge). Keep the user active until the apps are approved.

Review notes (paste into both stores):
> QID Scanner is a staff tool for Smart Step's QID management system. Sign in with the demo account (server address is pre-filled: https://q.sstqa.com).
> Without a physical Qatar ID card, tap the keyboard icon at the top of the scanner and enter 29035600001 — the app shows the client's balance and expiry, and the record opens on the web dashboard (https://q.sstqa.com, same login).
> The camera is used only to read the card's barcode/text on the device. No account creation in the app: staff accounts are created by the company administrator.

## 6. Google Play Console

Create app → name **QID Scanner**, default language English, **App**, **Free**. Then *Policy and programs → App content*:

| Form | Answer |
|---|---|
| Privacy policy | https://q.sstqa.com/privacy |
| App access | All or some functionality is restricted → add the `appreview` login + the review notes above |
| Ads | No ads |
| Content rating | Category **Reference, News or Educational → Utility/Productivity**; answer **No** to every content question (→ rated Everyone / 3+) |
| Target audience | **18 and over** only; not appealing to children |
| News app | No |
| Government apps | **No** — not developed by or on behalf of a government |
| Financial features | None |
| Health | None |
| Data safety | see below |

**Data safety**
- Collects or shares user data: **Yes**. Encrypted in transit: **Yes**. Users can request deletion: **Yes** (by e-mail, see privacy policy). Shared with third parties: **No**.
- Data types — all *Collected*, *not shared*, *required*, purpose **App functionality** (and **Account management** for the user ID):
  - Personal info → **Name** (cardholder name read from the ID)
  - Personal info → **User IDs** (staff username)
  - Personal info → **Other info** (Qatar ID number, nationality, occupation, card expiry)
  - App info & performance: none · Location: none · Photos/videos: none (camera frames are processed on the device and never stored or uploaded)

**Store listing**
- App icon: `store_assets/play-icon-512.png` · Feature graphic: `store_assets/play-feature-graphic-1024x500.png`
- Phone screenshots (2–8, 1080×1920 or similar): login screen, scanner, result card, manual entry — take them on an Android phone with the review account.
- Category: **Business** · Contact e-mail: smartstepgps@gmail.com · Website: https://q.sstqa.com
- Short description (80): `Scan a Qatar ID card and open the client record on your office system instantly.`
- Full description:
  ```
  QID Scanner is the companion app for the Smart Step QID management system.

  • Scan the barcode on the back of a Qatar ID card, or read the front with the camera
  • The client's record opens instantly on your desktop dashboard
  • See balance due and card expiry right on the phone
  • Unregistered card? The desktop opens the "add client" form with the details filled in
  • Damaged card: type the 11-digit number instead
  • Optional fingerprint / Face ID unlock

  For staff of companies using the QID management system; an account from your administrator is required.

  QID Scanner is an independent business tool. It is not affiliated with, endorsed by or acting for the Ministry of Interior or the Government of Qatar, and it does not issue or verify official documents.
  ```

**Release**: Testing → **Internal testing** → Create release → accept **Play App Signing** → upload the `.aab` → add yourself as tester → install from the opt-in link and check. Then **Production** → Create release → same `.aab` (or a newer one) → Send for review (usually 1–7 days).

## 7. App Store Connect

Apps → **+ New App** → iOS, name **QID Scanner** (if taken: *QID Scanner – Smart Step*), language English, bundle ID `com.qid.scanner`, SKU `qid-scanner`, full access.

| Section | Answer |
|---|---|
| Category | Business (secondary: Productivity) |
| Privacy Policy URL | https://q.sstqa.com/privacy |
| App Privacy → Data collection | **Yes**: Contact Info → **Name**; Identifiers → **User ID**; Other Data → **Other Data Types** (Qatar ID number, nationality, occupation, expiry). For each: used for **App Functionality**, **linked** to the user, **not** used for tracking |
| Age rating | answer **None/No** everywhere → **4+** |
| Encryption | answered in the build (`ITSAppUsesNonExemptEncryption = NO`) |
| Sign in required | Yes → demo account `appreview` + review notes (section 5) |
| Content rights | No third-party content |

- Screenshots: **6.9-inch iPhone** (1320×2868 or 1290×2796), 3–10 images — take them on an iPhone Pro Max (or 6.5-inch 1284×2778). iPad screenshots are **not** needed (the app is iPhone-only).
- Promotional text / description: reuse the Play texts above; keywords: `QID,Qatar ID,scanner,barcode,ID card,residency,client,records`.
- Support URL: https://q.sstqa.com/privacy (or a contact page) · Marketing URL: optional.
- Build: pick the TestFlight build → **Add for Review** → Submit (usually 1–3 days).

Apple note: an app made for one company's own staff can be refused as "not for the general public" (guideline 3.2). If that happens, reply asking for **Unlisted App Distribution** (https://developer.apple.com/support/unlisted-app-distribution/) — the app stays on the App Store but is only reachable by its link.

## 8. Before every submission (checklist)

- [ ] `pubspec.yaml` version raised (e.g. 1.0.2) for a new store version
- [ ] Workflow run is green (tests + both builds), Android log shows "Signing with the Play upload key"
- [ ] `https://q.sstqa.com/privacy` opens; review account signs in; sample QID 29035600001 shows a result
- [ ] Screenshots match the current app
