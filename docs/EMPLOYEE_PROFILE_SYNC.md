# Employee Profile Form Sync

## Overview
Synchronized forms between EmployeeProfile.jsx and Profile.tsx for next of kin, dependants, and documents.

## Database Tables
- next_of_kin: id, employee_id, name, relationship, contact, created_at, updated_at
- dependencies: id, employee_id, name, relationship, date_of_birth, gender, id_no, contact, created_at
- employee_documents: id, employee_id, document_name, category, file_name, uploaded_at

## Files Modified
- frontend/src/pages/EmployeeProfile.jsx
- frontend/src/pages/Profile.tsx
- backend/app/Repositories/EmployeeRepository.php
- backend/app/Services/EmployeeService.php
- backend/app/Controllers/EmployeeController.php

## Form Fields (Both Files)
- Next of Kin: Name, Relationship, Contact
- Dependants: Name, Relationship, Date of Birth, Gender, ID Number, Contact
- Documents: Document Name, Category, File Upload