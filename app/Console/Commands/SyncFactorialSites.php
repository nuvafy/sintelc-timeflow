<?php

namespace App\Console\Commands;

use App\Models\FactorialConnection;
use App\Models\Site;
use App\Services\FactorialService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncFactorialSites extends Command
{
    protected $signature = 'factorial:sync-sites {connectionId=1}';

    protected $description = 'Sincroniza locations de Factorial hacia la tabla sites';

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

        try {
            $service = new FactorialService($connection);
            $response = $service->getLocations();

            $locations = $response['data'] ?? [];
            $created = 0;
            $updated = 0;

            foreach ($locations as $location) {
                if (empty($location['id'])) {
                    $this->warn('Se omitió una location sin id.');
                    continue;
                }

                $site = Site::updateOrCreate(
                    [
                        'client_id' => $connection->client_id,
                        'external_location_id' => (int) $location['id'],
                    ],
                    [
                        'name' => $location['name'] ?? ('Location ' . $location['id']),
                        'code' => $location['code'] ?? null,
                        'status' => !empty($location['active']) ? 'active' : 'inactive',
                    ]
                );

                if ($site->wasRecentlyCreated) {
                    $created++;
                } else {
                    $updated++;
                }
            }

            $this->info('Locations procesadas: ' . count($locations));
            $this->info("Sites creados: {$created}");
            $this->info("Sites actualizados: {$updated}");

            return self::SUCCESS;
        } catch (\Throwable $e) {
            Log::error('Error al sincronizar sites desde Factorial', [
                'connection_id' => $connectionId,
                'message' => $e->getMessage(),
            ]);

            $this->error('Error al sincronizar sites: ' . $e->getMessage());

            return self::FAILURE;
        }
    }
}
