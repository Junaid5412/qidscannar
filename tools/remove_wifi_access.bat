@echo off
REM ===========================================================================
REM  QID Management System - Remove the Wi-Fi access firewall rule
REM
REM  Undoes tools\allow_wifi_access.bat. After running this, the mobile scanner
REM  app will no longer be able to reach this PC.
REM
REM  HOW TO RUN:  right-click this file  ->  "Run as administrator"
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
echo  Removing the XAMPP Apache firewall rules...
netsh advfirewall firewall delete rule name="XAMPP Apache (QID) HTTP"
netsh advfirewall firewall delete rule name="XAMPP Apache (QID) Discovery"
echo.
echo  [OK] Removed. The mobile app can no longer reach this PC.
echo.
pause
