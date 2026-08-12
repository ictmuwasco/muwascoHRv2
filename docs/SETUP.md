# Developer Setup Guide

## Prerequisites

- PHP 8.0 or higher
- Composer
- Node.js 18+ and npm
- MySQL 8.0 or higher
- XAMPP or similar local development environment

## Backend Setup

### 1. Install Dependencies

```bash
cd backend
composer install
```

### 2. Environment Configuration

Create a `.env` file in the root directory:

```env
APP_NAME="HR Management System"
APP_ENV=local
APP_DEBUG=true
APP_URL=http://localhost/hr

DB_HOST=localhost
DB_PORT=3306
DB_NAME=hrdemo
DB_USER=root
DB_PASS=

JWT_SECRET=your-secret-key-here
SESSION_LIFETIME=120
SESSION_SAME_SITE=Lax
```

### 3. Database Setup

```bash
# Create database
mysql -u root -p -e "CREATE DATABASE hrdemo CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# Run migrations
cd backend/database
php run_migration.php
```

### 4. Start Backend Server

The root `index.php` serves the React SPA (from `frontend/dist/`) and routes `/api/*` requests to `api.php`. The PHP built-in server ignores `.htaccess`, so a `router.php` script is used to replicate the routing.

```bash
# From the project root (c:\xampp\htdocs\hrdemo)
php -S localhost:8000 -t . router.php
```

The application will be available at `http://localhost:8000` and the API at `http://localhost:8000/api`

## Frontend Setup

### 1. Install Dependencies

```bash
cd frontend
npm install
```

### 2. Environment Configuration

Create a `.env` file in the frontend directory:

```env
VITE_API_URL=http://localhost:8000/api
```

### 3. Start Development Server

```bash
npm run dev
```

The application will be available at `http://localhost:5173` (in development) or `http://localhost:8000` (built via `npm run build` and served by the PHP backend).

> **Note**: To serve the production build through the PHP server, first run `npm run build`, then start the PHP server with `php -S localhost:8000 -t . router.php`.

## Running Tests

### Backend Tests

```bash
cd backend
./vendor/bin/phpunit
```

### Frontend Tests

```bash
cd frontend
npm test
```

## Project Structure

```
hrdemo/
├── backend/                 # PHP API backend
│   ├── app/
│   │   ├── Controllers/     # API controllers
│   │   ├── Services/        # Business logic
│   │   ├── Repositories/    # Data access
│   │   ├── Models/          # Entity models
│   │   ├── Validators/      # Input validation
│   │   ├── Middleware/      # Auth & authorization
│   │   ├── Responses/       # API response formatters
│   │   └── Helpers/         # Utility classes
│   ├── config/              # Configuration files
│   ├── database/            # Migrations & seeders
│   ├── routes/              # Route definitions
│   ├── storage/             # Logs, cache, uploads
│   └── tests/               # Test suite
│
├── frontend/                # React TypeScript frontend
│   ├── src/
│   │   ├── api/             # API client & services
│   │   ├── components/      # Reusable components
│   │   │   └── ui/          # UI component library
│   │   ├── pages/           # Page components
│   │   ├── context/         # React contexts
│   │   ├── types/           # TypeScript definitions
│   │   └── utils/           # Utility functions
│   └── public/              # Static assets
│
└── docs/                    # Documentation
```

## Coding Standards

### PHP (Backend)

- Follow PSR-12 coding standard
- Use strict typing
- Write unit tests for all services
- Use dependency injection
- Document all public methods with PHPDoc

### TypeScript (Frontend)

- Use TypeScript for all new code
- Follow React best practices
- Use functional components with hooks
- Write tests for all components
- Use meaningful variable names

## Git Workflow

1. Create feature branch from `main`
2. Make changes and commit
3. Push and create pull request
4. Wait for review and approval
5. Merge to main

## Troubleshooting

### Backend Issues

**Problem**: Composer install fails
**Solution**: Run `composer install --no-cache`

**Problem**: Database connection error
**Solution**: Check `.env` file for correct database credentials

**Problem**: 500 Internal Server Error
**Solution**: Check `backend/storage/logs/error.log`

### Frontend Issues

**Problem**: Module not found
**Solution**: Run `npm install` again

**Problem**: TypeScript errors
**Solution**: Check `tsconfig.json` and ensure all types are installed

**Problem**: API calls failing
**Solution**: Verify backend is running and CORS is configured

## Deployment

### Backend

1. Run `composer install --no-dev --optimize-autoloader`
2. Set `APP_ENV=production` and `APP_DEBUG=false`
3. Configure web server to point to `backend/public`
4. Set up SSL certificate
5. Configure cron jobs for scheduled tasks

### Frontend

1. Run `npm run build`
2. Deploy `dist/` folder to web server
3. Configure reverse proxy to API backend
4. Set up CDN for static assets

## Support

For issues and questions:
- Check documentation in `docs/` folder
- Review API documentation
- Check existing tests for examples