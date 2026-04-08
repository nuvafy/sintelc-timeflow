<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\BiometricProvider;
use App\Models\BiometricUserSync;
use App\Models\FactorialEmployee;

class PrepareBiometricUsers extends Command
{
    protected $signature = 'biometric:prepare-users {clientId}';

    protected $description = 'Prepara usuarios de Factorial para sincronizar con proveedores biométricos';

    public function handle(): int
    {
        $clientId = (int) $this->argument('clientId');

        $providers = BiometricProvider::where('client_id', $clientId)->get();

        if ($providers->isEmpty()) {
            $this->warn("No hay biometric providers para client_id {$clientId}");
            return self::SUCCESS;
        }

        $employees = FactorialEmployee::where('client_id', $clientId)->get();

        if ($employees->isEmpty()) {
            $this->warn("No hay empleados para client_id {$clientId}");
            return self::SUCCESS;
        }

        $created = 0;

        foreach ($providers as $provider) {
            foreach ($employees as $employee) {

                $sync = BiometricUserSync::firstOrCreate(
                    [
                        'biometric_provider_id' => $provider->id,
                        'factorial_employee_id' => $employee->id,
                    ],
                    [
                        'client_id' => $clientId,
                        'external_employee_code' => $employee->company_identifier,
                        'sync_status' => 'pending',
                    ]
                );

                if ($sync->wasRecentlyCreated) {
                    $created++;
                }
            }
        }

        $this->info("Usuarios preparados: {$created}");

        return self::SUCCESS;
    }
}
