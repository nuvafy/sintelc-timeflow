<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('biometric_sources', function (Blueprint $table) {
            $table->string('onboarding_status')->default('ready')->after('status');
            $table->timestamp('onboarding_started_at')->nullable()->after('onboarding_status');
            $table->timestamp('onboarding_completed_at')->nullable()->after('onboarding_started_at');
            $table->timestamp('last_inventory_at')->nullable()->after('device_users_fetched_at');
            $table->text('onboarding_error')->nullable()->after('onboarding_completed_at');
            $table->index(['client_id', 'onboarding_status']);
        });

        Schema::create('device_inventory_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('biometric_source_id')->constrained()->cascadeOnDelete();
            $table->string('origin'); // device | csv | legacy
            $table->string('status')->default('complete');
            $table->unsignedInteger('user_count')->default(0);
            $table->timestamp('captured_at');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['biometric_source_id', 'captured_at']);
        });

        Schema::create('device_inventory_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_inventory_snapshot_id')->constrained()->cascadeOnDelete();
            $table->foreignId('biometric_source_id')->constrained()->cascadeOnDelete();
            $table->string('pin');
            $table->string('name')->nullable();
            $table->string('card')->nullable();
            $table->string('privilege')->nullable();
            $table->string('protocol')->nullable();
            $table->json('raw_data')->nullable();
            $table->timestamps();

            $table->unique(['device_inventory_snapshot_id', 'pin'], 'device_inventory_snapshot_pin_unique');
            $table->index(['biometric_source_id', 'pin']);
        });

        Schema::create('device_user_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('biometric_source_id')->constrained()->cascadeOnDelete();
            $table->foreignId('biometric_user_sync_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('factorial_employee_id')->nullable()->constrained()->nullOnDelete();
            $table->string('pin');
            $table->string('name');
            $table->string('desired_state')->default('present');
            $table->string('sync_status')->default('planned');
            $table->timestamp('confirmed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->unique(['biometric_source_id', 'pin']);
            $table->index(['client_id', 'sync_status']);
            $table->index(['biometric_source_id', 'desired_state', 'sync_status'], 'device_assignment_reconcile_index');
        });

        Schema::create('device_sync_batches', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type'); // onboarding | bulk | reconciliation | restore
            $table->string('origin')->default('manual'); // manual | csv | factorial | sintelc
            $table->string('status')->default('draft');
            $table->unsignedInteger('total_items')->default(0);
            $table->unsignedInteger('confirmed_items')->default(0);
            $table->unsignedInteger('failed_items')->default(0);
            $table->unsignedInteger('pending_items')->default(0);
            $table->json('options')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['client_id', 'status']);
        });

        Schema::create('device_sync_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_sync_batch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('biometric_source_id')->constrained()->cascadeOnDelete();
            $table->foreignId('device_user_assignment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('factorial_employee_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action'); // create | update | delete | map | ignore
            $table->string('pin');
            $table->string('name');
            $table->boolean('syncs_with_factorial')->default(false);
            $table->string('status')->default('planned');
            $table->text('error')->nullable();
            $table->timestamps();

            $table->unique(
                ['device_sync_batch_id', 'biometric_source_id', 'pin', 'action'],
                'device_sync_item_idempotency_unique'
            );
            $table->index(['device_sync_batch_id', 'status']);
        });

        Schema::table('device_commands', function (Blueprint $table) {
            $table->foreignId('device_sync_item_id')->nullable()->after('biometric_source_id')
                ->constrained()->nullOnDelete();
            $table->string('idempotency_key')->nullable()->after('command_type')->unique();
        });
    }

    public function down(): void
    {
        Schema::table('device_commands', function (Blueprint $table) {
            $table->dropConstrainedForeignId('device_sync_item_id');
            $table->dropUnique(['idempotency_key']);
            $table->dropColumn('idempotency_key');
        });

        Schema::dropIfExists('device_sync_items');
        Schema::dropIfExists('device_sync_batches');
        Schema::dropIfExists('device_user_assignments');
        Schema::dropIfExists('device_inventory_users');
        Schema::dropIfExists('device_inventory_snapshots');

        Schema::table('biometric_sources', function (Blueprint $table) {
            $table->dropIndex(['client_id', 'onboarding_status']);
            $table->dropColumn([
                'onboarding_status',
                'onboarding_started_at',
                'onboarding_completed_at',
                'last_inventory_at',
                'onboarding_error',
            ]);
        });
    }
};
