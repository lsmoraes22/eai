<?php

use App\Models\CadEndpoint;
use App\Models\CadInterfaceStatus;
use App\Models\CadProcesso;
use App\Models\CadProcessosDepara;
use App\Models\Client;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

uses(Tests\UnitTestCase::class);

function collectionClient(string $code = 'collection-client'): Client
{
    return Client::create(['name' => 'Collection Client', 'code' => $code]);
}

function collectionEndpoint(Client $client, string $name, string $direction): CadEndpoint
{
    return CadEndpoint::create([
        'client_id' => $client->id,
        'nome' => $name,
        'direcao' => $direction,
        'extensao' => 'JSON',
        'ativo' => true,
        'url' => 'https://destination.test/items',
        'metodo' => 'POST',
        'autenticacao' => 'nenhum',
        'timeout' => 30,
    ]);
}

function collectionProcess(CadEndpoint $input, CadEndpoint $output, array $overrides = []): CadProcesso
{
    $process = CadProcesso::create(array_merge([
        'name' => 'collection_process',
        'input_endpoint_id' => $input->id,
        'output_endpoint_id' => $output->id,
        'input_collection_path' => 'data',
        'output_mode' => 'per_item',
        'initial_format' => 'JSON',
        'final_format' => 'JSON',
        'active' => true,
        'user_create_id' => 1,
    ], $overrides));

    CadProcessosDepara::create([
        'processo_id' => $process->id,
        'input_path' => 'customer.id',
        'output_path' => 'account.customerId',
        'data_type' => 'string',
        'active' => true,
        'order' => 1,
    ]);

    return $process;
}

function collectionInput(Client $client, string $endpointSlug, string $filename, string $content): string
{
    $path = "polling/{$client->code}/json/incoming/{$endpointSlug}/validated/{$filename}";
    Storage::disk('integrations')->put($path, $content);

    return $path;
}

function collectionContext(array $processOverrides = []): array
{
    $client = collectionClient();
    $input = collectionEndpoint($client, 'source_items', 'entrada');
    $output = collectionEndpoint($client, 'destination items', 'saida');
    $process = collectionProcess($input, $output, $processOverrides);

    return [$client, $input, $output, $process];
}

beforeEach(function () {
    Storage::fake('integrations');
    Cache::flush();

    Schema::create('clients', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->string('code');
        $table->string('access_token')->nullable();
        $table->dateTime('expires_at')->nullable();
        $table->timestamps();
    });
    Schema::create('cad_endpoints', function (Blueprint $table) {
        $table->id();
        $table->foreignId('client_id');
        $table->string('nome');
        $table->string('direcao');
        $table->string('extensao');
        $table->boolean('ativo')->default(true);
        $table->string('url')->nullable();
        $table->string('metodo')->nullable();
        $table->string('autenticacao')->nullable();
        $table->string('type_storage_token')->nullable();
        $table->text('auth_token')->nullable();
        $table->json('headers')->nullable();
        $table->integer('timeout')->nullable();
        $table->timestamps();
    });
    Schema::create('cad_processos', function (Blueprint $table) {
        $table->id();
        $table->foreignId('input_endpoint_id')->nullable();
        $table->foreignId('output_endpoint_id')->nullable();
        $table->string('input_collection_path')->nullable();
        $table->string('output_mode')->nullable();
        $table->string('name');
        $table->string('initial_format');
        $table->string('final_format');
        $table->boolean('active');
        $table->bigInteger('user_create_id');
        $table->timestamps();
    });
    Schema::create('cad_processos_deparas', function (Blueprint $table) {
        $table->id();
        $table->foreignId('processo_id');
        $table->string('input_path');
        $table->string('output_path');
        $table->unsignedInteger('order')->default(0);
        $table->string('data_type');
        $table->string('default_value')->nullable();
        $table->boolean('active');
        $table->timestamps();
    });
    Schema::create('cad_interface_status', function (Blueprint $table) {
        $table->id('int_id');
        $table->string('int_arquivo')->nullable();
        $table->integer('int_status')->nullable();
        $table->string('int_mensagem')->nullable();
        $table->timestamps();
    });
});

test('per item transforms one item relative to the item and preserves nested output paths', function () {
    [$client, , , $process] = collectionContext();
    $input = collectionInput(
        $client,
        'source-items',
        'page-000001.json',
        '{"data":[{"customer":{"id":"A"}}]}'
    );

    $this->artisan('app:convert-json-json')->assertExitCode(0);

    $output = 'polling/collection-client/json/outgoing/destination-items/raw/page-000001-item-000001.json';
    Storage::disk('integrations')->assertExists($output);
    expect(json_decode(Storage::disk('integrations')->get($output), true))->toBe([
        'account' => ['customerId' => 'A'],
    ]);
    Storage::disk('integrations')->assertExists(str_replace('/validated/', '/processed/', $input));

    $lockKey = 'eai:transform-item:' . hash('sha256', "{$process->id}|{$input}");
    $lock = Cache::lock($lockKey, 600);
    expect($lock->get())->toBeTrue();
    $lock->release();

    $targetLockKey = 'eai:transform-target:' . hash('sha256', $output);
    $targetLock = Cache::lock($targetLockKey, 60);
    expect($targetLock->get())->toBeTrue();
    $targetLock->release();
});

test('per item creates deterministic zero padded names for every item of a paginated input', function () {
    [$client] = collectionContext();
    collectionInput(
        $client,
        'source-items',
        '20260901163000123-page-000001.json',
        '{"data":[{"customer":{"id":"A"}},{"customer":{"id":"B"}}]}'
    );

    $this->artisan('app:convert-json-json')->assertExitCode(0);

    $raw = 'polling/collection-client/json/outgoing/destination-items/raw';
    expect(Storage::disk('integrations')->files($raw))->toBe([
        "{$raw}/20260901163000123-page-000001-item-000001.json",
        "{$raw}/20260901163000123-page-000001-item-000002.json",
    ]);
});

test('an empty collection succeeds with no outputs and processes the input', function () {
    [$client] = collectionContext();
    $input = collectionInput($client, 'source-items', 'empty.json', '{"data":[]}');
    $status = CadInterfaceStatus::create(['int_arquivo' => 'empty.json', 'int_status' => 1]);

    $this->artisan('app:convert-json-json')->assertExitCode(0);

    Storage::disk('integrations')->assertDirectoryEmpty(
        'polling/collection-client/json/outgoing/destination-items/raw'
    );
    Storage::disk('integrations')->assertExists(str_replace('/validated/', '/processed/', $input));
    expect($status->refresh()->int_status)->toBe(3);
});

test('invalid collection inputs fail without output move or status advancement', function (
    string $content,
    ?string $path,
    string $message
) {
    [$client] = collectionContext(['input_collection_path' => $path]);
    $input = collectionInput($client, 'source-items', 'invalid.json', $content);
    $status = CadInterfaceStatus::create(['int_arquivo' => 'invalid.json', 'int_status' => 1]);

    $this->artisan('app:convert-json-json')
        ->expectsOutputToContain($message)
        ->assertExitCode(0);

    Storage::disk('integrations')->assertExists($input);
    Storage::disk('integrations')->assertDirectoryEmpty(
        'polling/collection-client/json/outgoing/destination-items/raw'
    );
    expect($status->refresh()->int_status)->toBe(1);
})->with([
    'missing path' => ['{"other":[]}', 'data', 'não encontrado'],
    'null path' => ['{"data":null}', 'data', 'valor nulo'],
    'non array' => ['{"data":"invalid"}', 'data', 'lista JSON'],
    'associative array' => ['{"data":{"a":{"customer":{"id":"A"}}}}', 'data', 'lista JSON'],
    'scalar item' => ['{"data":[42]}', 'data', 'deve ser um objeto JSON'],
    'empty configured path' => ['{"data":[]}', null, 'não configurado'],
    'invalid json' => ['{"data":[}', 'data', 'JSON inválido'],
]);

test('an invalid output mode fails safely before transformation or staging', function () {
    [$client, , , $process] = collectionContext(['output_mode' => 'foo']);
    $input = collectionInput($client, 'source-items', 'invalid-mode.json', '{"data":[{"customer":{"id":"A"}}]}');
    $status = CadInterfaceStatus::create(['int_arquivo' => 'invalid-mode.json', 'int_status' => 1]);

    $this->artisan('app:convert-json-json')
        ->expectsOutputToContain('Modo de saída inválido')
        ->assertExitCode(0);

    Storage::disk('integrations')->assertExists($input);
    Storage::disk('integrations')->assertDirectoryEmpty(
        'polling/collection-client/json/outgoing/destination-items/raw'
    );
    Storage::disk('integrations')->assertMissing(
        'polling/collection-client/json/outgoing/destination-items/staging'
    );
    expect($status->refresh()->int_status)->toBe(1);

    $lock = Cache::lock(
        'eai:transform-item:' . hash('sha256', "{$process->id}|{$input}"),
        600
    );
    expect($lock->get())->toBeTrue();
    $lock->release();
});

test('a failure on the first staging write publishes nothing and releases the input lock', function () {
    [$client, , , $process] = collectionContext();
    $input = collectionInput($client, 'source-items', 'first-failure.json', '{"data":[{"customer":{"id":"A"}}]}');
    $realDisk = Storage::disk('integrations');
    $disk = Mockery::mock($realDisk);
    $disk->shouldReceive('put')->once()->andReturnFalse();
    Storage::shouldReceive('disk')->with('integrations')->andReturn($disk);

    $this->artisan('app:convert-json-json')
        ->expectsOutputToContain('Falha ao salvar payload em staging')
        ->assertExitCode(0);

    $realDisk->assertExists($input);
    $realDisk->assertDirectoryEmpty('polling/collection-client/json/outgoing/destination-items/raw');
    $lockKey = 'eai:transform-item:' . hash('sha256', "{$process->id}|{$input}");
    $lock = Cache::lock($lockKey, 600);
    expect($lock->get())->toBeTrue();
    $lock->release();
});

test('an intermediate staging write failure cleans staging and publishes no partial raw', function () {
    [$client] = collectionContext();
    $input = collectionInput(
        $client,
        'source-items',
        'middle-failure.json',
        '{"data":[{"customer":{"id":"A"}},{"customer":{"id":"B"}}]}'
    );
    $realDisk = Storage::disk('integrations');
    $disk = Mockery::mock($realDisk);
    $disk->shouldReceive('put')->twice()->andReturn(true, false);
    Storage::shouldReceive('disk')->with('integrations')->andReturn($disk);

    $this->artisan('app:convert-json-json')->assertExitCode(0);

    $realDisk->assertExists($input);
    $realDisk->assertDirectoryEmpty('polling/collection-client/json/outgoing/destination-items/raw');
    $realDisk->assertMissing('polling/collection-client/json/outgoing/destination-items/staging');
});

test('a publication failure leaves input and status pending without deleting final files', function () {
    [$client] = collectionContext();
    $input = collectionInput($client, 'source-items', 'publish-failure.json', '{"data":[{"customer":{"id":"A"}}]}');
    $status = CadInterfaceStatus::create(['int_arquivo' => 'publish-failure.json', 'int_status' => 1]);
    $realDisk = Storage::disk('integrations');
    $disk = Mockery::mock($realDisk);
    $disk->shouldReceive('move')->once()->andReturnFalse();
    Storage::shouldReceive('disk')->with('integrations')->andReturn($disk);

    $this->artisan('app:convert-json-json')
        ->expectsOutputToContain('Falha ao publicar payload')
        ->assertExitCode(0);

    $realDisk->assertExists($input);
    expect($status->refresh()->int_status)->toBe(1);

    $target = 'polling/collection-client/json/outgoing/destination-items/raw/publish-failure-item-000001.json';
    $targetLock = Cache::lock('eai:transform-target:' . hash('sha256', $target), 60);
    expect($targetLock->get())->toBeTrue();
    $targetLock->release();
});

test('the input moves only after every staged output is published', function () {
    [$client] = collectionContext();
    $input = collectionInput(
        $client,
        'source-items',
        'ordered.json',
        '{"data":[{"customer":{"id":"A"}},{"customer":{"id":"B"}}]}'
    );
    $realDisk = Storage::disk('integrations');
    $disk = Mockery::mock($realDisk);
    $disk->shouldReceive('move')->withArgs(fn ($from, $to) => str_contains($from, '/staging/') && str_ends_with($to, 'item-000001.json'))
        ->once()->ordered()->andReturnUsing(fn ($from, $to) => $realDisk->move($from, $to));
    $disk->shouldReceive('move')->withArgs(fn ($from, $to) => str_contains($from, '/staging/') && str_ends_with($to, 'item-000002.json'))
        ->once()->ordered()->andReturnUsing(fn ($from, $to) => $realDisk->move($from, $to));
    $disk->shouldReceive('move')->with($input, str_replace('/validated/', '/processed/', $input))
        ->once()->ordered()->andReturnUsing(fn ($from, $to) => $realDisk->move($from, $to));
    Storage::shouldReceive('disk')->with('integrations')->andReturn($disk);

    $this->artisan('app:convert-json-json')->assertExitCode(0);

    $realDisk->assertMissing($input);
});

test('an occupied input lock skips all effects while another input remains independent', function () {
    [$client, , , $process] = collectionContext();
    $lockedInput = collectionInput($client, 'source-items', 'locked.json', '{"data":[{"customer":{"id":"A"}}]}');
    $freeInput = collectionInput($client, 'source-items', 'free.json', '{"data":[{"customer":{"id":"B"}}]}');
    $status = CadInterfaceStatus::create(['int_arquivo' => 'locked.json', 'int_status' => 1]);
    $lockKey = 'eai:transform-item:' . hash('sha256', "{$process->id}|{$lockedInput}");
    $heldLock = Cache::lock($lockKey, 600);
    expect($heldLock->get())->toBeTrue();

    try {
        $this->artisan('app:convert-json-json')
            ->expectsOutputToContain('já está em transformação')
            ->assertExitCode(0);
    } finally {
        $heldLock->release();
    }

    Storage::disk('integrations')->assertExists($lockedInput);
    Storage::disk('integrations')->assertExists(str_replace('/validated/', '/processed/', $freeInput));
    Storage::disk('integrations')->assertMissing(
        'polling/collection-client/json/outgoing/destination-items/raw/locked-item-000001.json'
    );
    Storage::disk('integrations')->assertExists(
        'polling/collection-client/json/outgoing/destination-items/raw/free-item-000001.json'
    );
    expect($status->refresh()->int_status)->toBe(1);
});

test('the transform lock key differentiates processes for the same input path', function () {
    [$client, $inputEndpoint, $outputEndpoint, $firstProcess] = collectionContext();
    $secondProcess = collectionProcess($inputEndpoint, $outputEndpoint, ['name' => 'second_process']);
    $input = collectionInput($client, 'source-items', 'shared.json', '{"data":[{"customer":{"id":"A"}}]}');
    $firstLockKey = 'eai:transform-item:' . hash('sha256', "{$firstProcess->id}|{$input}");
    $secondLockKey = 'eai:transform-item:' . hash('sha256', "{$secondProcess->id}|{$input}");

    expect($firstLockKey)->not->toBe($secondLockKey);
    $heldLock = Cache::lock($firstLockKey, 600);
    expect($heldLock->get())->toBeTrue();

    try {
        $this->artisan('app:convert-json-json')->assertExitCode(0);
    } finally {
        $heldLock->release();
    }

    Storage::disk('integrations')->assertExists(
        'polling/collection-client/json/outgoing/destination-items/raw/shared-item-000001.json'
    );
    Storage::disk('integrations')->assertExists(str_replace('/validated/', '/processed/', $input));
});

test('a conflicting preexisting final output is preserved and the input stays pending', function () {
    [$client] = collectionContext();
    $input = collectionInput($client, 'source-items', 'conflict.json', '{"data":[{"customer":{"id":"A"}}]}');
    $target = 'polling/collection-client/json/outgoing/destination-items/raw/conflict-item-000001.json';
    Storage::disk('integrations')->put($target, '{"preexisting":true}');

    $this->artisan('app:convert-json-json')
        ->expectsOutputToContain('já existe com conteúdo diferente')
        ->assertExitCode(0);

    Storage::disk('integrations')->assertExists($input);
    expect(Storage::disk('integrations')->get($target))->toBe('{"preexisting":true}');

    $targetLock = Cache::lock('eai:transform-target:' . hash('sha256', $target), 60);
    expect($targetLock->get())->toBeTrue();
    $targetLock->release();
});

test('an identical preexisting target is accepted without being overwritten', function () {
    [$client] = collectionContext();
    $input = collectionInput($client, 'source-items', 'identical.json', '{"data":[{"customer":{"id":"A"}}]}');
    $target = 'polling/collection-client/json/outgoing/destination-items/raw/identical-item-000001.json';
    $content = json_encode(
        ['account' => ['customerId' => 'A']],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
    );
    Storage::disk('integrations')->put($target, $content);
    $realDisk = Storage::disk('integrations');
    $disk = Mockery::mock($realDisk);
    $disk->shouldReceive('move')
        ->once()
        ->with($input, str_replace('/validated/', '/processed/', $input))
        ->andReturnUsing(fn ($from, $to) => $realDisk->move($from, $to));
    Storage::shouldReceive('disk')->with('integrations')->andReturn($disk);

    $this->artisan('app:convert-json-json')->assertExitCode(0);

    expect($realDisk->get($target))->toBe($content);
    $realDisk->assertExists(str_replace('/validated/', '/processed/', $input));
});

test('a busy target lock blocks different processes that generate the same target', function () {
    [$client, $inputEndpoint, $outputEndpoint] = collectionContext();
    collectionProcess($inputEndpoint, $outputEndpoint, ['name' => 'competing_process']);
    $input = collectionInput($client, 'source-items', 'contended.json', '{"data":[{"customer":{"id":"A"}}]}');
    $target = 'polling/collection-client/json/outgoing/destination-items/raw/contended-item-000001.json';
    $targetLockKey = 'eai:transform-target:' . hash('sha256', $target);
    $heldLock = Cache::lock($targetLockKey, 60);
    expect($heldLock->get())->toBeTrue();

    try {
        $this->artisan('app:convert-json-json')
            ->expectsOutputToContain('já está sendo publicado')
            ->assertExitCode(0);
    } finally {
        $heldLock->release();
    }

    Storage::disk('integrations')->assertExists($input);
    Storage::disk('integrations')->assertMissing($target);
    Storage::disk('integrations')->assertDirectoryEmpty(
        'polling/collection-client/json/outgoing/destination-items/staging'
    );
});

test('a target created by a cooperating publisher is detected inside the target lock on retry', function () {
    [$client] = collectionContext();
    $input = collectionInput($client, 'source-items', 'created-before-check.json', '{"data":[{"customer":{"id":"A"}}]}');
    $target = 'polling/collection-client/json/outgoing/destination-items/raw/created-before-check-item-000001.json';
    $content = json_encode(
        ['account' => ['customerId' => 'A']],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
    );
    $targetLock = Cache::lock('eai:transform-target:' . hash('sha256', $target), 60);
    expect($targetLock->get())->toBeTrue();
    Storage::disk('integrations')->put($target, $content);
    $targetLock->release();

    $this->artisan('app:convert-json-json')->assertExitCode(0);

    expect(Storage::disk('integrations')->get($target))->toBe($content);
    Storage::disk('integrations')->assertExists(str_replace('/validated/', '/processed/', $input));
});

test('the target lock is released when storage throws inside the publication region', function () {
    [$client] = collectionContext();
    $input = collectionInput($client, 'source-items', 'target-exception.json', '{"data":[{"customer":{"id":"A"}}]}');
    $target = 'polling/collection-client/json/outgoing/destination-items/raw/target-exception-item-000001.json';
    $realDisk = Storage::disk('integrations');
    $disk = Mockery::mock($realDisk);
    $disk->shouldReceive('exists')->with($target)->once()->andThrow(new RuntimeException('target exists failure'));
    Storage::shouldReceive('disk')->with('integrations')->andReturn($disk);

    $this->artisan('app:convert-json-json')
        ->expectsOutputToContain('target exists failure')
        ->assertExitCode(0);

    $realDisk->assertExists($input);
    $lock = Cache::lock('eai:transform-target:' . hash('sha256', $target), 60);
    expect($lock->get())->toBeTrue();
    $lock->release();
});

test('send endpoints consumes per item outputs from the existing raw path', function () {
    [$client, , $output] = collectionContext();
    collectionInput(
        $client,
        'source-items',
        'send.json',
        '{"data":[{"customer":{"id":"A"}},{"customer":{"id":"B"}}]}'
    );
    Http::fake(['*' => Http::response('{}', 200)]);

    $this->artisan('app:convert-json-json')->assertExitCode(0);
    $this->artisan('app:send-endpoints', ['--id' => $output->id])->assertExitCode(0);

    Http::assertSentCount(2);
    Storage::disk('integrations')->assertExists(
        'polling/collection-client/json/outgoing/destination-items/processed/send-item-000001.json'
    );
    Storage::disk('integrations')->assertExists(
        'polling/collection-client/json/outgoing/destination-items/processed/send-item-000002.json'
    );
});
