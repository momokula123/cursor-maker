@echo off
title Cursor Maker - Pollinations PHP Server
cd /d "%~dp0"
echo ============================================
echo   Cursor Maker HTTP server starting...
echo   Listening: 0.0.0.0:5551
echo   Local:     http://127.0.0.1:5551/
echo   LAN:       http://192.168.10.70:5551/
echo   Close this window to stop the server
echo ============================================
echo.
php -S 0.0.0.0:5551 -t .
if errorlevel 1 (
    echo.
    echo Failed to start. Port 5551 in use or PHP not in PATH.
    echo Install PHP 8+ and add it to PATH, or stop the process using port 5551.
    echo.
    pause
)
