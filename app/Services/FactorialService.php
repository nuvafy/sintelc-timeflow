<?php

namespace App\Services;

use App\Models\FactorialConnection;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
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
        $url = $this->baseUrl() . '/' . ltrim($uri, '/');

        $request = Http::withToken($this->accessToken())
            ->acceptJson()
            ->timeout(30);

        $response = match (strtolower($method)) {
            'get'    => $request->get($url, $options['query'] ?? []),
            'post'   => $request->post($url, $options['json'] ?? []),
            'put'    => $request->put($url, $options['json'] ?? []),
            'patch'  => $request->patch($url, $options['json'] ?? []),
            'delete' => $request->delete($url, $options['json'] ?? []),
            default  => throw new RuntimeException("Método HTTP no soportado: {$method}"),
        };

        return $response->throw();
    }

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
        $response = $this->request(
            'get',
            '/api/2026-04-01/resources/attendance/shifts',
            ['query' => $query]
        )->json();

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
