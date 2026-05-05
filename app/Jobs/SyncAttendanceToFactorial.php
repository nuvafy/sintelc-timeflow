<?php

namespace App\Jobs;

use App\Models\AttendanceLog;
use App\Models\FactorialConnection;
use App\Models\FactorialEmployee;
use App\Services\FactorialService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;

class SyncAttendanceToFactorial implements ShouldQueue
{
    use Queueable;

    public int $tries  = 3;
    public int $backoff = 10;

    public function __construct(
        public readonly int $attendanceLogId
    ) {}

    public function handle(): void
    {
        $log = AttendanceLog::find($this->attendanceLogId);

        if (!$log) {
            Log::warning('SyncAttendanceToFactorial: log no encontrado', ['id' => $this->attendanceLogId]);
            return;
        }

        if ($log->sync_status === 'synced') return;

        if (!$log->factorial_employee_id) {
            $this->fail($log, "factorial_employee_id no resuelto para employee_code: {$log->employee_code}");
            return;
        }

        $employee = FactorialEmployee::find($log->factorial_employee_id);
        if (!$employee) {
            $this->fail($log, "No se encontró factorial_employee_id: {$log->factorial_employee_id}");
            return;
        }

        $connection = FactorialConnection::find($employee->factorial_connection_id);
        if (!$connection) {
            $this->fail($log, "No se encontró factorial_connection para empleado: {$employee->id}");
            return;
        }

        if (!$log->check_type) {
            $this->fail($log, 'check_type no definido en el log');
            return;
        }

        $service = new FactorialService($connection);
        $this->syncWithFallback($log, $employee, $service);
    }

    // ── Flujo principal con fallback ───────────────────────────────

    private function syncWithFallback(AttendanceLog $log, FactorialEmployee $employee, FactorialService $service): void
    {
        $payload = [
            'employee_id' => $employee->factorial_id,
            'now'         => $log->occurred_at->toIso8601String(),
        ];

        if ($employee->location_id) {
            $payload['workplace_id'] = $employee->location_id;
        }

        try {
            // Método principal: clock_in / clock_out
            $response = match ($log->check_type) {
                'check_in', 'break_out' => $service->clockIn($payload),
                'check_out', 'break_in' => $service->clockOut($payload),
                default                 => null,
            };

            if ($response === null) {
                $this->fail($log, "check_type no soportado: {$log->check_type}");
                return;
            }

            // Guardar shift_id si viene en la respuesta
            $shiftId = $response['id'] ?? null;

            $log->update([
                'factorial_shift_id' => $shiftId,
                'sync_status'        => 'synced',
                'processed_at'       => now(),
                'sync_error'         => null,
            ]);

            Log::info('SyncAttendanceToFactorial: OK (método principal)', [
                'attendance_log_id'  => $log->id,
                'check_type'         => $log->check_type,
                'factorial_shift_id' => $shiftId,
            ]);

        } catch (RequestException $e) {
            $status  = $e->response->status();
            $body    = $e->response->json() ?? [];
            $message = $body['errors']['exception'][0] ?? ($body['message'] ?? $e->getMessage());

            // 409 duplicado → ya existe en Factorial, marcar como synced
            if ($status === 409 && !$this->isPolicyConflict($message)) {
                $log->update(['sync_status' => 'synced', 'processed_at' => now(), 'sync_error' => null]);
                Log::info('SyncAttendanceToFactorial: 409 duplicado, marcado como synced', ['attendance_log_id' => $log->id]);
                return;
            }

            // 409 por política o cualquier otro error → intentar toggle
            Log::warning('SyncAttendanceToFactorial: método principal falló, intentando toggle', [
                'attendance_log_id' => $log->id,
                'status'            => $status,
                'error'             => $message,
            ]);

            $this->tryToggle($log, $employee, $service, $message);
        } catch (\Throwable $e) {
            $this->fail($log, $e->getMessage());
            throw $e;
        }
    }

    // ── Fallback: toggle ───────────────────────────────────────────

    private function tryToggle(AttendanceLog $log, FactorialEmployee $employee, FactorialService $service, string $primaryError): void
    {
        $payload = [
            'employee_id' => $employee->factorial_id,
            'clock_time'  => $log->occurred_at->toIso8601String(),
        ];

        if ($employee->location_id) {
            $payload['location_type'] = 'office';
        }

        try {
            $response = $service->toggleClock($payload);
            $shiftId  = $response['id'] ?? null;

            $log->update([
                'factorial_shift_id' => $shiftId,
                'sync_status'        => 'synced',
                'processed_at'       => now(),
                'sync_error'         => null,
            ]);

            Log::info('SyncAttendanceToFactorial: OK (toggle fallback)', [
                'attendance_log_id'  => $log->id,
                'check_type'         => $log->check_type,
                'factorial_shift_id' => $shiftId,
            ]);

        } catch (RequestException $e) {
            $body    = $e->response->json() ?? [];
            $message = $body['errors']['exception'][0] ?? ($body['message'] ?? $e->getMessage());

            $this->fail($log, "Principal: {$primaryError} | Toggle: {$message}");
            throw $e;
        } catch (\Throwable $e) {
            $this->fail($log, "Principal: {$primaryError} | Toggle: {$e->getMessage()}");
            throw $e;
        }
    }

    // ── Helpers ────────────────────────────────────────────────────

    private function isPolicyConflict(string $message): bool
    {
        $lower = strtolower($message);
        return str_contains($lower, 'turno') || str_contains($lower, 'shift') || str_contains($lower, 'política');
    }

    private function fail(AttendanceLog $log, string $error): void
    {
        $log->update(['sync_status' => 'failed', 'sync_error' => $error]);

        Log::error('SyncAttendanceToFactorial: FAILED', [
            'attendance_log_id' => $log->id,
            'error'             => $error,
        ]);
    }
}
