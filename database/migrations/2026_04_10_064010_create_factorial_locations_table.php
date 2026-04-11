<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('factorial_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('factorial_connection_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('factorial_location_id');
            $table->unsignedBigInteger('factorial_company_id');
            $table->string('name');
            $table->timestamps();

            $table->unique(['factorial_connection_id', 'factorial_location_id']);
            $table->index(['client_id', 'factorial_connection_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('factorial_locations');
    }
};
