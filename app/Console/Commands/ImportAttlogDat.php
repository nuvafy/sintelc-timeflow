<?php

namespace App\Console\Commands;

use App\Models\AttendanceLog;
use App\Models\BiometricSource;
use App\Models\BiometricUserSync;
use App\Models\ClientAttendanceConfig;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ImportAttlogDat extends Command
{
    protected $signature = 'attlog:import-dat
                            {file : Ruta al archivo .dat}
                            {--sn= : Serial number del dispositivo (ej: NYU7253300714)}
                            {--dry-run : Solo muestra qué se importaría, sin guardar}
                            {--force : Importar aunque ya exista el registro}';

    protected $description = 'Importa registros de asistencia desde un archivo .dat de ZKTeco';

    public function handle(): int
    {
        $filePath = $this->argument('file');
        $sn       = $this->option('sn');
        $dryRun   = $this->option('dry-run');
        $force    = $this->option('force');

        // ── 1. Validar archivo ───────────────────────────────────────
        if (!file_exists($filePath)) {
            $this->error("Archivo no encontrado: {$filePath}");
            return self::FAILURE;
        }

        // ── 2. Buscar dispositivo ────────────────────────────────────
        if (!$sn) {
            $this->error('Debes indicar --sn=<serial_number> del dispositivo');
            return self::FAILURE;
        }

        $source = BiometricSource::where('serial_number', $sn)->first();

        if (!$source) {
            $this->error("Dispositivo no encontrado con SN: {$sn}");
            return self::FAILURE;
        }

        $this->info("Dispositivo: {$source->name} (ID: {$source->id}, client_id: {$source->client_id})");

        // ── 3. Cargar mappings ───────────────────────────────────────
        $mappings = BiometricUserSync::where('biometric_provider_id', $source->biometric_provider_id)
            ->whereNotNull('factorial_employee_id')
            ->pluck('factorial_employee_id', 'external_employee_code');

        $attendanceConfig = ClientAttendanceConfig::where('client_id', $source->client_id)->first();

        $this->info("Mappings cargados: {$mappings->count()} por código");

        // ── 4. Leer archivo ──────────────────────────────────────────
        $lines   = array_values(array_filter(
            array_map('trim', file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES))
        ));

        $this->info("Líneas en archivo: " . count($lines));

        // ── 5. Procesar líneas ───────────────────────────────────────
        // Formato DAT (tab-separado): PIN | DateTime | Verify | InOutMode | WorkCode | Reserved
        // ⚠️ Diferente al push en vivo donde el orden es PIN | DateTime | InOutMode | Verify | ...

        $records   = [];
        $skipped   = 0;
        $duplicate = 0;
        $invalid   = 0;
        $now       = now();

        // Pre-cargar todos los registros existentes de este dispositivo en un Set (evita N+1 queries)
        // La BD almacena occurred_at en hora local (app.timezone), DATE_FORMAT devuelve ese valor tal cual
        $existingKeys = DB::table('attendance_logs')
            ->where('biometric_source_id', $source->id)
            ->select('employee_code', DB::raw('DATE_FORMAT(occurred_at, "%Y-%m-%d %H:%i:%s") as occurred_at_local'))
            ->get()
            ->mapWithKeys(fn($r) => ["{$r->employee_code}|{$r->occurred_at_local}" => true]);

        $this->info("Registros existentes en BD para este dispositivo: {$existingKeys->count()}");

        $bar = $this->output->createProgressBar(count($lines));
        $bar->start();

        foreach ($lines as $line) {
            $bar->advance();

            $parts = explode("\t", $line);
            if (count($parts) < 4) {
                $invalid++;
                continue;
            }

            $pin      = trim($parts[0]);
            $rawTime  = trim($parts[1]);
            $verify   = trim($parts[2]); // col 3 = verify (método biométrico)
            $status   = trim($parts[3]); // col 4 = InOutMode (0=entrada,1=salida,2=descanso,3=fin descanso)
            $workcode = isset($parts[4]) ? trim($parts[4]) : null;

            if (!$pin || !$rawTime) {
                $invalid++;
                continue;
            }

            // Parsear timestamp
            try {
                $occurredAt = Carbon::createFromFormat('Y-m-d H:i:s', $rawTime, config('app.timezone'));
            } catch (\Exception $e) {
                $this->newLine();
                $this->warn("Timestamp inválido: {$rawTime}");
                $invalid++;
                continue;
            }

            // Verificar duplicado usando el Set en memoria (sin queries adicionales)
            // Comparamos en hora local porque la BD almacena en app.timezone (no UTC)
            if (!$force) {
                $localKey = "{$pin}|" . $occurredAt->format('Y-m-d H:i:s');
                if (isset($existingKeys[$localKey])) {
                    $duplicate++;
                    continue;
                }
            }

            $checkType = $attendanceConfig
                ? ($attendanceConfig->resolveCheckType($status) ?? 'unknown')
                : ClientAttendanceConfig::defaultCheckType($status);
            $employeeId = $mappings[$pin] ?? null;

            $records[] = [
                'client_id'             => $source->client_id,
                'biometric_source_id'   => $source->id,
                'factorial_employee_id' => $employeeId,
                'employee_code'         => $pin,
                'check_type'            => $checkType,
                'occurred_at'           => $occurredAt,
                'raw_payload'           => json_encode([
                    'pin'      => $pin,
                    'time'     => $rawTime,
                    'status'   => $status,
                    'verify'   => $verify,
                    'workcode' => $workcode,
                    'source'   => 'dat_import',
                ]),
                'sync_status' => 'synced', // históricos → ya sincronizados, no se re-envían
                'created_at'  => $now,
                'updated_at'  => $now,
            ];
        }

        $bar->finish();
        $this->newLine(2);

        // ── 6. Resumen ───────────────────────────────────────────────
        $this->table(
            ['Concepto', 'Cantidad'],
            [
                ['Total líneas', count($lines)],
                ['A importar',   count($records)],
                ['Duplicados',   $duplicate],
                ['Inválidos',    $invalid],
            ]
        );

        // Desglose por check_type
        $byType = collect($records)->groupBy('check_type')->map->count();
        $this->info('Por tipo:');
        foreach ($byType as $type => $count) {
            $this->line("  {$type}: {$count}");
        }

        // Desglose resueltos vs sin resolver
        $resolved   = collect($records)->filter(fn($r) => $r['factorial_employee_id'])->count();
        $unresolved = count($records) - $resolved;
        $this->info("Resueltos (con empleado): {$resolved} | Sin resolver: {$unresolved}");

        if (empty($records)) {
            $this->warn('No hay registros nuevos para importar.');
            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->warn('[DRY-RUN] No se guardó nada.');
            return self::SUCCESS;
        }

        // ── 7. Insertar ──────────────────────────────────────────────
        if (!$this->confirm("¿Confirmar importación de " . count($records) . " registros?", true)) {
            $this->warn('Cancelado.');
            return self::SUCCESS;
        }

        DB::transaction(function () use ($records) {
            foreach (array_chunk($records, 500) as $chunk) {
                AttendanceLog::insert($chunk);
            }
        });

        $this->info('✓ Importación completada.');

        return self::SUCCESS;
    }

}
