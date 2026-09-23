@echo off
title QID Connector - keep this window open
REM ===========================================================================
REM  QID Management System - PC Connector
REM
REM  Connects this PC outward to your online bridge so the mobile app can reach
REM  it from any network. Leave this window open while staff are scanning.
REM
REM  Auto-start with Windows: press Win+R, type  shell:startup  and put a
REM  shortcut to this file in the folder that opens.
REM ===========================================================================

setlocal

set "PHP_EXE=F:\xampp\php\php.exe"
if not exist "%PHP_EXE%" set "PHP_EXE=C:\xampp\php\php.exe"
if not exist "%PHP_EXE%" set "PHP_EXE=%~dp0..\..\..\php\php.exe"

if not exist "%PHP_EXE%" (
    echo.
    echo  [X] Could not find php.exe. Edit this file and set PHP_EXE.
    echo.
    pause
    exit /b 1
)

:run
"%PHP_EXE%" "%~dp0qid_connect.php"

echo.
echo  Connector stopped. Restarting in 10 seconds... (close this window to quit)
timeout /t 10 /nobreak >nul
goto run
