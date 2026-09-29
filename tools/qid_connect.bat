@echo off
title QID Connector - keep this window open
setlocal EnableExtensions
REM ===========================================================================
REM  QID Management System - PC Connector
REM
REM  Connects this PC outward to your online bridge so the mobile app can reach
REM  it from any network. Leave this window open while staff are scanning.
REM
REM  Started automatically by start_qid.bat, or run it on its own.
REM  Auto-start with Windows: Win+R -> shell:startup -> put a shortcut there.
REM ===========================================================================

REM This file lives in <xampp>\htdocs\QID\tools, so PHP is always three folders
REM up. No drive letter is assumed - the same file works on C:, D: or F:.
for %%I in ("%~dp0..\..\..") do set "XAMPP=%%~fI"
set "PHP_EXE=%XAMPP%\php\php.exe"

if not exist "%PHP_EXE%" set "PHP_EXE=C:\xampp\php\php.exe"
if not exist "%PHP_EXE%" set "PHP_EXE=F:\xampp\php\php.exe"

if not exist "%PHP_EXE%" (
    echo.
    echo  [X] Could not find php.exe.
    echo      Looked in: %XAMPP%\php\php.exe
    echo      Keep this file inside xampp\htdocs\QID\tools\
    echo.
    pause
    exit /b 1
)

:run
"%PHP_EXE%" "%~dp0qid_connect.php"

echo.
echo  Connector stopped. Restarting in 10 seconds... (close this window to quit)
ping -n 11 127.0.0.1 >nul
goto run
