@echo off
title QID Cloudflare Tunnel
REM ===========================================================================
REM  QID Management System - Cloudflare Tunnel (ready-made bridge)
REM
REM  Same idea as the custom PHP bridge, but run by Cloudflare instead of your
REM  own hosting: this PC dials OUT to Cloudflare, and Cloudflare gives you an
REM  HTTPS address that forwards straight to http://localhost/QID.
REM
REM  Nothing connects in to this PC, so Wi-Fi client isolation, a changing DHCP
REM  address and the lack of a forwarded port all stop mattering. The database
REM  and every record stay on this machine.
REM
REM  INSTALL cloudflared FIRST (one time), in an Administrator PowerShell:
REM      winget install --id Cloudflare.cloudflared
REM  or download it from:
REM      https://developers.cloudflare.com/cloudflare-one/connections/connect-networks/downloads/
REM ===========================================================================

setlocal

where cloudflared >nul 2>&1
if %errorLevel% neq 0 (
    echo.
    echo  [X] cloudflared is not installed or not on PATH.
    echo.
    echo      Install it once, in an Administrator PowerShell:
    echo          winget install --id Cloudflare.cloudflared
    echo.
    echo      Then close and reopen this window and run it again.
    echo.
    pause
    exit /b 1
)

echo.
echo  Checking that XAMPP is serving QID locally...
curl -s -o nul -w "  http://localhost/QID/api/discovery.php -> HTTP %%{http_code}\n" http://localhost/QID/api/discovery.php
echo.
echo  Starting the tunnel. Watch for a line like:
echo.
echo      https://something-random-words.trycloudflare.com
echo.
echo  Put THAT address in the mobile app's Server URL field, with /QID removed
echo  (the tunnel already points at the QID folder).
echo.
echo  Leave this window OPEN while staff are scanning.
echo.

cloudflared tunnel --url http://localhost/QID

echo.
echo  The tunnel stopped.
pause

REM ===========================================================================
REM  NOTE ON THE ADDRESS CHANGING
REM  A free "quick tunnel" like the one above gets a NEW random address every
REM  time it starts, so the app has to be updated each restart. To get a
REM  permanent address you need a domain on a free Cloudflare account, then:
REM
REM      cloudflared tunnel login
REM      cloudflared tunnel create qid
REM      cloudflared tunnel route dns qid qid.yourdomain.com
REM      cloudflared tunnel run --url http://localhost/QID qid
REM
REM  After that, https://qid.yourdomain.com is fixed forever and you set it in
REM  the app once. Use the custom PHP bridge instead if you would rather keep
REM  everything on hosting you already own.
REM ===========================================================================
