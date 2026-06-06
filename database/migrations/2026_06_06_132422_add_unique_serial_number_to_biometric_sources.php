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
        Schema::table('biometric_sources', function (Blueprint $table) {
            $table->unique('serial_number');
        });
    }

    public function down(): void
    {
        Schema::table('biometric_sources', function (Blueprint $table) {
            $table->dropUnique(['serial_number']);
        });
    }
};
