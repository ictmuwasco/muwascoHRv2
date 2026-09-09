<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fix: the inherited `subsections` and `offices` tables were created without
 * AUTO_INCREMENT on the `id` column. This migration adds it so factories and
 * Eloquent inserts work correctly.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Fix subsections.id auto_increment
        DB::statement('ALTER TABLE subsections MODIFY COLUMN id INT UNSIGNED NOT NULL AUTO_INCREMENT');

        // Fix offices.id auto_increment (also add primary key if missing)
        DB::statement('ALTER TABLE offices MODIFY COLUMN id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY');
    }

    public function down(): void
    {
        // No-op: removing auto-increment would break the schema.
    }
};
