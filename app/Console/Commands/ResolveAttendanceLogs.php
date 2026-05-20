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

        $total = $query->count();

        if ($total === 0) {
            $this->info('No hay registros pendientes de resolución.');
            return self::SUCCESS;
        }

        $this->info("Procesando {$total} registros...");

        $resolved   = 0;
        $unresolved = 0;
        $delay      = 0;

        // Pre-cargar mappings y employees por fuente para evitar N+1
        $mappingsCache   = [];
        $accessIdCache   = [];
        $companyIdCache  = [];

        $query->with('biometricSource')->chunkById(100, function ($logs) use (
            &$resolved, &$unresolved, &$delay,
            &$mappingsCache, &$accessIdCache, &$companyIdCache
        ) {
            $resolvedIds = [];

            foreach ($logs as $log) {
                $source = $log->biometricSource;
                if (!$source) { $unresolved++; continue; }

                $providerId = $source->biometric_provider_id;
                $clientId   = $source->client_id;

                // Cache mappings por proveedor
                if (!isset($mappingsCache[$providerId])) {
                    $mappingsCache[$providerId] = BiometricUserSync::where('biometric_provider_id', $providerId)
                        ->whereNotNull('factorial_employee_id')
                        ->pluck('factorial_employee_id', 'external_employee_code');
                }

                // Cache employees por cliente
                if (!isset($accessIdCache[$clientId])) {
                    $companyIdCache[$clientId] = \App\Models\FactorialConnection::where('client_id', $clientId)
                        ->whereNotNull('factorial_company_id')
                        ->value('factorial_company_id');

                    $empQuery = FactorialEmployee::whereNotNull('access_id');
                    if ($companyIdCache[$clientId]) {
                        $empQuery->where('company_id', $companyIdCache[$clientId]);
                    } else {
                        $empQuery->where('factorial_connection_id', function ($q) use ($clientId) {
                            $q->select('id')->from('factorial_connections')->where('client_id', $clientId);
                        });
                    }
                    $accessIdCache[$clientId] = $empQuery->pluck('id', 'access_id');
                }

                $employeeId = $mappingsCache[$providerId][$log->employee_code]
                    ?? $accessIdCache[$clientId][$log->employee_code]
                    ?? null;

                if ($employeeId) {
                    $resolvedIds[$log->id] = $employeeId;
                    $resolved++;
                } else {
                    $unresolved++;
                }
            }

            // Batch update en lugar de update individual
            foreach ($resolvedIds as $logId => $employeeId) {
                AttendanceLog::where('id', $logId)->update([
                    'factorial_employee_id' => $employeeId,
                    'sync_status'           => 'resolved',
                ]);
                SyncAttendanceToFactorial::dispatch($logId)->delay(now()->addSeconds($delay));
                $delay += 2;
            }
        });

        $this->info("Resueltos: {$resolved} | Sin resolver: {$unresolved}");

        return self::SUCCESS;
    }
}
