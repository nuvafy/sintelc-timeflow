<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // 1. Agregar campos a clients
        Schema::table('clients', function (Blueprint $table) {
            $table->string('oauth_client_id')->nullable()->after('slug');
            $table->text('oauth_client_secret')->nullable()->after('oauth_client_id');
            $table->string('hq_address')->nullable()->after('oauth_client_secret');
            $table->string('contact_email')->nullable()->after('hq_address');
        });

        // 2. Migrar datos existentes de connections → clients
        DB::table('factorial_connections')
            ->whereNotNull('client_id')
            ->get()
            ->each(function ($conn) {
                DB::table('clients')->where('id', $conn->client_id)->update([
                    'oauth_client_id'     => $conn->oauth_client_id,
                    'oauth_client_secret' => $conn->oauth_client_secret,
                    'contact_email'       => $conn->contact_email ?? null,
                ]);
            });

        // 3. Limpiar factorial_connections
        Schema::table('factorial_connections', function (Blueprint $table) {
            $table->dropColumn(['oauth_client_id', 'oauth_client_secret', 'contact_email']);
        });
    }

    public function down(): void
    {
        Schema::table('factorial_connections', function (Blueprint $table) {
            $table->string('oauth_client_id')->nullable()->after('name');
            $table->text('oauth_client_secret')->nullable()->after('oauth_client_id');
            $table->string('contact_email')->nullable()->after('oauth_client_secret');
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn(['oauth_client_id', 'oauth_client_secret', 'hq_address', 'contact_email']);
        });
    }
};
