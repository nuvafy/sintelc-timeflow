<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('biometric_sources', function (Blueprint $table) {
            $table->json('biodata_cache')->nullable()->after('device_users_fetched_at');
            $table->timestamp('biodata_cached_at')->nullable()->after('biodata_cache');
            $table->unsignedBigInteger('clone_target_id')->nullable()->after('biodata_cached_at');
        });
    }

    public function down(): void
    {
        Schema::table('biometric_sources', function (Blueprint $table) {
            $table->dropColumn(['biodata_cache', 'biodata_cached_at', 'clone_target_id']);
        });
    }
};
