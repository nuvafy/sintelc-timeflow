<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FactorialAuthController extends Controller
{
    public function redirect()
    {
        $query = http_build_query([
            'client_id' => config('services.factorial.client_id'),
            'redirect_uri' => config('services.factorial.redirect'),
            'response_type' => 'code',
            'resource_owner_type' => 'company',
        ]);

        $url = config('services.factorial.base_url') . '/oauth/authorize?' . $query;

        return redirect($url);
    }

    public function callback(Request $request)
    {
        // 1. Manejo de error
        if ($request->filled('error')) {
            return response()->json([
                'ok' => false,
                'error' => $request->get('error'),
                'description' => $request->get('error_description'),
            ], 400);
        }

        // 2. Obtener code
        $code = $request->get('code');

        if (!$code) {
            return response()->json([
                'ok' => false,
                'message' => 'No se recibió authorization code'
            ], 400);
        }

        // 3. Intercambiar code por token
        $response = Http::asForm()->post(
            config('services.factorial.base_url') . '/oauth/token',
            [
                'client_id' => config('services.factorial.client_id'),
                'client_secret' => config('services.factorial.client_secret'),
                'code' => $code,
                'grant_type' => 'authorization_code',
                'redirect_uri' => config('services.factorial.redirect'),
            ]
        );

        if ($response->failed()) {
            Log::error('Factorial OAuth error', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return response()->json([
                'ok' => false,
                'message' => 'Error al obtener token',
                'response' => $response->json(),
            ], 500);
        }

        $data = $response->json();

        // 🔥 Aquí luego guardaremos en DB
        // access_token
        // refresh_token
        // expires_in

        return response()->json([
            'ok' => true,
            'message' => 'OAuth completado',
            'data' => $data,
        ]);
    }
}
