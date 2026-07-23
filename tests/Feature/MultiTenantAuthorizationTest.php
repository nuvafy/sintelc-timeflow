<?php

namespace Tests\Feature;

use App\Models\BiometricProvider;
use App\Models\BiometricSource;
use App\Models\Client;
use App\Models\DeviceCommand;
use App\Models\DeviceUserAssignment;
use App\Models\FactorialConnection;
use App\Models\FactorialEmployee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class MultiTenantAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_cannot_mutate_another_clients_device(): void
    {
        [$firstClient, $firstUser] = $this->makeClient('first');
        [$secondClient] = $this->makeClient('second');
        $device = $this->makeDevice($secondClient, 'SECOND-SN');

        $this->actingAs($firstUser);

        Livewire::test('devices.device-manager')
            ->call('toggleStatus', $device->id)
            ->assertForbidden();

        $this->assertSame('active', $device->fresh()->status);
    }

    public function test_client_cannot_delete_another_clients_device(): void
    {
        [, $firstUser] = $this->makeClient('first');
        [$secondClient] = $this->makeClient('second');
        $device = $this->makeDevice($secondClient, 'SECOND-SN');

        $this->actingAs($firstUser);

        Livewire::test('devices.device-manager')
            ->call('delete', $device->id)
            ->assertForbidden();

        $this->assertDatabaseHas('biometric_sources', ['id' => $device->id]);
    }

    public function test_client_cannot_select_another_tenant_in_employee_manager(): void
    {
        [$firstClient, $firstUser] = $this->makeClient('first');
        [$secondClient] = $this->makeClient('second');

        $this->actingAs($firstUser);

        Livewire::test('employees.employee-sync-manager')
            ->set('client_id', $secondClient->id)
            ->assertForbidden();

        $this->assertNotSame($firstClient->id, $secondClient->id);
    }

    public function test_client_cannot_map_an_employee_from_another_tenant(): void
    {
        [$firstClient, $firstUser] = $this->makeClient('first');
        [$secondClient] = $this->makeClient('second');
        $this->makeDevice($firstClient, 'FIRST-SN');
        $foreignEmployee = $this->makeEmployee($secondClient, 9002);

        $this->actingAs($firstUser);

        Livewire::test('employees.employee-sync-manager')
            ->call('saveBiometricId', $foreignEmployee->id, '200')
            ->assertForbidden();

        $this->assertDatabaseMissing('biometric_user_syncs', [
            'factorial_employee_id' => $foreignEmployee->id,
            'external_employee_code' => '200',
        ]);
    }

    public function test_adding_to_an_offline_device_is_queued_without_waiting_for_inventory(): void
    {
        [$client, $user] = $this->makeClient('add-local');
        $device = $this->makeDevice($client, 'LEGACY-SN');
        $device->update([
            'device_users' => [
                ['pin' => '522', 'name' => 'Existing User'],
            ],
            'device_firmware' => 'Ver 8.0.4.7-20230726',
        ]);

        $this->actingAs($user);

        Livewire::test('employees.employee-sync-manager')
            ->set('addName', 'Nueva Persona')
            ->call('startAddEmployee')
            ->assertSet('addStep', 5)
            ->assertSet('addPin', '523');

        $this->assertSame(0, DeviceCommand::where('command_type', 'query_users')->count());
        $this->assertDatabaseHas('device_commands', [
            'biometric_source_id' => $device->id,
            'command_type' => 'set_user',
            'status' => 'pending',
        ]);
    }

    public function test_online_device_registration_becomes_pending_after_thirty_seconds(): void
    {
        [$client, $user] = $this->makeClient('online-pending');
        $this->makeDevice($client, 'ONLINE-SN')->update(['last_ping_at' => now()]);
        $this->actingAs($user);

        $component = Livewire::test('employees.employee-sync-manager')
            ->set('addName', 'Persona Pendiente')
            ->call('startAddEmployee')
            ->assertSet('addStep', 3);

        $this->travel(31)->seconds();

        $component->call('pollAddEmployee')
            ->assertSet('addStep', 5);

        $this->assertDatabaseHas('device_user_assignments', [
            'client_id' => $client->id,
            'name' => 'Persona Pendiente',
            'sync_status' => 'queued',
        ]);
        $this->assertSame(1, DeviceUserAssignment::where('sync_status', 'queued')->count());
    }

    private function makeClient(string $slug): array
    {
        $client = Client::create([
            'name' => $slug,
            'slug' => $slug,
            'status' => 'active',
        ]);

        $user = User::factory()->create([
            'role' => 'client',
            'client_id' => $client->id,
        ]);

        return [$client, $user];
    }

    private function makeConnection(Client $client): FactorialConnection
    {
        return FactorialConnection::create([
            'client_id' => $client->id,
            'name' => "connection-{$client->id}",
            'resource_owner_type' => 'company',
        ]);
    }

    private function makeDevice(Client $client, string $serial): BiometricSource
    {
        $connection = $this->makeConnection($client);
        $provider = BiometricProvider::create([
            'client_id' => $client->id,
            'factorial_connection_id' => $connection->id,
            'vendor' => 'zkteco',
            'status' => 'active',
        ]);

        return BiometricSource::create([
            'client_id' => $client->id,
            'biometric_provider_id' => $provider->id,
            'name' => $serial,
            'serial_number' => $serial,
            'status' => 'active',
        ]);
    }

    private function makeEmployee(Client $client, int $factorialId): FactorialEmployee
    {
        $connection = $this->makeConnection($client);

        return FactorialEmployee::create([
            'client_id' => $client->id,
            'factorial_connection_id' => $connection->id,
            'factorial_id' => $factorialId,
            'company_id' => $client->id,
            'full_name' => 'Foreign Employee',
        ]);
    }
}
