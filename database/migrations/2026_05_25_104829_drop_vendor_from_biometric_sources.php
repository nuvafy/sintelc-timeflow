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
            $table->dropIndex('biometric_sources_client_id_vendor_index');
        });

        Schema::table('biometric_sources', function (Blueprint $table) {
            $table->dropColumn('vendor');
        });
    }

    public function down(): void
    {
        Schema::table('biometric_sources', function (Blueprint $table) {
            $table->string('vendor')->nullable()->after('name');
        });

        Schema::table('biometric_sources', function (Blueprint $table) {
            $table->index(['client_id', 'vendor']);
        });
    }
};
