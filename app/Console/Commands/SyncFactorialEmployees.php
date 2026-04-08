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
            $response = $service->getEmployees();

            $employees = $response['data'] ?? [];
            $meta = $response['meta'] ?? [];

            $created = 0;
            $updated = 0;
            $skipped = 0;

            foreach ($employees as $employee) {
                if (empty($employee['id'])) {
                    $this->warn('Se omitió un empleado porque no contiene id.');
                    $skipped++;
                    continue;
                }

                $model = FactorialEmployee::updateOrCreate(
                    [
                        'factorial_connection_id' => $connection->id,
                        'factorial_id' => (int) $employee['id'],
                    ],
                    [
                        'client_id' => $connection->client_id,
                        'access_id' => isset($employee['access_id']) ? (int) $employee['access_id'] : null,
                        'first_name' => $employee['first_name'] ?? null,
                        'last_name' => $employee['last_name'] ?? null,
                        'full_name' => $employee['full_name'] ?? null,
                        'email' => $employee['email'] ?? null,
                        'login_email' => $employee['login_email'] ?? null,
                        'company_id' => isset($employee['company_id']) ? (int) $employee['company_id'] : null,
                        'company_identifier' => $employee['company_identifier'] ?? null,
                        'location_id' => isset($employee['location_id']) ? (int) $employee['location_id'] : null,
                        'active' => (bool) ($employee['active'] ?? false),
                        'attendable' => (bool) ($employee['attendable'] ?? false),
                        'is_terminating' => (bool) ($employee['is_terminating'] ?? false),
                        'terminated_on' => $this->parseDate($employee['terminated_on'] ?? null),
                        'factorial_created_at' => $this->parseDate($employee['created_at'] ?? null),
                        'factorial_updated_at' => $this->parseDate($employee['updated_at'] ?? null),
                        'raw_payload' => $employee,
                    ]
                );

                if ($model->wasRecentlyCreated) {
                    $created++;
                } else {
                    $updated++;
                }
            }

            $this->info('Empleados procesados: ' . count($employees));
            $this->info("Creados: {$created}");
            $this->info("Actualizados: {$updated}");
            $this->info("Omitidos: {$skipped}");

            if (! empty($meta)) {
                $this->line('Meta: ' . json_encode($meta, JSON_UNESCAPED_UNICODE));
            }

            return self::SUCCESS;
        } catch (\Throwable $e) {
            Log::error('Error al sincronizar empleados de Factorial', [
                'connection_id' => $connectionId,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
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
