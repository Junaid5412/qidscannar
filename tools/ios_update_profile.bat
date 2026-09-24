@echo off
REM ===========================================================================
REM  QID Scanner - Update the iOS provisioning profile after adding a device
REM
REM  Double-click this AFTER downloading the updated .mobileprovision from
REM  developer.apple.com into  Desktop\qid_signing\
REM
REM  It verifies the profile is actually usable, then writes the base64 for the
REM  IOS_PROVISION_BASE64 GitHub secret and opens it ready to copy.
REM ===========================================================================
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0ios_update_profile.ps1"
