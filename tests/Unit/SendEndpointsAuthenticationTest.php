<?php

use App\Console\Commands\SendEndpoints;
use App\Models\CadEndpoint;
use App\Models\Client;
use Illuminate\Console\OutputStyle;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

uses(Tests\TestCase::class);

function sendAuthenticationHeaders(CadEndpoint $endpoint, Client $client): array
{
    $command = new SendEndpoints();
    $buffer = new BufferedOutput();
    $command->setOutput(new OutputStyle(new ArrayInput([]), $buffer));
    $headers = [];
    $method = new ReflectionMethod($command, 'applyAuthentication');
    $method->invokeArgs($command, [$endpoint, $client, &$headers]);

    return [$headers, $buffer->fetch()];
}

function sendEndpoint(array $attributes = []): CadEndpoint
{
    return new CadEndpoint(array_merge([
        'nome' => 'orders',
        'autenticacao' => 'bearer',
        'type_storage_token' => 'file',
        'auth_token' => null,
    ], $attributes));
}

function sendClient(array $attributes = []): Client
{
    return new Client(array_merge([
        'name' => 'Cliente Send',
        'code' => 'cliente-send',
    ], $attributes));
}

beforeEach(function () {
    Storage::fake('public');
});

test('send uses the default or configured key from json tokens', function (?string $key, array $content, string $expected) {
    Storage::disk('public')->put('token/cliente-send/orders/auth.txt', json_encode($content));

    [$headers] = sendAuthenticationHeaders(sendEndpoint(['auth_token' => $key]), sendClient());

    expect($headers['Authorization'])->toBe("Bearer {$expected}");
})->with([
    'default access_token' => [null, ['access_token' => 'default-token'], 'default-token'],
    'configured path' => ['credentials.token', ['credentials' => ['token' => 'configured-token']], 'configured-token'],
]);

test('send supports plain text, fixed and client tokens', function (string $source, ?string $configured, ?string $clientToken, string $expected) {
    if ($source === 'file') {
        Storage::disk('public')->put('token/cliente-send/orders/auth.txt', "  {$expected}\n");
    }

    [$headers] = sendAuthenticationHeaders(
        sendEndpoint(['type_storage_token' => $source, 'auth_token' => $configured]),
        sendClient(['access_token' => $clientToken])
    );

    expect($headers['Authorization'])->toBe("Bearer {$expected}");
})->with([
    'plain text file' => ['file', null, null, 'plain-token'],
    'fixed token' => ['fixed', 'fixed-token', null, 'fixed-token'],
    'client token' => ['client_token', null, 'client-token', 'client-token'],
]);

test('send rejects non scalar token values', function () {
    Storage::disk('public')->put('token/cliente-send/orders/auth.txt', json_encode([
        'access_token' => ['invalid-token'],
    ]));

    [$headers, $output] = sendAuthenticationHeaders(sendEndpoint(), sendClient());

    expect($headers)->not->toHaveKey('Authorization')
        ->and($output)->toContain('Token ausente ou não escalar');
});
