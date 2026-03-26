<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\FactorialConnection;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FactorialAuthController extends Controller
{
    public function redirect(Request $request)
    {
        // 🔑 por ahora lo dejamos fijo (luego lo hacemos dinámico)
        $client = Client::where('slug', 'sintelc-sandbox-zkt')->firstOrFail();

        $connection = FactorialConnection::where('client_id', $client->id)->firstOrFail();

        $query = http_build_query([
            'client_id' => $connection->oauth_client_id,
            'redirect_uri' => config('services.factorial.redirect'),
            'response_type' => 'code',
            'resource_owner_type' => $connection->resource_owner_type ?? 'company'
        ]);

        return redirect(config('services.factorial.base_url') . '/oauth/authorize?' . $query);
    }

    public function callback(Request $request)
    {
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

        $client = Client::where('slug', 'sintelc-sandbox-zkt')->firstOrFail();
        $connection = FactorialConnection::where('client_id', $client->id)->firstOrFail();

        $response = Http::asForm()->post(
            config('services.factorial.base_url') . '/oauth/token',
            [
                'client_id' => $connection->oauth_client_id,
                'client_secret' => $connection->oauth_client_secret,
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

        // 🔥 AQUÍ GUARDAMOS TODO
        $connection->update([
            'access_token' => $data['access_token'] ?? null,
            'refresh_token' => $data['refresh_token'] ?? null,
            'token_type' => $data['token_type'] ?? null,
            'expires_in' => $data['expires_in'] ?? null,
            'expires_at' => isset($data['expires_in'])
                ? Carbon::now()->addSeconds((int) $data['expires_in'])
                : null,
            'raw_response' => $data,
        ]);

        $client->update([
            'status' => 'active',
        ]);

        return response()->json([
            'ok' => true,
            'message' => 'Token guardado correctamente',
            'client' => $client->slug,
        ]);
    }
}
