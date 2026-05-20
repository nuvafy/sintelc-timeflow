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
    protected $signature = 'factorial:sync-employees {connectionId=1}';

    protected $description = 'Sincroniza empleados desde Factorial hacia la base local';

    public function handle(): int
    {
        $connectionId = (int) $this->argument('connectionId');

        $connection = FactorialConnection::find($connectionId);

        if (! $connection) {
            $this->error("No se encontró la conexión de Factorial con ID {$connectionId}.");
            return self::FAILURE;
        }

        if (empty($connection->access_token)) {
            $this->error("La conexión {$connectionId} no tiene access_token.");
            return self::FAILURE;
        }

        $this->info("Usando conexión #{$connection->id} ({$connection->name})");

        try {
            $service = new FactorialService($connection);

            // ── Paginación: sigue hasta que la página venga incompleta ──
            $allEmployees = [];
            $offset       = 0;
            $limit        = 100;

            do {
                $response = $service->getEmployees(['offset' => $offset, 'limit' => $limit]);
                $page     = $response['data'] ?? [];
                $allEmployees = array_merge($allEmployees, $page);
                $offset  += $limit;
            } while (count($page) === $limit);

            $this->info("Total empleados obtenidos de Factorial: " . count($allEmployees));

            // ── Pre-cargar IDs existentes para contar creados vs actualizados ──
            $existingFactorialIds = FactorialEmployee::where('factorial_connection_id', $connection->id)
                ->pluck('factorial_id')
                ->flip();

            $now     = now()->toDateTimeString();
            $rows    = [];
            $skipped = 0;

            foreach ($allEmployees as $employee) {
                if (empty($employee['id'])) {
                    $this->warn('Se omitió un empleado porque no contiene id.');
                    $skipped++;
                    continue;
                }

                $rows[] = [
                    'factorial_connection_id' => $connection->id,
                    'factorial_id'            => (int) $employee['id'],
                    'client_id'               => $connection->client_id,
                    'access_id'               => isset($employee['access_id']) ? (int) $employee['access_id'] : null,
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
                // Batch upsert: 1 query en lugar de N updateOrCreate
                FactorialEmployee::upsert(
                    $rows,
                    ['factorial_connection_id', 'factorial_id'],
                    [
                        'client_id', 'access_id', 'first_name', 'last_name', 'full_name',
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

            $this->info("Creados:     {$created}");
            $this->info("Actualizados: {$updated}");
            $this->info("Omitidos:    {$skipped}");

            return self::SUCCESS;

        } catch (\Throwable $e) {
            Log::error('Error al sincronizar empleados de Factorial', [
                'connection_id' => $connectionId,
                'message'       => $e->getMessage(),
                'trace'         => $e->getTraceAsString(),
            ]);

            $this->error('Error al sincronizar empleados: ' . $e->getMessage());

            return self::FAILURE;
        }
    }

    protected function parseDate(?string $date): ?Carbon
    {
        if (empty($date)) {
            return null;
        }

        return Carbon::parse($date);
    }
}
