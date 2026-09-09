<?php

declare(strict_types=1);

use Database\Seeders\DatabaseSeeder;

/**
 * Migration: Run database seeders
 * 
 * This migration runs all seeders to populate the database
 * with initial/reference data for development and testing.
 */

// Only run in development/testing environments
$environment = $_ENV['APP_ENV'] ?? 'production';
if ($environment === 'production') {
    echo "Skipping seeders in production environment.\n";
    return;
}

try {
    $seeder = new DatabaseSeeder();
    $seeder->run();
    echo "Database seeded successfully.\n";
} catch (\Exception $e) {
    echo "Seeding failed: " . $e->getMessage() . "\n";
    throw $e;
}