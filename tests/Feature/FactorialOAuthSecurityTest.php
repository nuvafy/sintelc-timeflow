<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\FactorialConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Vinkla\Hashids\Facades\Hashids;

class FactorialOAuthSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_start_oauth_flow(): void
    {
        $connection = $this->makeConnection();

        $this->get(route('oauth.factorial.redirect', [
            'connection_id' => Hashids::encode($connection->id),
        ]))->assertRedirect('/login');
    }

    public function test_client_user_cannot_start_oauth_flow(): void
    {
        $connection = $this->makeConnection();
        $user = User::factory()->create([
            'role' => 'client',
            'client_id' => $connection->client_id,
        ]);

        $this->actingAs($user)
            ->get(route('oauth.factorial.redirect', [
                'connection_id' => Hashids::encode($connection->id),
            ]))
            ->assertForbidden();
    }

    public function test_oauth_state_is_random_bound_to_user_and_single_use(): void
    {
        Http::fake([
            '*/oauth/token' => Http::response([
                'access_token' => $this->jwt(['company_id' => 321]),
                'refresh_token' => 'refresh-secret',
                'token_type' => 'Bearer',
                'expires_in' => 3600,
                'scope' => 'read write',
            ]),
            '*/resources/companies/companies' => Http::response([
                ['legal_name' => 'Secure Company', 'email' => 'secure@example.test'],
            ]),
        ]);

        $connection = $this->makeConnection();
        $admin = User::factory()->create(['role' => 'admin']);

        $redirect = $this->actingAs($admin)->get(route('oauth.factorial.redirect', [
            'connection_id' => Hashids::encode($connection->id),
        ]));

        $redirect->assertRedirect();
        parse_str(parse_url($redirect->headers->get('Location'), PHP_URL_QUERY), $query);

        $this->assertArrayHasKey('state', $query);
        $this->assertSame(64, strlen($query['state']));
        $this->assertSame([
            'connection_id' => $connection->id,
            'user_id' => $admin->id,
        ], Cache::get("factorial_oauth_state:{$query['state']}"));

        $this->get(route('oauth.factorial.callback', [
            'code' => 'authorization-secret',
            'state' => $query['state'],
        ]))->assertOk();

        $this->get(route('oauth.factorial.callback', [
            'code' => 'authorization-secret',
            'state' => $query['state'],
        ]))->assertBadRequest();

        $connection->refresh();
        $this->assertSame('refresh-secret', $connection->refresh_token);
        $this->assertArrayNotHasKey('access_token', $connection->raw_response);
        $this->assertArrayNotHasKey('refresh_token', $connection->raw_response);
    }

    public function test_client_oauth_secret_is_encrypted_at_rest(): void
    {
        $client = Client::create([
            'name' => 'Encrypted Client',
            'slug' => 'encrypted-client',
            'oauth_client_id' => 'client-id',
            'oauth_client_secret' => 'plain-secret',
        ]);

        $stored = DB::table('clients')->where('id', $client->id)->value('oauth_client_secret');

        $this->assertNotSame('plain-secret', $stored);
        $this->assertSame('plain-secret', $client->fresh()->oauth_client_secret);
    }

    private function makeConnection(): FactorialConnection
    {
        $client = Client::create([
            'name' => 'OAuth Client',
            'slug' => 'oauth-client-' . str()->random(8),
            'oauth_client_id' => 'factorial-client-id',
            'oauth_client_secret' => 'factorial-client-secret',
        ]);

        return FactorialConnection::create([
            'client_id' => $client->id,
            'name' => 'OAuth Connection',
            'resource_owner_type' => 'company',
        ]);
    }

    private function jwt(array $payload): string
    {
        $encode = fn(array $data): string => rtrim(strtr(base64_encode(json_encode($data)), '+/', '-_'), '=');

        return $encode(['alg' => 'none']) . '.' . $encode($payload) . '.';
    }
}
