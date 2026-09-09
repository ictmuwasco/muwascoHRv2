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
        Schema::create('leave_transactions_backup', function (Blueprint $table) {
            $table->integer('id')->default(0);
            $table->integer('application_id');
            $table->integer('employee_id');
            $table->dateTime('transaction_date');
            $table->enum('transaction_type', ['deduction', 'restoration', 'adjustment']);
            $table->text('details')->nullable()->comment('JSON storage of transaction details');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new \RuntimeException('Baseline down() disabled — inherited shared schema; restore from backups only (docs/PHASE_L1_REPORT.md).'); // Schema::dropIfExists('leave_transactions_backup');
    }
};

