<?php

use App\Models\CadEndpoint;
use App\Models\Client;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

uses(Tests\UnitTestCase::class);

function pagePagination(array $overrides = []): array
{
    return array_merge([
        'type' => 'page',
        'page_param' => 'page',
        'page_start' => 1,
        'page_size_param' => 'size',
        'page_size' => 100,
        'current_page_path' => 'meta.currentPage',
        'total_pages_path' => 'meta.totalPages',
        'max_pages' => 100,
    ], $overrides);
}

function createPaginationEndpoint(array $overrides = []): CadEndpoint
{
    $client = Client::create([
        'name' => 'Cliente Paginação',
        'code' => 'cliente-paginacao-' . uniqid(),
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
        'timer' => 30,
        'next_run' => now()->subMinute(),
        'timeout' => 1,
        'tentativas' => 1,
        'payload' => null,
        'pagination' => pagePagination(),
    ], $overrides));
}

function paginationRawDirectory(CadEndpoint $endpoint): string
{
    return "polling/{$endpoint->client->code}/json/incoming/{$endpoint->nome}/raw";
}

function requestQuery($request): array
{
    parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);

    return $query;
}

function requestBody($request): array
{
    return $request->data();
}

beforeEach(function () {
    Carbon::setTestNow('2026-09-01 10:07:00.123');
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
        $table->json('pagination')->nullable();
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

test('an endpoint without pagination keeps the single request behavior', function (?array $pagination) {
    $endpoint = createPaginationEndpoint(['pagination' => $pagination]);
    Http::fake(['*' => Http::response('{"ok":true}', 200)]);

    $this->artisan('app:fetch-endpoints', ['--id' => $endpoint->id])->assertExitCode(0);

    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => !isset(requestQuery($request)['page']) && !isset(requestQuery($request)['size']));
    $files = Storage::disk('integrations')->files(paginationRawDirectory($endpoint));
    expect($files)->toHaveCount(1)
        ->and(basename($files[0]))->not->toContain('-page-');
})->with([
    'null' => [null],
    'type absent' => [[]],
    'none' => [['type' => 'none']],
]);

test('a paginated auth endpoint is rejected before http and releases its lock', function () {
    $endpoint = createPaginationEndpoint([
        'direcao' => 'auth',
        'pagination' => pagePagination(),
    ]);
    Http::fake();

    $this->artisan('app:fetch-endpoints', ['--id' => $endpoint->id])
        ->expectsOutputToContain('Endpoints de autenticação não suportam paginação')
        ->assertExitCode(0);

    Http::assertNothingSent();
    Storage::disk('integrations')->assertMissing("token/{$endpoint->client->code}/{$endpoint->nome}/auth.txt");
    expect($endpoint->refresh()->next_run->toDateTimeString())->toBe('2026-09-01 10:12:00');
    $nextLock = Cache::lock("eai:fetch-endpoint:{$endpoint->id}", 900);
    expect($nextLock->get())->toBeTrue();
    $nextLock->release();
});

test('an auth endpoint without pagination keeps saving auth txt', function () {
    $endpoint = createPaginationEndpoint([
        'nome' => 'auth-token',
        'direcao' => 'auth',
        'metodo' => 'POST',
        'pagination' => null,
    ]);
    $body = '{"access_token":"auth-token"}';
    Http::fake(['*' => Http::response($body, 200)]);

    $this->artisan('app:fetch-endpoints', ['--id' => $endpoint->id])->assertExitCode(0);

    Http::assertSentCount(1);
    expect(Storage::disk('integrations')->get(
        "token/{$endpoint->client->code}/auth-token/auth.txt"
    ))->toBe($body)
        ->and($endpoint->refresh()->next_run->toDateTimeString())->toBe('2026-09-01 10:30:00');
});

test('one page sends configured defaults and saves one raw', function () {
    $endpoint = createPaginationEndpoint();
    $body = '{"data":[],"meta":{"currentPage":1,"totalPages":1}}';
    Http::fake(['*' => Http::response($body, 200)]);

    $this->artisan('app:fetch-endpoints', ['--id' => $endpoint->id])->assertExitCode(0);

    Http::assertSent(fn ($request) => requestQuery($request)['page'] === '1'
        && requestQuery($request)['size'] === '100');
    $files = Storage::disk('integrations')->files(paginationRawDirectory($endpoint));
    expect($files)->toHaveCount(1)
        ->and(basename($files[0]))->toBe('20260901100700123-page-000001.json')
        ->and(Storage::disk('integrations')->get($files[0]))->toBe($body);
});

test('a missing null or empty location defaults to query', function (array $pagination) {
    $endpoint = createPaginationEndpoint([
        'payload' => json_encode(['status' => 'open']),
        'pagination' => $pagination,
    ]);
    Http::fake(['*' => Http::response('{"meta":{"currentPage":1,"totalPages":1}}', 200)]);

    $this->artisan('app:fetch-endpoints', ['--id' => $endpoint->id])->assertExitCode(0);

    Http::assertSent(function ($request) {
        $query = requestQuery($request);

        return $query === ['status' => 'open', 'page' => '1', 'size' => '100'];
    });
})->with([
    'missing' => [pagePagination()],
    'null' => [pagePagination(['location' => null])],
    'empty' => [pagePagination(['location' => ''])],
]);

test('an explicit query location keeps pagination in query and preserves the request body', function () {
    $payload = ['filters' => ['status' => 'active']];
    $endpoint = createPaginationEndpoint([
        'metodo' => 'POST',
        'payload' => json_encode($payload),
        'pagination' => pagePagination(['location' => 'query']),
    ]);
    Http::fake(['*' => Http::response('{"meta":{"currentPage":1,"totalPages":1}}', 200)]);

    $this->artisan('app:fetch-endpoints', ['--id' => $endpoint->id])->assertExitCode(0);

    Http::assertSent(function ($request) use ($payload) {
        $query = requestQuery($request);

        return $query['page'] === '1'
            && $query['size'] === '100'
            && requestBody($request) === $payload;
    });
});

test('a body location uses dot notation and preserves the base payload across pages', function () {
    $payload = ['filters' => ['status' => 'active']];
    $endpoint = createPaginationEndpoint([
        'metodo' => 'POST',
        'payload' => json_encode($payload),
        'pagination' => pagePagination([
            'location' => 'body',
            'page_param' => 'pagination.page',
            'page_size_param' => 'pagination.pageSize',
        ]),
    ]);
    $requestBodies = [];
    Http::fake(function ($request) use (&$requestBodies) {
        $body = requestBody($request);
        $requestBodies[] = $body;

        return Http::response(json_encode([
            'meta' => [
                'currentPage' => $body['pagination']['page'],
                'totalPages' => 2,
            ],
        ]), 200);
    });

    $this->artisan('app:fetch-endpoints', ['--id' => $endpoint->id])->assertExitCode(0);

    expect($requestBodies)->toBe([
        [
            'filters' => ['status' => 'active'],
            'pagination' => ['page' => 1, 'pageSize' => 100],
        ],
        [
            'filters' => ['status' => 'active'],
            'pagination' => ['page' => 2, 'pageSize' => 100],
        ],
    ]);
    foreach (Http::recorded() as [$request]) {
        expect(requestQuery($request))->toBe([]);
    }
});

test('three pages make distinct requests and preserve each raw byte for byte', function () {
    $endpoint = createPaginationEndpoint();
    $bodies = [
        1 => "{\n  \"data\": [\"á\"], \"meta\": {\"currentPage\": 1, \"totalPages\": 3}\n}",
        2 => '{"data":["\"quoted\""],"meta":{"currentPage":2,"totalPages":3}}',
        3 => '{"data":[],"meta":{"currentPage":3,"totalPages":3}}',
    ];
    $requested = [];
    Http::fake(function ($request) use (&$requested, $bodies) {
        $page = (int) requestQuery($request)['page'];
        $requested[] = $page;

        return Http::response($bodies[$page], 200);
    });

    $this->artisan('app:fetch-endpoints', ['--id' => $endpoint->id])->assertExitCode(0);

    expect($requested)->toBe([1, 2, 3]);
    $files = Storage::disk('integrations')->files(paginationRawDirectory($endpoint));
    expect(array_map('basename', $files))->toBe([
        '20260901100700123-page-000001.json',
        '20260901100700123-page-000002.json',
        '20260901100700123-page-000003.json',
    ]);
    foreach ($files as $index => $file) {
        expect(Storage::disk('integrations')->get($file))->toBe($bodies[$index + 1]);
    }
});

test('existing query parameters are preserved and pagination parameter names are configurable', function () {
    $endpoint = createPaginationEndpoint([
        'payload' => json_encode(['cycle' => '2026-08', 'status' => 'open', 'p' => 999]),
        'pagination' => pagePagination([
            'page_param' => 'p',
            'page_size_param' => 'per_page',
            'page_size' => 25,
        ]),
    ]);
    Http::fake(['*' => Http::response('{"meta":{"currentPage":1,"totalPages":1}}', 200)]);

    $this->artisan('app:fetch-endpoints', ['--id' => $endpoint->id])->assertExitCode(0);

    Http::assertSent(function ($request) {
        $query = requestQuery($request);

        return $query === [
            'cycle' => '2026-08',
            'status' => 'open',
            'p' => '1',
            'per_page' => '25',
        ];
    });
});

test('page start and metadata paths are configurable', function () {
    $endpoint = createPaginationEndpoint([
        'pagination' => pagePagination([
            'page_start' => 5,
            'current_page_path' => 'pagination.current',
            'total_pages_path' => 'pagination.pages',
        ]),
    ]);
    Http::fake(['*' => Http::response('{"pagination":{"current":"5","pages":"5"}}', 200)]);

    $this->artisan('app:fetch-endpoints', ['--id' => $endpoint->id])->assertExitCode(0);

    Http::assertSent(fn ($request) => requestQuery($request)['page'] === '5');
    expect(basename(Storage::disk('integrations')->files(paginationRawDirectory($endpoint))[0]))
        ->toBe('20260901100700123-page-000005.json');
});

test('an intermediate http failure stops collection and keeps previous raws', function () {
    $endpoint = createPaginationEndpoint();
    $requested = [];
    Http::fake(function ($request) use (&$requested) {
        $page = (int) requestQuery($request)['page'];
        $requested[] = $page;

        return $page === 2
            ? Http::response('unavailable', 500)
            : Http::response('{"meta":{"currentPage":1,"totalPages":3}}', 200);
    });

    $this->artisan('app:fetch-endpoints', ['--id' => $endpoint->id])->assertExitCode(0);

    expect($requested)->toBe([1, 2])
        ->and(Storage::disk('integrations')->files(paginationRawDirectory($endpoint)))->toHaveCount(1)
        ->and($endpoint->refresh()->next_run->toDateTimeString())->toBe('2026-09-01 10:12:00');
});

test('an intermediate transport exception stops collection and releases the lock', function () {
    $endpoint = createPaginationEndpoint();
    $requested = [];
    Http::fake(function ($request) use (&$requested) {
        $page = (int) requestQuery($request)['page'];
        $requested[] = $page;
        if ($page === 2) {
            throw new RuntimeException('connection failed');
        }

        return Http::response('{"meta":{"currentPage":1,"totalPages":3}}', 200);
    });

    $this->artisan('app:fetch-endpoints', ['--id' => $endpoint->id])->assertExitCode(0);

    expect($requested)->toBe([1, 2])
        ->and(Storage::disk('integrations')->files(paginationRawDirectory($endpoint)))->toHaveCount(1)
        ->and($endpoint->refresh()->next_run->toDateTimeString())->toBe('2026-09-01 10:12:00');
    $nextLock = Cache::lock("eai:fetch-endpoint:{$endpoint->id}", 900);
    expect($nextLock->get())->toBeTrue();
    $nextLock->release();
});

test('invalid json is saved before pagination fails', function () {
    $endpoint = createPaginationEndpoint();
    $body = '{"meta":invalid}';
    Http::fake(['*' => Http::response($body, 200)]);

    $this->artisan('app:fetch-endpoints', ['--id' => $endpoint->id])
        ->expectsOutputToContain('não contém JSON válido')
        ->assertExitCode(0);

    $file = Storage::disk('integrations')->files(paginationRawDirectory($endpoint))[0];
    expect(Storage::disk('integrations')->get($file))->toBe($body)
        ->and($endpoint->refresh()->next_run->toDateTimeString())->toBe('2026-09-01 10:12:00');
});

test('page pagination requires both metadata paths explicitly', function (string $missingKey) {
    $pagination = pagePagination();
    unset($pagination[$missingKey]);
    $endpoint = createPaginationEndpoint(['pagination' => $pagination]);
    Http::fake();

    $this->artisan('app:fetch-endpoints', ['--id' => $endpoint->id])
        ->expectsOutputToContain('Configuração de paginação inválida ou incompleta')
        ->assertExitCode(0);

    Http::assertNothingSent();
    expect($endpoint->refresh()->next_run->toDateTimeString())->toBe('2026-09-01 10:12:00');
})->with([
    'current page path' => ['current_page_path'],
    'total pages path' => ['total_pages_path'],
]);

test('missing or non numeric metadata fails in a controlled way', function (array $pagination, string $body) {
    $endpoint = createPaginationEndpoint(['pagination' => pagePagination($pagination)]);
    Http::fake(['*' => Http::response($body, 200)]);

    $this->artisan('app:fetch-endpoints', ['--id' => $endpoint->id])
        ->expectsOutputToContain('Metadata de paginação ausente ou não numérica')
        ->assertExitCode(0);

    Http::assertSentCount(1);
    expect(Storage::disk('integrations')->files(paginationRawDirectory($endpoint)))->toHaveCount(1)
        ->and($endpoint->refresh()->next_run->toDateTimeString())->toBe('2026-09-01 10:12:00');
})->with([
    'current path missing' => [[], '{"meta":{"totalPages":1}}'],
    'total path missing' => [[], '{"meta":{"currentPage":1}}'],
    'current non numeric' => [[], '{"meta":{"currentPage":"first","totalPages":1}}'],
    'total non numeric' => [[], '{"meta":{"currentPage":1,"totalPages":1.5}}'],
    'configured current path absent' => [['current_page_path' => 'paging.current'], '{"meta":{"currentPage":1,"totalPages":1}}'],
    'configured total path absent' => [['total_pages_path' => 'paging.total'], '{"meta":{"currentPage":1,"totalPages":1}}'],
]);

test('regressive or incoherent metadata stops without looping', function (string $body) {
    $endpoint = createPaginationEndpoint();
    $requested = [];
    Http::fake(function ($request) use (&$requested, $body) {
        $page = (int) requestQuery($request)['page'];
        $requested[] = $page;

        return $page === 1
            ? Http::response('{"meta":{"currentPage":1,"totalPages":3}}', 200)
            : Http::response($body, 200);
    });

    $this->artisan('app:fetch-endpoints', ['--id' => $endpoint->id])
        ->expectsOutputToContain('Metadata de paginação incoerente')
        ->assertExitCode(0);

    expect($requested)->toBe([1, 2])
        ->and($endpoint->refresh()->next_run->toDateTimeString())->toBe('2026-09-01 10:12:00');
})->with([
    'repeated page' => ['{"meta":{"currentPage":1,"totalPages":3}}'],
    'total behind current' => ['{"meta":{"currentPage":2,"totalPages":1}}'],
]);

test('max pages stops before requesting beyond the configured limit', function () {
    $endpoint = createPaginationEndpoint(['pagination' => pagePagination(['max_pages' => 2])]);
    $requested = [];
    Http::fake(function ($request) use (&$requested) {
        $page = (int) requestQuery($request)['page'];
        $requested[] = $page;

        return Http::response(json_encode(['meta' => ['currentPage' => $page, 'totalPages' => 5]]), 200);
    });

    $this->artisan('app:fetch-endpoints', ['--id' => $endpoint->id])
        ->expectsOutputToContain('Limite de paginação atingido')
        ->assertExitCode(0);

    expect($requested)->toBe([1, 2])
        ->and(Storage::disk('integrations')->files(paginationRawDirectory($endpoint)))->toHaveCount(2)
        ->and($endpoint->refresh()->next_run->toDateTimeString())->toBe('2026-09-01 10:12:00');
});

test('a storage failure on an intermediate page stops collection', function () {
    $endpoint = createPaginationEndpoint();
    $directory = paginationRawDirectory($endpoint);
    $realDisk = Storage::disk('integrations');
    $putCalls = 0;
    $disk = Mockery::mock($realDisk);
    $disk->shouldReceive('put')->twice()->andReturnUsing(function ($path, $body) use ($realDisk, &$putCalls) {
        $putCalls++;

        return $putCalls === 1 ? $realDisk->put($path, $body) : false;
    });
    Storage::shouldReceive('disk')->with('integrations')->andReturn($disk);
    Http::fake(function ($request) {
        $page = (int) requestQuery($request)['page'];

        return Http::response(json_encode(['meta' => ['currentPage' => $page, 'totalPages' => 3]]), 200);
    });

    $this->artisan('app:fetch-endpoints', ['--id' => $endpoint->id])
        ->expectsOutputToContain('Falha ao salvar')
        ->assertExitCode(0);

    Http::assertSentCount(2);
    $files = $realDisk->files($directory);
    expect($files)->toHaveCount(1)
        ->and(basename($files[0]))->toBe('20260901100700123-page-000001.json')
        ->and($realDisk->get($files[0]))->toContain('"currentPage":1')
        ->and($endpoint->refresh()->next_run->toDateTimeString())->toBe('2026-09-01 10:12:00');
});

test('the endpoint lock covers every page and next run advances only after the final page', function () {
    $endpoint = createPaginationEndpoint();
    $lockAttempts = [];
    $nextRunsDuringRequests = [];
    Http::fake(function ($request) use ($endpoint, &$lockAttempts, &$nextRunsDuringRequests) {
        $page = (int) requestQuery($request)['page'];
        $competingLock = Cache::lock("eai:fetch-endpoint:{$endpoint->id}", 900);
        $lockAttempts[] = $competingLock->get();
        $nextRunsDuringRequests[] = $endpoint->fresh()->next_run->toDateTimeString();

        return Http::response(json_encode(['meta' => ['currentPage' => $page, 'totalPages' => 3]]), 200);
    });

    $originalNextRun = $endpoint->next_run->toDateTimeString();
    $this->artisan('app:fetch-endpoints', ['--id' => $endpoint->id])->assertExitCode(0);

    expect($lockAttempts)->toBe([false, false, false])
        ->and($nextRunsDuringRequests)->toBe([$originalNextRun, $originalNextRun, $originalNextRun])
        ->and($endpoint->refresh()->next_run->toDateTimeString())->toBe('2026-09-01 10:30:00');
    $nextLock = Cache::lock("eai:fetch-endpoint:{$endpoint->id}", 900);
    expect($nextLock->get())->toBeTrue();
    $nextLock->release();
});

test('authentication is present on every paginated request', function () {
    $endpoint = createPaginationEndpoint([
        'autenticacao' => 'bearer',
        'type_storage_token' => 'fixed',
        'auth_token' => 'page-token',
    ]);
    Http::fake(function ($request) {
        $page = (int) requestQuery($request)['page'];

        return Http::response(json_encode(['meta' => ['currentPage' => $page, 'totalPages' => 2]]), 200);
    });

    $this->artisan('app:fetch-endpoints', ['--id' => $endpoint->id])->assertExitCode(0);

    Http::assertSentCount(2);
    $requests = Http::recorded()->map(fn ($record) => $record[0]);
    expect($requests)->toHaveCount(2);
    foreach ($requests as $request) {
        expect($request->hasHeader('Authorization', 'Bearer page-token'))->toBeTrue();
    }
});
