# HRM System - Testing Checklist

## Frontend Pages Synchronized

### 1. Profile.tsx (Employee Self-Service)
**Location:** `frontend/src/pages/Profile.tsx`

#### Next of Kin Tab
- [ ] Form fields: name, relationship, phone, email, address
- [ ] Save button updates successfully
- [ ] Data persists after page refresh
- [ ] Error messages display on failure
- [ ] Success message shows on save

#### Dependants Tab
- [ ] Add dependant form: name, relationship, date_of_birth
- [ ] Add button creates new dependant
- [ ] Dependant appears in list immediately
- [ ] Delete button removes dependant
- [ ] Confirmation dialog appears before delete
- [ ] Data persists after page refresh

#### Documents Tab
- [ ] Upload form: document_name, category, file input
- [ ] File selection updates UI
- [ ] Upload button submits form
- [ ] Document appears in list after upload
- [ ] Delete button removes document
- [ ] Confirmation dialog appears before delete
- [ ] Data persists after page refresh

### 2. EmployeeProfile.jsx (HR Management View)
**Location:** `frontend/src/pages/EmployeeProfile.jsx`

#### Next of Kin Tab
- [ ] Form fields: name, relationship, phone, email, address (matches Profile.tsx)
- [ ] Save button updates successfully
- [ ] Data displays in "Current Next of Kin" section
- [ ] All 5 fields display with icons
- [ ] Data persists after page refresh
- [ ] Error messages display on failure
- [ ] Success message shows on save

#### Dependants Tab
- [ ] Add dependant form: name, relationship, date_of_birth (matches Profile.tsx)
- [ ] Add button creates new dependant
- [ ] Dependant appears in list immediately
- [ ] Delete button removes dependant
- [ ] Confirmation dialog appears before delete
- [ ] Data persists after page refresh

#### Documents Tab
- [ ] Upload form: document_name, category, file input (matches Profile.tsx)
- [ ] File selection updates UI
- [ ] Upload button submits form
- [ ] Document appears in list after upload
- [ ] Delete button removes document
- [ ] Confirmation dialog appears before delete
- [ ] Data persists after page refresh

### 3. Employees.jsx (Employee List)
**Location:** `frontend/src/pages/Employees.jsx`

#### List View
- [ ] Employee table displays correctly
- [ ] Search functionality works
- [ ] Pagination works (previous/next)
- [ ] "View Profile" button navigates to EmployeeProfile.jsx
- [ ] "Edit" button navigates to edit page
- [ ] "Add Employee" button navigates to add page
- [ ] Loading spinner displays during fetch
- [ ] Empty state displays when no employees

## Backend API Endpoints

### Employee Profile Endpoints (HR)
- [ ] PUT /api/employees/{id} - Updates next_of_kin and dependants
- [ ] POST /api/employees/documents - Uploads documents
- [ ] DELETE /api/employees/documents/{id} - Deletes documents
- [ ] GET /api/employees/{id} - Retrieves employee data with parsed next_of_kin_data

### User Profile Endpoints (Self-Service)
- [ ] PUT /api/profile - Updates next_of_kin and dependants
- [ ] POST /api/profile/documents - Uploads documents
- [ ] DELETE /api/profile/documents/{id} - Deletes documents
- [ ] GET /api/profile - Retrieves user profile data

## Database Operations

### JSON Field Handling
- [ ] next_of_kin field stores JSON array correctly
- [ ] dependants field stores JSON array correctly
- [ ] documents are stored in employee_documents table
- [ ] Data is properly escaped to prevent SQL injection
- [ ] UTF-8 characters are preserved (JSON_UNESCAPED_UNICODE)

### Data Integrity
- [ ] Next of kin data displays after save in both Profile.tsx and EmployeeProfile.jsx
- [ ] Dependants list updates immediately after add/delete
- [ ] Documents list updates immediately after upload/delete
- [ ] Changes persist after page refresh
- [ ] Changes persist after navigating away and back

## Error Handling

### Frontend
- [ ] Network errors display user-friendly message
- [ ] Loading states show during API calls
- [ ] Disabled states prevent duplicate submissions
- [ ] Form validation prevents empty required fields
- [ ] Confirmation dialogs for destructive actions

### Backend
- [ ] 400 Bad Request for validation errors
- [ ] 401 Unauthorized for unauthenticated requests
- [ ] 403 Forbidden for unauthorized access
- [ ] 404 Not Found for missing resources
- [ ] 500 Internal Server Error for server errors
- [ ] Error messages are logged to console

## Browser Testing

### Test in Multiple Browsers
- [ ] Chrome/Edge (Chromium)
- [ ] Firefox
- [ ] Safari (if available)

### Responsive Design
- [ ] Desktop view (1920x1080)
- [ ] Tablet view (768px width)
- [ ] Mobile view (375px width)

## Performance Testing

### Load Times
- [ ] Profile page loads in < 2 seconds
- [ ] Employee list loads in < 2 seconds
- [ ] API responses complete in < 1 second

### Concurrent Operations
- [ ] Multiple users can add dependants simultaneously
- [ ] Document uploads don't block other operations
- [ ] Page refreshes don't cause duplicate submissions

## Security Testing

### Authentication
- [ ] Unauthenticated users cannot access profile pages
- [ ] Users can only view their own profile in Profile.tsx
- [ ] HR users can view any employee in EmployeeProfile.jsx

### Authorization
- [ ] Permission checks are enforced
- [ ] API endpoints validate user permissions
- [ ] Cross-user data access is prevented

### Data Validation
- [ ] XSS prevention (input sanitization)
- [ ] SQL injection prevention (prepared statements)
- [ ] File upload validation (type, size)
- [ ] CSRF protection enabled

## Notes
- Database file: `laravel/database/admin_hrmuwasco.sql` (if exists)
- Backend API: http://localhost:5173/api
- Frontend dev server: http://localhost:5173
- Backend PHP server: Check .env for correct URL

## Known Issues
- Document download functionality not implemented (buttons present but not functional)
- Password change in Profile.tsx not connected to backend