<?php

use App\Console\Commands\FetchEndpoints;
use App\Models\CadEndpoint;
use App\Models\Client;
use Illuminate\Console\OutputStyle;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

uses(Tests\UnitTestCase::class);

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
    Storage::fake('integrations');
    Schema::create('cad_interface_status', function (Blueprint $table) {
        $table->id('int_id');
        $table->string('int_direcao')->nullable();
        $table->string('int_interface')->nullable();
        $table->string('int_arquivo')->nullable();
        $table->string('int_idoc')->nullable();
        $table->integer('int_status')->nullable();
        $table->dateTime('int_data_processamento')->nullable();
        $table->string('int_envio')->nullable();
        $table->string('int_mensagem')->nullable();
        $table->dateTime('int_data_envio');
    });
});

test('fetch uses the default or configured key from json tokens', function (?string $key, array $content, string $expected) {
    Storage::disk('integrations')->put('token/cliente-fetch/orders/auth.txt', json_encode($content));

    [$headers] = fetchAuthenticationHeaders(fetchEndpoint(['auth_token' => $key]), fetchClient());

    expect($headers['Authorization'])->toBe("Bearer {$expected}");
})->with([
    'default access_token' => [null, ['access_token' => 'default-token'], 'default-token'],
    'configured path' => ['credentials.token', ['credentials' => ['token' => 'configured-token']], 'configured-token'],
]);

test('fetch supports plain text, fixed and client tokens', function (string $source, ?string $configured, ?string $clientToken, string $expected) {
    if ($source === 'file') {
        Storage::disk('integrations')->put('token/cliente-fetch/orders/auth.txt', "  {$expected}\n");
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
    Storage::disk('integrations')->put('token/cliente-fetch/orders/auth.txt', json_encode([
        'access_token' => ['invalid-token'],
    ]));

    [$headers, $output] = fetchAuthenticationHeaders(fetchEndpoint(), fetchClient());

    expect($headers)->not->toHaveKey('Authorization')
        ->and($output)->toContain('Token ausente ou não escalar');
});

test('fetch preserves response bodies exactly as received in raw storage', function () {
    Storage::fake('public');
    $command = new FetchEndpoints();
    $buffer = new BufferedOutput();
    $command->setOutput(new OutputStyle(new ArrayInput([]), $buffer));
    $method = new ReflectionMethod($command, 'saveResponse');

    $cases = [
        'json' => [
            'direction' => 'entrada',
            'extension' => 'json',
            'body' => '{"data":[],"meta":{"currentPage":1,"pageSize":10,"totalItems":0,"totalPages":1}}',
        ],
        'json-escaped-unicode' => [
            'direction' => 'entrada',
            'extension' => 'json',
            'body' => '{"message":"Ele disse: \\"Olá, mundo!\\"","city":"São Paulo","emoji":"🚀"}',
        ],
        'xml' => [
            'direction' => 'entrada',
            'extension' => 'xml',
            'body' => '<?xml version="1.0" encoding="UTF-8"?><item id="42" active="true">valor</item>',
        ],
        'csv' => [
            'direction' => 'entrada',
            'extension' => 'csv',
            'body' => "id,description\r\n1,\"valor, com vírgula\"\r\n",
        ],
        'txt' => [
            'direction' => 'entrada',
            'extension' => 'txt',
            'body' => 'Texto contendo "aspas" deve permanecer intacto.',
        ],
        'auth' => [
            'direction' => 'auth',
            'extension' => 'json',
            'body' => '{"access_token":"algar-token","token_type":"bearer","expires_in":3600}',
        ],
        'invalid-json' => [
            'direction' => 'entrada',
            'extension' => 'json',
            'body' => '{"data":[invalid],"meta":{"currentPage":1}}',
        ],
    ];

    foreach ($cases as $name => $case) {
        $endpoint = fetchEndpoint([
            'nome' => $name,
            'direcao' => $case['direction'],
            'extensao' => $case['extension'],
        ]);
        $response = Mockery::mock();
        $response->shouldReceive('body')->once()->andReturn($case['body']);

        $method->invoke($command, $endpoint, 'cliente-fetch', $response, $case['extension']);

        if ($case['direction'] === 'auth') {
            expect(Storage::disk('integrations')->get('token/cliente-fetch/auth/auth.txt'))->toBe($case['body']);
        } else {
            $path = "polling/cliente-fetch/{$case['extension']}/incoming/{$name}/raw";
            $files = Storage::disk('integrations')->files($path);

            expect($files)->toHaveCount(1)
                ->and(Storage::disk('integrations')->get($files[0]))->toBe($case['body']);
        }
    }

    $savedJson = Storage::disk('integrations')->get(Storage::disk('integrations')->files(
        'polling/cliente-fetch/json/incoming/json/raw'
    )[0]);

    expect(json_decode($savedJson, true))->toBe([
        'data' => [],
        'meta' => [
            'currentPage' => 1,
            'pageSize' => 10,
            'totalItems' => 0,
            'totalPages' => 1,
        ],
    ])->and(json_last_error())->toBe(JSON_ERROR_NONE);
});
