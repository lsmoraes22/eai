<?php

use App\Models\Client;
use Illuminate\Support\Facades\Cache;
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
        'token_url' => 'https://provider.test/token',
        'app_client_id' => 'client-id',
        'app_client_secret' => 'client-secret',
    ], $overrides));
}

test('oauth callback does not expose provider response body on token exchange failure', function () {
    $client = createOAuthClient();
    $state = $client->generateOAuthState();

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
        ->assertJson(['error' => 'Falha na comunicação com o Bling'])
        ->assertDontSee('sensitive-token-from-provider')
        ->assertDontSee('sensitive-refresh-token-from-provider');
});

test('oauth callback rejects insecure token urls before sending a request', function () {
    $client = createOAuthClient([
        'token_url' => 'http://provider.test/token',
    ]);
    $state = $client->generateOAuthState();

    Http::fake();

    $response = $this->get("/token/{$client->id}?code=auth-code&state={$state}");

    $response
        ->assertStatus(422)
        ->assertJson(['error' => 'Configuração OAuth inválida.']);

    Http::assertNothingSent();
    expect(Cache::has(Client::oauthStateCacheKey($state)))->toBeTrue();
});

test('oauth callback succeeds when state is valid', function () {
    $client = createOAuthClient();
    $state = $client->generateOAuthState();

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

    expect(Cache::has(Client::oauthStateCacheKey($state)))->toBeFalse();
});

test('oauth callback rejects missing state', function () {
    $client = createOAuthClient();

    Http::fake();

    $response = $this->get("/token/{$client->id}?code=auth-code");

    $response
        ->assertStatus(419)
        ->assertJson(['error' => 'State OAuth inválido ou expirado.']);

    Http::assertNothingSent();
});

test('oauth callback rejects mismatched state', function () {
    $client = createOAuthClient();
    $otherClient = createOAuthClient([
        'code' => 'outro-cliente',
        'cnpj' => '11111111111111',
    ]);
    $state = $otherClient->generateOAuthState();

    Http::fake();

    $response = $this->get("/token/{$client->id}?code=auth-code&state={$state}");

    $response
        ->assertStatus(419)
        ->assertJson(['error' => 'State OAuth inválido ou expirado.']);

    Http::assertNothingSent();
});

test('oauth callback rejects expired state', function () {
    $client = createOAuthClient();
    $state = 'expired-state';

    Cache::put(Client::oauthStateCacheKey($state), $client->id, now()->subMinute());
    Http::fake();

    $response = $this->get("/token/{$client->id}?code=auth-code&state={$state}");

    $response
        ->assertStatus(419)
        ->assertJson(['error' => 'State OAuth inválido ou expirado.']);

    Http::assertNothingSent();
});

test('oauth callback keeps valid state after provider failure for retry', function () {
    $client = createOAuthClient();
    $state = $client->generateOAuthState();

    Http::fake(['*' => Http::response(['error' => 'temporarily_unavailable'], 503)]);

    $this->get("/token/{$client->id}?code=auth-code&state={$state}")
        ->assertStatus(502);

    expect(Cache::has(Client::oauthStateCacheKey($state)))->toBeTrue();
});
