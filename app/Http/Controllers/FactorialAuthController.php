<?php

namespace App\Http\Controllers;

use App\Models\FactorialConnection;
use App\Services\FactorialService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Vinkla\Hashids\Facades\Hashids;

class FactorialAuthController extends Controller
{
    public function redirect(Request $request)
    {
        $hashedId = $request->query('connection_id');

        if (! $hashedId) {
            return response()->json(['ok' => false, 'message' => 'connection_id es requerido'], 400);
        }

        $decoded = Hashids::decode($hashedId);

        if (empty($decoded)) {
            return response()->json(['ok' => false, 'message' => 'connection_id inválido'], 400);
        }

        $connection = FactorialConnection::with('client')->findOrFail($decoded[0]);

        abort_if(
            !$connection->client
            || empty($connection->client->oauth_client_id)
            || empty($connection->client->oauth_client_secret),
            422,
            'La empresa no tiene credenciales OAuth completas.'
        );

        $state = Str::random(64);
        Cache::put("factorial_oauth_state:{$state}", [
            'connection_id' => $connection->id,
            'user_id'       => $request->user()->id,
        ], now()->addMinutes(10));

        $query = http_build_query([
            'client_id'           => $connection->client->oauth_client_id,
            'redirect_uri'        => config('services.factorial.redirect'),
            'response_type'       => 'code',
            'resource_owner_type' => $connection->resource_owner_type ?? 'company',
            'state'               => $state,
        ]);

        return redirect(config('services.factorial.base_url') . '/oauth/authorize?' . $query);
    }

    public function callback(Request $request)
    {
        if ($request->filled('error')) {
            Log::warning('Factorial OAuth authorization rejected', [
                'error' => $request->string('error')->limit(100)->toString(),
            ]);

            return response()->json([
                'ok' => false,
                'message' => 'Factorial rechazó la autorización.',
            ], 400);
        }

        $code = $request->input('code');

        if (! $code) {
            return response()->json([
                'ok' => false,
                'message' => 'No se recibió authorization code',
            ], 400);
        }

        $state = $request->input('state');

        if (! is_string($state) || $state === '') {
            return response()->json(['ok' => false, 'message' => 'state ausente en el callback'], 400);
        }

        $stateData = Cache::pull("factorial_oauth_state:{$state}");

        if (! is_array($stateData)
            || empty($stateData['connection_id'])
            || (int) ($stateData['user_id'] ?? 0) !== (int) $request->user()->id) {
            return response()->json(['ok' => false, 'message' => 'state inválido o expirado'], 400);
        }

        $connection = FactorialConnection::with('client')->findOrFail($stateData['connection_id']);

        $response = Http::asForm()->post(
            config('services.factorial.base_url') . '/oauth/token',
            [
                'client_id' => $connection->client->oauth_client_id,
                'client_secret' => $connection->client->oauth_client_secret,
                'code' => $code,
                'grant_type' => 'authorization_code',
                'redirect_uri' => config('services.factorial.redirect'),
            ]
        );

        if ($response->failed()) {
            Log::error('Factorial token exchange failed', [
                'status' => $response->status(),
                'connection_id' => $connection->id,
            ]);

            return response()->json([
                'ok' => false,
                'message' => 'No se pudo obtener token',
            ], 500);
        }

        $data = $response->json();

        $connection->update([
            'access_token'  => $data['access_token'] ?? null,
            'refresh_token' => $data['refresh_token'] ?? null,
            'token_type'    => $data['token_type'] ?? null,
            'expires_in'    => $data['expires_in'] ?? null,
            'expires_at'    => isset($data['expires_in'])
                ? Carbon::now()->addSeconds((int) $data['expires_in'])
                : null,
            'raw_response'  => array_filter([
                'scope'      => $data['scope'] ?? null,
                'created_at' => now()->toIso8601String(),
            ]),
        ]);

        // Extraer company_id del JWT del access_token
        try {
            $jwt       = $data['access_token'] ?? null;
            $companyId = null;

            if ($jwt) {
                $parts   = explode('.', $jwt);
                $payload = json_decode(base64_decode(strtr($parts[1] ?? '', '-_', '+/')), true);
                $companyId = $payload['company_id'] ?? null;
            }

            if ($companyId) {
                $connection->update(['factorial_company_id' => $companyId]);
            }
        } catch (\Throwable $e) {
            Log::warning('No se pudo extraer company_id del JWT', [
                'connection_id' => $connection->id,
                'error'         => $e->getMessage(),
            ]);
        }

        // Obtener nombre y email de la empresa desde Factorial
        try {
            $service = new FactorialService($connection);
            $company = $service->getCompany();

            $updates = [];

            if (!empty($company['legal_name'])) {
                $updates['name'] = $company['legal_name'];
            } elseif (!empty($company['name'])) {
                $updates['name'] = $company['name'];
            }

            if (!empty($updates)) {
                $connection->update($updates);
            }

            if (!empty($company['email']) && empty($connection->client->contact_email)) {
                $connection->client->update(['contact_email' => $company['email']]);
            }

            Log::info('Factorial company info fetched', [
                'connection_id' => $connection->id,
            ]);
        } catch (\Throwable $e) {
            Log::warning('No se pudo obtener info de empresa Factorial', [
                'connection_id' => $connection->id,
                'error'         => $e->getMessage(),
            ]);
        }

        if ($connection->client_id) {
            $connection->client->update(['status' => 'active']);
        }

        return response()->json([
            'ok'      => true,
            'message' => 'Conexión autorizada correctamente. Ya puedes cerrar esta ventana.',
            'connection_id' => $connection->id,
        ]);
    }
}
