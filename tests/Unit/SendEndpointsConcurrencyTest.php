<?php

use App\Models\CadEndpoint;
use App\Models\CadInterfaceStatus;
use App\Models\Client;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(Tests\UnitTestCase::class);

function createSendConcurrencyEndpoint(array $overrides = []): CadEndpoint
{
    $client = Client::create([
        'name' => 'Cliente Send Concorrência',
        'code' => 'cliente-send-' . uniqid(),
    ]);

    return CadEndpoint::create(array_merge([
        'client_id' => $client->id,
        'nome' => 'orders-' . uniqid(),
        'metodo' => 'POST',
        'url' => 'https://api.test/orders',
        'extensao' => 'json',
        'headers' => [],
        'autenticacao' => 'nenhum',
        'type_storage_token' => 'fixed',
        'ativo' => true,
        'direcao' => 'saida',
        'timeout' => 1,
        'tentativas' => 1,
    ], $overrides));
}

function sendConcurrencyPath(CadEndpoint $endpoint, string $filename): string
{
    return 'polling/' . $endpoint->client->code . '/' . strtolower($endpoint->extensao)
        . '/outgoing/' . Str::slug($endpoint->nome) . '/raw/' . $filename;
}

function sendProcessedPath(CadEndpoint $endpoint, string $filename): string
{
    return 'polling/' . $endpoint->client->code . '/' . strtolower($endpoint->extensao)
        . '/outgoing/' . Str::slug($endpoint->nome) . '/processed/' . $filename;
}

function sendItemLock(CadEndpoint $endpoint, string $path)
{
    return Cache::lock(
        'eai:send-item:' . hash('sha256', "{$endpoint->id}|{$path}"),
        600
    );
}

function createSendStatus(string $filename, int $status = 0): CadInterfaceStatus
{
    return CadInterfaceStatus::create([
        'int_arquivo' => $filename,
        'int_status' => $status,
        'int_mensagem' => 'Pendente',
        'int_data_envio' => now(),
    ]);
}

beforeEach(function () {
    config(['cache.default' => 'array']);
    Cache::setDefaultDriver('array');
    Cache::flush();
    Storage::fake('integrations');

    Schema::create('clients', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->string('code');
        $table->text('access_token')->nullable();
        $table->timestamp('expires_at')->nullable();
        $table->timestamps();
    });

    Schema::create('cad_endpoints', function (Blueprint $table) {
        $table->id();
        $table->foreignId('client_id');
        $table->string('nome');
        $table->string('metodo');
        $table->text('url');
        $table->string('extensao');
        $table->json('headers')->nullable();
        $table->string('autenticacao');
        $table->string('type_storage_token');
        $table->text('auth_token')->nullable();
        $table->boolean('ativo');
        $table->string('direcao');
        $table->integer('timeout');
        $table->integer('tentativas');
        $table->timestamps();
    });

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

test('only one execution sends an item when two executions dispute it', function () {
    $endpoint = createSendConcurrencyEndpoint();
    $path = sendConcurrencyPath($endpoint, 'order.json');
    Storage::disk('integrations')->put($path, '{"id":1}');
    $activeExecution = sendItemLock($endpoint, $path);
    expect($activeExecution->get())->toBeTrue();
    Http::fake(['*' => Http::response('', 200)]);

    $this->artisan('app:send-endpoints', ['--id' => $endpoint->id])
        ->expectsOutputToContain('já está em processamento')
        ->assertExitCode(0);
    Http::assertNothingSent();

    $activeExecution->release();
    $this->artisan('app:send-endpoints', ['--id' => $endpoint->id])->assertExitCode(0);

    Http::assertSentCount(1);
});

test('an occupied item lock changes neither file nor status', function () {
    $endpoint = createSendConcurrencyEndpoint();
    $path = sendConcurrencyPath($endpoint, 'occupied.json');
    $status = createSendStatus('occupied.json');
    Storage::disk('integrations')->put($path, '{"id":1}');
    $lock = sendItemLock($endpoint, $path);
    expect($lock->get())->toBeTrue();
    Http::fake();

    $this->artisan('app:send-endpoints', ['--id' => $endpoint->id])
        ->expectsOutputToContain('já está em processamento')
        ->assertExitCode(0);

    Http::assertNothingSent();
    Storage::disk('integrations')->assertExists($path);
    Storage::disk('integrations')->assertMissing(sendProcessedPath($endpoint, 'occupied.json'));
    expect($status->refresh()->int_status)->toBe(0)
        ->and($status->int_mensagem)->toBe('Pendente');
    $lock->release();
});

test('different items can be processed independently', function () {
    $endpoint = createSendConcurrencyEndpoint();
    $lockedPath = sendConcurrencyPath($endpoint, 'locked.json');
    $availablePath = sendConcurrencyPath($endpoint, 'available.json');
    Storage::disk('integrations')->put($lockedPath, '{"id":1}');
    Storage::disk('integrations')->put($availablePath, '{"id":2}');
    $lock = sendItemLock($endpoint, $lockedPath);
    expect($lock->get())->toBeTrue();
    Http::fake(['*' => Http::response('', 200)]);

    $this->artisan('app:send-endpoints', ['--id' => $endpoint->id])->assertExitCode(0);

    Http::assertSentCount(1);
    Storage::disk('integrations')->assertExists($lockedPath);
    Storage::disk('integrations')->assertExists(sendProcessedPath($endpoint, 'available.json'));
    $lock->release();
});

test('the same filename in different endpoints does not collide', function () {
    $lockedEndpoint = createSendConcurrencyEndpoint();
    $availableEndpoint = createSendConcurrencyEndpoint();
    $lockedPath = sendConcurrencyPath($lockedEndpoint, 'same.json');
    $availablePath = sendConcurrencyPath($availableEndpoint, 'same.json');
    Storage::disk('integrations')->put($lockedPath, '{"endpoint":1}');
    Storage::disk('integrations')->put($availablePath, '{"endpoint":2}');
    $lock = sendItemLock($lockedEndpoint, $lockedPath);
    expect($lock->get())->toBeTrue();
    Http::fake(['*' => Http::response('', 200)]);

    $this->artisan('app:send-endpoints', ['--id' => $availableEndpoint->id])->assertExitCode(0);

    Http::assertSentCount(1);
    Storage::disk('integrations')->assertExists($lockedPath);
    Storage::disk('integrations')->assertExists(sendProcessedPath($availableEndpoint, 'same.json'));
    $lock->release();
});

test('the lock remains held during http and until the item transition completes', function () {
    $endpoint = createSendConcurrencyEndpoint();
    $path = sendConcurrencyPath($endpoint, 'held.json');
    Storage::disk('integrations')->put($path, '{"id":1}');
    $competingLockAcquired = null;
    $rawExistedDuringRequest = null;
    Http::fake(function () use ($endpoint, $path, &$competingLockAcquired, &$rawExistedDuringRequest) {
        $competingLock = sendItemLock($endpoint, $path);
        $competingLockAcquired = $competingLock->get();
        $rawExistedDuringRequest = Storage::disk('integrations')->exists($path);

        return Http::response('', 200);
    });

    $this->artisan('app:send-endpoints', ['--id' => $endpoint->id])->assertExitCode(0);

    expect($competingLockAcquired)->toBeFalse()
        ->and($rawExistedDuringRequest)->toBeTrue();
    Storage::disk('integrations')->assertMissing($path);
    Storage::disk('integrations')->assertExists(sendProcessedPath($endpoint, 'held.json'));
    $nextLock = sendItemLock($endpoint, $path);
    expect($nextLock->get())->toBeTrue();
    $nextLock->release();
});

test('the lock is released after success', function () {
    $endpoint = createSendConcurrencyEndpoint();
    $path = sendConcurrencyPath($endpoint, 'success.json');
    Storage::disk('integrations')->put($path, '{"id":1}');
    Http::fake(['*' => Http::response('', 200)]);

    $this->artisan('app:send-endpoints', ['--id' => $endpoint->id])->assertExitCode(0);

    $nextLock = sendItemLock($endpoint, $path);
    expect($nextLock->get())->toBeTrue();
    $nextLock->release();
});

test('a failed move after http success does not mark success and releases the lock', function () {
    $endpoint = createSendConcurrencyEndpoint();
    $filename = 'move-failed.json';
    $path = sendConcurrencyPath($endpoint, $filename);
    $sourcePath = dirname($path);
    $processedPath = dirname(sendProcessedPath($endpoint, $filename));
    $status = createSendStatus($filename);
    $disk = Mockery::mock();
    $disk->shouldReceive('exists')->with($sourcePath)->once()->andReturnTrue();
    $disk->shouldReceive('files')->with($sourcePath)->once()->andReturn([$path]);
    $disk->shouldReceive('get')->with($path)->once()->andReturn('{"id":1}');
    $disk->shouldReceive('makeDirectory')->with($processedPath)->once()->andReturnTrue();
    $disk->shouldReceive('move')
        ->with($path, sendProcessedPath($endpoint, $filename))
        ->once()
        ->andReturnFalse();
    $disk->shouldReceive('exists')->with($path)->once()->andReturnTrue();
    Storage::shouldReceive('disk')->with('integrations')->andReturn($disk);
    Http::fake(['*' => Http::response('', 200)]);

    $this->artisan('app:send-endpoints', ['--id' => $endpoint->id])
        ->expectsOutputToContain('Falha ao mover')
        ->doesntExpectOutputToContain('✔ Sucesso!')
        ->assertExitCode(0);

    expect($status->refresh()->int_status)->toBe(0)
        ->and($status->int_mensagem)->toBe('Pendente')
        ->and(Storage::disk('integrations')->exists($path))->toBeTrue();
    $nextLock = sendItemLock($endpoint, $path);
    expect($nextLock->get())->toBeTrue();
    $nextLock->release();
});

test('an http error releases the lock and leaves the item eligible', function () {
    $endpoint = createSendConcurrencyEndpoint();
    $path = sendConcurrencyPath($endpoint, 'http-error.json');
    $status = createSendStatus('http-error.json');
    Storage::disk('integrations')->put($path, '{"id":1}');
    Http::fake(['*' => Http::response('unavailable', 503)]);

    $this->artisan('app:send-endpoints', ['--id' => $endpoint->id])->assertExitCode(0);

    Storage::disk('integrations')->assertExists($path);
    expect($status->refresh()->int_status)->toBe(2);
    $nextLock = sendItemLock($endpoint, $path);
    expect($nextLock->get())->toBeTrue();
    $nextLock->release();
});

test('a transport exception releases the lock and leaves the item eligible', function () {
    $endpoint = createSendConcurrencyEndpoint();
    $path = sendConcurrencyPath($endpoint, 'exception.json');
    Storage::disk('integrations')->put($path, '{"id":1}');
    Http::fake(fn () => throw new RuntimeException('connection failed'));

    $this->artisan('app:send-endpoints', ['--id' => $endpoint->id])->assertExitCode(0);

    Storage::disk('integrations')->assertExists($path);
    $nextLock = sendItemLock($endpoint, $path);
    expect($nextLock->get())->toBeTrue();
    $nextLock->release();
});

test('an unexpected throwable releases the lock', function () {
    $endpoint = createSendConcurrencyEndpoint();
    $path = sendConcurrencyPath($endpoint, 'throwable.json');
    Storage::disk('integrations')->put($path, '{"id":1}');
    Http::fake(fn () => throw new Error('unexpected failure'));
    $caught = false;

    try {
        $this->artisan('app:send-endpoints', ['--id' => $endpoint->id])->run();
    } catch (Error $error) {
        $caught = true;
        expect($error->getMessage())->toBe('unexpected failure');
    }

    expect($caught)->toBeTrue();
    $nextLock = sendItemLock($endpoint, $path);
    expect($nextLock->get())->toBeTrue();
    $nextLock->release();
});

test('manual execution with id respects the item lock', function () {
    $endpoint = createSendConcurrencyEndpoint();
    $otherEndpoint = createSendConcurrencyEndpoint();
    $path = sendConcurrencyPath($endpoint, 'manual.json');
    $otherPath = sendConcurrencyPath($otherEndpoint, 'other.json');
    Storage::disk('integrations')->put($path, '{"id":1}');
    Storage::disk('integrations')->put($otherPath, '{"id":2}');
    $lock = sendItemLock($endpoint, $path);
    expect($lock->get())->toBeTrue();
    Http::fake();

    $this->artisan('app:send-endpoints', ['--id' => $endpoint->id])
        ->expectsOutputToContain('já está em processamento')
        ->assertExitCode(0);

    Http::assertNothingSent();
    Storage::disk('integrations')->assertExists($path);
    Storage::disk('integrations')->assertExists($otherPath);
    $lock->release();
});

test('authentication remains applied while the item lock is used', function () {
    $endpoint = createSendConcurrencyEndpoint([
        'autenticacao' => 'bearer',
        'type_storage_token' => 'fixed',
        'auth_token' => 'fixed-token',
    ]);
    $path = sendConcurrencyPath($endpoint, 'authenticated.json');
    Storage::disk('integrations')->put($path, '{"id":1}');
    Http::fake(['*' => Http::response('', 200)]);

    $this->artisan('app:send-endpoints', ['--id' => $endpoint->id])->assertExitCode(0);

    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer fixed-token'));
});
