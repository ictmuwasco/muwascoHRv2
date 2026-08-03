<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('surname')->nullable();
            $table->enum('gender', ['male', 'female'])->nullable();
            $table->string('password');
            $table->enum('role', ['super_admin', 'admin', 'hr_manager', 'officer'])->default('officer');
            $table->string('designation')->nullable();
            $table->string('phone')->nullable();
            $table->text('address')->nullable();
            $table->string('profile_image_url')->nullable();
            $table->string('employee_id')->nullable();
            $table->string('session_token')->nullable();
            $table->string('login_identifier')->nullable();
            $table->boolean('is_active')->default(1);
            $table->timestamp('last_activity')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};