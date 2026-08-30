<?php

use App\Console\Commands\FetchEndpoints;
use App\Models\CadEndpoint;
use App\Models\Client;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

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

test('the fetch scheduler event has a stable name and explicit overlap ttl', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => $event->description === 'eai:fetch-endpoints');

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('* * * * *')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->expiresAt)->toBe(60);
});

