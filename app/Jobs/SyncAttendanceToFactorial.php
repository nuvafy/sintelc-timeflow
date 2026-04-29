<?php

namespace App\Jobs;

use App\Models\AttendanceLog;
use App\Models\FactorialConnection;
use App\Models\FactorialEmployee;
use App\Services\FactorialService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SyncAttendanceToFactorial implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public int $backoff = 10;

    public function __construct(
        public readonly int $attendanceLogId
    ) {}

    public function handle(): void
    {
        $log = AttendanceLog::find($this->attendanceLogId);

        if (!$log) {
            Log::warning('SyncAttendanceToFactorial: log no encontrado', [
                'id' => $this->attendanceLogId,
            ]);
            return;
        }

        if ($log->sync_status === 'synced') {
            return;
        }

        // 1. Verificar que el log tiene empleado resuelto
        if (!$log->factorial_employee_id) {
            $this->fail($log, "factorial_employee_id no resuelto para employee_code: {$log->employee_code}");
            return;
        }

        // 2. Buscar empleado Factorial
        $employee = FactorialEmployee::find($log->factorial_employee_id);

        if (!$employee) {
            $this->fail($log, "No se encontró factorial_employee_id: {$log->factorial_employee_id}");
            return;
        }

        // 3. Buscar conexión Factorial
        $connection = FactorialConnection::find($employee->factorial_connection_id);

        if (!$connection) {
            $this->fail($log, "No se encontró factorial_connection para empleado: {$employee->id}");
            return;
        }

        // 4. Preparar payload
        $now = $log->occurred_at->toIso8601String();

        $payload = [
            'employee_id' => $employee->factorial_id,
            'now'         => $now,
        ];

        if ($employee->location_id) {
            $payload['workplace_id'] = $employee->location_id;
        }

        // 5. Llamar a Factorial
        try {
            $service = new FactorialService($connection);

            if (in_array($log->check_type, ['break_out', 'break_in'])) {
                $configs = $service->getBreakConfigurations();
                $breakConfigId = $configs[0]['id'] ?? null;

                if (!$breakConfigId) {
                    // Sin break config en Factorial: marcar como synced y esperar
                    // a que el cliente configure las pausas en Factorial
                    $log->update([
                        'sync_status'  => 'synced',
                        'processed_at' => now(),
                        'sync_error'   => 'Sin break_configuration en Factorial — pendiente de configuración',
                    ]);
                    Log::info('SyncAttendanceToFactorial: break ignorado, sin configuración en Factorial', [
                        'attendance_log_id' => $log->id,
                    ]);
                    return;
                }

                $payload['time_settings_break_configuration_id'] = $breakConfigId;
            }

            $response = match ($log->check_type) {
                'check_in'  => $service->clockIn($payload),
                'check_out' => $service->clockOut($payload),
                'break_out' => $service->breakStart($payload),
                'break_in'  => $service->breakEnd($payload),
                default     => null,
            };

            if ($response === null) {
                $this->fail($log, "check_type no soportado: {$log->check_type}");
                return;
            }

            // 6. Marcar como synced
            $log->update([
                'sync_status'  => 'synced',
                'processed_at' => now(),
                'sync_error'   => null,
            ]);

            Log::info('SyncAttendanceToFactorial: OK', [
                'attendance_log_id' => $log->id,
                'employee_code'     => $log->employee_code,
                'check_type'        => $log->check_type,
                'factorial_id'      => $employee->factorial_id,
            ]);
        } catch (\Illuminate\Http\Client\RequestException $e) {
            // 409 = ya existe en Factorial, se trata como sincronizado
            if ($e->response->status() === 409) {
                $log->update([
                    'sync_status'  => 'synced',
                    'processed_at' => now(),
                    'sync_error'   => null,
                ]);

                Log::info('SyncAttendanceToFactorial: 409 ya existía en Factorial, marcado como synced', [
                    'attendance_log_id' => $log->id,
                ]);

                return;
            }

            $this->fail($log, $e->getMessage());
            throw $e; // permite reintentos
        } catch (\Throwable $e) {
            $this->fail($log, $e->getMessage());
            throw $e;
        }
    }

    private function fail(AttendanceLog $log, string $error): void
    {
        $log->update([
            'sync_status' => 'failed',
            'sync_error'  => $error,
        ]);

        Log::error('SyncAttendanceToFactorial: FAILED', [
            'attendance_log_id' => $log->id,
            'error'             => $error,
        ]);
    }
}
