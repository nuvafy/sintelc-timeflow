<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('employee_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('biometric_source_id')->constrained()->cascadeOnDelete();
            $table->string('biometric_employee_code');
            $table->string('factorial_employee_id');
            $table->string('factorial_identifier')->nullable();
            $table->timestamps();

            $table->unique(
                ['biometric_source_id', 'biometric_employee_code'],
                'employee_mappings_biometric_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_mappings');
    }
};
