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
