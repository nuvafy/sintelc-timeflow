<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // biometric_sources: serial_number se busca en cada ping/attlog del biométrico
        Schema::table('biometric_sources', function (Blueprint $table) {
            $table->index('serial_number');
            $table->index(['status', 'last_ping_at']);
        });

        // factorial_employees: access_id se usa para mapear PINs biométricos
        Schema::table('factorial_employees', function (Blueprint $table) {
            $table->index('access_id');
            $table->index('client_id');
        });

        // attendance_logs: columnas críticas para queries de sincronización
        Schema::table('attendance_logs', function (Blueprint $table) {
            $table->index('factorial_employee_id');
            $table->index(['biometric_source_id', 'occurred_at']);
            $table->index(['client_id', 'sync_status']);
            $table->index(['client_id', 'employee_code']);
        });

        // biometric_user_syncs: búsquedas por PIN y cliente
        Schema::table('biometric_user_syncs', function (Blueprint $table) {
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
