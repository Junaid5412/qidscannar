<?php
/**
 * QID Management System - Public privacy policy
 *
 * App Store Connect requires a publicly reachable privacy policy URL, and the
 * reviewer opens it. This page is deliberately outside the login so it can be
 * read by anyone, including Apple.
 *
 * Publish it at a stable address, e.g. https://sstqa.com/qid/privacy.php, and
 * put that URL in App Store Connect under App Privacy.
 *
 * NOTE: this is a factual description of what the software does, written as a
 * starting point. Have it reviewed against Qatari data-protection law
 * (Law No. 13 of 2016) before publishing - it handles government ID numbers.
 */

$company   = 'Smart Step';
$app_name  = 'QID Scanner';
$contact   = 'smartstepgps@gmail.com';
$updated   = 'September 2026';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Privacy Policy &mdash; <?= htmlspecialchars($app_name) ?></title>
<style>
  :root { --ink:#1e293b; --muted:#64748b; --line:#e2e8f0; --accent:#8a1538; }
  * { box-sizing: border-box; }
  body { font:16px/1.7 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif;
         color:var(--ink); background:#fff; margin:0; padding:32px 20px; }
  .wrap { max-width: 760px; margin: 0 auto; }
  h1 { font-size:28px; margin:0 0 4px; }
  .updated { color:var(--muted); font-size:14px; margin-bottom:32px; }
  h2 { font-size:19px; margin:34px 0 10px; padding-top:18px; border-top:1px solid var(--line); }
  h2:first-of-type { border-top:0; padding-top:0; }
  ul { padding-left:22px; } li { margin:6px 0; }
  .box { background:#f8fafc; border:1px solid var(--line); border-left:3px solid var(--accent);
         border-radius:6px; padding:14px 18px; margin:18px 0; }
  code { background:#f1f5f9; padding:1px 5px; border-radius:3px; font-size:14px; }
  a { color:var(--accent); }
  footer { margin-top:44px; padding-top:18px; border-top:1px solid var(--line);
           color:var(--muted); font-size:14px; }
</style>
</head>
<body>
<div class="wrap">

<h1>Privacy Policy</h1>
<div class="updated"><?= htmlspecialchars($app_name) ?> &mdash; last updated <?= htmlspecialchars($updated) ?></div>

<div class="box">
  <strong><?= htmlspecialchars($app_name) ?> is a workplace tool.</strong> It is used by staff of
  <?= htmlspecialchars($company) ?> to read a Qatar ID card and open the matching record on their
  own company system. Everything the app reads is sent only to that company's own
  server. It is never sent to the app developer, to an advertising network, or to
  any other third party.
</div>

<h2>What the app reads</h2>
<p>When a staff member scans a Qatar ID card, the app reads from the card:</p>
<ul>
  <li>The Qatar ID (QID) number</li>
  <li>The cardholder's name</li>
  <li>Nationality, occupation and card expiry date, where the card shows them</li>
</ul>
<p>The app also handles:</p>
<ul>
  <li>The staff member's username and password, used to sign in to their employer's system</li>
  <li>The device type (for example "Apple iPhone"), recorded alongside a scan so the
      employer can see which device submitted it</li>
</ul>

<h2>Camera</h2>
<p>
  The camera is used only while a scan is in progress, to read the barcode and text
  on the card. Reading happens <strong>on the phone itself</strong>. Photographs of
  cards are not saved to the device, not stored in the photo library, and not
  uploaded. Only the text read from the card is transmitted.
</p>

<h2>Face ID and Touch ID</h2>
<p>
  If the staff member turns on biometric unlock, iOS performs the check. The app is
  told only whether it succeeded. Fingerprint and face data never leave the device's
  secure hardware and are never accessible to this app or to us.
</p>

<h2>Where the data goes and who holds it</h2>
<p>
  Scanned details are sent to the employer's own server and stored in the employer's
  own database. <?= htmlspecialchars($company) ?> supplies the software; the employer
  controls the data and decides how long to keep it.
</p>
<p>
  Where the employer's server is reached through a connection relay on our hosting,
  the request passes through in transit only. It is held for the seconds the request
  takes and then deleted. No scan data is retained on the relay.
</p>

<h2>What is stored on the phone</h2>
<ul>
  <li>The server address the app connects to</li>
  <li>The signed-in session token</li>
  <li>If the staff member chooses "Save login", their username and password,
      held in the iOS Keychain &mdash; the operating system's encrypted store</li>
</ul>
<p>
  Signing out removes all of the above from the device.
</p>

<h2>What we do not do</h2>
<ul>
  <li>No advertising, and no advertising identifiers</li>
  <li>No tracking across other apps or websites</li>
  <li>No analytics on staff behaviour</li>
  <li>No selling or sharing of data with third parties</li>
  <li>No location collection</li>
</ul>

<h2>Keeping it secure</h2>
<ul>
  <li>Connections use HTTPS</li>
  <li>Signing in is required before any scan can be submitted</li>
  <li>Each staff account sees only its own scans</li>
  <li>Saved passwords use the iOS Keychain, not ordinary app storage</li>
</ul>

<h2>Children</h2>
<p>
  This is an employee tool and is not directed at children. It is not intended for
  anyone under 16.
</p>

<h2>Your rights</h2>
<p>
  If your Qatar ID was scanned and you want to know what is held, or want it
  corrected or erased, contact the organisation that scanned your card &mdash; they
  hold the records. If you are unsure who that is, write to us at the address below
  and we will point you to the right contact.
</p>

<h2>Changes</h2>
<p>
  If this policy changes, the date at the top of the page changes with it.
</p>

<h2>Contact</h2>
<p>
  <?= htmlspecialchars($company) ?><br>
  <a href="mailto:<?= htmlspecialchars($contact) ?>"><?= htmlspecialchars($contact) ?></a>
</p>

<footer>
  &copy; <?= date('Y') ?> <?= htmlspecialchars($company) ?>. This policy covers the
  <?= htmlspecialchars($app_name) ?> mobile application.
</footer>

</div>
</body>
</html>
