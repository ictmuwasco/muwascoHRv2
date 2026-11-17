# MUWASCO HR System - Running Instructions

## Prerequisites
- XAMPP installed with MySQL running ✓ (verified)
- PHP 8.0+ available
- Node.js/npm available

## Step 1: Verify MySQL is Running
Open Command Prompt and confirm MySQL is running:
```
tasklist | findstr /I "mysqld.exe"
```
If not running, start it via XAMPP Control Panel.

## Step 2: Set Up Database
Run the database setup script:
```
cd c:\\xampp\\htdocs\\hrdemo
setup-database.bat
```
This will:
1. Create the `muwasco` database (or use existing)
2. Import the schema from `backend/database/muwasco (1).sql`

**Note:** The .env file specifies:
- DB_DATABASE=hr (note: different from 'muwasco')
- DB_USERNAME=root
- DB_PASSWORD=root

If the schema import uses a different database name, update `.env` accordingly.

## Step 3: Install PHP Dependencies
```
cd c:\\xampp\\htdocs\\hrdemo
composer install
```
This will install all PHP dependencies defined in `composer.json`:
- firebase/php-jwt, vlucas/phpdotenv, phpmailer, etc.

## Step 4: Install Node.js Dependencies
```
cd c:\\xampp\\htdocs\\hrdemo\\frontend
npm install
```
This will install all frontend dependencies for the React Vite app.

## Step 5: Build the Frontend
```
cd c:\\xampp\\htdocs\\hrdemo\\frontend
npm run build
```
This will:
- Build the React app using Vite
- Output fingerprinted bundles to `backend/public/` (as configured in vite.config.js)
- Create the `index.html` shell and assets

## Step 6: Start the PHP Development Server
```
cd c:\\xampp\\htdocs\\hrdemo
php -S localhost:8000 -t . router.php
```
The backend will be available at `http://localhost:8000`

## Step 7: Start the React Development Server
Open a new terminal and:
```
cd c:\\xampp\\htdocs\\hrdemo\\frontend
npm run dev
```
The frontend will be available at `http://localhost:5173` (default Vite port)

## Alternative: Use the Start Script
You can also use the provided `start-servers.bat`:
```
cd c:\\xampp\\htdocs\\hrdemo
start-servers.bat
```
This will start:
- MySQL check (already running)
- PHP backend at `http://localhost:8000`
- React frontend at `http://localhost:5173`

**Login:** Use your MUWASCO HR account credentials. 
**Note:** Default credentials are NOT stored in this repository. 
Configure them in your local `.env` file if needed.

## Environment Configuration
The `.env` file at the root contains:
- Database settings (DB_HOST, DB_DATABASE, DB_USERNAME, DB_PASSWORD)
- JWT secrets and token expiry
- CORS and CSRF configuration
- Feature flags
- SMS and attendance settings
- Backup encryption settings

**Important:** Never commit real credentials. The `.env` file is git-tracked - use external environment variables for production.

## API Endpoints
The API is available at `http://localhost:8000/api`

Key modules include:
- Authentication (login, registration, password reset)
- Employee management
- Leave management
- Attendance tracking
- Appraisal system
- HR settings (departments, sections, holidays)
- Reports and analytics

## Troubleshooting
1. **Composer issues:** Ensure `composer.exe` is in PATH or use `php composer.phar`
2. **Node issues:** Ensure `node.exe` is in PATH and npm is available
3. **Database import failures:** Verify MySQL is running and `.env` credentials are correct
4. **Build failures:** Delete `node_modules` and `vendor` directories, then reinstall
5. **CORS issues:** The CORS configuration is in `backend/config/cors.php`