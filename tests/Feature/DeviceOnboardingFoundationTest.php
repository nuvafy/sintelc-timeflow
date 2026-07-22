<?php

namespace Tests\Feature;

use App\Models\BiometricProvider;
use App\Models\BiometricSource;
use App\Models\BiometricUserSync;
use App\Models\Client;
use App\Models\DeviceCommand;
use App\Models\FactorialConnection;
use App\Models\FactorialEmployee;
use App\Services\DeviceInventoryService;
use App\Services\DeviceOnboardingService;
use App\Services\DeviceReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeviceOnboardingFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_device_inventory_is_captured_as_an_immutable_snapshot(): void
    {
        [, , $source] = $this->makeSource();
        $source->update(['onboarding_status' => 'querying_users']);

        $snapshot = app(DeviceInventoryService::class)->capture($source->fresh(), [
            ['pin' => ' 100 ', 'name' => "Alice\tExample", 'protocol' => 'attendance'],
            ['pin' => '100', 'name' => 'Alice Updated', 'protocol' => 'attendance'],
            ['pin' => '', 'name' => 'Invalid'],
        ]);

        $this->assertSame(1, $snapshot->user_count);
        $this->assertSame('100', $snapshot->users->first()->pin);
        $this->assertSame('Alice Updated', $snapshot->users->first()->name);
        $this->assertSame('needs_review', $source->fresh()->onboarding_status);
        $this->assertNotNull($source->fresh()->last_inventory_at);
    }

    public function test_reconciliation_classifies_factorial_local_and_unresolved_cases(): void
    {
        [$client, $provider, $source, $connection] = $this->makeSource();

        $mappedPresent = $this->makeEmployee($client, $connection, 101, 'Mapped Present');
        $mappedMissing = $this->makeEmployee($client, $connection, 102, 'Mapped Missing');
        $suggested = $this->makeEmployee($client, $connection, 103, 'Suggested Person');
        $factorialOnly = $this->makeEmployee($client, $connection, 104, 'Factorial Only');

        BiometricUserSync::create([
            'client_id' => $client->id,
            'biometric_provider_id' => $provider->id,
            'factorial_employee_id' => $mappedPresent->id,
            'external_employee_code' => '10',
            'sync_status' => 'synced',
        ]);
        BiometricUserSync::create([
            'client_id' => $client->id,
            'biometric_provider_id' => $provider->id,
            'factorial_employee_id' => $mappedMissing->id,
            'external_employee_code' => '20',
            'sync_status' => 'synced',
        ]);
        BiometricUserSync::create([
            'client_id' => $client->id,
            'biometric_provider_id' => $provider->id,
            'factorial_employee_id' => null,
            'external_employee_code' => '30',
            'local_name' => 'Local Person',
            'sync_status' => 'synced',
        ]);

        app(DeviceInventoryService::class)->capture($source, [
            ['pin' => '10', 'name' => 'Mapped Present'],
            ['pin' => '30', 'name' => 'Local Person'],
            ['pin' => '40', 'name' => 'Suggested Person'],
            ['pin' => '50', 'name' => 'Unknown Person'],
        ]);

        $analysis = app(DeviceReconciliationService::class)->analyze($source);

        $this->assertSame(1, $analysis['summary']['matched_factorial']);
        $this->assertSame(1, $analysis['summary']['matched_local']);
        $this->assertSame(1, $analysis['summary']['device_only_suggested']);
        $this->assertSame(1, $analysis['summary']['device_only']);
        $this->assertSame(1, $analysis['summary']['factorial_mapped_missing_on_device']);
        $this->assertSame(2, $analysis['summary']['factorial_only']);

        $suggestedRow = collect($analysis['rows'])->firstWhere('case', 'device_only_suggested');
        $this->assertSame($suggested->id, $suggestedRow['suggested_factorial_employee_id']);
        $this->assertNotSame($factorialOnly->id, $suggestedRow['suggested_factorial_employee_id']);
    }

    public function test_inventory_request_is_idempotent_while_a_query_is_active(): void
    {
        [, , $source] = $this->makeSource();

        $first = app(DeviceOnboardingService::class)->requestInventory($source);
        $second = app(DeviceOnboardingService::class)->requestInventory($source->fresh());

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, DeviceCommand::where('command_type', 'query_users')->count());
        $this->assertSame('querying_users', $source->fresh()->onboarding_status);
    }

    public function test_senseface_inventory_uses_security_push_user_table(): void
    {
        [, , $source] = $this->makeSource();
        $source->update(['device_name' => 'SenseFace 3A']);

        $command = app(DeviceOnboardingService::class)->requestInventory($source->fresh());

        $this->assertSame('DATA QUERY user', $command->payload);
    }

    private function makeSource(): array
    {
        $client = Client::create([
            'name' => 'Onboarding Client',
            'slug' => 'onboarding-' . str()->random(8),
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
            'name' => 'Test Device',
            'serial_number' => 'TEST-' . str()->random(8),
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
