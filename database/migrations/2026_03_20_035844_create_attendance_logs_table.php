<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('attendance_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('biometric_source_id')->constrained()->cascadeOnDelete();
            $table->string('external_event_id')->nullable();
            $table->string('employee_code');
            $table->string('check_type')->nullable();
            $table->timestamp('occurred_at');
            $table->json('raw_payload')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->string('sync_status')->default('pending');
            $table->text('sync_error')->nullable();
            $table->timestamps();

            $table->index(['client_id', 'sync_status']);
            $table->index(['biometric_source_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_logs');
    }
};
