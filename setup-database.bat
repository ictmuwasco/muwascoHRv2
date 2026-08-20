@echo off
echo ========================================
echo MUWASCO HR System - Database Setup
echo ========================================
echo.

echo [1/4] Checking XAMPP MySQL...
tasklist | findstr /I "mysqld.exe" >nul
if %errorlevel% neq 0 (
    echo MySQL is NOT running. Starting XAMPP Control Panel...
    echo.
    echo PLEASE CLICK THE "START" BUTTON NEXT TO MySQL IN THE XAMPP CONTROL PANEL
    echo Wait until MySQL shows "Running" in green
    echo.
    start "" "c:\xampp\xampp-control.exe"
    timeout /t 5 /nobreak >nul
) else (
    echo MySQL is already running!
)

echo.
echo [2/4] Creating database...
c:\xampp\mysql\bin\mysql.exe -u root -e "CREATE DATABASE IF NOT EXISTS muwasco CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
if %errorlevel% equ 0 (
    echo Database 'muwasco' created successfully!
) else (
    echo Failed to create database. Make sure MySQL is running.
    pause
    exit /b 1
)

echo.
echo [3/4] Running Laravel migrations...
cd /d c:\xampp\htdocs\hrdemo\laravel
php artisan migrate --force
if %errorlevel% neq 0 (
    echo Migration failed!
    pause
    exit /b 1
)

echo.
echo [4/4] Seeding database...
php artisan db:seed --force
if %errorlevel% neq 0 (
    echo Seeding failed!
    pause
    exit /b 1
)

echo.
echo ========================================
echo Setup Complete!
echo ========================================
echo.
echo Login Credentials:
echo   Email: admin001@gmail.com
echo   Password: ADMIN001
echo.
echo Next steps:
echo   1. Start Laravel: php artisan serve --host=127.0.0.1 --port=8000
echo   2. Start React: cd c:\xampp\htdocs\hrdemo\frontend ^& npm run dev
echo   3. Open: http://localhost:5173
echo.
pause