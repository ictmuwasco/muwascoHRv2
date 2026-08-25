<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use App\Helpers\Database;

try {
    $db = Database::getInstance();
    $conn = $db->getConnection();
    
    // Run migration 002 - Refresh tokens
    $sql = file_get_contents(__DIR__ . '/migrations/002_refresh_tokens.sql');
    
    if ($conn->multi_query($sql)) {
        do {
            if ($result = $conn->store_result()) {
                $result->free();
            }
        } while ($conn->more_results() && $conn->next_result());
        
        echo "Migration 002_refresh_tokens.sql executed successfully\n";
    } else {
        echo "Error executing migration 002: " . $conn->error . "\n";
        exit(1);
    }
    
    // Verify table was created
    $result = $conn->query("SHOW TABLES LIKE 'refresh_tokens'");
    if ($result && $result->num_rows > 0) {
        echo "✓ refresh_tokens table created successfully\n";
    } else {
        echo "✗ refresh_tokens table not found\n";
        exit(1);
    }
    
    // Run migration 003 - User page permissions
    $sql = file_get_contents(__DIR__ . '/migrations/003_user_page_permissions.sql');
    
    if ($conn->multi_query($sql)) {
        do {
            if ($result = $conn->store_result()) {
                $result->free();
            }
        } while ($conn->more_results() && $conn->next_result());
        
        echo "Migration 003_user_page_permissions.sql executed successfully\n";
    } else {
        echo "Error executing migration 003: " . $conn->error . "\n";
        exit(1);
    }
    
    // Verify table was created
    $result = $conn->query("SHOW TABLES LIKE 'user_page_permissions'");
    if ($result && $result->num_rows > 0) {
        echo "✓ user_page_permissions table created successfully\n";
    } else {
        echo "✗ user_page_permissions table not found\n";
        exit(1);
    }
    
    // Run migration 004 - Role permissions
    $sql = file_get_contents(__DIR__ . '/migrations/004_role_permissions.sql');
    
    if ($conn->multi_query($sql)) {
        do {
            if ($result = $conn->store_result()) {
                $result->free();
            }
        } while ($conn->more_results() && $conn->next_result());
        
        echo "Migration 004_role_permissions.sql executed successfully\n";
    } else {
        echo "Error executing migration 004: " . $conn->error . "\n";
        exit(1);
    }
    
    // Verify table was created
    $result = $conn->query("SHOW TABLES LIKE 'role_permissions'");
    if ($result && $result->num_rows > 0) {
        echo "✓ role_permissions table created successfully\n";
    } else {
        echo "✗ role_permissions table not found\n";
        exit(1);
    }
    
    // Run migration 007 - Financial years
    $sql = file_get_contents(__DIR__ . '/migrations/007_financial_years.sql');
    
    if ($conn->multi_query($sql)) {
        do {
            if ($result = $conn->store_result()) {
                $result->free();
            }
        } while ($conn->more_results() && $conn->next_result());
        
        echo "Migration 007_financial_years.sql executed successfully\n";
    } else {
        echo "Error executing migration 007: " . $conn->error . "\n";
        exit(1);
    }
    
    // Verify tables were created
    $result = $conn->query("SHOW TABLES LIKE 'financial_years'");
    if ($result && $result->num_rows > 0) {
        echo "✓ financial_years table created successfully\n";
    } else {
        echo "✗ financial_years table not found\n";
        exit(1);
    }
    
    $result = $conn->query("SHOW TABLES LIKE 'employee_leave_balances'");
    if ($result && $result->num_rows > 0) {
        echo "✓ employee_leave_balances table created successfully\n";
    } else {
        echo "✗ employee_leave_balances table not found\n";
        exit(1);
    }

    // Run migration 011 - Leave application documents
    $sql = file_get_contents(__DIR__ . '/migrations/011_leave_application_documents.sql');
    
    if ($conn->multi_query($sql)) {
        do {
            if ($result = $conn->store_result()) {
                $result->free();
            }
        } while ($conn->more_results() && $conn->next_result());
        
        echo "Migration 011_leave_application_documents.sql executed successfully\n";
    } else {
        echo "Error executing migration 011: " . $conn->error . "\n";
        exit(1);
    }
    
    // Verify table was created
    $result = $conn->query("SHOW TABLES LIKE 'leave_application_documents'");
    if ($result && $result->num_rows > 0) {
        echo "✓ leave_application_documents table created successfully\n";
    } else {
        echo "✗ leave_application_documents table not found\n";
        exit(1);
    }

    // Run migration 012 - Delegate columns
    $sql = file_get_contents(__DIR__ . '/migrations/012_add_delegate_to_leave_applications.sql');
    
    if ($conn->multi_query($sql)) {
        do {
            if ($result = $conn->store_result()) {
                $result->free();
            }
        } while ($conn->more_results() && $conn->next_result());
        
        echo "Migration 012_add_delegate_to_leave_applications.sql executed successfully\n";
    } else {
        echo "Error executing migration 012: " . $conn->error . "\n";
        exit(1);
    }
    
    // Verify columns were added
    $result = $conn->query("SHOW COLUMNS FROM leave_applications LIKE 'delegate_emp_id'");
    if ($result && $result->num_rows > 0) {
        echo "✓ delegate_emp_id column added successfully\n";
    } else {
        echo "✗ delegate_emp_id column not found\n";
        exit(1);
    }
    
    // Run migration 013 - Audit logs table
    $sql = file_get_contents(__DIR__ . '/migrations/013_audit_logs.sql');

    if ($conn->multi_query($sql)) {
        do {
            if ($result = $conn->store_result()) {
                $result->free();
            }
        } while ($conn->more_results() && $conn->next_result());

        echo "Migration 013_audit_logs.sql executed successfully\n";
    } else {
        echo "Error executing migration 013: " . $conn->error . "\n";
        exit(1);
    }

    // Verify table was created
    $result = $conn->query("SHOW TABLES LIKE 'audit_logs'");
    if ($result && $result->num_rows > 0) {
        echo "✓ audit_logs table created successfully\n";
    } else {
        echo "✗ audit_logs table not found\n";
        exit(1);
    }

    // Run migration 015 - Hybrid permission overrides (module/action structure)
    $sql = file_get_contents(__DIR__ . '/migrations/015_hybrid_permission_overrides.sql');

    if ($conn->multi_query($sql)) {
        do {
            if ($result = $conn->store_result()) {
                $result->free();
            }
        } while ($conn->more_results() && $conn->next_result());

        echo "Migration 015_hybrid_permission_overrides.sql executed successfully\n";
    } else {
        echo "Error executing migration 015: " . $conn->error . "\n";
        exit(1);
    }

    // Verify module/action columns were added
    $result = $conn->query("SHOW COLUMNS FROM user_page_permissions LIKE 'module'");
    if ($result && $result->num_rows > 0) {
        echo "✓ module column added successfully\n";
    } else {
        echo "✗ module column not found\n";
        exit(1);
    }

    $result = $conn->query("SHOW COLUMNS FROM user_page_permissions LIKE 'action'");
    if ($result && $result->num_rows > 0) {
        echo "✓ action column added successfully\n";
    } else {
        echo "✗ action column not found\n";
        exit(1);
    }

    // Drop partially-created meetings tables first (if any) so the
    // CREATE TABLE IF NOT EXISTS + CREATE INDEX statements run cleanly.
    $conn->query("DROP TABLE IF EXISTS meeting_invitations");
    $conn->query("DROP TABLE IF EXISTS meetings");

    // Run migration 016 - Create meetings table
    $sql = file_get_contents(__DIR__ . '/migrations/016_create_meetings_table.sql');
    if ($conn->multi_query($sql)) {
        do {
            if ($result = $conn->store_result()) {
                $result->free();
            }
        } while ($conn->more_results() && $conn->next_result());
        echo "Migration 016_create_meetings_table.sql executed successfully\n";
    } else {
        echo "Error executing migration 016: " . $conn->error . "\n";
        exit(1);
    }
    $result = $conn->query("SHOW TABLES LIKE 'meetings'");
    if ($result && $result->num_rows > 0) {
        echo "✓ meetings table created successfully\n";
    } else {
        echo "✗ meetings table not found\n";
        exit(1);
    }

    // Run migration 017 - Create meeting_invitations table
    $sql = file_get_contents(__DIR__ . '/migrations/017_create_meeting_invitations_table.sql');
    if ($conn->multi_query($sql)) {
        do {
            if ($result = $conn->store_result()) {
                $result->free();
            }
        } while ($conn->more_results() && $conn->next_result());
        echo "Migration 017_create_meeting_invitations_table.sql executed successfully\n";
    } else {
        echo "Error executing migration 017: " . $conn->error . "\n";
        exit(1);
    }
    $result = $conn->query("SHOW TABLES LIKE 'meeting_invitations'");
    if ($result && $result->num_rows > 0) {
        echo "✓ meeting_invitations table created successfully\n";
    } else {
        echo "✗ meeting_invitations table not found\n";
        exit(1);
    }

    // Run migration 018 - Leave roster table
    $sql = file_get_contents(__DIR__ . '/migrations/018_leave_roster.sql');
    if ($conn->multi_query($sql)) {
        do {
            if ($result = $conn->store_result()) {
                $result->free();
            }
        } while ($conn->more_results() && $conn->next_result());
        echo "Migration 018_leave_roster.sql executed successfully\n";
    } else {
        echo "Error executing migration 018: " . $conn->error . "\n";
        exit(1);
    }
    $result = $conn->query("SHOW TABLES LIKE 'leave_roster'");
    if ($result && $result->num_rows > 0) {
        echo "✓ leave_roster table created successfully\n";
    } else {
        echo "✗ leave_roster table not found\n";
        exit(1);
    }

    echo "\n✓ All migrations completed successfully!\n";
    
    // Re-run role permissions migration to ensure new permissions are added
    echo "\nUpdating role permissions...\n";
    $sql = file_get_contents(__DIR__ . '/migrations/004_role_permissions.sql');
    
    if ($conn->multi_query($sql)) {
        do {
            if ($result = $conn->store_result()) {
                $result->free();
            }
        } while ($conn->more_results() && $conn->next_result());
        
        echo "✓ Role permissions updated successfully\n";
    } else {
        echo "Warning: Error updating role permissions: " . $conn->error . "\n";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
