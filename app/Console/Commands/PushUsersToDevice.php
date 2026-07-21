<?php

namespace App\Console\Commands;

use App\Models\BiometricSource;
use App\Models\BiometricUserSync;
use App\Models\DeviceCommand;
use App\Models\FactorialEmployee;
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

        $maxSeq  = DeviceCommand::where('biometric_source_id', $source->id)->max('command_seq') ?? 0;
        $now     = now();
        $inserts = [];

        // USERINFO (Attendance PUSH Protocol) funciona en todos los modelos probados
        // incluyendo Ver 2.x (UEED), Ver 3.0.x (VGU/NQN) y posteriores.
        // device_name se captura del handshake para referencia futura.
        foreach ($employees as $i => $employee) {
            $seq  = $maxSeq + $i + 1;
            $pin  = $employee->factorial_id;
            $name = mb_substr($employee->full_name, 0, 24);

            $payload = "DATA UPDATE USERINFO PIN={$pin}\tName={$name}\tPassword=\tPrivilege=0\tGroup=1";

            $inserts[] = [
                'biometric_source_id' => $source->id,
                'command_seq'         => $seq,
                'command_type'        => 'set_user',
                'payload'             => $payload,
                'status'              => 'pending',
                'created_at'          => $now,
                'updated_at'          => $now,
            ];
        }

        DeviceCommand::insert($inserts);

        // Actualizar device_users en DB (Security PUSH no soporta QUERY).
        $source->update([
            'device_users'            => $employees->map(fn($e) => [
                'pin'  => (string) $e->factorial_id,
                'name' => mb_substr($e->full_name, 0, 24),
            ])->toArray(),
            'device_users_fetched_at' => $now,
        ]);

        // Crear/actualizar mappings: factorial_id → empleado.
        // Upsert por (biometric_provider_id, factorial_employee_id) para que
        // un re-push actualice el external_employee_code si cambió el PIN.
        $mappings = $employees->map(fn($e) => [
            'biometric_provider_id'  => $source->biometric_provider_id,
            'factorial_employee_id'  => $e->id,
            'client_id'              => $source->client_id,
            'external_employee_code' => (string) $e->factorial_id,
            'sync_status'            => 'synced',
            'created_at'             => $now,
            'updated_at'             => $now,
        ])->toArray();

        BiometricUserSync::upsert(
            $mappings,
            ['biometric_provider_id', 'factorial_employee_id'],
            ['external_employee_code', 'sync_status', 'updated_at']
        );

        $this->info("Se encolaron {$employees->count()} usuarios para el dispositivo \"{$source->name}\" (SN: {$source->serial_number}).");
        $this->line("Mappings actualizados: {$employees->count()} empleados → PIN = factorial_id.");
        $this->line("El equipo los recibirá en su próxima llamada a /iclock/getrequest.");

        return self::SUCCESS;
    }
}
