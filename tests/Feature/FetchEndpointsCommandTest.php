<?php

use App\Models\CadEndpoint;
use App\Models\Client;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

function createFetchClient(array $overrides = []): Client
{
    return Client::create(array_merge([
        'name' => 'Cliente Fetch',
        'code' => 'cliente-fetch',
        'telefone' => '0000-0000',
        'endereco' => 'Rua Teste',
        'cnpj' => '00000000000000',
        'active' => true,
    ], $overrides));
}

function createFetchEndpoint(Client $client, array $overrides = []): CadEndpoint
{
    return CadEndpoint::create(array_merge([
        'client_id' => $client->id,
        'nome' => 'orders',
        'tipo' => 'REST',
        'metodo' => 'GET',
        'url' => 'https://api.test/orders',
        'extensao' => 'json',
        'headers' => [],
        'autenticacao' => 'nenhum',
        'direcao' => 'entrada',
        'timer' => 0,
        'next_run' => now()->subMinute(),
        'type_storage_token' => 'fixed',
        'ativo' => true,
        'timeout' => 5,
        'tentativas' => 2,
        'payload' => '{}',
        'auth_api_way' => 'header',
    ], $overrides));
}

test('fetch endpoints blocks sensitive get payload before sending request', function () {
    $client = createFetchClient();
    $endpoint = createFetchEndpoint($client, [
        'payload' => json_encode(['email' => 'person@example.test', 'token' => 'secret-token']),
    ]);

    Http::fake();

    $this->artisan('app:fetch-endpoints', ['--id' => $endpoint->id])
        ->assertExitCode(0);

    Http::assertNothingSent();

    $this->assertDatabaseHas('cad_interface_status', [
        'int_interface' => 'orders',
        'int_status' => 2,
    ]);
});

test('fetch endpoints stores api responses on private encrypted disk', function () {
    Storage::fake('public');
    Storage::fake('local');

    $client = createFetchClient();
    $endpoint = createFetchEndpoint($client);

    Http::fake([
        '*' => Http::response(['access_token' => 'sensitive-response-token', 'ok' => true]),
    ]);

    $this->artisan('app:fetch-endpoints', ['--id' => $endpoint->id])
        ->assertExitCode(0);

    expect(Storage::disk('public')->allFiles())->toBeEmpty();

    $files = Storage::disk('local')->allFiles();
    expect($files)->toHaveCount(1);

    $stored = Storage::disk('local')->get($files[0]);
    expect($stored)->not->toContain('sensitive-response-token')
        ->and(Crypt::decryptString($stored))->toContain('sensitive-response-token');
});

test('fetch endpoints preserves json auth responses when storing them', function () {
    Storage::fake('local');

    $client = createFetchClient();
    $endpoint = createFetchEndpoint($client, [
        'nome' => 'auth-token',
        'metodo' => 'POST',
        'direcao' => 'auth',
    ]);

    Http::fake(['*' => Http::response(['access_token' => 'algar-token', 'expires_in' => 3600])]);

    $this->artisan('app:fetch-endpoints', ['--id' => $endpoint->id])->assertExitCode(0);

    $stored = Crypt::decryptString(Storage::disk('local')->get('token/cliente-fetch/auth-token/auth.txt'));

    expect(json_decode($stored, true))->toMatchArray([
        'access_token' => 'algar-token',
        'expires_in' => 3600,
    ]);
});

test('fetch endpoints reads the default access_token from a json file', function () {
    Storage::fake('local');

    $client = createFetchClient();
    $endpoint = createFetchEndpoint($client, [
        'autenticacao' => 'bearer',
        'type_storage_token' => 'file',
        'auth_token' => null,
    ]);

    Storage::disk('local')->put(
        'token/cliente-fetch/orders/auth.txt',
        Crypt::encryptString(json_encode(['access_token' => 'json-default-token', 'expires_in' => 3600]))
    );
    Http::fake(['*' => Http::response(['ok' => true])]);

    $this->artisan('app:fetch-endpoints', ['--id' => $endpoint->id])->assertExitCode(0);

    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer json-default-token'));
});

test('fetch endpoints reads a configured key from a json file', function () {
    Storage::fake('local');

    $client = createFetchClient();
    $endpoint = createFetchEndpoint($client, [
        'autenticacao' => 'bearer',
        'type_storage_token' => 'file',
        'auth_token' => 'credentials.token',
    ]);

    Storage::disk('local')->put(
        'token/cliente-fetch/orders/auth.txt',
        Crypt::encryptString(json_encode(['credentials' => ['token' => 'configured-token']]))
    );
    Http::fake(['*' => Http::response(['ok' => true])]);

    $this->artisan('app:fetch-endpoints', ['--id' => $endpoint->id])->assertExitCode(0);

    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer configured-token'));
});

test('fetch endpoints remains compatible with plain text and fixed tokens', function (string $storageType, string $expectedToken) {
    Storage::fake('local');

    $client = createFetchClient();
    $endpoint = createFetchEndpoint($client, [
        'autenticacao' => 'bearer',
        'type_storage_token' => $storageType,
        'auth_token' => $storageType === 'fixed' ? $expectedToken : null,
    ]);

    if ($storageType === 'file') {
        Storage::disk('local')->put('token/cliente-fetch/orders/auth.txt', Crypt::encryptString("  {$expectedToken}\n"));
    }

    Http::fake(['*' => Http::response(['ok' => true])]);

    $this->artisan('app:fetch-endpoints', ['--id' => $endpoint->id])->assertExitCode(0);

    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', "Bearer {$expectedToken}"));
})->with([
    'plain text file' => ['file', 'plain-text-token'],
    'fixed field' => ['fixed', 'fixed-token'],
]);

test('fetch endpoints rejects a non scalar token value', function () {
    Storage::fake('local');

    $client = createFetchClient();
    $endpoint = createFetchEndpoint($client, [
        'autenticacao' => 'bearer',
        'type_storage_token' => 'file',
        'auth_token' => null,
    ]);

    Storage::disk('local')->put(
        'token/cliente-fetch/orders/auth.txt',
        Crypt::encryptString(json_encode(['access_token' => ['invalid-token']]))
    );
    Http::fake(['*' => Http::response(['ok' => true])]);

    $this->artisan('app:fetch-endpoints', ['--id' => $endpoint->id])
        ->expectsOutputToContain('Token ausente ou não escalar')
        ->assertExitCode(0);

    Http::assertSent(fn ($request) => !$request->hasHeader('Authorization'));
});

test('fetch endpoints does not reprocess a successful idempotency key', function () {
    Storage::fake('local');

    $client = createFetchClient();
    $endpoint = createFetchEndpoint($client);

    Http::fake(['*' => Http::response(['ok' => true])]);

    $this->artisan('app:fetch-endpoints', ['--id' => $endpoint->id])
        ->assertExitCode(0);

    $this->artisan('app:fetch-endpoints', ['--id' => $endpoint->id])
        ->assertExitCode(0);

    Http::assertSentCount(1);
});

test('fetch endpoints marks repeated failures as dead', function () {
    $client = createFetchClient();
    $endpoint = createFetchEndpoint($client, [
        'tentativas' => 2,
    ]);

    Http::fake(['*' => Http::response(['error' => 'down'], 500)]);

    $this->artisan('app:fetch-endpoints', ['--id' => $endpoint->id])
        ->assertExitCode(0);

    $this->artisan('app:fetch-endpoints', ['--id' => $endpoint->id])
        ->assertExitCode(0);

    $this->assertDatabaseHas('cad_interface_status', [
        'int_interface' => 'orders',
        'int_status' => 5,
    ]);
});
