<?php

namespace Tests\Feature;

use App\Models\BiometricProvider;
use App\Models\BiometricSource;
use App\Models\BiometricUserSync;
use App\Models\Client;
use App\Models\DeviceCommand;
use App\Models\DeviceUserAssignment;
use App\Models\FactorialConnection;
use App\Services\DeviceEmployeeRefreshService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeviceEmployeeRefreshTest extends TestCase
{
    use RefreshDatabase;

    public function test_refresh_only_queues_missing_or_changed_assigned_employees(): void
    {
        $client = Client::create(['name' => 'Refresh Client', 'slug' => 'refresh-client']);
        $connection = FactorialConnection::create([
            'client_id' => $client->id,
            'name' => 'Refresh Connection',
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
            'name' => 'Refresh Device',
            'serial_number' => 'REFRESH-SN',
            'status' => 'active',
            'device_users' => [
                ['pin' => '100', 'name' => 'PERSONA CORRECTA'],
                ['pin' => '200', 'name' => 'Nombre anterior'],
            ],
        ]);

        $this->assignment($client, $provider, $source, '100', 'Persona Correcta');
        $this->assignment($client, $provider, $source, '200', 'Persona Actualizada');
        $this->assignment($client, $provider, $source, '300', 'Persona Faltante');
        $source->update(['device_users_fetched_at' => now()->addSecond()]);

        $result = app(DeviceEmployeeRefreshService::class)->refresh($source->fresh());

        $this->assertSame(3, $result['assigned']);
        $this->assertSame(2, $result['queued']);
        $this->assertSame(1, $result['unchanged']);
        $this->assertSame(0, $result['active']);
        $this->assertSame(
            ['200', '300'],
            DeviceCommand::where('command_type', 'set_user')
                ->orderBy('id')
                ->get()
                ->map(fn($command) => preg_match('/PIN=(\d+)/', $command->payload, $matches) ? $matches[1] : null)
                ->all()
        );
    }

    public function test_refresh_does_not_duplicate_an_active_employee_operation(): void
    {
        $client = Client::create(['name' => 'Active Client', 'slug' => 'active-client']);
        $connection = FactorialConnection::create([
            'client_id' => $client->id,
            'name' => 'Active Connection',
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
            'name' => 'Active Device',
            'serial_number' => 'ACTIVE-SN',
            'status' => 'active',
            'device_users' => [],
        ]);
        $assignment = $this->assignment($client, $provider, $source, '400', 'Persona Activa');
        $assignment->update(['sync_status' => 'queued']);

        $result = app(DeviceEmployeeRefreshService::class)->refresh($source);

        $this->assertSame(0, $result['queued']);
        $this->assertSame(1, $result['active']);
        $this->assertSame(0, DeviceCommand::count());
    }

    public function test_refresh_trusts_confirmed_assignments_when_individual_inventory_is_stale(): void
    {
        $client = Client::create(['name' => 'Stale Client', 'slug' => 'stale-client']);
        $connection = FactorialConnection::create([
            'client_id' => $client->id,
            'name' => 'Stale Connection',
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
            'name' => 'Stale Device',
            'serial_number' => 'STALE-SN',
            'status' => 'active',
            'device_users' => [],
            'device_users_fetched_at' => now()->subDay(),
        ]);
        $this->assignment($client, $provider, $source, '500', 'Persona Confirmada');

        $result = app(DeviceEmployeeRefreshService::class)->refresh($source->fresh());

        $this->assertSame(0, $result['queued']);
        $this->assertSame(1, $result['unchanged']);
        $this->assertSame(0, DeviceCommand::count());
    }

    private function assignment(
        Client $client,
        BiometricProvider $provider,
        BiometricSource $source,
        string $pin,
        string $name
    ): DeviceUserAssignment {
        $identity = BiometricUserSync::create([
            'client_id' => $client->id,
            'biometric_provider_id' => $provider->id,
            'external_employee_code' => $pin,
            'local_name' => $name,
            'sync_status' => 'synced',
        ]);

        return DeviceUserAssignment::create([
            'client_id' => $client->id,
            'biometric_source_id' => $source->id,
            'biometric_user_sync_id' => $identity->id,
            'pin' => $pin,
            'name' => $name,
            'desired_state' => 'present',
            'sync_status' => 'confirmed',
            'confirmed_at' => now(),
        ]);
    }
}
