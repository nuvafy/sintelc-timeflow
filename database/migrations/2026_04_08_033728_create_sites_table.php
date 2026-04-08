<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->string('code')->nullable();
            $table->unsignedBigInteger('external_location_id')->nullable();
            $table->string('status')->default('active');

            $table->timestamps();

            $table->unique(['client_id', 'name'], 'sites_client_name_unique');
            $table->unique(['client_id', 'external_location_id'], 'sites_client_external_location_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sites');
    }
};
