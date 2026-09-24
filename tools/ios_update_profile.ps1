# ===========================================================================
#  QID Scanner - Update the iOS provisioning profile after adding a device
# ===========================================================================
#
#  Run this after downloading a new .mobileprovision from Apple's portal.
#  It checks the profile is actually usable before you spend a build on it,
#  then writes the base64 for the IOS_PROVISION_BASE64 GitHub secret.
#
#  The certificate check matters: a profile built against the wrong certificate
#  signs without complaint and only fails on the phone, with an error that says
#  nothing useful. That happened twice during setup.
# ===========================================================================

# Deliberately NOT 'Stop': openssl writes progress such as "Verification
# successful" to stderr, which PowerShell would otherwise treat as fatal.
# Every failure below is checked explicitly instead.
$ErrorActionPreference = 'Continue'

$dir = Join-Path $env:USERPROFILE 'Desktop\qid_signing'
$cer = Join-Path $dir 'ios_distribution.cer'
$out = Join-Path $dir 'SECRET_3_profile_base64.txt'

$ssl = 'F:\xampp\apache\bin\openssl.exe'
if (-not (Test-Path $ssl)) { $ssl = 'C:\xampp\apache\bin\openssl.exe' }
$env:OPENSSL_CONF = Split-Path $ssl | Join-Path -ChildPath '..\conf\openssl.cnf'

function Fail($msg) {
    Write-Host ''
    Write-Host "  [X] $msg" -ForegroundColor Red
    Write-Host ''
    Read-Host 'Press Enter to close'
    exit 1
}

Write-Host ''
Write-Host '  QID Scanner - iOS profile update' -ForegroundColor Cyan
Write-Host '  --------------------------------'
Write-Host ''

if (-not (Test-Path $ssl)) { Fail "OpenSSL not found. Expected it at $ssl" }
if (-not (Test-Path $cer)) { Fail "Certificate missing: $cer" }

$prof = Get-ChildItem $dir -Filter *.mobileprovision -ErrorAction SilentlyContinue |
        Sort-Object LastWriteTime -Descending | Select-Object -First 1
if (-not $prof) { Fail "No .mobileprovision found in $dir`n      Download the updated profile from developer.apple.com and put it there." }

Write-Host "  Profile : $($prof.Name)"
Write-Host "  Updated : $($prof.LastWriteTime)"
Write-Host ''

# Decode the CMS-signed profile into its plist.
$plist = Join-Path $dir '_check.plist'
& $ssl smime -inform DER -verify -noverify -in $prof.FullName -out $plist 2>$null
if (-not (Test-Path $plist)) { Fail 'Could not read that profile - is it a real .mobileprovision?' }

[xml]$x = Get-Content $plist
$keys = @($x.plist.dict.key)
$vals = @($x.plist.dict.ChildNodes | Where-Object { $_.Name -ne 'key' })
function Val($name) { $i = [array]::IndexOf($keys, $name); if ($i -ge 0) { return $vals[$i] } return $null }

$ok = $true

# --- bundle id ---
$raw = Get-Content $plist -Raw
if ($raw -match '<key>application-identifier</key>\s*<string>([^<]+)</string>') {
    $appId = $Matches[1]
    if ($appId -like '*com.qid.scanner') {
        Write-Host "  [OK] Bundle ID      : $appId" -ForegroundColor Green
    } else {
        Write-Host "  [!!] Bundle ID      : $appId  (expected com.qid.scanner)" -ForegroundColor Red
        $ok = $false
    }
}

# --- expiry ---
$exp = (Val 'ExpirationDate').InnerText
$days = [math]::Round(([datetime]$exp - (Get-Date)).TotalDays)
if ($days -gt 0) {
    Write-Host "  [OK] Expires        : $exp  ($days days left)" -ForegroundColor Green
} else {
    Write-Host "  [!!] EXPIRED        : $exp" -ForegroundColor Red
    $ok = $false
}

# --- certificate: the check that actually matters ---
$ourMod = & $ssl x509 -inform DER -in $cer -noout -modulus 2>$null
$certNode = Val 'DeveloperCertificates'
$certs = @($certNode.data)
$match = $false
for ($n = 0; $n -lt $certs.Count; $n++) {
    $tmp = Join-Path $dir ('_c' + $n + '.cer')
    [IO.File]::WriteAllBytes($tmp, [Convert]::FromBase64String(($certs[$n] -replace '\s', '')))
    if ((& $ssl x509 -inform DER -in $tmp -noout -modulus 2>$null) -eq $ourMod) { $match = $true }
    Remove-Item $tmp -Force -ErrorAction SilentlyContinue
}
if ($match) {
    Write-Host "  [OK] Certificate    : matches your signing key" -ForegroundColor Green
} else {
    Write-Host "  [!!] Certificate    : WRONG - this profile was built against a different certificate" -ForegroundColor Red
    Write-Host "                        Re-create it on developer.apple.com and pick the" -ForegroundColor Red
    Write-Host "                        'iPhone Distribution' certificate expiring 23 Sep 2027." -ForegroundColor Red
    $ok = $false
}

# --- devices ---
$devNode = Val 'ProvisionedDevices'
if ($devNode) {
    $devs = @($devNode.string)
    Write-Host "  [OK] Devices        : $($devs.Count)" -ForegroundColor Green
    foreach ($d in $devs) { Write-Host "         $d" -ForegroundColor DarkGray }
} else {
    Write-Host "  [!!] Devices        : none - this is not an Ad Hoc profile" -ForegroundColor Red
    $ok = $false
}

Remove-Item $plist -Force -ErrorAction SilentlyContinue

Write-Host ''
if (-not $ok) { Fail 'Profile is not usable. Fix the points above before rebuilding.' }

[Convert]::ToBase64String([IO.File]::ReadAllBytes($prof.FullName)) |
    Set-Content -Encoding ascii $out

Write-Host '  All checks passed.' -ForegroundColor Green
Write-Host ''
Write-Host '  NEXT STEPS' -ForegroundColor Cyan
Write-Host '    1. The file below just opened - select all (Ctrl+A), copy (Ctrl+C)'
Write-Host "       $out"
Write-Host '    2. GitHub repo -> Settings -> Secrets and variables -> Actions'
Write-Host '    3. Click IOS_PROVISION_BASE64 -> Update secret -> paste -> Save'
Write-Host '    4. Re-run the build, download the artifact, install the new signed IPA'
Write-Host ''
Write-Host '  The phone you already installed on keeps working - no need to reinstall it.'
Write-Host ''

Start-Process notepad.exe $out
Read-Host 'Press Enter to close'
