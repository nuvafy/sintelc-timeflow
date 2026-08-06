<?php

namespace App\Console\Commands;

use App\Models\BiometricUserSync;
use App\Models\Client;
use App\Models\FactorialConnection;
use App\Models\FactorialEmployee;
use App\Services\FactorialService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Diagnóstico previo a desplegar el cambio a toggle_clock.
 *
 * toggle_clock cierra CUALQUIER turno abierto que encuentre para un empleado,
 * sin importar qué tan viejo sea. Si un empleado tiene un turno abierto desde
 * hace días (el caso de ~24h reportado por Factorial en agosto 2026) y pasa
 * a ser el primero en poder ponchar después del deploy, ese turno viejo se
 * va a cerrar con la hora de la ponchada actual — generando otra vez una
 * jornada de duración incorrecta. Este comando los detecta ANTES del deploy
 * para poder cerrarlos a mano en Factorial primero.
 */
class ListFactorialOpenShifts extends Command
{
    protected $signature = 'factorial:list-open-shifts
        {--client= : Slug o ID del cliente (omitir = todos los clientes con conexión activa)}
        {--stale-only : Solo mostrar turnos abiertos que NO son de hoy (los realmente riesgosos)}';

    protected $description = 'Lista turnos abiertos en Factorial por cliente, señalando los que llevan más de un día abiertos';

    public function handle(): int
    {
        $clientOption = $this->option('client');
        $staleOnly    = (bool) $this->option('stale-only');

        $connections = FactorialConnection::whereNotNull('access_token')
            ->when($clientOption, function ($query) use ($clientOption) {
                $query->whereHas('client', function ($q) use ($clientOption) {
                    $q->where('slug', $clientOption)->orWhere('id', $clientOption);
                });
            })
            ->with('client')
            ->get();

        if ($connections->isEmpty()) {
            $this->warn('No hay conexiones de Factorial con access_token disponibles' . ($clientOption ? " para \"{$clientOption}\"." : '.'));
            return self::SUCCESS;
        }

        $today   = Carbon::today();
        $rows    = [];
        $errored = false;

        foreach ($connections as $connection) {
            $clientName = $connection->client?->name ?? "cliente #{$connection->client_id}";
            $this->line("── {$clientName} (conexión #{$connection->id}) ──");

            try {
                $openShifts = $this->fetchOpenShifts($connection);
            } catch (\Throwable $e) {
                Log::error('factorial:list-open-shifts: error consultando turnos', [
                    'connection_id' => $connection->id,
                    'message'       => $e->getMessage(),
                ]);
                $this->error("  Error consultando Factorial: {$e->getMessage()}");
                $errored = true;
                continue;
            }

            if (empty($openShifts)) {
                $this->line('  Sin turnos abiertos.');
                continue;
            }

            foreach ($openShifts as $shift) {
                $shiftDate = isset($shift['date']) ? Carbon::parse($shift['date']) : null;
                $daysOpen  = $shiftDate ? $shiftDate->diffInDays($today) : null;
                $isStale   = $daysOpen !== null && $daysOpen >= 1;

                if ($staleOnly && ! $isStale) {
                    continue;
                }

                [$employeeName, $pin] = $this->resolveEmployee($connection->id, (int) ($shift['employee_id'] ?? 0));

                $rows[] = [
                    $clientName,
                    $employeeName,
                    $pin ?? '—',
                    $shift['id'] ?? '—',
                    $shiftDate?->toDateString() ?? '—',
                    $shift['clock_in'] ?? '—',
                    $daysOpen === null ? '—' : $daysOpen,
                    $isStale ? '⚠ REVISAR' : 'hoy',
                ];
            }
        }

        if (empty($rows)) {
            $this->newLine();
            $this->info($staleOnly
                ? 'No se encontraron turnos abiertos de días anteriores. Vía libre para desplegar.'
                : 'No se encontraron turnos abiertos.');
            return $errored ? self::FAILURE : self::SUCCESS;
        }

        $this->newLine();
        $this->table(
            ['Cliente', 'Empleado', 'PIN dispositivo', 'Shift ID', 'Fecha apertura', 'Clock in', 'Días abierto', 'Estado'],
            $rows
        );

        $staleCount = collect($rows)->filter(fn($r) => $r[7] !== 'hoy')->count();
        if ($staleCount > 0) {
            $this->newLine();
            $this->warn("{$staleCount} turno(s) llevan más de un día abiertos. Ciérralos manualmente en Factorial antes de desplegar el cambio a toggle_clock — si no, la primera ponchada de esos empleados va a volver a generar una jornada con duración incorrecta.");
        }

        return $errored ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Trae el turno más reciente de cada empleado (latest_shift=true) y filtra
     * los que siguen abiertos (clock_out null).
     *
     * NOTA: si un empleado tiene un turno abierto que NO es su más reciente
     * (un edge case raro — implicaría datos inconsistentes en Factorial), este
     * comando no lo detecta. Es la misma limitación que ya tiene el fallback
     * de overwrite en SyncAttendanceToFactorial (findOpenShift también solo
     * mira una fecha puntual).
     */
    private function fetchOpenShifts(FactorialConnection $connection): array
    {
        $service = new FactorialService($connection);

        // 'latest_shift' es booleano en la doc de Factorial; se manda como
        // string 'true' porque FactorialService::getShifts() castea los
        // valores del query a string tal cual (bool true -> "1", no "true").
        $shifts = $service->getShifts(['latest_shift' => 'true']);

        return array_values(array_filter(
            $shifts,
            fn($shift) => array_key_exists('clock_out', $shift) && $shift['clock_out'] === null
        ));
    }

    /**
     * @return array{0: string, 1: ?string} [nombre, pin del dispositivo]
     */
    private function resolveEmployee(int $connectionId, int $factorialId): array
    {
        if (! $factorialId) {
            return ['(desconocido)', null];
        }

        $employee = FactorialEmployee::where('factorial_connection_id', $connectionId)
            ->where('factorial_id', $factorialId)
            ->first();

        if (! $employee) {
            return ["factorial_id:{$factorialId}", null];
        }

        $pin = BiometricUserSync::where('factorial_employee_id', $employee->id)
            ->value('external_employee_code');

        return [$employee->full_name ?? "factorial_id:{$factorialId}", $pin];
    }
}
