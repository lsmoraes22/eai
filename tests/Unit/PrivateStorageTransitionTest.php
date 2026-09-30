<?php

use Illuminate\Support\Facades\Storage;

uses(Tests\UnitTestCase::class);

beforeEach(function () {
    Storage::fake('public');
    Storage::fake('integrations');
});

test('legacy inspection changes neither public nor private files', function () {
    Storage::disk('public')->put('token/example/auth.txt', 'fixture-token');
    $this->artisan('app:import-private-storage')->expectsOutputToContain('pending: 1')->assertSuccessful();
    Storage::disk('public')->assertExists('token/example/auth.txt');
    Storage::disk('integrations')->assertMissing('token/example/auth.txt');
});

test('legacy copy preserves paths bytes and originals and can be repeated before cutover', function () {
    $files = ['token/example/auth.txt', 'polling/example/json/incoming/orders/raw/a.json', 'webhooks/example/json/incoming/orders/raw/b.json', 'temp/xsd/orders.xsd'];
    foreach ($files as $path) {
        Storage::disk('public')->put($path, 'fixture-' . $path);
    }
    Storage::disk('public')->put('assets/logo.txt', 'public asset');
    $this->artisan('app:import-private-storage', ['--copy' => true])->expectsOutputToContain('Copied: 4')->assertSuccessful();
    foreach ($files as $path) {
        expect(Storage::disk('integrations')->get($path))->toBe(Storage::disk('public')->get($path));
    }
    Storage::disk('integrations')->assertMissing('assets/logo.txt');
    $this->artisan('app:import-private-storage', ['--copy' => true])->expectsOutputToContain('identical: 4')->assertSuccessful();
});

test('legacy conflicts fail without overwriting or deleting either version', function () {
    Storage::disk('public')->put('token/example/auth.txt', 'old-fixture');
    Storage::disk('integrations')->put('token/example/auth.txt', 'new-fixture');
    $this->artisan('app:import-private-storage', ['--copy' => true])->assertFailed();
    expect(Storage::disk('public')->get('token/example/auth.txt'))->toBe('old-fixture')
        ->and(Storage::disk('integrations')->get('token/example/auth.txt'))->toBe('new-fixture');
});

test('failed private copy reports failure and preserves legacy files', function () {
    Storage::disk('public')->put('token/example/auth.txt', 'fixture-token');
    $public = Storage::disk('public');
    $private = Mockery::mock(Storage::disk('integrations'));
    $private->shouldReceive('writeStream')->once()->andReturnFalse();
    Storage::shouldReceive('disk')->with('public')->andReturn($public);
    Storage::shouldReceive('disk')->with('integrations')->andReturn($private);
    $this->artisan('app:import-private-storage', ['--copy' => true])->assertFailed();
    expect(Storage::disk('public')->get('token/example/auth.txt'))->toBe('fixture-token');
});

test('legacy local uploads and client files are copied to the consistent private paths', function () {
    Storage::fake('local');
    Storage::disk('local')->put('temp/xsd/orders.xsd', '<schema/>');
    Storage::disk('local')->put('public/clients/example/orders.json', '{"id":1}');
    Storage::disk('local')->put('tokens/example/orders.txt', 'fixture-token');
    $this->artisan('app:import-private-storage', ['--source' => 'local', '--copy' => true])->assertSuccessful();
    expect(Storage::disk('integrations')->get('temp/xsd/orders.xsd'))->toBe('<schema/>')
        ->and(Storage::disk('integrations')->get('clients/example/orders.json'))->toBe('{"id":1}')
        ->and(Storage::disk('integrations')->get('tokens/example/orders.txt'))->toBe('fixture-token');
    Storage::disk('local')->assertExists('public/clients/example/orders.json');
});
