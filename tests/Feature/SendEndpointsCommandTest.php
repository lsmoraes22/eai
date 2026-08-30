<?php

use App\Models\CadEndpoint;
use App\Models\CadInterfaceStatus;
use App\Models\Client;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

function createSendClient(array $overrides = []): Client
{
    return Client::create(array_merge([
        'name' => 'Cliente Send',
        'code' => 'cliente-send',
        'telefone' => '0000-0000',
        'endereco' => 'Rua Teste',
        'cnpj' => '00000000000000',
        'active' => true,
    ], $overrides));
}

function createSendEndpoint(Client $client, array $overrides = []): CadEndpoint
{
    return CadEndpoint::create(array_merge([
        'client_id' => $client->id,
        'nome' => 'orders',
        'tipo' => 'REST',
        'metodo' => 'POST',
        'url' => 'https://api.test/orders',
        'extensao' => 'json',
        'headers' => [],
        'autenticacao' => 'bearer',
        'direcao' => 'saida',
        'timer' => 0,
        'next_run' => now()->subMinute(),
        'type_storage_token' => 'file',
        'auth_token' => null,
        'ativo' => true,
        'timeout' => 5,
        'tentativas' => 2,
        'payload' => '{}',
        'auth_api_way' => 'header',
    ], $overrides));
}

function prepareSendPayload(): void
{
    Storage::disk('local')->put('polling/cliente-send/json/outgoing/orders/raw/payload.json', '{"order":123}');

    CadInterfaceStatus::create([
        'int_direcao' => 'saida',
        'int_interface' => 'orders',
        'int_arquivo' => 'payload.json',
        'int_idoc' => 'payload',
        'int_status' => 0,
        'int_data_envio' => now(),
    ]);
}

test('send endpoints uses private storage for outgoing payloads and file tokens', function () {
    Storage::fake('local');
    Storage::fake('public');

    $client = createSendClient();
    $endpoint = createSendEndpoint($client);

    Storage::disk('local')->put('polling/cliente-send/json/outgoing/orders/raw/payload.json', '{"order":123}');
    Storage::disk('local')->put('token/cliente-send/orders/auth.txt', Crypt::encryptString('private-token'));

    CadInterfaceStatus::create([
        'int_direcao' => 'saida',
        'int_interface' => 'orders',
        'int_arquivo' => 'payload.json',
        'int_idoc' => 'payload',
        'int_status' => 0,
        'int_data_envio' => now(),
    ]);

    Http::fake(['*' => Http::response(['ok' => true], 200)]);

    $this->artisan('app:send-endpoints', ['--id' => $endpoint->id])
        ->assertExitCode(0);

    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer private-token'));

    expect(Storage::disk('public')->allFiles())->toBeEmpty()
        ->and(Storage::disk('local')->exists('polling/cliente-send/json/outgoing/orders/processed/payload.json'))->toBeTrue();
});

test('send endpoints reads json tokens using the default or configured key', function (?string $configuredKey, array $storedToken, string $expectedToken) {
    Storage::fake('local');
    Storage::fake('public');

    $client = createSendClient();
    $endpoint = createSendEndpoint($client, ['auth_token' => $configuredKey]);
    prepareSendPayload();
    Storage::disk('local')->put(
        'token/cliente-send/orders/auth.txt',
        Crypt::encryptString(json_encode($storedToken))
    );
    Http::fake(['*' => Http::response(['ok' => true])]);

    $this->artisan('app:send-endpoints', ['--id' => $endpoint->id])->assertExitCode(0);

    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', "Bearer {$expectedToken}"));
})->with([
    'default access_token' => [null, ['access_token' => 'json-default-token'], 'json-default-token'],
    'configured nested key' => ['credentials.token', ['credentials' => ['token' => 'configured-token']], 'configured-token'],
]);

test('send endpoints remains compatible with fixed tokens', function () {
    Storage::fake('local');
    Storage::fake('public');

    $client = createSendClient();
    $endpoint = createSendEndpoint($client, [
        'type_storage_token' => 'fixed',
        'auth_token' => 'fixed-token',
    ]);
    prepareSendPayload();
    Http::fake(['*' => Http::response(['ok' => true])]);

    $this->artisan('app:send-endpoints', ['--id' => $endpoint->id])->assertExitCode(0);

    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer fixed-token'));
});

test('send endpoints rejects a non scalar token value', function () {
    Storage::fake('local');
    Storage::fake('public');

    $client = createSendClient();
    $endpoint = createSendEndpoint($client);
    prepareSendPayload();
    Storage::disk('local')->put(
        'token/cliente-send/orders/auth.txt',
        Crypt::encryptString(json_encode(['access_token' => ['invalid-token']]))
    );
    Http::fake(['*' => Http::response(['ok' => true])]);

    $this->artisan('app:send-endpoints', ['--id' => $endpoint->id])
        ->expectsOutputToContain('Token ausente ou não escalar')
        ->assertExitCode(0);

    Http::assertSent(fn ($request) => !$request->hasHeader('Authorization'));
});

test('send endpoints sanitizes sensitive external response data', function () {
    Storage::fake('local');

    $client = createSendClient();
    $endpoint = createSendEndpoint($client, [
        'autenticacao' => 'nenhum',
        'type_storage_token' => 'fixed',
    ]);

    Storage::disk('local')->put('polling/cliente-send/json/outgoing/orders/raw/payload.json', '{"order":123}');

    CadInterfaceStatus::create([
        'int_direcao' => 'saida',
        'int_interface' => 'orders',
        'int_arquivo' => 'payload.json',
        'int_idoc' => 'payload',
        'int_status' => 0,
        'int_data_envio' => now(),
    ]);

    Http::fake([
        '*' => Http::response([
            'token' => 'external-token',
            'password' => 'external-password',
            'cpf' => '123.456.789-10',
            'cnpj' => '12.345.678/0001-90',
            'email' => 'person@example.test',
            'authorization' => 'Bearer external-auth',
            'message' => 'invalid',
        ], 400, ['X-Correlation-ID' => 'corr-123']),
    ]);

    $this->artisan('app:send-endpoints', ['--id' => $endpoint->id])
        ->assertExitCode(0);

    $message = CadInterfaceStatus::where('int_arquivo', 'payload.json')->value('int_mensagem');

    expect($message)->toContain('HTTP 400')
        ->and($message)->toContain('corr-123')
        ->and($message)->not->toContain('external-token')
        ->and($message)->not->toContain('external-password')
        ->and($message)->not->toContain('123.456.789-10')
        ->and($message)->not->toContain('12.345.678/0001-90')
        ->and($message)->not->toContain('person@example.test')
        ->and($message)->not->toContain('external-auth');
});

test('send endpoints remains compatible with public outgoing payloads', function () {
    Storage::fake('local');
    Storage::fake('public');

    $client = createSendClient();
    $endpoint = createSendEndpoint($client, [
        'autenticacao' => 'nenhum',
        'type_storage_token' => 'fixed',
    ]);

    Storage::disk('public')->put('polling/cliente-send/json/outgoing/orders/raw/payload.json', '{"order":123}');

    CadInterfaceStatus::create([
        'int_direcao' => 'saida',
        'int_interface' => 'orders',
        'int_arquivo' => 'payload.json',
        'int_idoc' => 'payload',
        'int_status' => 0,
        'int_data_envio' => now(),
    ]);

    Http::fake(['*' => Http::response(['ok' => true], 200)]);

    $this->artisan('app:send-endpoints', ['--id' => $endpoint->id])
        ->assertExitCode(0);

    expect(Storage::disk('public')->exists('polling/cliente-send/json/outgoing/orders/processed/payload.json'))->toBeTrue();
});
