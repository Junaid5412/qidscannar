@echo off
title QID Bridge Agent
REM ===========================================================================
REM  QID Management System - Online Bridge Agent
REM
REM  Connects this PC OUTWARD to your hosting and waits for the mobile app's
REM  requests. Works even when the Wi-Fi blocks phone-to-PC traffic, because
REM  nothing ever connects in to this machine.
REM
REM  Setup (once):
REM    1. Upload the "bridge" folder to your hosting, e.g. /public_html/qidbridge
REM    2. On the host, copy bridge_config.sample.php to bridge_config.php
REM       and set BRIDGE_KEY to a long random string
REM    3. Here, copy bridge_config_agent.sample.php to bridge_config_agent.php
REM       and set BRIDGE_URL + the SAME BRIDGE_KEY
REM    4. Run this file and leave the window open
REM    5. In the app, set Server URL to your bridge URL
REM
REM  Auto-start with Windows: put a shortcut to this file in  shell:startup
REM ===========================================================================

setlocal

set "PHP_EXE=%~dp0..\..\..\php\php.exe"
if not exist "%PHP_EXE%" set "PHP_EXE=F:\xampp\php\php.exe"
if not exist "%PHP_EXE%" set "PHP_EXE=C:\xampp\php\php.exe"

if not exist "%PHP_EXE%" (
    echo.
    echo  [X] Could not find php.exe. Edit this file and set PHP_EXE.
    echo.
    pause
    exit /b 1
)

if not exist "%~dp0bridge_config_agent.php" (
    echo.
    echo  [X] tools\bridge_config_agent.php is missing.
    echo.
    echo      Copy bridge_config_agent.sample.php to bridge_config_agent.php
    echo      and set BRIDGE_URL and BRIDGE_KEY inside it.
    echo.
    pause
    exit /b 1
)

:run
echo.
echo  Starting QID bridge agent...
echo  Leave this window OPEN while staff are scanning.
echo.

"%PHP_EXE%" "%~dp0bridge_agent.php"

echo.
echo  Agent stopped. Restarting in 10 seconds... (close this window to quit)
timeout /t 10 /nobreak >nul
goto run
