<?php

use App\Services\LegacyIntegrationStorage;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;

uses(Tests\UnitTestCase::class);

beforeEach(function () {
    config(['cache.default' => 'array']);
    Cache::flush();
    foreach (['integrations', 'local', 'public'] as $disk) {
        Storage::fake($disk);
    }
});

test('canonical private tokens take precedence over legacy encrypted tokens', function () {
    $path = 'token/client/orders/auth.txt';
    Storage::disk('integrations')->put($path, 'current-token');
    Storage::disk('local')->put($path, Crypt::encryptString('old-token'));
    expect(app(LegacyIntegrationStorage::class)->token($path))->toBe('current-token');
});

test('a corrupt legacy token never falls back to a public credential', function () {
    $path = 'token/client/orders/auth.txt';
    Storage::disk('local')->put($path, 'not encrypted');
    Storage::disk('public')->put($path, 'public-token');
    expect(fn () => app(LegacyIntegrationStorage::class)->token($path))->toThrow(DecryptException::class);
});

test('encrypted legacy inputs are imported privately without deleting the encrypted originals', function () {
    $directory = 'polling/client/json/incoming/orders/raw';
    $path = $directory.'/input.json';
    $encrypted = Crypt::encryptString('{"order":123}');
    Storage::disk('local')->put($path, $encrypted);
    $storage = app(LegacyIntegrationStorage::class);
    $storage->importDirectory($directory, true);
    expect(Storage::disk('integrations')->get($path))->toBe('{"order":123}')
        ->and(Storage::disk('local')->get($path))->toBe($encrypted)
        ->and(Storage::disk('public')->allFiles())->toBeEmpty();
    $processed = str_replace('/raw/', '/processed/', $path);
    Storage::disk('integrations')->move($path, $processed);
    $storage->importDirectory($directory, true);
    Storage::disk('integrations')->assertMissing($path);
    Storage::disk('integrations')->assertExists($processed);
});

test('legacy output import never overwrites a conflicting private payload', function () {
    $directory = 'polling/client/json/outgoing/orders/raw';
    $path = $directory.'/output.json';
    Storage::disk('local')->put($path, '{"order":1}');
    Storage::disk('integrations')->put($path, '{"order":2}');
    expect(fn () => app(LegacyIntegrationStorage::class)->importDirectory($directory, false))
        ->toThrow(RuntimeException::class, 'Legacy integration file conflict.');
    expect(Storage::disk('integrations')->get($path))->toBe('{"order":2}');
});
