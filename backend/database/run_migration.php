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
