<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('factorial_employees', function (Blueprint $table) {
            $table->unsignedBigInteger('access_id')->nullable()->after('factorial_id');
            $table->string('login_email')->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('factorial_employees', function (Blueprint $table) {
            $table->dropColumn(['access_id', 'login_email']);
        });
    }
};
