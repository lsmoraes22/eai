<?php

use App\Console\Commands\FetchEndpoints;
use App\Models\CadEndpoint;
use App\Models\Client;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

uses(Tests\TestCase::class);

function createConcurrencyEndpoint(array $overrides = []): CadEndpoint
{
    $client = Client::create([
        'name' => 'Cliente Concorrência',
        'code' => 'cliente-concorrencia-' . uniqid(),
    ]);

    return CadEndpoint::create(array_merge([
        'client_id' => $client->id,
        'nome' => 'orders-' . uniqid(),
        'metodo' => 'GET',
        'url' => 'https://api.test/orders',
        'extensao' => 'json',
        'headers' => [],
        'autenticacao' => 'nenhum',
        'type_storage_token' => 'fixed',
        'ativo' => true,
        'direcao' => 'entrada',
        'timer' => 5,
        'next_run' => now()->subMinute(),
        'timeout' => 1,
        'tentativas' => 1,
        'payload' => null,
    ], $overrides));
}

beforeEach(function () {
    config(['cache.default' => 'array']);
    Cache::setDefaultDriver('array');
    Cache::flush();
    Storage::fake('public');

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
        $table->string('auth_user')->nullable();
        $table->string('auth_pass')->nullable();
        $table->text('auth_token')->nullable();
        $table->boolean('ativo');
        $table->string('direcao');
        $table->integer('timer')->nullable();
        $table->timestamp('next_run')->nullable();
        $table->integer('timeout');
        $table->integer('tentativas');
        $table->text('payload')->nullable();
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

afterEach(function () {
    Carbon::setTestNow();
});

test('only one execution calls the api when two executions dispute the same endpoint', function () {
    $endpoint = createConcurrencyEndpoint();
    $originalNextRun = $endpoint->next_run->copy();
    $activeExecution = Cache::lock("eai:fetch-endpoint:{$endpoint->id}", 900);
    expect($activeExecution->get())->toBeTrue();

    Http::fake(['*' => Http::response(['error' => 'unavailable'], 500)]);

    $this->artisan('app:fetch-endpoints', ['--id' => $endpoint->id])
        ->expectsOutputToContain('já está em processamento')
        ->assertExitCode(0);

    expect($endpoint->refresh()->next_run->equalTo($originalNextRun))->toBeTrue();
    Http::assertNothingSent();

    $activeExecution->release();

    $this->artisan('app:fetch-endpoints', ['--id' => $endpoint->id])->assertExitCode(0);

    Http::assertSentCount(1);
});

test('an occupied lock skips the endpoint without changing next_run or calling the api', function () {
    $endpoint = createConcurrencyEndpoint();
    $originalNextRun = $endpoint->next_run->copy();
    $lock = Cache::lock("eai:fetch-endpoint:{$endpoint->id}", 900);
    expect($lock->get())->toBeTrue();
    Http::fake();

    $this->artisan('app:fetch-endpoints', ['--id' => $endpoint->id])
        ->expectsOutputToContain('já está em processamento')
        ->assertExitCode(0);

    expect($endpoint->refresh()->next_run->equalTo($originalNextRun))->toBeTrue();
    Http::assertNothingSent();
    $lock->release();
});

test('different endpoints can be processed independently', function () {
    $lockedEndpoint = createConcurrencyEndpoint();
    $availableEndpoint = createConcurrencyEndpoint();
    $lock = Cache::lock("eai:fetch-endpoint:{$lockedEndpoint->id}", 900);
    expect($lock->get())->toBeTrue();
    Http::fake(['*' => Http::response(['error' => 'unavailable'], 500)]);

    $this->artisan('app:fetch-endpoints')->assertExitCode(0);

    Http::assertSentCount(1);
    expect($lockedEndpoint->refresh()->next_run->isPast())->toBeTrue()
        ->and($availableEndpoint->refresh()->next_run->isFuture())->toBeTrue();
    $lock->release();
});

test('manual execution with id respects the endpoint lock', function () {
    $endpoint = createConcurrencyEndpoint();
    $lock = Cache::lock("eai:fetch-endpoint:{$endpoint->id}", 900);
    expect($lock->get())->toBeTrue();
    Http::fake();

    $this->artisan('app:fetch-endpoints', ['--id' => $endpoint->id])
        ->expectsOutputToContain('já está em processamento')
        ->assertExitCode(0);

    Http::assertNothingSent();
    $lock->release();
});

test('the endpoint lock is released after an exception during processing', function () {
    $endpoint = createConcurrencyEndpoint();
    Http::fake(fn () => throw new RuntimeException('connection failed'));

    $this->artisan('app:fetch-endpoints', ['--id' => $endpoint->id])->assertExitCode(0);

    $nextLock = Cache::lock("eai:fetch-endpoint:{$endpoint->id}", 900);
    expect($nextLock->get())->toBeTrue();
    $nextLock->release();
});

test('a successful execution persists raw and advances next_run to the next aligned slot', function () {
    Carbon::setTestNow('2026-08-30 16:07:00');
    $endpoint = createConcurrencyEndpoint(['timer' => 30]);
    Http::fake(['*' => Http::response(['ok' => true], 200)]);

    $this->artisan('app:fetch-endpoints', ['--id' => $endpoint->id])->assertExitCode(0);

    expect($endpoint->refresh()->next_run->toDateTimeString())->toBe('2026-08-30 16:30:00');
    Http::assertSentCount(1);
    expect(Storage::disk('public')->files(
        "polling/{$endpoint->client->code}/json/incoming/{$endpoint->nome}/raw"
    ))->toHaveCount(1);
});

test('a successful execution exactly on a timer boundary advances to the following slot', function () {
    Carbon::setTestNow('2026-08-30 16:30:00');
    $endpoint = createConcurrencyEndpoint(['timer' => 30]);
    Http::fake(['*' => Http::response(['ok' => true], 200)]);

    $this->artisan('app:fetch-endpoints', ['--id' => $endpoint->id])->assertExitCode(0);

    expect($endpoint->refresh()->next_run->toDateTimeString())->toBe('2026-08-30 17:00:00');
});

test('a successful execution just after a timer boundary advances to the following slot', function () {
    Carbon::setTestNow('2026-08-30 16:30:01');
    $endpoint = createConcurrencyEndpoint(['timer' => 30]);
    Http::fake(['*' => Http::response(['ok' => true], 200)]);

    $this->artisan('app:fetch-endpoints', ['--id' => $endpoint->id])->assertExitCode(0);

    expect($endpoint->refresh()->next_run->toDateTimeString())->toBe('2026-08-30 17:00:00');
});

test('an http error schedules a retry five minutes after the attempt', function () {
    Carbon::setTestNow('2026-08-30 16:07:00');
    $endpoint = createConcurrencyEndpoint(['timer' => 30]);
    Http::fake(['*' => Http::response(['error' => 'unavailable'], 500)]);

    $this->artisan('app:fetch-endpoints', ['--id' => $endpoint->id])->assertExitCode(0);

    expect($endpoint->refresh()->next_run->toDateTimeString())->toBe('2026-08-30 16:12:00');
});

test('a transport exception schedules a retry five minutes after the attempt', function () {
    Carbon::setTestNow('2026-08-30 16:07:00');
    $endpoint = createConcurrencyEndpoint(['timer' => 30]);
    Http::fake(fn () => throw new RuntimeException('connection failed'));

    $this->artisan('app:fetch-endpoints', ['--id' => $endpoint->id])->assertExitCode(0);

    expect($endpoint->refresh()->next_run->toDateTimeString())->toBe('2026-08-30 16:12:00');
});

test('an unresolved authentication skips the request and schedules a retry', function () {
    Carbon::setTestNow('2026-08-30 16:07:00');
    $endpoint = createConcurrencyEndpoint([
        'autenticacao' => 'bearer',
        'type_storage_token' => 'fixed',
        'auth_token' => null,
        'timer' => 30,
    ]);
    Http::fake();

    $this->artisan('app:fetch-endpoints', ['--id' => $endpoint->id])
        ->expectsOutputToContain('Não foi possível resolver a autenticação')
        ->assertExitCode(0);

    Http::assertNothingSent();
    expect($endpoint->refresh()->next_run->toDateTimeString())->toBe('2026-08-30 16:12:00');
});

test('a storage failure does not report saved and schedules a retry', function () {
    Carbon::setTestNow('2026-08-30 16:07:00');
    $endpoint = createConcurrencyEndpoint(['timer' => 30]);
    Http::fake(['*' => Http::response(['ok' => true], 200)]);
    $disk = Mockery::mock();
    $disk->shouldReceive('makeDirectory')->once()->andReturnTrue();
    $disk->shouldReceive('put')->once()->andReturnFalse();
    Storage::shouldReceive('disk')->with('public')->andReturn($disk);

    $this->artisan('app:fetch-endpoints', ['--id' => $endpoint->id])
        ->doesntExpectOutputToContain('Salvo:')
        ->expectsOutputToContain('Falha ao salvar:')
        ->assertExitCode(0);

    expect($endpoint->refresh()->next_run->toDateTimeString())->toBe('2026-08-30 16:12:00');
});

test('a successful auth endpoint write uses the normal aligned schedule', function () {
    Carbon::setTestNow('2026-08-30 16:07:00');
    $endpoint = createConcurrencyEndpoint([
        'nome' => 'auth-token',
        'direcao' => 'auth',
        'metodo' => 'POST',
        'timer' => 30,
    ]);
    $body = '{"access_token":"algar-token","token_type":"bearer","expires_in":3600}';
    Http::fake(['*' => Http::response($body, 200)]);

    $this->artisan('app:fetch-endpoints', ['--id' => $endpoint->id])->assertExitCode(0);

    expect(Storage::disk('public')->get(
        "token/{$endpoint->client->code}/auth-token/auth.txt"
    ))->toBe($body)
        ->and($endpoint->refresh()->next_run->toDateTimeString())->toBe('2026-08-30 16:30:00');
});

test('the endpoint lock remains held through processing and is released afterwards', function () {
    $endpoint = createConcurrencyEndpoint();
    $competingLockAcquired = null;
    Http::fake(function () use ($endpoint, &$competingLockAcquired) {
        $competingLock = Cache::lock("eai:fetch-endpoint:{$endpoint->id}", 900);
        $competingLockAcquired = $competingLock->get();

        return Http::response(['error' => 'unavailable'], 500);
    });

    $this->artisan('app:fetch-endpoints', ['--id' => $endpoint->id])->assertExitCode(0);

    expect($competingLockAcquired)->toBeFalse();
    $nextLock = Cache::lock("eai:fetch-endpoint:{$endpoint->id}", 900);
    expect($nextLock->get())->toBeTrue();
    $nextLock->release();
});

test('the fetch scheduler event has a stable name and explicit overlap ttl', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => $event->description === 'eai:fetch-endpoints');

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('* * * * *')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->expiresAt)->toBe(60);
});
