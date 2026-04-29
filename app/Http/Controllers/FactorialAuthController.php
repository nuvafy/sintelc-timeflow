<?php

namespace App\Http\Controllers;

use App\Models\FactorialConnection;
use App\Services\FactorialService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FactorialAuthController extends Controller
{
    public function redirect(Request $request)
    {
        $connectionId = $request->query('connection_id');

        if (! $connectionId) {
            return response()->json(['ok' => false, 'message' => 'connection_id es requerido'], 400);
        }

        $connection = FactorialConnection::with('client')->findOrFail($connectionId);

        $query = http_build_query([
            'client_id'           => $connection->client->oauth_client_id,
            'redirect_uri'        => config('services.factorial.redirect'),
            'response_type'       => 'code',
            'resource_owner_type' => $connection->resource_owner_type ?? 'company',
            'state'               => $connection->id,
        ]);

        return redirect(config('services.factorial.base_url') . '/oauth/authorize?' . $query);
    }

    public function callback(Request $request)
    {
        Log::info('Factorial OAuth callback', ['all' => $request->all(), 'query' => $request->query()]);

        if ($request->filled('error')) {
            return response()->json([
                'ok' => false,
                'error' => $request->input('error'),
                'description' => $request->input('error_description'),
            ], 400);
        }

        $code = $request->input('code');

        if (! $code) {
            return response()->json([
                'ok' => false,
                'message' => 'No se recibió authorization code',
            ], 400);
        }

        $connectionId = $request->input('state');

        if (! $connectionId) {
            return response()->json(['ok' => false, 'message' => 'state (connection_id) ausente en el callback'], 400);
        }

        $connection = FactorialConnection::with('client')->findOrFail($connectionId);

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
                'body' => $response->body(),
            ]);

            return response()->json([
                'ok' => false,
                'message' => 'No se pudo obtener token',
                'details' => $response->json(),
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
            'raw_response'  => $data,
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

            if (!empty($company['email']) && empty($connection->contact_email)) {
                $updates['contact_email'] = $company['email'];
            }

            if (!empty($updates)) {
                $connection->update($updates);
            }

            Log::info('Factorial company info fetched', [
                'connection_id' => $connection->id,
                'company'       => $company,
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
