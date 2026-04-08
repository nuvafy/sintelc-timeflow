<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('factorial_employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('factorial_connection_id')->constrained()->cascadeOnDelete();

            $table->unsignedBigInteger('factorial_id');
            $table->unsignedBigInteger('access_id')->nullable();

            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('full_name')->nullable();
            $table->string('email')->nullable()->index();
            $table->string('login_email')->nullable()->index();

            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('company_identifier')->nullable()->index();
            $table->unsignedBigInteger('location_id')->nullable()->index();

            $table->boolean('active')->default(true);
            $table->boolean('attendable')->default(false);
            $table->boolean('is_terminating')->default(false);
            $table->timestamp('terminated_on')->nullable();

            $table->timestamp('factorial_created_at')->nullable();
            $table->timestamp('factorial_updated_at')->nullable();

            $table->json('raw_payload')->nullable();

            $table->timestamps();

            $table->unique(['factorial_connection_id', 'factorial_id'], 'factorial_employees_connection_employee_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('factorial_employees');
    }
};
