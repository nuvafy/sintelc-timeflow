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
        $this->sync($log, $employee, $service);
    }

    // ── Flujo principal ────────────────────────────────────────────

    private function sync(AttendanceLog $log, FactorialEmployee $employee, FactorialService $service): void
    {
        $workplaceId = $log->biometricSource?->factorial_location_id;

        $payload = [
            'employee_id' => $employee->factorial_id,
            'now'         => $log->occurred_at->format('Y-m-d\TH:i:s'),
        ];

        if ($workplaceId) {
            $payload['workplace_id']  = $workplaceId;
            $payload['location_type'] = 'office';
        }

        try {
            $response = match ($log->check_type) {
                'check_in', 'break_out' => $service->clockIn($payload),
                'check_out', 'break_in' => $service->clockOut($payload),
                default                 => null,
            };

            if ($response === null) {
                $this->fail($log, "check_type no soportado: {$log->check_type}");
                return;
            }

            $this->markSynced($log, $response['id'] ?? null, 'directo');

        } catch (RequestException $e) {
            $body    = $e->response->json() ?? [];
            $message = $body['errors']['exception'][0] ?? ($body['message'] ?? $e->getMessage());

            Log::warning('SyncAttendanceToFactorial: método directo falló, intentando overwrite', [
                'attendance_log_id' => $log->id,
                'http_status'       => $e->response->status(),
                'error'             => $message,
            ]);

            $this->tryOverwrite($log, $employee, $service, $message);

        } catch (\Throwable $e) {
            $this->fail($log, $e->getMessage());
            throw $e;
        }
    }

    // ── Fallback: sobreescribir turno existente ────────────────────
    //
    // La API tiene mayor jerarquía que la plataforma web/móvil.
    // Si hay un turno abierto (sea cual sea su in_source), lo sobreescribimos
    // con el timestamp del biométrico.

    private function tryOverwrite(AttendanceLog $log, FactorialEmployee $employee, FactorialService $service, string $primaryError): void
    {
        try {
            // Buscar turno abierto del empleado en la fecha del registro
            // También revisamos el día anterior por si el turno cruzó medianoche
            $openShift = $this->findOpenShift($service, $employee->factorial_id, $log->occurred_at);

            if (!$openShift) {
                $this->fail($log, "Sin turno abierto para sobreescribir. Error original: {$primaryError}");
                return;
            }

            // Regla: no sobreescribir turnos creados vía API/biométrico (in_source = null).
            // null                → creado vía API/biométrico → NO tocar
            // desktop             → web de Factorial          → SÍ permitir
            // mobile              → app móvil Factorial       → SÍ permitir
            // mobile_geolocation  → app móvil con geoloc.     → SÍ permitir
            $inSource = $openShift['in_source'] ?? null;
            if ($inSource === null) {
                $this->fail($log, "No se permite sobreescribir turno creado vía API/biométrico (in_source=null). Error original: {$primaryError}");
                return;
            }

            $time = $log->occurred_at->format('H:i:s');

            $updatePayload = match ($log->check_type) {
                'check_in', 'break_out' => ['clock_in'  => $time],
                'check_out', 'break_in' => ['clock_out' => $time],
                default                 => null,
            };

            if ($updatePayload === null) {
                $this->fail($log, "check_type no soportado: {$log->check_type}");
                return;
            }

            $service->updateShift($openShift['id'], $updatePayload);

            $this->markSynced($log, $openShift['id'], "overwrite ({$inSource})");

            Log::info('SyncAttendanceToFactorial: OK (overwrite)', [
                'attendance_log_id'  => $log->id,
                'check_type'         => $log->check_type,
                'factorial_shift_id' => $openShift['id'],
                'in_source'          => $inSource,
            ]);

        } catch (RequestException $e) {
            $body    = $e->response->json() ?? [];
            $message = $body['errors']['exception'][0] ?? ($body['message'] ?? $e->getMessage());

            $this->fail($log, "Directo: {$primaryError} | Overwrite: {$message}");
            throw $e;

        } catch (\Throwable $e) {
            $this->fail($log, "Directo: {$primaryError} | Overwrite: {$e->getMessage()}");
            throw $e;
        }
    }

    // ── Helpers ────────────────────────────────────────────────────

    /**
     * Busca un turno sin clock_out en la fecha exacta del log.
     * Solo se revisa el día del registro — nunca días anteriores.
     *
     * NOTA: la API de Factorial ignora los query params employee_id y date,
     * devuelve todos los turnos de la empresa. Filtramos en PHP para garantizar
     * que solo tocamos turnos del empleado correcto en la fecha correcta.
     */
    private function findOpenShift(FactorialService $service, int $factorialEmployeeId, \Carbon\Carbon $date): ?array
    {
        $targetDate = $date->format('Y-m-d');

        $shifts = $service->getShifts([
            'employee_ids' => [$factorialEmployeeId],
            'start_on'     => $targetDate,
            'end_on'       => $targetDate,
        ]);

        // Filtro defensivo: aunque la API devuelva más registros de los esperados,
        // solo aceptamos turnos del empleado correcto en la fecha exacta.
        return collect($shifts)->first(
            fn($s) => $s['clock_out'] === null
                   && (int) $s['employee_id'] === $factorialEmployeeId
                   && $s['date'] === $targetDate
        );
    }

    private function markSynced(AttendanceLog $log, ?int $shiftId, string $note): void
    {
        $log->update([
            'factorial_shift_id' => $shiftId,
            'sync_status'        => 'synced',
            'processed_at'       => now(),
            'sync_error'         => null,
            'sync_note'          => $note,
        ]);

        Log::info('SyncAttendanceToFactorial: OK', [
            'attendance_log_id'  => $log->id,
            'check_type'         => $log->check_type,
            'factorial_shift_id' => $shiftId,
            'note'               => $note,
        ]);
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
