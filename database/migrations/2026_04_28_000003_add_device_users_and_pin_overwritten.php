<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('biometric_sources', function (Blueprint $table) {
            $table->json('device_users')->nullable()->after('settings');
            $table->timestamp('device_users_fetched_at')->nullable()->after('device_users');
        });

        Schema::table('biometric_user_syncs', function (Blueprint $table) {
            $table->boolean('pin_overwritten')->default(false)->after('sync_status');
        });
    }

    public function down(): void
    {
        Schema::table('biometric_sources', function (Blueprint $table) {
            $table->dropColumn(['device_users', 'device_users_fetched_at']);
        });

        Schema::table('biometric_user_syncs', function (Blueprint $table) {
            $table->dropColumn('pin_overwritten');
        });
    }
};
