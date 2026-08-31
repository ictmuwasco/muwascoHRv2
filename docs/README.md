# HR Management System - Enterprise Modernization

## Overview

This is a complete enterprise modernization of the HR Management System, transforming it from a PHP-rendered application to a modern, scalable, API-first architecture with a React TypeScript frontend.

## Key Features

### Backend (PHP API)
- **Layered Architecture**: Repository → Service → Controller pattern
- **API-First Design**: All endpoints return JSON
- **Authentication**: JWT-based with secure sessions
- **Authorization**: Role-Based Access Control (RBAC)
- **Validation**: Centralized input validation
- **Security**: CSRF protection, XSS prevention, SQL injection prevention
- **Testing**: Comprehensive unit and integration tests

### Frontend (React TypeScript)
- **Modern Stack**: React 19, Vite, TypeScript, Tailwind CSS
- **Type Safety**: 40+ TypeScript interfaces
- **Component Library**: Reusable UI components (Button, Card, Table, etc.)
- **State Management**: AuthContext for authentication
- **API Services**: Typed service modules for all features
- **Responsive Design**: Mobile-first approach

## Technology Stack

### Backend
- PHP 8.0+
- MySQL 8.0+
- Custom autoloading (no Composer dependency)
- JWT Authentication
- Repository Pattern
- Service Layer Pattern

### Frontend
- React 19
- TypeScript
- Vite
- Tailwind CSS
- Axios
- React Router
- Lucide React (icons)

### Testing
- PHPUnit-style custom suite, no Composer dependency (backend)
- Vitest (frontend)

## Quick Start

See [SETUP.md](SETUP.md) for detailed setup instructions.

### Backend
```bash
# See SETUP.md for the authoritative dev-server command (XAMPP + api.php entry point).
# There is no composer install step — the backend has no composer.json.
```

### Frontend
```bash
cd frontend
npm install
npm run dev
```

## Project Structure

```
hrdemo/
├── backend/
│   ├── app/
│   │   ├── Controllers/      # API controllers
│   │   ├── Services/         # Business logic
│   │   ├── Repositories/     # Data access
│   │   ├── Models/           # Entity models
│   │   ├── Validators/       # Input validation
│   │   ├── Middleware/       # Auth & authorization
│   │   ├── Policies/         # Authorization policies
│   │   ├── Gates/            # Authorization gates
│   │   ├── Responses/        # API response formatters
│   │   └── Helpers/          # Utility classes
│   ├── config/               # Configuration
│   ├── database/             # Migrations
│   ├── routes/               # Route definitions
│   ├── storage/              # Logs, cache, uploads
│   └── tests/                # Test suite
│
├── frontend/
│   ├── src/
│   │   ├── api/              # API client & services
│   │   ├── components/       # Reusable components
│   │   │   └── ui/           # UI component library
│   │   ├── pages/            # Page components
│   │   │   ├── auth/         # Authentication pages
│   │   │   ├── employee/     # Employee management pages
│   │   │   ├── leave/        # Leave management pages
│   │   │   ├── hr-admin/     # HR administration pages
│   │   │   ├── meetings/     # Meeting management pages
│   │   │   └── settings/     # Settings and admin pages
│   │   ├── context/          # React contexts
│   │   ├── types/            # TypeScript definitions
│   │   └── utils/            # Utilities
│   └── public/               # Static assets
│
└── docs/                     # Documentation
    ├── API_DOCUMENTATION.md
    ├── ARCHITECTURE.md
    ├── PAGE_DOCUMENTATION.md
    ├── SETUP.md
    └── DEPLOYMENT.md
```

## Architecture

### Backend Layers

```
Request → Controller → Service → Repository → Database
```

- **Controller**: Handles HTTP requests/responses
- **Service**: Business logic orchestration
- **Repository**: Data access abstraction
- **Model**: Entity definitions

### Frontend Layers

```
Component → Service → API Client → Backend
```

- **Pages**: Feature-specific components
- **Components**: Reusable UI components
- **Services**: API communication
- **Types**: TypeScript definitions

## Key Improvements

### From Legacy System
- ❌ PHP Views with HTML rendering
- ❌ Mixed business logic in controllers
- ❌ SQL queries in views
- ❌ No type safety
- ❌ Duplicate code
- ❌ Tight coupling

### To Modern System
- ✅ API-first backend (JSON only)
- ✅ Clean layered architecture
- ✅ Repository pattern for data access
- ✅ TypeScript throughout frontend
- ✅ Reusable components
- ✅ Dependency injection
- ✅ Comprehensive testing
- ✅ Complete documentation

## Testing

### Backend Tests
```bash
php backend/run_tests.php
```

### Frontend Tests
```bash
cd frontend
npm run test
```

See [testing.md](testing.md) for the full test map, coverage status and how to add tests.

## Documentation

Full index — see the [Documentation Map](#documentation-map) below for the complete, status-annotated list.

## Documentation Map

| Document | Status (audit 2026-08-31) |
|---|---|
| [API_REFERENCE.md](API_REFERENCE.md) | **NEW** — complete catalogue of all 270 routes |
| [API_DOCUMENTATION.md](API_DOCUMENTATION.md) | kept — deep guide for core modules (auth, employees, attendance, leave, reports, errors) |
| [ARCHITECTURE.md](ARCHITECTURE.md) | kept — still accurate |
| [SETUP.md](SETUP.md) | kept |
| [DEPLOYMENT.md](DEPLOYMENT.md) | kept |
| [SECURITY_AUDIT.md](SECURITY_AUDIT.md) | kept — canonical security reference |
| [AUTHENTICATION_MIDDLEWARE_ANALYSIS.md](AUTHENTICATION_MIDDLEWARE_ANALYSIS.md) | kept — historical middleware analysis |
| [NOTIFICATIONS.md](NOTIFICATIONS.md) | kept — push/SMS notifications |
| [OBSERVABILITY.md](OBSERVABILITY.md) | kept — logging, audit, error tracking |
| [PAGE_DOCUMENTATION.md](PAGE_DOCUMENTATION.md) | kept — frontend page inventory |
| [meetings.md](meetings.md) | **new** — meetings, invitations, RSVP, attendance |
| [meeting-minutes.md](meeting-minutes.md) | **NEW** — structured minutes, draft→publish lifecycle |
| [attendance.md](attendance.md) | **NEW** — clock in/out, geofence, reminders, HR views |
| [leave.md](leave.md) | **NEW** — applications, delegates, roster, balances |
| [employees.md](employees.md) | **NEW** — employees, org tree, users & permissions |
| [strategy-performance.md](strategy-performance.md) | **NEW** — plans, workplans, KPIs, contracts, appraisals |
| [reports.md](reports.md) | **NEW** — attendance & leave reporting engines |
| [complaints-consent.md](complaints-consent.md) | **NEW** — complaints register, versioned consent |
| [payroll.md](payroll.md) | **NEW** — payroll register (status: minimal, see gaps) |
| [database.md](database.md) | **NEW** — schema domains, all 37 migrations, relationships |
| [testing.md](testing.md) | **NEW** — test suites, commands, coverage gaps |
| [archive/](archive/) | Historical one-off fix logs |

> Docs live in `/docs` (project root). There is **no** `/backend/docs` directory — the old convention pointing there is obsolete.


## Security Features

- JWT authentication
- Role-based access control (RBAC)
- Input validation
- SQL injection prevention
- XSS prevention
- CSRF protection
- Secure file uploads
- Password hashing (bcrypt)
- Audit logging
- Rate limiting

## Performance Features

- Database query optimization
- Response caching
- Frontend code splitting
- Lazy loading
- Image optimization
- Gzip compression
- CDN-ready

## Browser Support

- Chrome (latest)
- Firefox (latest)
- Safari (latest)
- Edge (latest)

## Contributing

1. Fork the repository
2. Create a feature branch
3. Make changes and add tests
4. Submit a pull request

## License

Proprietary - All rights reserved

## Support

For issues and questions:
- Review documentation in `docs/` folder
- Check API documentation
- Review test files for examples