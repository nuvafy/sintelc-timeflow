<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Re-encripta access_token y refresh_token de factorial_connections
     * que estén en texto plano. Idempotente: si ya están encriptados los deja.
     */
    public function up(): void
    {
        DB::table('factorial_connections')
            ->whereNotNull('access_token')
            ->get()
            ->each(function ($row) {
                $updates = [];

                if ($row->access_token && ! $this->isEncrypted($row->access_token)) {
                    $updates['access_token'] = Crypt::encryptString($row->access_token);
                }

                if ($row->refresh_token && ! $this->isEncrypted($row->refresh_token)) {
                    $updates['refresh_token'] = Crypt::encryptString($row->refresh_token);
                }

                if (! empty($updates)) {
                    DB::table('factorial_connections')
                        ->where('id', $row->id)
                        ->update($updates);
                }
            });
    }

    public function down(): void
    {
        // No revertible: no almacenamos tokens en texto plano
    }

    private function isEncrypted(string $value): bool
    {
        try {
            Crypt::decryptString($value);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }
};
