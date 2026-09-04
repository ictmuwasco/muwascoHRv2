@echo off
echo ========================================
echo MUWASCO HR System - Database Setup
echo ========================================
echo.

echo [1/3] Checking XAMPP MySQL...
tasklist | findstr /I "mysqld.exe" >nul
if %errorlevel% neq 0 (
    echo MySQL is NOT running. Please start it from the XAMPP Control Panel first.
    pause
    exit /b 1
)
echo MySQL is running.

echo.
echo [2/3] Creating database "muwasco"...
c:\xampp\mysql\bin\mysql.exe -u root -e "CREATE DATABASE IF NOT EXISTS muwasco CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
if %errorlevel% neq 0 (
    echo Failed to create database. Check MySQL credentials in your environment config.
    pause
    exit /b 1
)

echo.
echo [3/3] Importing schema from backend\database\muwasco (1).sql ...
c:\xampp\mysql\bin\mysql.exe -u root muwasco < "c:\xampp\htdocs\hrdemo\backend\database\muwasco (1).sql"
if %errorlevel% neq 0 (
    echo Schema import failed. Verify the file exists and MySQL is running.
    pause
    exit /b 1
)

echo.
echo ========================================
echo Setup Complete!
echo ========================================
echo.
echo No default login accounts are provisioned by this script.
echo User accounts are created by your HR administrator.
echo.
echo Security note: never commit credentials to this repository.
echo Configure them in your local .env file instead.
echo.
pause