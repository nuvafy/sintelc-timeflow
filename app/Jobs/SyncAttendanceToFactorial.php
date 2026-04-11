<?php

namespace App\Jobs;

use App\Models\AttendanceLog;
use App\Models\BiometricUserSync;
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

        // 1. Buscar mapeo PIN → empleado Factorial
        $sync = BiometricUserSync::where('external_employee_code', $log->employee_code)
            ->where('client_id', $log->client_id)
            ->first();

        if (!$sync) {
            $this->fail($log, "No se encontró mapeo para employee_code: {$log->employee_code}");
            return;
        }

        // 2. Buscar empleado Factorial
        $employee = FactorialEmployee::find($sync->factorial_employee_id);

        if (!$employee) {
            $this->fail($log, "No se encontró factorial_employee_id: {$sync->factorial_employee_id}");
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
        } catch (\Throwable $e) {
            $this->fail($log, $e->getMessage());
            throw $e; // permite reintentos
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
