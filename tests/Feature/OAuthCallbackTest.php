<?php

use App\Models\Client;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Illuminate\Support\Facades\Http;

function createOAuthClient(array $overrides = []): Client
{
    return Client::create(array_merge([
        'name' => 'Cliente Teste',
        'code' => 'cliente-teste',
        'telefone' => '0000-0000',
        'endereco' => 'Rua Teste',
        'cnpj' => '00000000000000',
        'active' => true,
        'auth_url' => 'https://provider.test/authorize',
        'token_url' => 'https://provider.test/token',
        'app_client_id' => 'client-id',
        'app_client_secret' => 'client-secret',
    ], $overrides));
}

beforeEach(function () {
    $user = User::factory()->create();
    Permission::findOrCreate('update_client', 'web');
    $user->givePermissionTo('update_client');
    $this->actingAs($user);
    Http::preventStrayRequests();
});

function callbackState($test, Client $client): string
{
    $response = $test->get(route('oauth.authorize', $client));
    $response->assertRedirect();
    parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
    return $query['state'];
}

test('oauth callback does not expose provider response body on token exchange failure', function () {
    $client = createOAuthClient();
    $state = callbackState($this, $client);

    Http::fake([
        '*' => Http::response([
            'error' => 'invalid_grant',
            'access_token' => 'sensitive-token-from-provider',
            'refresh_token' => 'sensitive-refresh-token-from-provider',
        ], 400),
    ]);

    $response = $this->get("/token/{$client->id}?code=auth-code&state={$state}");

    $response
        ->assertStatus(502)
        ->assertJson(['error' => 'Falha na autorização com o provedor.'])
        ->assertDontSee('sensitive-token-from-provider')
        ->assertDontSee('sensitive-refresh-token-from-provider');
});

test('oauth callback rejects insecure token urls before sending a request', function () {
    $client = createOAuthClient([
        'token_url' => 'http://provider.test/token',
    ]);
    $state = callbackState($this, $client);

    Http::fake();

    $response = $this->get("/token/{$client->id}?code=auth-code&state={$state}");

    $response
        ->assertStatus(422)
        ->assertJson(['error' => 'Configuração OAuth inválida.']);

    Http::assertNothingSent();
    expect(session('oauth_attempts.'.hash('sha256', $state)))->toBeNull();
});

test('oauth callback succeeds when state is valid', function () {
    $client = createOAuthClient();
    $state = callbackState($this, $client);

    Http::fake([
        '*' => Http::response([
            'access_token' => 'new-access-token',
            'refresh_token' => 'new-refresh-token',
            'expires_in' => 3600,
            'account_id' => 'account-123',
        ]),
    ]);

    $response = $this->get("/token/{$client->id}?code=auth-code&state={$state}");

    $response->assertRedirect('/admin/clients');

    expect($client->refresh()->access_token)->toBe('new-access-token')
        ->and($client->refresh_token)->toBe('new-refresh-token');

    expect(session('oauth_attempts.'.hash('sha256', $state)))->toBeNull();
});

test('oauth callback rejects missing state', function () {
    $client = createOAuthClient();

    Http::fake();

    $response = $this->get("/token/{$client->id}?code=auth-code");

    $response
        ->assertForbidden();

    Http::assertNothingSent();
});

test('oauth callback rejects mismatched state', function () {
    $client = createOAuthClient();
    $otherClient = createOAuthClient([
        'code' => 'outro-cliente',
        'cnpj' => '11111111111111',
    ]);
    $state = callbackState($this, $otherClient);

    Http::fake();

    $response = $this->get("/token/{$client->id}?code=auth-code&state={$state}");

    $response
        ->assertForbidden();

    Http::assertNothingSent();
});

test('oauth callback rejects expired state', function () {
    $client = createOAuthClient();
    $state = callbackState($this, $client);
    $attempts = session('oauth_attempts');
    $attempts[hash('sha256', $state)]['expires_at'] = time() - 1;
    $this->withSession(['oauth_attempts' => $attempts]);
    Http::fake();

    $response = $this->get("/token/{$client->id}?code=auth-code&state={$state}");

    $response
        ->assertForbidden();

    Http::assertNothingSent();
});

test('oauth callback consumes state after provider failure and requires a fresh authorization', function () {
    $client = createOAuthClient();
    $state = callbackState($this, $client);

    Http::fake(['*' => Http::response(['error' => 'temporarily_unavailable'], 503)]);

    $this->get("/token/{$client->id}?code=auth-code&state={$state}")
        ->assertStatus(502);

    expect(session('oauth_attempts.'.hash('sha256', $state)))->toBeNull();
});
