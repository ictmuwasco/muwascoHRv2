@echo off
echo ========================================
echo MUWASCO HR System - Starting Servers
echo ========================================
echo.

echo [1/3] Checking MySQL...
tasklist /FI "IMAGENAME eq mysqld.exe" 2>NUL | find /I "mysqld.exe" >NUL
if %errorlevel% neq 0 (
    echo MySQL is NOT running. Please start MySQL from XAMPP Control Panel.
    goto :end
) else (
    echo MySQL is running.
)

echo.
echo [2/3] Starting PHP Backend Server...
cd /d c:\xampp\htdocs\hrdemo
start "PHP Backend" cmd /c "php -S localhost:8000 -t . router.php"

echo.
echo [3/3] Starting React Frontend...
cd /d c:\xampp\htdocs\hrdemo\frontend
start "React Frontend" cmd /c "npm run dev"

echo.
echo ========================================
echo Servers Starting...
echo ========================================
echo PHP Backend: http://localhost:8000
echo API: http://localhost:8000/api
echo React Frontend: http://localhost:5173
echo.
echo Login: admin@muwasco.co.ke / Admin@123
echo.

:end