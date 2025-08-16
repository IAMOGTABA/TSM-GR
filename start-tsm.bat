@echo off
title TSM-GR Startup Script
color 0A

echo.
echo ====================================
echo   TSM-GR Task Management System
echo ====================================
echo.

echo [1] Starting XAMPP Control Panel...
echo.

REM Try common XAMPP installation paths
if exist "C:\xampp\xampp-control.exe" (
    echo Found XAMPP at C:\xampp\
    start "" "C:\xampp\xampp-control.exe"
) else if exist "C:\Program Files\XAMPP\xampp-control.exe" (
    echo Found XAMPP at C:\Program Files\XAMPP\
    start "" "C:\Program Files\XAMPP\xampp-control.exe"
) else if exist "C:\Program Files (x86)\XAMPP\xampp-control.exe" (
    echo Found XAMPP at C:\Program Files (x86)\XAMPP\
    start "" "C:\Program Files (x86)\XAMPP\xampp-control.exe"
) else (
    echo XAMPP not found in common locations!
    echo Please start XAMPP Control Panel manually.
    echo.
    pause
    exit
)

echo [2] Waiting for services to start...
echo.
echo Please start Apache and MySQL services in XAMPP Control Panel
echo Then press any key to continue...
pause > nul

echo.
echo [3] Opening TSM-GR in your default browser...
echo.

REM Open the application in default browser
start "" "http://localhost/TSM-GR/"

echo.
echo [4] TSM-GR should now be opening in your browser!
echo.
echo If the page doesn't load:
echo - Make sure Apache and MySQL are running in XAMPP
echo - Check that the folder is in C:\xampp\htdocs\TSM-GR\
echo - Try accessing http://localhost/TSM-GR/ manually
echo.

echo Press any key to exit...
pause > nul
