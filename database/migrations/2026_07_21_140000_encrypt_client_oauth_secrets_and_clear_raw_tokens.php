<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('clients')
            ->whereNotNull('oauth_client_secret')
            ->where('oauth_client_secret', '!=', '')
            ->orderBy('id')
            ->each(function ($client): void {
                try {
                    Crypt::decryptString($client->oauth_client_secret);
                } catch (Throwable) {
                    DB::table('clients')->where('id', $client->id)->update([
                        'oauth_client_secret' => Crypt::encryptString($client->oauth_client_secret),
                    ]);
                }
            });

        // Las respuestas históricas podían contener copias en texto plano de los tokens.
        DB::table('factorial_connections')->whereNotNull('raw_response')->update([
            'raw_response' => null,
        ]);
    }

    public function down(): void
    {
        // El cifrado y la eliminación de copias históricas de tokens son intencionalmente irreversibles.
    }
};
