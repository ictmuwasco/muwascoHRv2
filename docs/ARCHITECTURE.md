# System Architecture

## Overview

The HR Management System follows a modern, enterprise-grade architecture with complete separation of concerns.

## Architecture Diagram

```
┌─────────────────────────────────────────────────────────────┐
│                         Browser                              │
└───────────────────────────┬─────────────────────────────────┘
                            │ HTTPS
                            ▼
┌─────────────────────────────────────────────────────────────┐
│              React SPA (Vite + TypeScript)                   │
│  ┌──────────────────────────────────────────────────────┐   │
│  │  Pages: Dashboard, Employees, Attendance, Leave, etc │   │
│  └──────────────────────────────────────────────────────┘   │
│  ┌──────────────────────────────────────────────────────┐   │
│  │  Components: Button, Card, Table, Input, etc         │   │
│  └──────────────────────────────────────────────────────┘   │
│  ┌──────────────────────────────────────────────────────┐   │
│  │  Services: authService, employeeService, etc         │   │
│  └──────────────────────────────────────────────────────┘   │
└───────────────────────────┬─────────────────────────────────┘
                            │ REST API (JSON)
                            ▼
┌─────────────────────────────────────────────────────────────┐
│                   PHP Backend (API-First)                    │
│  ┌──────────────────────────────────────────────────────┐   │
│  │  Controllers: EmployeeController, AuthController, etc│   │
│  │  - Receive Requests                                  │   │
│  │  - Validate Input                                    │   │
│  │  - Call Services                                     │   │
│  │  - Return JSON Responses                             │   │
│  └──────────────────────────────────────────────────────┘   │
│                            │                                  │
│  ┌─────────────────────────▼──────────────────────────┐    │
│  │              Services Layer                          │    │
│  │  - EmployeeService, AuthService, LeaveService, etc  │    │
│  │  - Business Logic                                    │    │
│  │  - Orchestration                                     │    │
│  └─────────────────────────┬──────────────────────────┘    │
│                            │                                │
│  ┌─────────────────────────▼──────────────────────────┐    │
│  │            Repositories Layer                        │    │
│  │  - EmployeeRepository, UserRepository, etc           │    │
│  │  - Data Access                                       │    │
│  │  - SQL Queries                                       │    │
│  └─────────────────────────┬──────────────────────────┘    │
│                            │                                │
│  ┌─────────────────────────▼──────────────────────────┐    │
│  │              Models Layer                            │    │
│  │  - User, Employee, Attendance, Leave, etc            │    │
│  │  - Entity Definitions                                │    │
│  └──────────────────────────────────────────────────────┘   │
└───────────────────────────┬─────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────┐
│                    MySQL Database                            │
│  - users                                                    │
│  - employees                                                │
│  - departments                                              │
│  - attendance                                               │
│  - leave                                                    │
│  - audit_logs                                               │
└─────────────────────────────────────────────────────────────┘
```

## Backend Architecture

### Project Structure

The backend is located in the `backend/app` directory. It follows a custom MVC-like architecture with the following structure:

```
backend/
├── app/
│   ├── Controllers/     # HTTP request handlers
│   ├── Services/        # Business logic
│   ├── Repositories/    # Data access layer
│   ├── Models/          # Entity definitions
│   ├── Middleware/      # Request/response filters
│   ├── Validators/      # Input validation
│   ├── Helpers/         # Utility classes
│   ├── Responses/       # API response formatters
│   ├── Policies/        # Authorization policies
│   ├── Gates/           # Authorization gates
│   └── Templates/       # Email/response templates
├── config/              # Application configuration
├── database/            # Migrations and seeders
├── routes/              # API route definitions
├── public/              # Public assets and entry point
├── bootstrap.php        # Application bootstrap
└── composer.json        # PHP dependencies
```

### Layered Architecture

```
Controller → Service → Repository → Database
```

**Controller Layer**
- Receives HTTP requests
- Validates input
- Calls appropriate service methods
- Returns JSON responses
- No business logic

**Service Layer**
- Contains all business logic
- Orchestrates operations
- Calls repositories for data access
- Transaction management
- Reusable across controllers

**Repository Layer**
- Data access abstraction
- SQL queries
- CRUD operations
- No business logic

**Model Layer**
- Entity definitions
- Relationships
- Data structures

### Key Design Patterns

1. **Repository Pattern**: Data access abstraction
2. **Service Layer Pattern**: Business logic separation
3. **Dependency Injection**: Loose coupling
4. **Interface Segregation**: Contract-based design
5. **Single Responsibility**: One class, one purpose

## Frontend Architecture

### Component Hierarchy

```
App
├── AuthContext (State Management)
├── Router
│   ├── LoginPage
│   ├── Layout
│   │   ├── Header
│   │   ├── Sidebar
│   │   └── ProtectedRoute
│   │       ├── DashboardPage
│   │       ├── EmployeesPage
│   │       ├── AttendancePage
│   │       ├── LeavePage
│   │       └── ...
│   └── ...
└── API Client (Axios)
```

### State Management

- **Authentication State**: AuthContext
- **Server State**: React Query / API services
- **Local State**: useState/useReducer
- **URL State**: React Router

### API Layer

```
Component → Service → API Client → Backend
```

Components never call API directly. They use service modules.

## Security Architecture

### Authentication
- JWT-based authentication
- Secure HTTP-only cookies
- Token refresh mechanism
- Session management

### Authorization
- Role-Based Access Control (RBAC)
- Policies and Gates
- Middleware-based checks
- Permission-based UI rendering

### Data Protection
- Input validation
- SQL injection prevention (prepared statements)
- XSS prevention (output escaping)
- CSRF protection
- Secure file uploads
- Password hashing (bcrypt)

## Database Architecture

### Entity Relationships

```
users
  ├── employee (1:1)
  ├── roles (1:many)
  └── permissions (many:many)

employees
  ├── department (many:1)
  ├── section (many:1)
  ├── office (many:1)
  ├── attendance (1:many)
  ├── leave (1:many)
  └── next_of_kin (1:1)

departments
  ├── sections (1:many)
  └── employees (1:many)

attendance
  └── employee (many:1)

leave
  └── employee (many:1)
```

### Indexing Strategy

- Primary keys on all tables
- Foreign key indexes
- Composite indexes for frequently queried columns
- Full-text search indexes where needed

## Performance Considerations

### Backend
- Database query optimization
- Connection pooling
- Response caching
- Lazy loading
- Pagination

### Frontend
- Code splitting
- Lazy loading routes
- Image optimization
- Bundle size optimization
- Memoization

## Scalability

### Horizontal Scaling
- Stateless API controllers
- Session stored in database/Redis
- Load balancer ready

### Vertical Scaling
- Efficient database queries
- Caching layers
- Optimized algorithms

## Monitoring & Logging

### Logs
- Application logs
- Error logs
- Security logs
- Audit logs
- API request logs

### Monitoring
- Performance metrics
- Error tracking
- User activity tracking
- Database query monitoring