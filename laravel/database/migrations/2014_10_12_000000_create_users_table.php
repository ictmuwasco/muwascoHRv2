<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The users table is created by the custom migration
     * 2024_01_01_000001_create_users_table.php with HRMS-specific columns.
     * This migration is kept as a no-op to avoid duplicate table creation.
     *
     * @return void
     */
    public function up()
    {
        // No-op: users table is created by 2024_01_01_000001_create_users_table.php
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        // No-op: users table is dropped by 2024_01_01_000001_create_users_table.php
    }
};