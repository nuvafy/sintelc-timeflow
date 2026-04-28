<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('biometric_sources', function (Blueprint $table) {
            // Drop existing FKs antes de cambiar nullability
            $table->dropForeign(['client_id']);
            $table->dropForeign(['biometric_provider_id']);

            $table->foreignId('client_id')->nullable()->change();
            $table->foreignId('biometric_provider_id')->nullable()->change();

            $table->foreign('client_id')->references('id')->on('clients')->cascadeOnDelete();
            $table->foreign('biometric_provider_id')->references('id')->on('biometric_providers')->cascadeOnDelete();

            $table->timestamp('last_ping_at')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('biometric_sources', function (Blueprint $table) {
            $table->dropColumn('last_ping_at');

            $table->dropForeign(['client_id']);
            $table->dropForeign(['biometric_provider_id']);

            $table->foreignId('client_id')->nullable(false)->change();
            $table->foreignId('biometric_provider_id')->nullable(false)->change();

            $table->foreign('client_id')->references('id')->on('clients')->cascadeOnDelete();
            $table->foreign('biometric_provider_id')->references('id')->on('biometric_providers')->cascadeOnDelete();
        });
    }
};
