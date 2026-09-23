@echo off
title QID Discovery Service
REM ===========================================================================
REM  QID Management System - Instant phone discovery service
REM
REM  Keep this window open while staff are scanning. It lets the mobile app find
REM  this PC in about 100 milliseconds instead of probing 254 addresses.
REM
REM  Run it normally (no administrator needed). To start it automatically with
REM  Windows, see "Auto-start" at the bottom of this file.
REM ===========================================================================

setlocal

set "PHP_EXE=%~dp0..\..\..\php\php.exe"
if not exist "%PHP_EXE%" set "PHP_EXE=F:\xampp\php\php.exe"
if not exist "%PHP_EXE%" set "PHP_EXE=C:\xampp\php\php.exe"

if not exist "%PHP_EXE%" (
    echo.
    echo  [X] Could not find php.exe.
    echo      Edit this file and set PHP_EXE to your XAMPP php.exe path.
    echo.
    pause
    exit /b 1
)

echo.
echo  Starting QID discovery service...
echo  Using PHP: %PHP_EXE%
echo.
echo  Leave this window OPEN. Closing it stops instant discovery
echo  (the app then falls back to scanning, which still works but is slower).
echo.

"%PHP_EXE%" "%~dp0discovery_daemon.php"

echo.
echo  The discovery service stopped.
pause

REM ===========================================================================
REM  Auto-start with Windows
REM  -----------------------
REM  Press Win+R, type:   shell:startup
REM  Then put a shortcut to THIS file into the folder that opens.
REM
REM  Firewall: tools\allow_wifi_access.bat also opens UDP 45454 for this.
REM ===========================================================================
