<?php

use App\Models\CadEndpoint;
use App\Models\Client;
use App\Models\LinkedResource;
use App\Services\LinkedResources\CaptureException;
use App\Services\LinkedResources\ResourceCapture;
use App\Services\LinkedResources\SafeResourceDownloader;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

uses(Tests\UnitTestCase::class);

// Exercises the real safety/stream callbacks without DNS or network access.
class SimulatedResourceDownloader extends SafeResourceDownloader
{
    public array $ips = ['93.184.216.34'];

    public array $chunks = ['code,value\n001,10.50\n'];

    public array $headers = [];

    public array $requests = [];

    public int $status = 200;

    public ?string $error = null;

    public $duringTransfer = null;

    protected function resolve(string $host, int $timeout): array
    {
        return $this->ips;
    }

    protected function execute(array $options): int
    {
        $this->requests[] = $options;
        if ($this->duringTransfer) {
            ($this->duringTransfer)();
        }
        if ($this->error) {
            throw new CaptureException($this->error);
        }
        foreach ($this->headers as $header) {
            if ($options[CURLOPT_HEADERFUNCTION](null, $header) === 0) {
                throw new CaptureException('transport_failed');
            }
        }
        foreach ($this->chunks as $chunk) {
            if (strlen($chunk) !== $options[CURLOPT_WRITEFUNCTION](null, $chunk)) {
                throw new CaptureException('transport_failed');
            }
        }

        return $this->status;
    }
}

function linkedEndpoint(array $overrides = []): CadEndpoint
{
    $client = Client::create(['name' => 'Capture client', 'code' => 'capture-client-'.uniqid()]);

    return CadEndpoint::create(array_merge([
        'client_id' => $client->id, 'nome' => 'capture-'.uniqid(), 'metodo' => 'GET',
        'url' => 'https://api.test/resources', 'extensao' => 'json',
        'headers' => ['Authorization' => 'Bearer original-secret', 'Cookie' => 'original-cookie'],
        'autenticacao' => 'nenhum', 'type_storage_token' => 'fixed', 'ativo' => true,
        'direcao' => 'entrada', 'timer' => 30, 'next_run' => now()->subMinute(),
        'timeout' => 1, 'tentativas' => 1,
    ], $overrides));
}

function linkedPolicy(CadEndpoint $endpoint, array $overrides = []): void
{
    config(['linked_resources.endpoints.'.$endpoint->id => array_merge([
        'enabled' => true, 'collection_path' => 'data', 'id_path' => 'id',
        'revision_path' => 'revision', 'url_path' => 'file.url',
        'allowed_hosts' => ['files.example.test'], 'max_bytes' => 1024,
    ], $overrides)]);
}

function linkedBody(array $overrides = []): string
{
    return json_encode(['data' => [array_replace_recursive([
        'id' => 'resource-1', 'revision' => 'v1',
        'file' => ['url' => 'https://files.example.test/data?signature=private-signature'],
    ], $overrides)]], JSON_THROW_ON_ERROR);
}

beforeEach(function () {
    config(['cache.default' => 'array', 'linked_resources.endpoints' => [], 'app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
    Storage::fake('integrations');
    Schema::create('clients', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->string('code');
        $table->timestamps();
    });
    Schema::create('cad_endpoints', function (Blueprint $table) {
        $table->id();
        $table->foreignId('client_id')->constrained();
        foreach (['nome', 'metodo', 'url', 'extensao', 'autenticacao', 'type_storage_token', 'direcao'] as $column) {
            $table->string($column);
        }
        $table->json('headers')->nullable();
        $table->boolean('ativo');
        $table->integer('timer');
        $table->timestamp('next_run')->nullable();
        $table->integer('timeout');
        $table->integer('tentativas');
        $table->text('payload')->nullable();
        $table->json('pagination')->nullable();
        $table->timestamps();
    });
    Schema::create('cad_interface_status', function (Blueprint $table) {
        $table->id('int_id');
        foreach (['int_direcao', 'int_interface', 'int_arquivo', 'int_idoc'] as $column) {
            $table->string($column)->nullable();
        }
        $table->integer('int_status');
        $table->dateTime('int_data_envio');
    });
    (require base_path('database/migrations/2026_10_08_000000_create_linked_resources_table.php'))->up();
    $this->transport = new SimulatedResourceDownloader;
    app()->instance(SafeResourceDownloader::class, $this->transport);
    Http::preventStrayRequests();
});

test('capture preserves bytes privately and encrypts the hidden signed URL', function () {
    $endpoint = linkedEndpoint();
    linkedPolicy($endpoint);
    expect(app(ResourceCapture::class)->discover($endpoint, linkedBody(), 'original/raw.json'))->toBeTrue();
    $resource = LinkedResource::sole();
    $content = implode('', $this->transport->chunks);
    expect($resource->state)->toBe('captured')
        ->and($resource->attempts)->toBe(1)
        ->and($resource->byte_size)->toBe(strlen($content))
        ->and($resource->sha256)->toBe(hash('sha256', $content))
        ->and($resource->toArray())->not->toHaveKey('download_url')
        ->and(DB::table('linked_resources')->value('download_url'))->not->toContain('private-signature')
        ->and(Storage::disk('integrations')->get($resource->storage_path))->toBe($content);
    expect(Storage::disk('integrations')->allFiles('linked-resources/'.$endpoint->client_id.'/'.$endpoint->id))
        ->toBe([$resource->storage_path]);
    expect($this->transport->requests[0][CURLOPT_HTTPHEADER])->toBe(['Accept: application/octet-stream', 'Accept-Encoding: identity'])
        ->and($this->transport->requests[0][CURLOPT_RESOLVE])->toBe(['files.example.test:443:93.184.216.34'])
        ->and($this->transport->requests[0][CURLOPT_PROXY])->toBe('')
        ->and($this->transport->requests[0][CURLOPT_FOLLOWLOCATION])->toBeFalse()
        ->and($this->transport->requests[0][CURLOPT_SSL_VERIFYPEER])->toBeTrue();
});

test('repeat discovery and renewed signatures preserve one captured identity', function () {
    $endpoint = linkedEndpoint();
    linkedPolicy($endpoint);
    $capture = app(ResourceCapture::class);
    $capture->discover($endpoint, linkedBody(), 'first.json');
    expect($capture->discover($endpoint, linkedBody(['file' => ['url' => 'https://files.example.test/data?signature=renewed']]), 'second.json'))->toBeTrue()
        ->and(LinkedResource::count())->toBe(1)
        ->and($this->transport->requests)->toHaveCount(1);
});

test('revision and endpoint and client partition the identity', function () {
    $first = linkedEndpoint();
    $second = linkedEndpoint();
    linkedPolicy($first);
    linkedPolicy($second);
    $capture = app(ResourceCapture::class);
    $capture->discover($first, linkedBody(), 'first.json');
    $capture->discover($first, linkedBody(['revision' => 'v2']), 'second.json');
    $capture->discover($second, linkedBody(), 'third.json');
    expect(LinkedResource::count())->toBe(3)->and($this->transport->requests)->toHaveCount(3);
});

test('revision is optional and missing configured identities fail before download', function () {
    $endpoint = linkedEndpoint();
    linkedPolicy($endpoint, ['revision_path' => null]);
    expect(app(ResourceCapture::class)->discover($endpoint, linkedBody(['revision' => null]), 'raw.json'))->toBeTrue();
    expect(LinkedResource::sole()->revision)->toBeNull();
    expect(fn () => app(ResourceCapture::class)->discover($endpoint, linkedBody(['id' => null]), 'raw.json'))
        ->toThrow(CaptureException::class, 'invalid_reference');
    expect($this->transport->requests)->toHaveCount(1);
});

test('disabled capture needs no resource table and keeps legacy fetch behavior', function () {
    Schema::drop('linked_resources');
    $endpoint = linkedEndpoint();
    Http::fake(['*' => Http::response(linkedBody())]);
    $this->artisan('app:fetch-endpoints', ['--id' => $endpoint->id])->assertExitCode(0);
    Http::assertSentCount(1);
    expect($this->transport->requests)->toBe([]);
});

test('pagination captures each page before requesting the next page', function () {
    $endpoint = linkedEndpoint(['pagination' => [
        'type' => 'page', 'current_page_path' => 'meta.page', 'total_pages_path' => 'meta.total',
    ]]);
    linkedPolicy($endpoint);
    $this->transport->duringTransfer = function () {
        expect(LinkedResource::where('state', 'captured')->count())->toBe(count($this->transport->requests) - 1);
    };
    Http::fake(function ($request) {
        parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);
        $page = (int) $query['page'];
        expect(LinkedResource::where('state', 'captured')->count())->toBe($page - 1);
        $body = json_decode(linkedBody(['id' => 'page-'.$page]), true);
        $body['meta'] = ['page' => $page, 'total' => 2];

        return Http::response($body);
    });
    $this->artisan('app:fetch-endpoints', ['--id' => $endpoint->id])->assertExitCode(0);
    expect(LinkedResource::where('state', 'captured')->count())->toBe(2);
    Http::assertSentCount(2);
});

test('unsafe URLs fail without contacting any destination', function (string $url) {
    $endpoint = linkedEndpoint();
    linkedPolicy($endpoint);
    expect(app(ResourceCapture::class)->discover($endpoint, linkedBody(['file' => ['url' => $url]]), 'raw.json'))->toBeFalse()
        ->and(LinkedResource::sole()->state)->toBe('failed')
        ->and($this->transport->requests)->toBe([]);
})->with([
    'http' => 'http://files.example.test/a',
    'foreign host' => 'https://evil.example/a',
    'suffix' => 'https://files.example.test.evil.example/a',
    'userinfo' => 'https://user:pass@files.example.test/a',
    'port' => 'https://files.example.test:8443/a',
    'literal metadata' => 'https://169.254.169.254/a',
    'IPv6' => 'https://[::1]/a',
    'fragment' => 'https://files.example.test/a#fragment',
    'backslash' => 'https://files.example.test\\@evil.example/a',
]);

test('non public DNS answers fail closed including mixed answers', function (array $ips) {
    $endpoint = linkedEndpoint();
    linkedPolicy($endpoint);
    $this->transport->ips = $ips;
    expect(app(ResourceCapture::class)->discover($endpoint, linkedBody(), 'raw.json'))->toBeFalse()
        ->and($this->transport->requests)->toBe([]);
})->with([
    'loopback' => [['127.0.0.1']], 'private' => [['10.0.0.1']],
    'link local' => [['169.254.169.254']], 'CGNAT' => [['100.64.0.1']],
    'IPv6 loopback' => [['::1']], 'IPv6 private' => [['fc00::1']],
    'IPv4 mapped' => [['::ffff:93.184.216.34']],
    'multicast' => [['224.0.0.1']],
    '6to4 private destination' => [['2002:7f00:1::']],
    'mixed' => [['93.184.216.34', '192.168.1.1']], 'no DNS' => [[]],
]);

test('redirects partial responses and HTTP errors never publish', function (int $status) {
    $endpoint = linkedEndpoint();
    linkedPolicy($endpoint);
    $this->transport->status = $status;
    expect(app(ResourceCapture::class)->discover($endpoint, linkedBody(), 'raw.json'))->toBeFalse();
    $resource = LinkedResource::sole();
    expect($resource->error_code)->toBe('http_'.$status)
        ->and($resource->storage_path)->toBeNull()
        ->and(Storage::disk('integrations')->allFiles("linked-resources/{$endpoint->client_id}/{$endpoint->id}"))->toBe([]);
})->with([302, 206, 403, 500]);

test('both declared and streamed excessive bodies are stopped', function (bool $declared) {
    $endpoint = linkedEndpoint();
    linkedPolicy($endpoint, ['max_bytes' => 4]);
    $this->transport->headers = $declared ? ["Content-Length: 100\r\n"] : [];
    $this->transport->chunks = ['123', '45'];
    expect(app(ResourceCapture::class)->discover($endpoint, linkedBody(), 'raw.json'))->toBeFalse()
        ->and(LinkedResource::sole()->error_code)->toBe('too_large');
    expect(Storage::disk('integrations')->allFiles("linked-resources/{$endpoint->client_id}/{$endpoint->id}"))->toBe([]);
})->with([true, false]);

test('timeouts are recorded without signed URLs and can recover using a renewed URL', function () {
    $endpoint = linkedEndpoint();
    linkedPolicy($endpoint);
    $this->transport->error = 'timeout';
    Http::fake(['*' => Http::response(linkedBody())]);
    $this->artisan('app:fetch-endpoints', ['--id' => $endpoint->id])
        ->doesntExpectOutputToContain('private-signature')->assertExitCode(0);
    expect(LinkedResource::sole()->error_code)->toBe('timeout')
        ->and($endpoint->refresh()->next_run->isFuture())->toBeTrue();
    $this->transport->error = null;
    expect(app(ResourceCapture::class)->discover($endpoint, linkedBody(['file' => ['url' => 'https://files.example.test/new?signature=renewed']]), 'new.json'))->toBeTrue()
        ->and(LinkedResource::sole()->attempts)->toBe(2);
});

test('nested competing captures cannot download or alter a resource being captured', function () {
    $endpoint = linkedEndpoint();
    linkedPolicy($endpoint);
    $this->transport->duringTransfer = function () use ($endpoint) {
        expect(app(ResourceCapture::class)->discover($endpoint, linkedBody(), 'competing.json'))->toBeFalse()
            ->and(LinkedResource::sole()->state)->toBe('capturing');
    };
    expect(app(ResourceCapture::class)->discover($endpoint, linkedBody(), 'first.json'))->toBeTrue()
        ->and($this->transport->requests)->toHaveCount(1);
});

test('recovery handles interruption before metadata after metadata and after publication', function (string $boundary) {
    $endpoint = linkedEndpoint();
    linkedPolicy($endpoint);
    $capture = app(ResourceCapture::class);
    $capture->discover($endpoint, linkedBody(), 'raw.json', false);
    $resource = LinkedResource::sole();
    $base = "linked-resources/{$endpoint->client_id}/{$endpoint->id}/{$resource->id}";
    $stage = "{$base}/staging/content.part";
    $target = "{$base}/captured/content";
    $bytes = 'preserved csv';
    $resource->update(['state' => 'capturing', 'staging_path' => $stage, 'attempts' => 1]);
    Storage::disk('integrations')->put($boundary === 'published' ? $target : $stage, $bytes);
    if ($boundary !== 'partial') {
        $resource->update(['sha256' => hash('sha256', $bytes), 'byte_size' => strlen($bytes), 'http_status' => 200]);
    }
    $this->artisan('app:recover-linked-resources', ['--resource' => $resource->id])->assertExitCode(0);
    expect($resource->refresh()->state)->toBe('captured')
        ->and($this->transport->requests)->toHaveCount($boundary === 'partial' ? 1 : 0)
        ->and(Storage::disk('integrations')->get($target))->toBe($boundary === 'partial' ? implode('', $this->transport->chunks) : $bytes);
})->with(['partial', 'verified staging', 'published']);

test('recovery rediscovers a parent moved before resources were registered', function () {
    $endpoint = linkedEndpoint();
    linkedPolicy($endpoint);
    $source = "polling/{$endpoint->client->code}/json/incoming/{$endpoint->nome}/processed/parent.json";
    Storage::disk('integrations')->put($source, linkedBody());
    $this->artisan('app:recover-linked-resources', ['--endpoint' => $endpoint->id])->assertExitCode(0);
    expect(LinkedResource::sole()->state)->toBe('captured');
    Storage::disk('integrations')->assertExists($source);
});

test('captured bytes are not silently overwritten after corruption', function () {
    $endpoint = linkedEndpoint();
    linkedPolicy($endpoint);
    $capture = app(ResourceCapture::class);
    $capture->discover($endpoint, linkedBody(), 'raw.json');
    $resource = LinkedResource::sole();
    Storage::disk('integrations')->put($resource->storage_path, 'corrupted');
    expect($capture->recover($resource))->toBeFalse()
        ->and($resource->refresh()->error_code)->toBe('integrity_failed')
        ->and($this->transport->requests)->toHaveCount(1);
});

test('a missing previously captured file cannot trigger a silent new capture', function () {
    $endpoint = linkedEndpoint();
    linkedPolicy($endpoint);
    $capture = app(ResourceCapture::class);
    $capture->discover($endpoint, linkedBody(), 'raw.json');
    $resource = LinkedResource::sole();
    Storage::disk('integrations')->delete($resource->storage_path);
    expect($capture->recover($resource))->toBeFalse()
        ->and($capture->recover($resource->fresh()))->toBeFalse()
        ->and($this->transport->requests)->toHaveCount(1);
});

test('database rejects duplicate identities and migration rolls back independently', function () {
    $endpoint = linkedEndpoint();
    linkedPolicy($endpoint);
    app(ResourceCapture::class)->discover($endpoint, linkedBody(), 'raw.json');
    $record = DB::table('linked_resources')->first();
    $duplicate = (array) $record;
    unset($duplicate['id']);
    expect(fn () => DB::table('linked_resources')->insert($duplicate))->toThrow(\Illuminate\Database\UniqueConstraintViolationException::class);
    (require base_path('database/migrations/2026_10_08_000000_create_linked_resources_table.php'))->down();
    expect(Schema::hasTable('linked_resources'))->toBeFalse()->and(Schema::hasTable('cad_endpoints'))->toBeTrue();
});

test('publication failure retains a verified stage and recovery does not download again', function () {
    $endpoint = linkedEndpoint();
    linkedPolicy($endpoint);
    $disk = Storage::disk('integrations');
    $proxy = Mockery::mock($disk);
    $firstMove = true;
    $proxy->shouldReceive('move')->twice()->andReturnUsing(function ($from, $to) use ($disk, &$firstMove) {
        if ($firstMove) {
            $firstMove = false;

            return false;
        }

        return $disk->move($from, $to);
    });
    Storage::shouldReceive('disk')->with('integrations')->andReturn($proxy);
    expect(app(ResourceCapture::class)->discover($endpoint, linkedBody(), 'raw.json'))->toBeFalse();
    $resource = LinkedResource::sole();
    expect($resource->state)->toBe('failed')->and($resource->error_code)->toBe('storage_failed');
    expect($disk->exists($resource->staging_path))->toBeTrue();
    expect(app(ResourceCapture::class)->recover($resource))->toBeTrue()
        ->and($this->transport->requests)->toHaveCount(1);
});

test('a failed confirmation after rename is recovered from the published bytes', function () {
    $endpoint = linkedEndpoint();
    linkedPolicy($endpoint);
    LinkedResource::updating(function (LinkedResource $resource) {
        if ($resource->state === 'captured') {
            throw new RuntimeException('simulated confirmation failure');
        }
    });
    try {
        expect(app(ResourceCapture::class)->discover($endpoint, linkedBody(), 'raw.json'))->toBeFalse();
    } finally {
        LinkedResource::flushEventListeners();
    }
    expect(app(ResourceCapture::class)->recover(LinkedResource::sole()))->toBeTrue()
        ->and($this->transport->requests)->toHaveCount(1);
});

test('incomplete or encoded responses and excessive headers never publish', function (array $headers, string $error) {
    $endpoint = linkedEndpoint();
    linkedPolicy($endpoint);
    $this->transport->chunks = ['short'];
    $this->transport->headers = $headers;
    expect(app(ResourceCapture::class)->discover($endpoint, linkedBody(), 'raw.json'))->toBeFalse()
        ->and(LinkedResource::sole()->error_code)->toBe($error);
})->with([
    'truncated' => [["Content-Length: 8\r\n"], 'incomplete_response'],
    'compressed' => [["Content-Encoding: gzip\r\n"], 'unsupported_encoding'],
    'headers' => [[str_repeat('h', 65537)], 'headers_too_large'],
]);

test('a malformed or oversized discovery leaves no registered or published resources', function (string $body) {
    $endpoint = linkedEndpoint();
    linkedPolicy($endpoint, ['max_references' => 1]);
    expect(fn () => app(ResourceCapture::class)->discover($endpoint, $body, 'raw.json'))->toThrow(CaptureException::class);
    expect(LinkedResource::count())->toBe(0)->and($this->transport->requests)->toBe([]);
})->with([
    'invalid JSON' => '{', 'missing list' => '{}', 'null list' => '{"data":null}',
    'object' => '{"data":{"id":"x"}}',
    'too many' => '{"data":[{},{}]}',
]);

test('invalid policies fail closed and do not affect an unrelated endpoint', function () {
    $endpoint = linkedEndpoint();
    linkedPolicy($endpoint, ['timeout' => 9999]);
    expect(fn () => app(ResourceCapture::class)->discover($endpoint, linkedBody(), 'raw.json'))
        ->toThrow(CaptureException::class, 'invalid_configuration');
    expect(app(ResourceCapture::class)->discover(linkedEndpoint(), 'not JSON', 'other.json'))->toBeTrue()
        ->and(LinkedResource::count())->toBe(0);
});

test('explicitly disabled policies remain no ops even when their other fields are invalid', function () {
    $endpoint = linkedEndpoint();
    linkedPolicy($endpoint, ['enabled' => false, 'allowed_hosts' => null]);
    expect(app(ResourceCapture::class)->discover($endpoint, 'not JSON', 'raw.json'))->toBeTrue()
        ->and(LinkedResource::count())->toBe(0);
});

test('recovery selects the newest preserved URL across parent state directories', function () {
    $endpoint = linkedEndpoint();
    linkedPolicy($endpoint);
    $base = "polling/{$endpoint->client->code}/json/incoming/{$endpoint->nome}";
    Storage::disk('integrations')->put($base.'/processed/20261001000000000.json', linkedBody());
    Storage::disk('integrations')->put($base.'/raw/20261002000000000.json', linkedBody(['file' => ['url' => 'https://files.example.test/fresh?signature=newest']]));
    $this->artisan('app:recover-linked-resources', ['--endpoint' => $endpoint->id])->assertExitCode(0);
    expect($this->transport->requests[0][CURLOPT_URL])->toBe('https://files.example.test/fresh?signature=newest');
});

test('recovery respects disabled endpoints and policies without making a download', function (bool $active, bool $enabled) {
    $endpoint = linkedEndpoint();
    linkedPolicy($endpoint);
    app(ResourceCapture::class)->discover($endpoint, linkedBody(), 'raw.json', false);
    $endpoint->update(['ativo' => $active]);
    linkedPolicy($endpoint, ['enabled' => $enabled]);
    expect(app(ResourceCapture::class)->recover(LinkedResource::sole()))->toBeFalse()
        ->and($this->transport->requests)->toBe([]);
})->with([[false, true], [true, false]]);

test('a failed first reference does not prevent capturing the remaining references', function () {
    $endpoint = linkedEndpoint();
    linkedPolicy($endpoint);
    $first = json_decode(linkedBody(['file' => ['url' => 'https://evil.example/a']]), true)['data'][0];
    $second = json_decode(linkedBody(['id' => 'second']), true)['data'][0];
    expect(app(ResourceCapture::class)->discover($endpoint, json_encode(['data' => [$first, $second]]), 'raw.json'))->toBeFalse()
        ->and(LinkedResource::where('state', 'failed')->count())->toBe(1)
        ->and(LinkedResource::where('state', 'captured')->count())->toBe(1);
});
