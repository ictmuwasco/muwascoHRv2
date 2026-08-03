@echo off
echo ========================================
echo MUWASCO HR System - Starting Servers
echo ========================================
echo.

echo [1/3] Checking MySQL...
tasklist | findstr /I "mysqld.exe" >nul
if %errorlevel% neq 0 (
    echo MySQL is NOT running. Please start MySQL from XAMPP Control Panel.
    pause
    exit /b 1
) else (
    echo MySQL is running.
)

echo.
echo [2/3] Starting Laravel Backend...
cd /d c:\xampp\htdocs\hrdemo\laravel
start "Laravel Backend" cmd /k "php artisan serve --host=127.0.0.1 --port=8000"

timeout /t 3 /nobreak >nul

echo.
echo [3/3] Starting React Frontend...
cd /d c:\xampp\htdocs\hrdemo\frontend
start "React Frontend" cmd /k "npm run dev"

echo.
echo ========================================
echo Servers Starting...
echo ========================================
echo Laravel API: http://127.0.0.1:8000/api
echo React Frontend: http://localhost:5173
echo.
echo Login: admin@muwasco.co.ke / Admin@123
echo.
pause