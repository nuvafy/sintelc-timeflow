<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('biometric_user_syncs', function (Blueprint $table) {
            // Make factorial_employee_id nullable to support local-only employees
            $table->unsignedBigInteger('factorial_employee_id')->nullable()->change();
            $table->string('local_name')->nullable()->after('external_employee_code');
        });
    }

    public function down(): void
    {
        Schema::table('biometric_user_syncs', function (Blueprint $table) {
            $table->dropColumn('local_name');
            $table->unsignedBigInteger('factorial_employee_id')->nullable(false)->change();
        });
    }
};
