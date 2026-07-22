<?php

namespace App\Console\Commands;

use App\Models\BiometricSource;
use App\Models\FactorialEmployee;
use App\Services\DeviceSyncBatchService;
use Illuminate\Console\Command;

class PushUsersToDevice extends Command
{
    protected $signature   = 'biometric:push-users {sourceId : ID del BiometricSource destino}';
    protected $description = 'Encola comandos USERINFO para enviar todos los empleados de Factorial al dispositivo biométrico';

    public function handle(): int
    {
        $sourceId = (int) $this->argument('sourceId');
        $source   = BiometricSource::find($sourceId);

        if (!$source) {
            $this->error("Dispositivo #{$sourceId} no encontrado.");
            return self::FAILURE;
        }

        if (!$source->client_id) {
            $this->error("El dispositivo no está asignado a ninguna empresa.");
            return self::FAILURE;
        }

        $employees = FactorialEmployee::where('client_id', $source->client_id)
            ->where('active', true)
            ->get();

        if ($employees->isEmpty()) {
            $this->warn("No hay empleados activos para el cliente #{$source->client_id}.");
            return self::SUCCESS;
        }

        $decisions = $employees->map(fn($employee) => [
            'action' => 'add_factorial',
            'pin' => (string) $employee->factorial_id,
            'name' => $employee->full_name,
            'factorial_employee_id' => $employee->id,
        ])->all();

        $batch = app(DeviceSyncBatchService::class)->create(
            $source, $decisions, null, 'bulk', 'console'
        );

        $this->info("Se encolaron {$employees->count()} usuarios para el dispositivo \"{$source->name}\" (SN: {$source->serial_number}).");
        $this->line("Lote: {$batch->uuid}");
        $this->line('Los usuarios se confirmarán únicamente después de releer el equipo.');

        return self::SUCCESS;
    }
}
