<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('attendance_logs', function (Blueprint $table) {
            $table->foreignId('factorial_employee_id')
                ->nullable()
                ->after('biometric_source_id')
                ->constrained('factorial_employees')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('attendance_logs', function (Blueprint $table) {
            $table->dropForeign(['factorial_employee_id']);
            $table->dropColumn('factorial_employee_id');
        });
    }
};
