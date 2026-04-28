<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('factorial_connections', function (Blueprint $table) {
            $table->dropForeign(['client_id']);
            $table->foreignId('client_id')->nullable()->change();
            $table->foreign('client_id')->references('id')->on('clients')->nullOnDelete();

            $table->unsignedBigInteger('factorial_company_id')->nullable()->change();

            $table->string('contact_email')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('factorial_connections', function (Blueprint $table) {
            $table->dropColumn('contact_email');

            $table->dropForeign(['client_id']);
            $table->foreignId('client_id')->nullable(false)->change();
            $table->foreign('client_id')->references('id')->on('clients')->cascadeOnDelete();

            $table->unsignedBigInteger('factorial_company_id')->nullable(false)->change();
        });
    }
};
