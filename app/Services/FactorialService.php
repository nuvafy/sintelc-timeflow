<?php

namespace App\Services;

use App\Models\FactorialConnection;
use Carbon\Carbon;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class FactorialService
{
    public function __construct(
        protected FactorialConnection $connection
    ) {}

    protected function baseUrl(): string
    {
        return rtrim(config('services.factorial.base_url'), '/');
    }

    protected function accessToken(): string
    {
        if (empty($this->connection->access_token)) {
            throw new RuntimeException('La conexión de Factorial no tiene access token.');
        }

        return $this->connection->access_token;
    }

    protected function request(string $method, string $uri, array $options = []): Response
    {
        $url      = $this->baseUrl() . '/' . ltrim($uri, '/');
        $response = $this->doRequest($method, $url, $options, $this->accessToken());

        // Si la respuesta es 401, intentar refrescar el token y reintentar una vez
        if ($response->status() === 401) {
            $this->refreshAccessToken();
            $response = $this->doRequest($method, $url, $options, $this->accessToken());
        }

        return $response->throw();
    }

    private function doRequest(string $method, string $url, array $options, string $token): Response
    {
        $request = Http::withToken($token)
            ->acceptJson()
            ->timeout(30);

        return match (strtolower($method)) {
            'get'    => $request->get($url, $options['query'] ?? []),
            'post'   => $request->post($url, $options['json'] ?? []),
            'put'    => $request->put($url, $options['json'] ?? []),
            'patch'  => $request->patch($url, $options['json'] ?? []),
            'delete' => $request->delete($url, $options['json'] ?? []),
            default  => throw new RuntimeException("Método HTTP no soportado: {$method}"),
        };
    }

    /**
     * Refresca el access_token usando el refresh_token.
     * Usa un Cache lock por conexión para que solo un worker refresque
     * a la vez — los demás esperan y reutilizan el token nuevo.
     */
    protected function refreshAccessToken(): void
    {
        $lockKey = "factorial_token_refresh:{$this->connection->id}";
        $lock    = Cache::lock($lockKey, 30); // máximo 30s esperando

        // Intentar adquirir el lock; si otro worker lo tiene, esperar hasta 25s
        $lock->block(25);

        try {
            // Recargar la conexión: puede que otro worker ya refrescó el token
            $this->connection->refresh();

            if (empty($this->connection->refresh_token)) {
                throw new RuntimeException(
                    "La conexión #{$this->connection->id} no tiene refresh_token. Reconecta el OAuth manualmente."
                );
            }

            $client = $this->connection->client;

            if (! $client || empty($client->oauth_client_id) || empty($client->oauth_client_secret)) {
                throw new RuntimeException(
                    "La conexión #{$this->connection->id} no tiene client_id/secret configurados en el cliente."
                );
            }

            $response = Http::asForm()->post(
                $this->baseUrl() . '/oauth/token',
                [
                    'grant_type'    => 'refresh_token',
                    'refresh_token' => $this->connection->refresh_token,
                    'client_id'     => $client->oauth_client_id,
                    'client_secret' => $client->oauth_client_secret,
                ]
            );

            if ($response->failed()) {
                throw new RuntimeException(
                    "Refresh token fallido para conexión #{$this->connection->id}: " . $response->body()
                );
            }

            $data = $response->json();

            $this->connection->update([
                'access_token'  => $data['access_token'] ?? null,
                'refresh_token' => $data['refresh_token'] ?? $this->connection->refresh_token,
                'expires_in'    => $data['expires_in'] ?? null,
                'expires_at'    => isset($data['expires_in'])
                    ? Carbon::now()->addSeconds((int) $data['expires_in'])
                    : null,
            ]);

            // Recargar para que accessToken() devuelva el nuevo valor
            $this->connection->refresh();

            Log::info('FactorialService: token refrescado', [
                'connection_id' => $this->connection->id,
            ]);

        } finally {
            $lock->release();
        }
    }

    // ── Métodos públicos ──────────────────────────────────────────────

    public function getEmployees(array $query = []): array
    {
        $defaultQuery = [
            'only_active'   => 'true',
            'only_managers' => 'false',
            'limit'         => 100,
        ];

        return $this->request(
            'get',
            '/api/2026-04-01/resources/employees/employees',
            ['query' => array_merge($defaultQuery, $query)]
        )->json();
    }

    public function getCompany(): array
    {
        return $this->request('get', '/api/2026-04-01/resources/companies/companies')->json()[0] ?? [];
    }

    public function getLocations(array $query = []): array
    {
        return $this->request(
            'get',
            '/api/2026-04-01/resources/locations/locations',
            ['query' => $query]
        )->json();
    }

    public function getBreakConfigurations(array $query = []): array
    {
        return $this->request(
            'get',
            '/api/2026-04-01/resources/attendance/break_configurations',
            ['query' => $query]
        )->json();
    }

    public function getShifts(array $query = []): array
    {
        // Construimos el query string manualmente porque http_build_query añade
        // índices numéricos (employee_ids[0]=X) y Factorial espera employee_ids[]=X
        $parts = [];

        foreach ($query as $key => $value) {
            if (is_array($value)) {
                foreach ($value as $item) {
                    $parts[] = urlencode($key . '[]') . '=' . urlencode($item);
                }
            } else {
                $parts[] = urlencode($key) . '=' . urlencode($value);
            }
        }

        $uri = '/api/2026-04-01/resources/attendance/shifts'
             . ($parts ? '?' . implode('&', $parts) : '');

        $response = $this->request('get', $uri)->json();

        return $response['data'] ?? $response;
    }

    public function clockIn(array $payload): array
    {
        return $this->request(
            'post',
            '/api/2026-04-01/resources/attendance/shifts/clock_in',
            ['json' => $payload]
        )->json();
    }

    public function clockOut(array $payload): array
    {
        return $this->request(
            'post',
            '/api/2026-04-01/resources/attendance/shifts/clock_out',
            ['json' => $payload]
        )->json();
    }

    public function toggleClock(array $payload): array
    {
        return $this->request(
            'post',
            '/api/2026-04-01/resources/attendance/shifts/toggle_clock',
            ['json' => $payload]
        )->json();
    }

    public function createShift(array $payload): array
    {
        return $this->request(
            'post',
            '/api/2026-04-01/resources/attendance/shifts',
            ['json' => $payload]
        )->json();
    }

    public function updateShift(int $shiftId, array $payload): array
    {
        return $this->request(
            'put',
            "/api/2026-04-01/resources/attendance/shifts/{$shiftId}",
            ['json' => $payload]
        )->json();
    }
}
