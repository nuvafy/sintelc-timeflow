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
        $now     = now();

        foreach ($providers as $provider) {
            // Pre-cargar syncs existentes para este proveedor
            $existing = BiometricUserSync::where('biometric_provider_id', $provider->id)
                ->pluck('factorial_employee_id')
                ->flip();

            $toInsert = [];

            foreach ($employees as $employee) {
                if (!$employee->access_id) continue;
                if (isset($existing[$employee->id])) continue;

                $toInsert[] = [
                    'biometric_provider_id'  => $provider->id,
                    'factorial_employee_id'  => $employee->id,
                    'client_id'              => $clientId,
                    'external_employee_code' => (string) $employee->access_id,
                    'sync_status'            => 'pending',
                    'created_at'             => $now,
                    'updated_at'             => $now,
                ];
            }

            if (!empty($toInsert)) {
                BiometricUserSync::insert($toInsert);
                $created += count($toInsert);
            }
        }

        $this->info("Usuarios preparados: {$created}");

        return self::SUCCESS;
    }
}
