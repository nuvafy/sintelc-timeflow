<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('biometric_providers', function (Blueprint $table) {
            $table->id();

            $table->foreignId('client_id')->constrained()->cascadeOnDelete();

            $table->string('vendor'); // zkteco, hikvision, etc.
            $table->string('name');
            $table->string('base_url')->nullable();
            $table->json('credentials')->nullable();
            $table->string('status')->default('active');

            $table->timestamps();

            $table->index(['client_id', 'vendor']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('biometric_providers');
    }
};
