<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('biometric_sources', function (Blueprint $table) {
            $table->string('push_protocol_profile')->nullable()->after('push_version');
            $table->string('push_protocol_source')->nullable()->after('push_protocol_profile');
            $table->timestamp('push_protocol_detected_at')->nullable()->after('push_protocol_source');
            $table->string('device_firmware')->nullable()->after('device_name');
            $table->unsignedInteger('reported_user_count')->nullable()->after('device_firmware');
            $table->unsignedInteger('reported_fingerprint_count')->nullable()->after('reported_user_count');
            $table->unsignedInteger('reported_face_count')->nullable()->after('reported_fingerprint_count');
            $table->timestamp('device_info_reported_at')->nullable()->after('reported_face_count');
            $table->json('device_info_payload')->nullable()->after('device_info_reported_at');
        });

        Schema::table('device_user_assignments', function (Blueprint $table) {
            $table->string('verification_method')->nullable()->after('sync_status');
        });

        Schema::table('device_sync_items', function (Blueprint $table) {
            $table->string('verification_method')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('device_sync_items', fn(Blueprint $table) => $table->dropColumn('verification_method'));
        Schema::table('device_user_assignments', fn(Blueprint $table) => $table->dropColumn('verification_method'));
        Schema::table('biometric_sources', function (Blueprint $table) {
            $table->dropColumn([
                'push_protocol_profile', 'push_protocol_source', 'push_protocol_detected_at',
                'device_firmware', 'reported_user_count', 'reported_fingerprint_count',
                'reported_face_count', 'device_info_reported_at', 'device_info_payload',
            ]);
        });
    }
};
