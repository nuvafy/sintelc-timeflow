<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $existingBs = collect(Schema::getIndexes('biometric_sources'))->pluck('name')->toArray();

        Schema::table('biometric_sources', function (Blueprint $table) use ($existingBs) {
            if (!in_array('biometric_sources_serial_number_index', $existingBs))
                $table->index('serial_number');
            if (!in_array('biometric_sources_status_last_ping_at_index', $existingBs))
                $table->index(['status', 'last_ping_at']);
        });

        $existingFe = collect(Schema::getIndexes('factorial_employees'))->pluck('name')->toArray();

        Schema::table('factorial_employees', function (Blueprint $table) use ($existingFe) {
            if (!in_array('factorial_employees_access_id_index', $existingFe))
                $table->index('access_id');
            if (!in_array('factorial_employees_client_id_index', $existingFe))
                $table->index('client_id');
        });

        $existingAl = collect(Schema::getIndexes('attendance_logs'))->pluck('name')->toArray();

        Schema::table('attendance_logs', function (Blueprint $table) use ($existingAl) {
            if (!in_array('attendance_logs_factorial_employee_id_index', $existingAl))
                $table->index('factorial_employee_id');
            if (!in_array('attendance_logs_biometric_source_id_occurred_at_index', $existingAl))
                $table->index(['biometric_source_id', 'occurred_at']);
            if (!in_array('attendance_logs_client_id_sync_status_index', $existingAl))
                $table->index(['client_id', 'sync_status']);
            if (!in_array('attendance_logs_client_id_employee_code_index', $existingAl))
                $table->index(['client_id', 'employee_code']);
        });

        $existingBus = collect(Schema::getIndexes('biometric_user_syncs'))->pluck('name')->toArray();

        Schema::table('biometric_user_syncs', function (Blueprint $table) use ($existingBus) {
            if (!in_array('biometric_user_syncs_client_id_external_employee_code_index', $existingBus))
                $table->index(['client_id', 'external_employee_code']);
        });
    }

    public function down(): void
    {
        Schema::table('biometric_sources', function (Blueprint $table) {
            $table->dropIndex(['serial_number']);
            $table->dropIndex(['status', 'last_ping_at']);
        });

        Schema::table('factorial_employees', function (Blueprint $table) {
            $table->dropIndex(['access_id']);
            $table->dropIndex(['client_id']);
        });

        Schema::table('attendance_logs', function (Blueprint $table) {
            $table->dropIndex(['factorial_employee_id']);
            $table->dropIndex(['biometric_source_id', 'occurred_at']);
            $table->dropIndex(['client_id', 'sync_status']);
            $table->dropIndex(['client_id', 'employee_code']);
        });

        Schema::table('biometric_user_syncs', function (Blueprint $table) {
            $table->dropIndex(['client_id', 'external_employee_code']);
        });
    }
};
