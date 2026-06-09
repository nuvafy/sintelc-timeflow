<?php

namespace App\Console\Commands;

use App\Models\BiometricSource;
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
            ->whereNotNull('access_id')
            ->where('active', true)
            ->get();

        if ($employees->isEmpty()) {
            $this->warn("No hay empleados activos con PIN para el cliente #{$source->client_id}.");
            return self::SUCCESS;
        }

        $maxSeq  = DeviceCommand::where('biometric_source_id', $source->id)->max('command_seq') ?? 0;
        $now     = now();
        $inserts = [];

        foreach ($employees as $i => $employee) {
            $seq     = $maxSeq + $i + 1;
            $pin     = $employee->access_id;
            $name    = mb_substr($employee->full_name, 0, 24);
            // Security PUSH Protocol (dispositivos ZKTeco VGU/face recognition).
            // Tabla: "user" (no USERINFO). Campos: CardNo, Pin, Password, Group,
            // StartTime, EndTime, Name, Privilege.
            // -629 = "Incorrect table name" → ocurría porque usábamos USERINFO
            // que es del Attendance PUSH Protocol, no del Security PUSH.
            $payload = "DATA UPDATE user CardNo=\tPin={$pin}\tPassword=\tGroup=1\tStartTime=0\tEndTime=0\tName={$name}\tPrivilege=0";

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

        // Actualizar device_users_fetched_at y el conteo en nuestra DB
        // basándonos en los empleados encolados (Security PUSH no soporta QUERY).
        $source->update([
            'device_users'            => $employees->map(fn($e) => [
                'pin'  => $e->access_id,
                'name' => mb_substr($e->full_name, 0, 24),
            ])->toArray(),
            'device_users_fetched_at' => $now,
        ]);

        $this->info("Se encolaron {$employees->count()} usuarios para el dispositivo \"{$source->name}\" (SN: {$source->serial_number}).");
        $this->line("El equipo los recibirá en su próxima llamada a /iclock/getrequest.");

        return self::SUCCESS;
    }
}
