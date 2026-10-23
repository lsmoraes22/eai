<?php

use App\Filament\Resources\JsonSchemaResource\Pages\CreateJsonSchema;
use App\Models\CadEndpoint;
use App\Models\CadWebhook;
use App\Models\Client;
use App\Models\JsonSchema;
use App\Models\User;
use Database\Seeders\ShieldSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

function privateStorageClient(): Client
{
    return Client::create(['name' => 'Example', 'code' => 'example', 'telefone' => '0000000000', 'endereco' => 'Test', 'cnpj' => '00000000000000', 'app_client_secret' => 'fixture-secret']);
}

beforeEach(function () {
    Storage::fake('public');
    Storage::fake('integrations');
});

test('fetch writes authentication response only to private storage', function () {
    $client = privateStorageClient();
    $endpoint = CadEndpoint::create([
        'client_id' => $client->id, 'nome' => 'auth', 'tipo' => 'REST', 'url' => 'https://provider.test/token',
        'metodo' => 'POST', 'extensao' => 'json', 'direcao' => 'auth', 'autenticacao' => 'nenhum',
        'ativo' => true, 'timer' => 0,
    ]);
    Http::fake(['*' => Http::response(['access_token' => 'fixture-token'])]);
    $this->artisan('app:fetch-endpoints', ['--id' => $endpoint->id])->assertSuccessful();
    expect(Storage::disk('integrations')->get('token/example/auth/auth.txt'))->toContain('fixture-token');
    Storage::disk('public')->assertDirectoryEmpty('/');
});

test('signed webhook writes payload only to private storage', function () {
    $client = privateStorageClient();
    CadWebhook::create(['client_id' => $client->id, 'nome' => 'orders', 'ativo' => true, 'webhook_verify' => true, 'webhook_header' => 'X-Test-Signature', 'webhook_algo' => 'sha256']);
    $payload = '{"id":"synthetic-order"}';
    $this->call('POST', "/webhook/{$client->id}/orders", [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_TEST_SIGNATURE' => hash_hmac('sha256', $payload, 'fixture-secret'),
    ], $payload)->assertOk();
    $files = Storage::disk('integrations')->files('webhooks/example/json/incoming/orders/raw');
    expect($files)->toHaveCount(1)
        ->and(Storage::disk('integrations')->get($files[0]))->toBe($payload);
    Storage::disk('public')->assertDirectoryEmpty('/');
});

test('schema creation through Filament loads client options and saves the upload privately', function () {
    $this->seed(ShieldSeeder::class);
    $user = User::factory()->create();
    $user->assignRole('super_admin');
    $this->actingAs($user);
    $client = privateStorageClient();
    Livewire::test(CreateJsonSchema::class)
        ->fillForm(['client_id' => $client->id, 'name' => 'Orders', 'temp_file' => UploadedFile::fake()->createWithContent('orders.json', '{"type":"object"}')])
        ->call('create')->assertHasNoFormErrors();
    $schema = JsonSchema::firstOrFail();
    expect($schema->path)->toBe('polling/example/schema/orders.json')
        ->and(Storage::disk('integrations')->get($schema->path))->toBe('{"type":"object"}');
    Storage::disk('public')->assertDirectoryEmpty('/');
});

test('HTTP errors and transport exceptions do not disclose bodies credentials or query strings', function (string $command, bool $transportError) {
    $client = privateStorageClient();
    $endpoint = CadEndpoint::create([
        'client_id' => $client->id, 'nome' => 'orders', 'tipo' => 'REST',
        'url' => 'https://provider.test/orders?api_key=fixture-query-secret',
        'metodo' => 'POST', 'extensao' => 'json', 'direcao' => $command === 'app:fetch-endpoints' ? 'entrada' : 'saida',
        'autenticacao' => 'nenhum', 'ativo' => true, 'timer' => 0, 'tentativas' => 1,
    ]);
    Storage::disk('integrations')->put('polling/example/json/outgoing/orders/raw/test.json', '{"commercial":"fixture-payload"}');
    $attempts = 0;
    Http::fake(function () use ($transportError, &$attempts) {
        $attempts++;
        if ($transportError) {
            throw new \Illuminate\Http\Client\ConnectionException('Authorization: fixture-transport-secret');
        }
        return Http::response(['access_token' => 'fixture-response-secret'], 400);
    });
    \Illuminate\Support\Facades\Artisan::call($command, ['--id' => $endpoint->id]);
    $output = \Illuminate\Support\Facades\Artisan::output();
    expect($output)->not->toContain('fixture-query-secret', 'fixture-payload', 'fixture-transport-secret', 'fixture-response-secret');
    expect($attempts)->toBe(1);
})->with(['app:fetch-endpoints', 'app:send-endpoints'])->with([false, true]);

test('refresh token errors log status or exception type without provider content', function (bool $transportError) {
    $client = privateStorageClient();
    $client->update(['auth_url' => 'https://provider.test/authorize', 'token_url' => 'https://provider.test/token', 'refresh_token' => 'fixture-refresh']);
    \Illuminate\Support\Facades\Log::spy();
    Http::fake(fn () => $transportError
        ? throw new \Illuminate\Http\Client\ConnectionException('fixture-transport-secret')
        : Http::response(['access_token' => 'fixture-response-secret'], 400));
    expect($client->refreshToken())->toBeNull();
    \Illuminate\Support\Facades\Log::shouldHaveReceived('error')->once()->withArgs(fn (string $message) =>
        !str_contains($message, 'fixture-') && (str_contains($message, '400') || str_contains($message, 'ConnectionException')));
})->with([false, true]);

test('XSD upload creation and replacement share the private disk', function () {
    $this->seed(ShieldSeeder::class);
    $user = User::factory()->create();
    $user->assignRole('super_admin');
    $this->actingAs($user);
    $client = privateStorageClient();
    $xml = '<?xml version="1.0"?><xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema"/>';
    Livewire::test(\App\Filament\Resources\XsdFileResource\Pages\CreateXsdFile::class)
        ->fillForm(['client_id' => $client->id, 'name' => 'Orders', 'filename' => 'orders.xsd', 'temp_file' => UploadedFile::fake()->createWithContent('orders.xsd', $xml)])
        ->call('create')->assertHasNoFormErrors();
    $schema = \App\Models\XsdFile::firstOrFail();
    expect($schema->path)->toBe('polling/example/xsd/orders.xsd')
        ->and(Storage::disk('integrations')->get($schema->path))->toBe($xml);
    Livewire::test(\App\Filament\Resources\XsdFileResource\Pages\EditXsdFile::class, ['record' => $schema->getRouteKey()])
        ->fillForm(['temp_file' => UploadedFile::fake()->createWithContent('updated.xsd', $xml)])
        ->call('save')->assertHasNoFormErrors();
    expect($schema->refresh()->path)->toBe('polling/example/xsd/updated.xsd')
        ->and(Storage::disk('integrations')->get($schema->path))->toBe($xml);
    Storage::disk('public')->assertDirectoryEmpty('/');
});

test('panel image preview reads private bytes without a public storage URL', function () {
    $this->seed(ShieldSeeder::class);
    $user = User::factory()->create();
    $user->assignRole('super_admin');
    $this->actingAs($user);
    $bytes = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
    Storage::disk('integrations')->put('clients/example/preview.gif', $bytes);
    Livewire::test(\App\Filament\Pages\FileExplorer::class)
        ->set('currentPath', 'clients/example')->call('previewFile', 'preview.gif')
        ->assertSet('previewType', 'image')
        ->assertSet('previewContent', 'data:image/gif;base64,' . base64_encode($bytes));
    Storage::disk('public')->assertDirectoryEmpty('/');
});
