<?php

namespace Tests\Feature;

use App\Jobs\SyncAttendanceToFactorial;
use App\Models\BiometricProvider;
use App\Models\BiometricSource;
use App\Models\BiometricUserSync;
use App\Models\Client;
use App\Models\FactorialConnection;
use App\Models\FactorialEmployee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class IclockAttendanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_unknown_auto_mode_attendance_is_saved_without_client_config_and_not_dispatched(): void
    {
        [$source] = $this->makeMappedSource('3205241');
        Queue::fake();

        $response = $this->call(
            'POST',
            '/iclock/cdata?SN=' . $source->serial_number . '&table=ATTLOG',
            [],
            [], [], [],
            "3205241\t2026-07-22 15:51:21\t255\t1\t0\t0\t0\t0\t0\t0\t1"
        );

        $response->assertOk()->assertSee('OK: 1');
        $this->assertDatabaseHas('attendance_logs', [
            'biometric_source_id' => $source->id,
            'employee_code' => '3205241',
            'check_type' => 'unknown',
            'sync_status' => 'pending',
        ]);
        Queue::assertNotPushed(SyncAttendanceToFactorial::class);
    }

    public function test_standard_status_uses_fallback_mapping_without_client_config(): void
    {
        [$source] = $this->makeMappedSource('3205242');
        Queue::fake();

        $this->call(
            'POST',
            '/iclock/cdata?SN=' . $source->serial_number . '&table=ATTLOG',
            [],
            [], [], [],
            "3205242\t2026-07-22 16:10:00\t0\t1\t0"
        )->assertOk()->assertSee('OK: 1');

        $this->assertDatabaseHas('attendance_logs', [
            'biometric_source_id' => $source->id,
            'employee_code' => '3205242',
            'check_type' => 'check_in',
            'sync_status' => 'resolved',
        ]);
        Queue::assertPushed(SyncAttendanceToFactorial::class);
    }

    private function makeMappedSource(string $pin): array
    {
        $client = Client::create(['name' => 'Attendance Client', 'slug' => 'attendance-' . str()->random(8)]);
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
            'name' => 'Attendance Device',
            'serial_number' => 'ATT-' . str()->random(8),
            'status' => 'active',
        ]);
        $employee = FactorialEmployee::create([
            'client_id' => $client->id,
            'factorial_connection_id' => $connection->id,
            'factorial_id' => (int) $pin,
            'company_id' => $client->id,
            'full_name' => 'Mapped Employee',
            'active' => true,
        ]);
        BiometricUserSync::create([
            'client_id' => $client->id,
            'biometric_provider_id' => $provider->id,
            'factorial_employee_id' => $employee->id,
            'external_employee_code' => $pin,
            'sync_status' => 'synced',
        ]);

        return [$source, $employee];
    }
}
