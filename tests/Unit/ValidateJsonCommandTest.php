<?php

use App\Models\CadEndpoint;
use App\Models\CadInterfaceStatus;
use App\Models\Client;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

uses(Tests\UnitTestCase::class);

function validationSchema(): string
{
    return <<<'JSON'
{
  "$schema": "http://json-schema.org/draft-06/schema#",
  "type": "object",
  "required": ["data", "meta"],
  "properties": {
    "data": {
      "type": "array",
      "items": {
        "type": "object",
        "required": ["id", "dateDue", "totalAmount", "status"],
        "properties": {
          "id": {"type": "string"},
          "dateDue": {"type": "string", "format": "date-time"},
          "datePayment": {"type": ["string", "null"], "format": "date-time"},
          "totalAmount": {"type": "number"},
          "status": {"type": "string"}
        },
        "additionalProperties": true
      }
    },
    "meta": {"type": "object", "additionalProperties": true}
  },
  "additionalProperties": true
}
JSON;
}

function validationEndpoint(string $name = 'external_invoices'): array
{
    $client = Client::create(['name' => 'Validation Client', 'code' => 'validation-client']);
    $endpoint = CadEndpoint::create([
        'client_id' => $client->id,
        'nome' => $name,
        'extensao' => 'json',
        'direcao' => 'entrada',
    ]);

    return [$client, $endpoint];
}

function validationRaw(string $filename, string $content, string $endpointSlug = 'external-invoices'): string
{
    $path = "polling/validation-client/json/incoming/{$endpointSlug}/raw/{$filename}";
    Storage::disk('integrations')->put($path, $content);

    return $path;
}

function validationPutSchema(string $content): string
{
    $path = 'polling/validation-client/schema/external-invoices.json';
    Storage::disk('integrations')->put($path, $content);

    return $path;
}

function validationStatus(string $filename, int $status = 0): CadInterfaceStatus
{
    return CadInterfaceStatus::create([
        'int_arquivo' => $filename,
        'int_status' => $status,
        'int_mensagem' => 'pending',
    ]);
}

beforeEach(function () {
    Storage::fake('integrations');
    Cache::flush();

    Schema::create('clients', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->string('code');
        $table->timestamps();
    });
    Schema::create('cad_endpoints', function (Blueprint $table) {
        $table->id();
        $table->foreignId('client_id');
        $table->string('nome');
        $table->string('extensao');
        $table->string('direcao');
        $table->timestamps();
    });
    Schema::create('cad_interface_status', function (Blueprint $table) {
        $table->id('int_id');
        $table->string('int_arquivo')->nullable();
        $table->integer('int_status')->nullable();
        $table->string('int_mensagem')->nullable();
    });
});

test('a missing schema leaves raw reprocessable and status unchanged', function () {
    validationEndpoint();
    $raw = validationRaw('missing-schema.json', '{"data":[],"meta":{}}');
    $status = validationStatus('missing-schema.json');

    $this->artisan('app:validate-json')
        ->expectsOutputToContain(
            'Schema ausente para o endpoint external_invoices. Esperado: polling/validation-client/schema/external-invoices.json'
        )
        ->assertExitCode(0);

    Storage::disk('integrations')->assertExists($raw);
    Storage::disk('integrations')->assertMissing(str_replace('/raw/', '/validated/', $raw));
    expect($status->refresh()->int_status)->toBe(0);
});

test('malformed schema json is isolated and leaves raw reprocessable', function () {
    validationEndpoint();
    validationPutSchema('{"type":');
    $raw = validationRaw('malformed-schema.json', '{"data":[],"meta":{}}');

    $this->artisan('app:validate-json')
        ->expectsOutputToContain('Schema JSON inválido')
        ->assertExitCode(0);

    Storage::disk('integrations')->assertExists($raw);
    Storage::disk('integrations')->assertMissing(str_replace('/raw/', '/validated/', $raw));
});

test('a structurally invalid schema exception is controlled and preserves raw', function () {
    validationEndpoint();
    validationPutSchema('{"type":"bogus"}');
    $raw = validationRaw('invalid-schema.json', '{"data":[],"meta":{}}');

    $this->artisan('app:validate-json')
        ->expectsOutputToContain('Falha no schema ao validar invalid-schema.json')
        ->assertExitCode(0);

    Storage::disk('integrations')->assertExists($raw);
});

test('syntactically invalid input moves to refused and updates status after move', function () {
    validationEndpoint();
    validationPutSchema(validationSchema());
    $raw = validationRaw('syntax.json', '{"data":[');
    $status = validationStatus('syntax.json');

    $this->artisan('app:validate-json')
        ->expectsOutputToContain('JSON RECUSADO: syntax.json - Erro de sintaxe JSON')
        ->assertExitCode(0);

    Storage::disk('integrations')->assertMissing($raw);
    Storage::disk('integrations')->assertExists(str_replace('/raw/', '/refused/', $raw));
    expect($status->refresh()->int_status)->toBe(2)
        ->and($status->int_mensagem)->toBe('Erro de sintaxe JSON');
});

test('flexible draft six schema accepts valid documents', function (string $content) {
    validationEndpoint();
    validationPutSchema(validationSchema());
    $raw = validationRaw('valid.json', $content);
    $status = validationStatus('valid.json');

    $this->artisan('app:validate-json')->assertExitCode(0);

    Storage::disk('integrations')->assertMissing($raw);
    Storage::disk('integrations')->assertExists(str_replace('/raw/', '/validated/', $raw));
    expect($status->refresh()->int_status)->toBe(1);
})->with([
    'extra fields and nullable payment' => [json_encode([
        'data' => [[
            'id' => 'A',
            'dateDue' => '2026-09-20T03:00:00Z',
            'datePayment' => null,
            'totalAmount' => 87.04,
            'status' => 'pending',
            'urlReport' => 'https://example.test/report',
        ]],
        'meta' => ['currentPage' => 1, 'providerField' => true],
        'rootExtra' => 'allowed',
    ], JSON_THROW_ON_ERROR)],
    'empty data array' => ['{"data":[],"meta":{"currentPage":1},"extra":true}'],
]);

test('documents that violate the schema move to refused', function (string $content, string $expectedProperty) {
    validationEndpoint();
    validationPutSchema(validationSchema());
    $raw = validationRaw('invalid-document.json', $content);
    $status = validationStatus('invalid-document.json');

    $this->artisan('app:validate-json')
        ->expectsOutputToContain($expectedProperty)
        ->assertExitCode(0);

    Storage::disk('integrations')->assertExists(str_replace('/raw/', '/refused/', $raw));
    Storage::disk('integrations')->assertMissing(str_replace('/raw/', '/validated/', $raw));
    expect($status->refresh()->int_status)->toBe(2);
})->with([
    'missing required id' => [
        '{"data":[{"dateDue":"2026-09-20T03:00:00Z","totalAmount":87.04,"status":"pending"}],"meta":{}}',
        'id',
    ],
    'amount has wrong type' => [
        '{"data":[{"id":"A","dateDue":"2026-09-20T03:00:00Z","totalAmount":"87.04","status":"pending"}],"meta":{}}',
        'totalAmount',
    ],
]);

test('a false move on validation success leaves status unchanged', function () {
    validationEndpoint();
    validationPutSchema(validationSchema());
    $raw = validationRaw('success-move-false.json', '{"data":[],"meta":{}}');
    $status = validationStatus('success-move-false.json');
    $realDisk = Storage::disk('integrations');
    $disk = Mockery::mock($realDisk);
    $disk->shouldReceive('move')->once()->andReturnFalse();
    Storage::shouldReceive('disk')->with('integrations')->andReturn($disk);

    $this->artisan('app:validate-json')
        ->expectsOutputToContain('Falha ao mover success-move-false.json')
        ->assertExitCode(0);

    $realDisk->assertExists($raw);
    expect($status->refresh()->int_status)->toBe(0);
});

test('a false move on refusal leaves status unchanged', function () {
    validationEndpoint();
    validationPutSchema(validationSchema());
    $raw = validationRaw('refusal-move-false.json', '{"data":[');
    $status = validationStatus('refusal-move-false.json');
    $realDisk = Storage::disk('integrations');
    $disk = Mockery::mock($realDisk);
    $disk->shouldReceive('move')->once()->andReturnFalse();
    Storage::shouldReceive('disk')->with('integrations')->andReturn($disk);

    $this->artisan('app:validate-json')->assertExitCode(0);

    $realDisk->assertExists($raw);
    expect($status->refresh()->int_status)->toBe(0);
});

test('a move exception is controlled and leaves status unchanged', function () {
    validationEndpoint();
    validationPutSchema(validationSchema());
    $raw = validationRaw('move-exception.json', '{"data":[],"meta":{}}');
    $status = validationStatus('move-exception.json');
    $realDisk = Storage::disk('integrations');
    $disk = Mockery::mock($realDisk);
    $disk->shouldReceive('move')->once()->andThrow(new RuntimeException('move failed'));
    Storage::shouldReceive('disk')->with('integrations')->andReturn($disk);

    $this->artisan('app:validate-json')
        ->expectsOutputToContain('move failed')
        ->assertExitCode(0);

    $realDisk->assertExists($raw);
    expect($status->refresh()->int_status)->toBe(0);
});

test('a paginated filename is preserved after validation', function () {
    validationEndpoint();
    validationPutSchema(validationSchema());
    $filename = '20260901114440151-page-000001.json';
    $raw = validationRaw($filename, '{"data":[],"meta":{}}');

    $this->artisan('app:validate-json')->assertExitCode(0);

    Storage::disk('integrations')->assertMissing($raw);
    Storage::disk('integrations')->assertExists(str_replace('/raw/', '/validated/', $raw));
});

test('a move failure for one file does not prevent processing the next file', function () {
    validationEndpoint();
    validationPutSchema(validationSchema());
    $first = validationRaw('a-first.json', '{"data":[],"meta":{}}');
    $second = validationRaw('b-second.json', '{"data":[],"meta":{}}');
    $firstStatus = validationStatus('a-first.json');
    $secondStatus = validationStatus('b-second.json');
    $realDisk = Storage::disk('integrations');
    $calls = 0;
    $disk = Mockery::mock($realDisk);
    $disk->shouldReceive('move')->twice()->andReturnUsing(
        function ($from, $to) use (&$calls, $realDisk) {
            $calls++;
            return $calls === 1 ? false : $realDisk->move($from, $to);
        }
    );
    Storage::shouldReceive('disk')->with('integrations')->andReturn($disk);

    $this->artisan('app:validate-json')->assertExitCode(0);

    $realDisk->assertExists($first);
    $realDisk->assertExists(str_replace('/raw/', '/validated/', $second));
    expect($firstStatus->refresh()->int_status)->toBe(0)
        ->and($secondStatus->refresh()->int_status)->toBe(1);
});

test('an existing destination is never overwritten', function () {
    validationEndpoint();
    validationPutSchema(validationSchema());
    $raw = validationRaw('collision.json', '{"data":[],"meta":{}}');
    $target = str_replace('/raw/', '/validated/', $raw);
    Storage::disk('integrations')->put($target, '{"preexisting":true}');
    $status = validationStatus('collision.json');

    $this->artisan('app:validate-json')
        ->expectsOutputToContain('destino já existe')
        ->assertExitCode(0);

    Storage::disk('integrations')->assertExists($raw);
    expect(Storage::disk('integrations')->get($target))->toBe('{"preexisting":true}')
        ->and($status->refresh()->int_status)->toBe(0);
});
