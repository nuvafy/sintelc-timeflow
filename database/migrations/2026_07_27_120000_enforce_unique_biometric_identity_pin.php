<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('biometric_user_syncs', function (Blueprint $table) {
            $table->unique(
                ['biometric_provider_id', 'external_employee_code'],
                'biometric_user_syncs_provider_pin_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('biometric_user_syncs', function (Blueprint $table) {
            $table->dropUnique('biometric_user_syncs_provider_pin_unique');
        });
    }
};
