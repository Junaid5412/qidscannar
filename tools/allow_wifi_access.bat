@echo off
REM ===========================================================================
REM  QID Management System - Allow phones on the same Wi-Fi to reach XAMPP
REM ===========================================================================
REM
REM  Windows Firewall blocks incoming connections to Apache by default, so the
REM  mobile scanner app cannot see this PC even when both are on the same Wi-Fi.
REM  This adds an inbound rule for the Apache HTTP ports.
REM
REM  The rule is deliberately scoped to remoteip=localsubnet: only devices on
REM  your own Wi-Fi can connect. It does NOT expose this PC to the internet.
REM
REM  HOW TO RUN:  right-click this file  ->  "Run as administrator"
REM  TO UNDO:     run tools\remove_wifi_access.bat
REM ===========================================================================

net session >nul 2>&1
if %errorLevel% neq 0 (
    echo.
    echo  [X] Administrator rights are required.
    echo      Right-click this file and choose "Run as administrator".
    echo.
    pause
    exit /b 1
)

echo.
echo  Adding Windows Firewall rules for XAMPP Apache...
echo.

netsh advfirewall firewall delete rule name="XAMPP Apache (QID) HTTP" >nul 2>&1

netsh advfirewall firewall add rule ^
    name="XAMPP Apache (QID) HTTP" ^
    dir=in action=allow protocol=TCP localport=80,8080 ^
    profile=any remoteip=localsubnet ^
    description="Lets the QID mobile scanner app reach this PC over the local Wi-Fi."

if %errorLevel% neq 0 (
    echo.
    echo  [X] Failed to add the firewall rule.
    echo.
    pause
    exit /b 1
)

echo.
echo  [OK] Done. Phones on this Wi-Fi can now reach the QID system.
echo.
echo  This PC's current address on this network:
echo.
for /f "tokens=2 delims=:" %%a in ('ipconfig ^| findstr /C:"IPv4 Address"') do (
    for /f "tokens=* delims= " %%b in ("%%a") do echo        http://%%b/QID
)
echo.
echo  You do NOT need to type that address into the app -
echo  open the app and it will find this PC by itself.
echo.
pause
