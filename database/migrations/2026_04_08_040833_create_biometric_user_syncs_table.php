<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('biometric_user_syncs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('biometric_provider_id')->constrained()->cascadeOnDelete();
            $table->foreignId('factorial_employee_id')->constrained()->cascadeOnDelete();
            $table->string('external_employee_code');
            $table->string('provider_user_id')->nullable();
            $table->string('sync_status')->default('pending');
            $table->text('sync_error')->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->json('raw_request')->nullable();
            $table->json('raw_response')->nullable();
            $table->timestamps();

            $table->unique(
                ['biometric_provider_id', 'factorial_employee_id'],
                'biometric_user_syncs_provider_employee_unique'
            );
            $table->index('external_employee_code');
            $table->index('sync_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('biometric_user_syncs');
    }
};
