<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('device_commands', function (Blueprint $table) {
            $table->id();
            $table->foreignId('biometric_source_id')->constrained()->cascadeOnDelete();
            // ID secuencial que el equipo ZKTeco usa para rastrear el comando
            $table->unsignedInteger('command_seq');
            $table->string('command_type'); // set_user, delete_user, reboot
            // Línea completa que se envía al equipo (sin el prefijo C:{seq}:)
            $table->text('payload');
            $table->string('status')->default('pending'); // pending, sent, acknowledged, failed
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->text('device_response')->nullable();
            $table->timestamps();

            $table->index(['biometric_source_id', 'status']);
            $table->unique(['biometric_source_id', 'command_seq']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_commands');
    }
};
