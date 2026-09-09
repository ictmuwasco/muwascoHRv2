<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('employee_leave_balances', function (Blueprint $table) {
            $table->integer('id', true);
            $table->string('employee_id', 50);
            $table->integer('leave_type_id');
            $table->integer('financial_year_id');
            $table->decimal('allocated_days', 5)->default(0);
            $table->decimal('used_days', 5)->default(0);
            $table->decimal('brought_forward_days', 5)->default(0);
            $table->decimal('accumulated_days', 5)->default(0);
            $table->decimal('remaining_days', 5)->default(0);
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new \RuntimeException('Baseline down() disabled — inherited shared schema; restore from backups only (docs/PHASE_L1_REPORT.md).'); // Schema::dropIfExists('employee_leave_balances');
    }
};

