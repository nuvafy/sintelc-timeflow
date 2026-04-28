<?php

namespace App\Console\Commands;

use App\Models\FactorialConnection;
use App\Models\FactorialLocation;
use App\Services\FactorialService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncFactorialLocations extends Command
{
    protected $signature   = 'factorial:sync-locations {connectionId=1}';
    protected $description = 'Sincroniza ubicaciones desde Factorial hacia la base local';

    public function handle(): int
    {
        $connectionId = (int) $this->argument('connectionId');
        $connection   = FactorialConnection::find($connectionId);

        if (!$connection) {
            $this->error("No se encontró la conexión de Factorial con ID {$connectionId}.");
            return self::FAILURE;
        }

        if (empty($connection->access_token)) {
            $this->error("La conexión {$connectionId} no tiene access_token.");
            return self::FAILURE;
        }

        $this->info("Usando conexión #{$connection->id} ({$connection->name})");

        try {
            $service   = new FactorialService($connection);
            $response  = $service->getLocations();
            $locations = $response['data'] ?? $response;

            if (!is_array($locations)) {
                $this->error('Respuesta inesperada de Factorial.');
                return self::FAILURE;
            }

            $created = 0;
            $updated = 0;
            $skipped = 0;

            foreach ($locations as $location) {
                if (empty($location['id'])) {
                    $skipped++;
                    continue;
                }

                $model = FactorialLocation::updateOrCreate(
                    [
                        'factorial_connection_id' => $connection->id,
                        'factorial_location_id'   => (int) $location['id'],
                    ],
                    [
                        'client_id'            => $connection->client_id,
                        'factorial_company_id' => isset($location['company_id']) ? (int) $location['company_id'] : null,
                        'name'                 => $location['name'] ?? "Ubicación {$location['id']}",
                    ]
                );

                $model->wasRecentlyCreated ? $created++ : $updated++;
            }

            $this->info('Ubicaciones procesadas: ' . count($locations));
            $this->info("Creadas: {$created} | Actualizadas: {$updated} | Omitidas: {$skipped}");

            return self::SUCCESS;
        } catch (\Throwable $e) {
            Log::error('Error al sincronizar ubicaciones de Factorial', [
                'connection_id' => $connectionId,
                'message'       => $e->getMessage(),
            ]);

            $this->error('Error: ' . $e->getMessage());
            return self::FAILURE;
        }
    }
}
