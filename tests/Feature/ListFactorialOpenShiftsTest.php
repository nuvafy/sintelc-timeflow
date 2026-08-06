<?php

namespace Tests\Feature;

use App\Models\BiometricProvider;
use App\Models\BiometricUserSync;
use App\Models\Client;
use App\Models\FactorialConnection;
use App\Models\FactorialEmployee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ListFactorialOpenShiftsTest extends TestCase
{
    use RefreshDatabase;

    public function test_flags_shifts_open_since_before_today(): void
    {
        $client     = Client::create(['name' => 'Stale Client', 'slug' => 'stale-' . str()->random(8)]);
        $connection = FactorialConnection::create([
            'client_id'           => $client->id,
            'name'                => 'Connection',
            'resource_owner_type' => 'company',
            'access_token'        => 'test-token',
        ]);
        $provider = BiometricProvider::create([
            'client_id'               => $client->id,
            'factorial_connection_id' => $connection->id,
            'vendor'                  => 'zkteco',
            'status'                  => 'active',
        ]);
        $employee = FactorialEmployee::create([
            'client_id'               => $client->id,
            'factorial_connection_id' => $connection->id,
            'factorial_id'            => 111,
            'company_id'              => $client->id,
            'full_name'               => 'Juan Perez',
            'active'                  => true,
        ]);
        BiometricUserSync::create([
            'client_id'               => $client->id,
            'biometric_provider_id'   => $provider->id,
            'factorial_employee_id'   => $employee->id,
            'external_employee_code'  => '3205241',
            'sync_status'             => 'synced',
        ]);

        Http::fake([
            'https://api.factorialhr.com/api/2026-04-01/resources/attendance/shifts*' => Http::response([
                'data' => [[
                    'id'          => 900,
                    'employee_id' => 111,
                    'date'        => now()->subDays(3)->toDateString(),
                    'clock_in'    => '09:00:00',
                    'clock_out'   => null,
                ]],
                'meta' => ['total' => 1],
            ], 200),
        ]);

        $exitCode = Artisan::call('factorial:list-open-shifts');
        $output   = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Juan Perez', $output);
        $this->assertStringContainsString('3205241', $output);
        $this->assertStringContainsString('REVISAR', $output);
        $this->assertStringContainsString('llevan más de un día abiertos', $output);
    }

    public function test_todays_open_shift_is_not_flagged_as_stale(): void
    {
        $client     = Client::create(['name' => 'Active Client', 'slug' => 'active-' . str()->random(8)]);
        $connection = FactorialConnection::create([
            'client_id'           => $client->id,
            'name'                => 'Connection',
            'resource_owner_type' => 'company',
            'access_token'        => 'test-token',
        ]);
        FactorialEmployee::create([
            'client_id'               => $client->id,
            'factorial_connection_id' => $connection->id,
            'factorial_id'            => 222,
            'company_id'              => $client->id,
            'full_name'               => 'Ongoing Worker',
            'active'                  => true,
        ]);

        Http::fake([
            'https://api.factorialhr.com/api/2026-04-01/resources/attendance/shifts*' => Http::response([
                'data' => [[
                    'id'          => 901,
                    'employee_id' => 222,
                    'date'        => now()->toDateString(),
                    'clock_in'    => '09:00:00',
                    'clock_out'   => null,
                ]],
                'meta' => ['total' => 1],
            ], 200),
        ]);

        $exitCode = Artisan::call('factorial:list-open-shifts');
        $output   = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Ongoing Worker', $output);
        $this->assertStringNotContainsString('REVISAR', $output);
    }

    public function test_stale_only_option_hides_shifts_opened_today(): void
    {
        $client     = Client::create(['name' => 'Mixed Client', 'slug' => 'mixed-' . str()->random(8)]);
        $connection = FactorialConnection::create([
            'client_id'           => $client->id,
            'name'                => 'Connection',
            'resource_owner_type' => 'company',
            'access_token'        => 'test-token',
        ]);
        FactorialEmployee::create([
            'client_id'               => $client->id,
            'factorial_connection_id' => $connection->id,
            'factorial_id'            => 333,
            'company_id'              => $client->id,
            'full_name'               => 'Todays Worker',
            'active'                  => true,
        ]);

        Http::fake([
            'https://api.factorialhr.com/api/2026-04-01/resources/attendance/shifts*' => Http::response([
                'data' => [[
                    'id'          => 902,
                    'employee_id' => 333,
                    'date'        => now()->toDateString(),
                    'clock_in'    => '09:00:00',
                    'clock_out'   => null,
                ]],
                'meta' => ['total' => 1],
            ], 200),
        ]);

        $exitCode = Artisan::call('factorial:list-open-shifts', ['--stale-only' => true]);
        $output   = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Vía libre para desplegar', $output);
    }

    public function test_no_open_shifts_reports_clean(): void
    {
        $client = Client::create(['name' => 'Clean Client', 'slug' => 'clean-' . str()->random(8)]);
        FactorialConnection::create([
            'client_id'           => $client->id,
            'name'                => 'Connection',
            'resource_owner_type' => 'company',
            'access_token'        => 'test-token',
        ]);

        Http::fake([
            'https://api.factorialhr.com/api/2026-04-01/resources/attendance/shifts*' => Http::response([
                'data' => [],
                'meta' => ['total' => 0],
            ], 200),
        ]);

        $exitCode = Artisan::call('factorial:list-open-shifts');
        $output   = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Sin turnos abiertos.', $output);
    }

    public function test_client_option_scopes_to_a_single_client(): void
    {
        $target = Client::create(['name' => 'Target Client', 'slug' => 'target-' . str()->random(8)]);
        $other  = Client::create(['name' => 'Other Client', 'slug' => 'other-' . str()->random(8)]);

        FactorialConnection::create([
            'client_id' => $target->id, 'name' => 'C1', 'resource_owner_type' => 'company', 'access_token' => 'tok',
        ]);
        FactorialConnection::create([
            'client_id' => $other->id, 'name' => 'C2', 'resource_owner_type' => 'company', 'access_token' => 'tok',
        ]);

        Http::fake([
            'https://api.factorialhr.com/api/2026-04-01/resources/attendance/shifts*' => Http::response([
                'data' => [], 'meta' => ['total' => 0],
            ], 200),
        ]);

        $exitCode = Artisan::call('factorial:list-open-shifts', ['--client' => $target->slug]);
        $output   = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('TARGET CLIENT', $output);
        $this->assertStringNotContainsString('OTHER CLIENT', $output);
    }
}
