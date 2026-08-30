<?php

use App\Console\Commands\FetchEndpoints;
use App\Models\CadEndpoint;
use App\Models\Client;
use Illuminate\Console\OutputStyle;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

uses(Tests\TestCase::class);

function fetchAuthenticationHeaders(CadEndpoint $endpoint, Client $client): array
{
    $command = new FetchEndpoints();
    $buffer = new BufferedOutput();
    $command->setOutput(new OutputStyle(new ArrayInput([]), $buffer));
    $headers = [];
    $method = new ReflectionMethod($command, 'applyAuthentication');
    $method->invokeArgs($command, [$endpoint, $client, &$headers]);

    return [$headers, $buffer->fetch()];
}

function fetchEndpoint(array $attributes = []): CadEndpoint
{
    return new CadEndpoint(array_merge([
        'nome' => 'orders',
        'autenticacao' => 'bearer',
        'type_storage_token' => 'file',
        'auth_token' => null,
    ], $attributes));
}

function fetchClient(array $attributes = []): Client
{
    return new Client(array_merge([
        'name' => 'Cliente Fetch',
        'code' => 'cliente-fetch',
    ], $attributes));
}

beforeEach(function () {
    Storage::fake('public');
});

test('fetch uses the default or configured key from json tokens', function (?string $key, array $content, string $expected) {
    Storage::disk('public')->put('token/cliente-fetch/orders/auth.txt', json_encode($content));

    [$headers] = fetchAuthenticationHeaders(fetchEndpoint(['auth_token' => $key]), fetchClient());

    expect($headers['Authorization'])->toBe("Bearer {$expected}");
})->with([
    'default access_token' => [null, ['access_token' => 'default-token'], 'default-token'],
    'configured path' => ['credentials.token', ['credentials' => ['token' => 'configured-token']], 'configured-token'],
]);

test('fetch supports plain text, fixed and client tokens', function (string $source, ?string $configured, ?string $clientToken, string $expected) {
    if ($source === 'file') {
        Storage::disk('public')->put('token/cliente-fetch/orders/auth.txt', "  {$expected}\n");
    }

    [$headers] = fetchAuthenticationHeaders(
        fetchEndpoint(['type_storage_token' => $source, 'auth_token' => $configured]),
        fetchClient(['access_token' => $clientToken])
    );

    expect($headers['Authorization'])->toBe("Bearer {$expected}");
})->with([
    'plain text file' => ['file', null, null, 'plain-token'],
    'fixed token' => ['fixed', 'fixed-token', null, 'fixed-token'],
    'client token' => ['client_token', null, 'client-token', 'client-token'],
]);

test('fetch rejects non scalar token values', function () {
    Storage::disk('public')->put('token/cliente-fetch/orders/auth.txt', json_encode([
        'access_token' => ['invalid-token'],
    ]));

    [$headers, $output] = fetchAuthenticationHeaders(fetchEndpoint(), fetchClient());

    expect($headers)->not->toHaveKey('Authorization')
        ->and($output)->toContain('Token ausente ou não escalar');
});

test('fetch preserves the original json when saving an auth response', function () {
    $status = Mockery::mock('alias:App\\Models\\CadInterfaceStatus');
    $status->shouldReceive('create')->once();

    $command = new FetchEndpoints();
    $buffer = new BufferedOutput();
    $command->setOutput(new OutputStyle(new ArrayInput([]), $buffer));
    $endpoint = fetchEndpoint([
        'nome' => 'auth-token',
        'direcao' => 'auth',
        'extensao' => 'json',
    ]);
    $body = json_encode([
        'access_token' => 'algar-token',
        'token_type' => 'bearer',
        'expires_in' => 3600,
    ]);
    $response = Mockery::mock();
    $response->shouldReceive('body')->once()->andReturn($body);
    $method = new ReflectionMethod($command, 'saveResponse');

    $method->invoke($command, $endpoint, 'cliente-fetch', $response, 'json');

    expect(Storage::disk('public')->get('token/cliente-fetch/auth-token/auth.txt'))->toBe($body);
});
