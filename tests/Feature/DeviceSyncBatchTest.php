<?php

namespace Tests\Feature;

use App\Models\BiometricProvider;
use App\Models\BiometricSource;
use App\Models\Client;
use App\Models\DeviceCommand;
use App\Models\DeviceSyncItem;
use App\Models\FactorialConnection;
use App\Models\FactorialEmployee;
use App\Models\User;
use App\Services\DeviceCommandLifecycleService;
use App\Services\DeviceInventoryService;
use App\Services\DeviceInfoService;
use App\Services\DeviceSyncBatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DeviceSyncBatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_reported_users_are_mapped_without_sending_a_duplicate_command(): void
    {
        [$client, , $source, $connection] = $this->makeSource();
        $employee = $this->makeEmployee($client, $connection, 101, 'Persona Factorial');

        $batch = app(DeviceSyncBatchService::class)->create($source, [
            [
                'action' => 'map_factorial',
                'pin' => '10',
                'name' => 'Persona Factorial',
                'factorial_employee_id' => $employee->id,
            ],
            [
                'action' => 'keep_local',
                'pin' => '20',
                'name' => 'Persona Local',
            ],
        ]);

        $this->assertSame('completed', $batch->status);
        $this->assertSame(2, $batch->confirmed_items);
        $this->assertSame(0, DeviceCommand::where('command_type', 'set_user')->count());
        $this->assertDatabaseHas('device_user_assignments', [
            'biometric_source_id' => $source->id,
            'pin' => '10',
            'sync_status' => 'confirmed',
        ]);
    }

    public function test_new_user_is_only_confirmed_after_ack_and_inventory_verification(): void
    {
        [$client, , $source, $connection] = $this->makeSource();
        $employee = $this->makeEmployee($client, $connection, 102, 'Nueva Persona');

        $batch = app(DeviceSyncBatchService::class)->create($source, [[
            'action' => 'add_factorial',
            'pin' => '30',
            'name' => 'Nueva Persona',
            'factorial_employee_id' => $employee->id,
        ]]);

        $command = DeviceCommand::where('command_type', 'set_user')->firstOrFail();
        $this->assertSame('processing', $batch->status);
        $this->assertSame('queued', $command->syncItem->status);
        $this->assertNull($source->fresh()->device_users);

        $command->update(['status' => 'sent', 'sent_at' => now()]);
        app(DeviceCommandLifecycleService::class)->markSent($command->fresh());
        $this->assertSame('sent', $command->syncItem->fresh()->status);

        $command->update(['status' => 'acknowledged', 'acknowledged_at' => now()]);
        app(DeviceCommandLifecycleService::class)->markAcknowledged($command->fresh(), true, 'Return=0');

        $this->assertSame('acknowledged', $command->syncItem->fresh()->status);
        $this->assertSame('awaiting_verification', $command->syncItem->assignment->fresh()->sync_status);
        $this->assertDatabaseHas('device_commands', [
            'biometric_source_id' => $source->id,
            'command_type' => 'query_users',
            'status' => 'pending',
        ]);

        app(DeviceInventoryService::class)->capture($source->fresh(), [
            ['pin' => '30', 'name' => 'Nueva Persona'],
        ]);

        $this->assertSame('confirmed', $command->syncItem->fresh()->status);
        $this->assertSame('confirmed', $command->syncItem->assignment->fresh()->sync_status);
        $this->assertSame('completed', $batch->fresh()->status);
        $this->assertSame('ready', $source->fresh()->onboarding_status);
    }

    public function test_device_rejection_marks_item_assignment_and_identity_as_failed(): void
    {
        [, , $source] = $this->makeSource();
        $batch = app(DeviceSyncBatchService::class)->create($source, [[
            'action' => 'add_local',
            'pin' => '40',
            'name' => 'Persona Local',
        ]]);
        $command = DeviceCommand::where('command_type', 'set_user')->firstOrFail();
        $command->update(['status' => 'failed', 'acknowledged_at' => now()]);

        app(DeviceCommandLifecycleService::class)->markAcknowledged(
            $command->fresh(),
            false,
            "ID={$command->command_seq}\nReturn=-1004"
        );

        $item = DeviceSyncItem::firstOrFail();
        $this->assertSame('failed', $item->status);
        $this->assertSame('failed', $item->assignment->sync_status);
        $this->assertSame('failed', $item->assignment->identity->sync_status);
        $this->assertSame('failed', $batch->fresh()->status);
        $this->assertStringContainsString('-1004', $item->error);
    }

    public function test_a_factorial_employee_from_another_client_cannot_be_assigned(): void
    {
        [, , $source] = $this->makeSource();
        [$otherClient, , , $otherConnection] = $this->makeSource();
        $otherEmployee = $this->makeEmployee($otherClient, $otherConnection, 999, 'Otro Cliente');

        $this->expectException(ValidationException::class);

        app(DeviceSyncBatchService::class)->create($source, [[
            'action' => 'add_factorial',
            'pin' => '50',
            'name' => 'Otro Cliente',
            'factorial_employee_id' => $otherEmployee->id,
        ]]);
    }

    public function test_client_can_open_the_wizard_for_its_own_device(): void
    {
        [$client, , $source] = $this->makeSource();
        $user = User::factory()->create(['role' => 'client', 'client_id' => $client->id]);

        $this->actingAs($user)
            ->get(route('devices.onboarding', $source))
            ->assertOk()
            ->assertSee('Configurar ' . $source->name);
    }

    public function test_client_cannot_open_another_clients_wizard(): void
    {
        [$client] = $this->makeSource();
        [, , $otherSource] = $this->makeSource();
        $user = User::factory()->create(['role' => 'client', 'client_id' => $client->id]);

        $this->actingAs($user)
            ->get(route('devices.onboarding', $otherSource))
            ->assertForbidden();
    }

    public function test_console_bulk_push_uses_a_verifiable_batch_without_faking_inventory(): void
    {
        [$client, , $source, $connection] = $this->makeSource();
        $this->makeEmployee($client, $connection, 303, 'Desde Consola');

        $this->artisan('biometric:push-users', ['sourceId' => $source->id])
            ->assertSuccessful();

        $this->assertDatabaseHas('device_sync_batches', [
            'client_id' => $client->id,
            'type' => 'bulk',
            'origin' => 'console',
            'status' => 'processing',
        ]);
        $this->assertDatabaseHas('device_commands', [
            'biometric_source_id' => $source->id,
            'command_type' => 'set_user',
            'status' => 'pending',
        ]);
        $this->assertNull($source->fresh()->device_users);
    }

    public function test_senseface_batch_is_confirmed_by_a_later_sufficient_info_count(): void
    {
        [, , $source] = $this->makeSource();
        $source->update(['device_name' => 'SenseFace 3A']);
        app(DeviceInventoryService::class)->capture($source->fresh(), [], 'confirmed_empty');

        $batch = app(DeviceSyncBatchService::class)->create($source->fresh(), [
            ['action' => 'add_local', 'pin' => '601', 'name' => 'Uno'],
            ['action' => 'add_local', 'pin' => '602', 'name' => 'Dos'],
        ]);

        foreach (DeviceCommand::where('command_type', 'set_user')->get() as $command) {
            $command->update(['status' => 'sent', 'sent_at' => now()]);
            app(DeviceCommandLifecycleService::class)->markSent($command->fresh());
            $command->update(['status' => 'acknowledged', 'acknowledged_at' => now()]);
            app(DeviceCommandLifecycleService::class)->markAcknowledged($command->fresh(), true, 'Return=0');
        }

        $this->travel(1)->seconds();
        app(DeviceInfoService::class)->capture($source->fresh(), 'ZAM70-Test,1,0,0,192.168.1.2');
        $this->assertSame(2, $batch->fresh()->pending_items);

        $this->travel(1)->seconds();
        app(DeviceInfoService::class)->capture($source->fresh(), 'ZAM70-Test,2,0,0,192.168.1.2');

        $this->assertSame('senseface_push', $source->fresh()->push_protocol_profile);
        $this->assertSame(2, $source->fresh()->reported_user_count);
        $this->assertSame('completed', $batch->fresh()->status);
        $this->assertSame(2, $batch->fresh()->confirmed_items);
        $this->assertDatabaseCount('device_sync_items', 2);
        $this->assertDatabaseMissing('device_sync_items', ['verification_method' => null]);
        $this->assertDatabaseHas('device_user_assignments', [
            'pin' => '601',
            'sync_status' => 'confirmed',
            'verification_method' => 'device_info_count',
        ]);
    }

    public function test_version_eight_firmware_uses_aggregate_info_verification(): void
    {
        [, , $source] = $this->makeSource();

        app(DeviceInfoService::class)->capture(
            $source,
            'Ver 8.0.4.7-20230726,372,645,1218,192.168.1.2'
        );

        $source->refresh();

        $this->assertSame('legacy_attendance_aggregate', $source->push_protocol_profile);
        $this->assertSame(372, $source->reported_user_count);
    }

    private function makeSource(): array
    {
        $client = Client::create([
            'name' => 'Batch Client',
            'slug' => 'batch-' . str()->random(8),
        ]);
        $connection = FactorialConnection::create([
            'client_id' => $client->id,
            'name' => 'Connection',
            'resource_owner_type' => 'company',
        ]);
        $provider = BiometricProvider::create([
            'client_id' => $client->id,
            'factorial_connection_id' => $connection->id,
            'vendor' => 'zkteco',
            'status' => 'active',
        ]);
        $source = BiometricSource::create([
            'client_id' => $client->id,
            'biometric_provider_id' => $provider->id,
            'name' => 'Batch Device',
            'serial_number' => 'BATCH-' . str()->random(8),
            'status' => 'active',
        ]);

        return [$client, $provider, $source, $connection];
    }

    private function makeEmployee(
        Client $client,
        FactorialConnection $connection,
        int $factorialId,
        string $name
    ): FactorialEmployee {
        return FactorialEmployee::create([
            'client_id' => $client->id,
            'factorial_connection_id' => $connection->id,
            'factorial_id' => $factorialId,
            'company_id' => $client->id,
            'full_name' => $name,
            'active' => true,
        ]);
    }
}
