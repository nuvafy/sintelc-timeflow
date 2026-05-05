<?php

namespace App\Jobs;

use App\Models\AttendanceLog;
use App\Models\Client;
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
            Log::warning('SyncAttendanceToFactorial: log no encontrado', ['id' => $this->attendanceLogId]);
            return;
        }

        if ($log->sync_status === 'synced') {
            return;
        }

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

        // Resolver check_type desde config del cliente si no está resuelto
        if (!$log->check_type) {
            $this->fail($log, 'check_type no definido en el log');
            return;
        }

        $service = new FactorialService($connection);

        try {
            match ($log->check_type) {
                'check_in', 'break_out' => $this->handleOpen($log, $employee, $service),
                'check_out', 'break_in'  => $this->handleClose($log, $employee, $service),
                default => $this->fail($log, "check_type no soportado: {$log->check_type}"),
            };
        } catch (\Illuminate\Http\Client\RequestException $e) {
            if ($e->response->status() === 409) {
                $body    = $e->response->json() ?? [];
                $message = $body['errors']['exception'][0] ?? ($body['message'] ?? '');

                if (str_contains(strtolower($message), 'turno') || str_contains(strtolower($message), 'shift')) {
                    $this->fail($log, '409 conflicto de turno: ' . $message);
                    return;
                }

                $log->update(['sync_status' => 'synced', 'processed_at' => now(), 'sync_error' => null]);
                Log::info('SyncAttendanceToFactorial: 409 duplicado real, marcado como synced', ['attendance_log_id' => $log->id]);
                return;
            }

            $this->fail($log, $e->getMessage());
            throw $e;
        } catch (\Throwable $e) {
            $this->fail($log, $e->getMessage());
            throw $e;
        }
    }

    private function handleOpen(AttendanceLog $log, FactorialEmployee $employee, FactorialService $service): void
    {
        $payload = [
            'employee_id' => $employee->factorial_id,
            'clock_in'    => $log->occurred_at->toIso8601String(),
            'date'        => $log->occurred_at->toDateString(),
        ];

        $response = $service->createShift($payload);
        $shiftId  = $response['id'] ?? null;

        if (!$shiftId) {
            $this->fail($log, 'Factorial no devolvió shift id al crear el turno');
            return;
        }

        $log->update([
            'factorial_shift_id' => $shiftId,
            'shift_closed'       => false,
            'sync_status'        => 'synced',
            'processed_at'       => now(),
            'sync_error'         => null,
        ]);

        Log::info('SyncAttendanceToFactorial: turno abierto', [
            'attendance_log_id' => $log->id,
            'factorial_shift_id' => $shiftId,
            'check_type'        => $log->check_type,
        ]);
    }

    private function handleClose(AttendanceLog $log, FactorialEmployee $employee, FactorialService $service): void
    {
        // Buscar el turno abierto más reciente del empleado (ventana 24h)
        $openLog = AttendanceLog::where('factorial_employee_id', $employee->id)
            ->whereIn('check_type', ['check_in', 'break_out'])
            ->where('occurred_at', '>=', $log->occurred_at->subHours(24))
            ->where('occurred_at', '<', $log->occurred_at)
            ->whereNotNull('factorial_shift_id')
            ->where('shift_closed', false)
            ->latest('occurred_at')
            ->first();

        if (!$openLog) {
            $this->fail($log, 'No se encontró turno abierto para cerrar');
            return;
        }

        $payload = [
            'employee_id' => $employee->factorial_id,
            'clock_out'   => $log->occurred_at->toIso8601String(),
        ];

        $service->updateShift($openLog->factorial_shift_id, $payload);

        // Marcar el turno abierto como cerrado
        $openLog->update(['shift_closed' => true]);

        $log->update([
            'factorial_shift_id' => $openLog->factorial_shift_id,
            'sync_status'        => 'synced',
            'processed_at'       => now(),
            'sync_error'         => null,
        ]);

        Log::info('SyncAttendanceToFactorial: turno cerrado', [
            'attendance_log_id'  => $log->id,
            'factorial_shift_id' => $openLog->factorial_shift_id,
            'check_type'         => $log->check_type,
        ]);
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
