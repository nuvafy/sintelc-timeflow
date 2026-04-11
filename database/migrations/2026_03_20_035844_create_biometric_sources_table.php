<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('biometric_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('biometric_provider_id')->constrained()->cascadeOnDelete();
            $table->foreignId('factorial_location_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();
            $table->string('name');
            $table->string('vendor')->default('zkteco');
            $table->string('device_code')->nullable();
            $table->string('serial_number')->nullable();
            $table->string('site_name')->nullable();
            $table->json('settings')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();

            $table->index(['client_id', 'vendor']);
            $table->unique(
                ['biometric_provider_id', 'device_code'],
                'biometric_sources_provider_device_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('biometric_sources');
    }
};
