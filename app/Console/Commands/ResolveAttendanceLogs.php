<?php

namespace App\Console\Commands;

use App\Jobs\SyncAttendanceToFactorial;
use App\Models\AttendanceLog;
use App\Models\BiometricSource;
use App\Models\BiometricUserSync;
use App\Models\FactorialEmployee;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ResolveAttendanceLogs extends Command
{
    protected $signature = 'attendance:resolve {sourceId? : ID del dispositivo (omitir = todos)}';
    protected $description = 'Resuelve factorial_employee_id en attendance_logs usando la tabla de mapeo';

    public function handle(): int
    {
        $sourceId = $this->argument('sourceId');

        $query = AttendanceLog::whereNull('factorial_employee_id');

        if ($sourceId) {
            $query->where('biometric_source_id', $sourceId);
        }

        $logs = $query->with('biometricSource')->get();

        if ($logs->isEmpty()) {
            $this->info('No hay registros pendientes de resolución.');
            return self::SUCCESS;
        }

        $this->info("Procesando {$logs->count()} registros...");

        $resolved = 0;
        $unresolved = 0;

        foreach ($logs as $log) {
            $source = $log->biometricSource;
            if (!$source) { $unresolved++; continue; }

            $employeeId = $this->resolveEmployee($log->employee_code, $source);

            if ($employeeId) {
                $log->update([
                    'factorial_employee_id' => $employeeId,
                    'sync_status'           => 'resolved',
                ]);
                SyncAttendanceToFactorial::dispatch($log->id);
                $resolved++;
            } else {
                $unresolved++;
            }
        }

        $this->info("Resueltos: {$resolved} | Sin resolver: {$unresolved}");

        return self::SUCCESS;
    }

    protected function resolveEmployee(string $employeeCode, BiometricSource $source): ?int
    {
        // Estrategia 1: tabla de mapeo
        $sync = BiometricUserSync::where('biometric_provider_id', $source->biometric_provider_id)
            ->where('external_employee_code', $employeeCode)
            ->first();

        if ($sync) {
            return $sync->factorial_employee_id;
        }

        // Estrategia 2: match directo por access_id, filtrado por factorial_company_id
        $factorialCompanyId = \App\Models\FactorialConnection::where('client_id', $source->client_id)
            ->whereNotNull('factorial_company_id')
            ->value('factorial_company_id');

        $query = FactorialEmployee::where('access_id', $employeeCode);

        if ($factorialCompanyId) {
            $query->where('company_id', $factorialCompanyId);
        } else {
            $query->where('factorial_connection_id', function ($q) use ($source) {
                $q->select('id')->from('factorial_connections')->where('client_id', $source->client_id);
            });
        }

        return $query->value('id');
    }
}
