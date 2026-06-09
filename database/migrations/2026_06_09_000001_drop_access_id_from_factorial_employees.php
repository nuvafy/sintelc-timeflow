<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('factorial_employees', function (Blueprint $table) {
            // El índice fue creado en 2026_05_25_181649_add_performance_indexes.php
            $table->dropIndex(['access_id']);
            $table->dropColumn('access_id');
        });
    }

    public function down(): void
    {
        Schema::table('factorial_employees', function (Blueprint $table) {
            $table->unsignedBigInteger('access_id')->nullable()->after('factorial_id');
            $table->index('access_id');
        });
    }
};
