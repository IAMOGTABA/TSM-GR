# TSM-GR PowerShell Startup Script
# This script automatically starts XAMPP services and opens TSM-GR

Write-Host ""
Write-Host "====================================" -ForegroundColor Green
Write-Host "   TSM-GR Task Management System" -ForegroundColor Green
Write-Host "====================================" -ForegroundColor Green
Write-Host ""

# Function to find XAMPP installation
function Find-XAMPP {
    $paths = @(
        "C:\xampp",
        "C:\Program Files\XAMPP",
        "C:\Program Files (x86)\XAMPP"
    )
    
    foreach ($path in $paths) {
        if (Test-Path "$path\xampp-control.exe") {
            return $path
        }
    }
    return $null
}

# Find XAMPP installation
Write-Host "[1] Locating XAMPP installation..." -ForegroundColor Yellow
$xamppPath = Find-XAMPP

if ($xamppPath) {
    Write-Host "✓ Found XAMPP at: $xamppPath" -ForegroundColor Green
    
    # Start XAMPP Control Panel
    Write-Host ""
    Write-Host "[2] Starting XAMPP Control Panel..." -ForegroundColor Yellow
    Start-Process "$xamppPath\xampp-control.exe"
    
    # Wait for user to start services
    Write-Host ""
    Write-Host "Please start Apache and MySQL services in XAMPP Control Panel" -ForegroundColor Cyan
    Write-Host "Press Enter when services are running..." -ForegroundColor Cyan
    Read-Host
    
    # Test if services are running
    Write-Host ""
    Write-Host "[3] Testing services..." -ForegroundColor Yellow
    
    try {
        $response = Invoke-WebRequest -Uri "http://localhost" -TimeoutSec 5 -UseBasicParsing
        Write-Host "✓ Apache is running" -ForegroundColor Green
    }
    catch {
        Write-Host "⚠ Apache might not be running" -ForegroundColor Red
    }
    
    # Open TSM-GR
    Write-Host ""
    Write-Host "[4] Opening TSM-GR..." -ForegroundColor Yellow
    Start-Process "http://localhost/TSM-GR/"
    
    Write-Host ""
    Write-Host "✓ TSM-GR should now be opening in your browser!" -ForegroundColor Green
    Write-Host ""
    Write-Host "If you encounter issues:" -ForegroundColor Yellow
    Write-Host "- Ensure Apache and MySQL are running (green in XAMPP)" -ForegroundColor White
    Write-Host "- Check that TSM-GR folder is in $xamppPath\htdocs\" -ForegroundColor White
    Write-Host "- Try accessing http://localhost/TSM-GR/ manually" -ForegroundColor White
    
} else {
    Write-Host "✗ XAMPP not found!" -ForegroundColor Red
    Write-Host ""
    Write-Host "Please install XAMPP or start it manually:" -ForegroundColor Yellow
    Write-Host "1. Download from: https://www.apachefriends.org/" -ForegroundColor White
    Write-Host "2. Install with default settings" -ForegroundColor White
    Write-Host "3. Run this script again" -ForegroundColor White
}

Write-Host ""
Write-Host "Press Enter to exit..." -ForegroundColor Gray
Read-Host
