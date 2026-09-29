@echo off
setlocal EnableExtensions
title QID System Launcher

REM ===========================================================================
REM  QID Management System - Start everything
REM ===========================================================================
REM
REM  Starts Apache, MySQL and the QID bridge connector in one go.
REM
REM  Double-click it, or put a shortcut to it in  shell:startup  to have the
REM  whole system come up with Windows.
REM
REM  No drive letters are hard-coded: this file lives in <xampp>\htdocs\QID\tools
REM  so XAMPP is always three folders up. It works the same on C:, D: or F:.
REM ===========================================================================

for %%I in ("%~dp0..") do set "QID_DIR=%%~fI"
for %%I in ("%~dp0..\..\..") do set "XAMPP=%%~fI"

echo.
echo   QID Management System
echo   =====================
echo   XAMPP : %XAMPP%
echo   QID   : %QID_DIR%
echo.

if not exist "%XAMPP%\apache\bin\httpd.exe" (
    echo   [X] Apache not found at %XAMPP%\apache\bin\httpd.exe
    echo       This file must stay inside xampp\htdocs\QID\tools\
    echo.
    pause
    exit /b 1
)

REM --------------------------------------------------------------------------
REM  Apache
REM --------------------------------------------------------------------------
tasklist /FI "IMAGENAME eq httpd.exe" 2>NUL | find /I "httpd.exe" >NUL
if not errorlevel 1 (
    echo   [=] Apache already running
) else (
    REM A crash leaves a stale pid file behind that stops Apache starting again.
    if exist "%XAMPP%\apache\logs\httpd.pid" del /f /q "%XAMPP%\apache\logs\httpd.pid"
    echo   [^>] Starting Apache...
    start "XAMPP Apache" /min /D "%XAMPP%" "%XAMPP%\apache\bin\httpd.exe"
)

REM --------------------------------------------------------------------------
REM  MySQL
REM --------------------------------------------------------------------------
tasklist /FI "IMAGENAME eq mysqld.exe" 2>NUL | find /I "mysqld.exe" >NUL
if not errorlevel 1 (
    echo   [=] MySQL already running
) else (
    if exist "%XAMPP%\mysql\data\mysql.pid" del /f /q "%XAMPP%\mysql\data\mysql.pid"
    echo   [^>] Starting MySQL...
    start "XAMPP MySQL" /min /D "%XAMPP%" "%XAMPP%\mysql\bin\mysqld.exe" --defaults-file="%XAMPP%\mysql\bin\my.ini" --standalone
)

REM --------------------------------------------------------------------------
REM  Wait until they actually answer, not just until the process exists.
REM  Starting the connector too early makes it report the server as down.
REM --------------------------------------------------------------------------
echo.
echo   Waiting for services...

set /a TRIES=0
:wait_apache
curl -s -o nul -m 2 http://localhost/ >NUL 2>&1
if not errorlevel 1 goto apache_ok
set /a TRIES+=1
if %TRIES% GEQ 20 goto apache_slow
ping -n 2 127.0.0.1 >NUL
goto wait_apache
:apache_slow
echo   [!] Apache did not answer on port 80 after 20s.
echo       Another program may be using port 80 (Skype, IIS, VMware).
goto after_apache
:apache_ok
echo   [OK] Apache is serving on http://localhost/
:after_apache

set /a TRIES=0
:wait_mysql
"%XAMPP%\mysql\bin\mysqladmin.exe" -u root --connect-timeout=2 ping >NUL 2>&1
if not errorlevel 1 goto mysql_ok
set /a TRIES+=1
if %TRIES% GEQ 25 goto mysql_slow
ping -n 2 127.0.0.1 >NUL
goto wait_mysql
:mysql_slow
echo   [!] MySQL did not answer after 25s. Check %XAMPP%\mysql\data for errors.
goto after_mysql
:mysql_ok
echo   [OK] MySQL is accepting connections
:after_mysql

REM --------------------------------------------------------------------------
REM  QID bridge connector
REM --------------------------------------------------------------------------
echo.
REM Matched on the command line, not the window title: the title belongs to the
REM cmd.exe wrapper and never reaches the php.exe child, so a title check
REM silently starts a second connector every run.
powershell -NoProfile -Command "if (Get-CimInstance Win32_Process -Filter \"Name='php.exe'\" -ErrorAction SilentlyContinue | Where-Object { $_.CommandLine -like '*qid_connect*' }) { exit 0 } else { exit 1 }" >NUL 2>&1
if not errorlevel 1 (
    echo   [=] QID connector already running
) else (
    if exist "%QID_DIR%\data\bridge_link.txt" (
        echo   [^>] Starting QID connector...
        start "QID Connector" /min cmd /c ""%~dp0qid_connect.bat""
    ) else (
        echo   [!] No bridge link saved yet - connector not started.
        echo       Open QID - Settings - Online Bridge Link, paste your link, Save.
        echo       Then run this file again.
    )
)

echo.
echo   -----------------------------------------------------------
echo    Dashboard : http://localhost/QID
echo    Leave the minimised windows open while staff are scanning.
echo   -----------------------------------------------------------
echo.
echo   This window can be closed.
ping -n 9 127.0.0.1 >NUL
endlocal
