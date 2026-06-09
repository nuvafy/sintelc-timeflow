<?php

namespace App\Console\Commands;

use App\Models\FactorialConnection;
use App\Models\FactorialEmployee;
use App\Services\FactorialService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class SyncFactorialEmployees extends Command
{
    protected $signature = 'factorial:sync-employees {connectionId? : ID de conexión específica (omitir = todas)}';

    protected $description = 'Sincroniza empleados desde Factorial hacia la base local (todas las conexiones o una específica)';

    public function handle(): int
    {
        $connectionId = $this->argument('connectionId');

        $connections = $connectionId
            ? FactorialConnection::whereNotNull('access_token')->where('id', (int) $connectionId)->get()
            : FactorialConnection::whereNotNull('access_token')->get();

        if ($connections->isEmpty()) {
            $this->warn('No hay conexiones de Factorial con access_token disponibles.');
            return self::SUCCESS;
        }

        $this->info("Conexiones a sincronizar: {$connections->count()}");

        $exitCode = self::SUCCESS;

        foreach ($connections as $connection) {
            $this->newLine();
            $this->info("── Conexión #{$connection->id}: {$connection->name} (client_id: {$connection->client_id}) ──");

            try {
                $this->syncConnection($connection);
            } catch (\Throwable $e) {
                Log::error('Error al sincronizar empleados de Factorial', [
                    'connection_id' => $connection->id,
                    'message'       => $e->getMessage(),
                    'trace'         => $e->getTraceAsString(),
                ]);

                $this->error("Error en conexión #{$connection->id}: " . $e->getMessage());
                $exitCode = self::FAILURE;
            }
        }

        return $exitCode;
    }

    private function syncConnection(FactorialConnection $connection): void
    {
        $service = new FactorialService($connection);

        // ── Paginación: sigue hasta que la página venga incompleta ──
        $allEmployees = [];
        $limit        = 100;
        $pageNum      = 0;
        $cursor       = null;

        do {
            $pageNum++;
            $params   = ['limit' => $limit];
            if ($cursor) $params['after_id'] = $cursor;

            $response = $service->getEmployees($params);
            $page     = $response['data'] ?? [];
            $meta     = $response['meta'] ?? [];

            if (empty($page)) break;

            $allEmployees = array_merge($allEmployees, $page);

            $hasNext = $meta['has_next_page'] ?? false;
            $cursor  = $meta['end_cursor'] ?? null;

            if ($pageNum >= 50) break;

        } while ($hasNext && $cursor);

        $this->line("  Empleados obtenidos de Factorial: " . count($allEmployees));

        // ── Pre-cargar IDs existentes para contar creados vs actualizados ──
        $existingFactorialIds = FactorialEmployee::where('factorial_connection_id', $connection->id)
            ->pluck('factorial_id')
            ->flip();

        $now     = now()->toDateTimeString();
        $rows    = [];
        $skipped = 0;

        foreach ($allEmployees as $employee) {
            if (empty($employee['id'])) {
                $skipped++;
                continue;
            }

            $rows[] = [
                'factorial_connection_id' => $connection->id,
                'factorial_id'            => (int) $employee['id'],
                'client_id'               => $connection->client_id,
                'first_name'              => $employee['first_name'] ?? null,
                'last_name'               => $employee['last_name'] ?? null,
                'full_name'               => $employee['full_name'] ?? null,
                'email'                   => $employee['email'] ?? null,
                'login_email'             => $employee['login_email'] ?? null,
                'company_id'              => isset($employee['company_id']) ? (int) $employee['company_id'] : null,
                'company_identifier'      => $employee['company_identifier'] ?? null,
                'location_id'             => isset($employee['location_id']) ? (int) $employee['location_id'] : null,
                'active'                  => (bool) ($employee['active'] ?? false),
                'attendable'              => (bool) ($employee['attendable'] ?? false),
                'is_terminating'          => (bool) ($employee['is_terminating'] ?? false),
                'terminated_on'           => $this->parseDate($employee['terminated_on'] ?? null)?->toDateTimeString(),
                'factorial_created_at'    => $this->parseDate($employee['created_at'] ?? null)?->toDateTimeString(),
                'factorial_updated_at'    => $this->parseDate($employee['updated_at'] ?? null)?->toDateTimeString(),
                'raw_payload'             => json_encode($employee),
                'created_at'              => $now,
                'updated_at'              => $now,
            ];
        }

        if (! empty($rows)) {
            FactorialEmployee::upsert(
                $rows,
                ['factorial_connection_id', 'factorial_id'],
                [
                    'client_id', 'first_name', 'last_name', 'full_name',
                    'email', 'login_email', 'company_id', 'company_identifier',
                    'location_id', 'active', 'attendable', 'is_terminating',
                    'terminated_on', 'factorial_created_at', 'factorial_updated_at',
                    'raw_payload', 'updated_at',
                ]
            );
        }

        $created = collect($rows)
            ->filter(fn($r) => ! isset($existingFactorialIds[$r['factorial_id']]))
            ->count();
        $updated = count($rows) - $created;

        $this->line("  Creados:     {$created}");
        $this->line("  Actualizados: {$updated}");
        $this->line("  Omitidos:    {$skipped}");
    }

    protected function parseDate(?string $date): ?Carbon
    {
        if (empty($date)) {
            return null;
        }

        return Carbon::parse($date);
    }
}
