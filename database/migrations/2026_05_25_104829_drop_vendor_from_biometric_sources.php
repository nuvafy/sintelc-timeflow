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
            // El índice compuesto (client_id, vendor) es el único que
            // respalda la llave foránea de client_id. Hay que darle un
            // índice de reemplazo antes de poder borrarlo.
            $table->index('client_id', 'biometric_sources_client_id_index');
        });

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
            $table->dropIndex('biometric_sources_client_id_index');
        });
    }
};