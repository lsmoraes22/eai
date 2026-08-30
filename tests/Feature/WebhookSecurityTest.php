<?php

use App\Models\CadWebhook;
use App\Models\Client;
use Illuminate\Support\Facades\Storage;

function createWebhookClient(array $overrides = []): Client
{
    return Client::create(array_merge([
        'name' => 'Cliente Webhook',
        'code' => 'cliente-webhook',
        'telefone' => '0000-0000',
        'endereco' => 'Rua Teste',
        'cnpj' => '00000000000000',
        'active' => true,
        'app_client_secret' => 'webhook-secret',
    ], $overrides));
}

test('webhook with hmac enabled rejects invalid signatures without persisting payload', function () {
    Storage::fake('public');

    $client = createWebhookClient();
    CadWebhook::create([
        'client_id' => $client->id,
        'nome' => 'orders',
        'ativo' => true,
        'webhook_verify' => true,
        'webhook_header' => 'X-Test-Signature',
        'webhook_algo' => 'sha256',
    ]);

    $response = $this
        ->withHeaders(['X-Test-Signature' => 'sha256=invalid-signature'])
        ->postJson("/webhook/{$client->id}/orders", ['id' => 123]);

    $response->assertUnauthorized();

    expect(Storage::disk('public')->allFiles())->toBeEmpty();
    $this->assertDatabaseMissing('cad_interface_status', [
        'int_interface' => 'orders',
        'int_status' => 0,
    ]);
});

test('webhook with hmac enabled rejects unsupported algorithms', function () {
    Storage::fake('public');

    $client = createWebhookClient();
    CadWebhook::create([
        'client_id' => $client->id,
        'nome' => 'orders',
        'ativo' => true,
        'webhook_verify' => true,
        'webhook_header' => 'X-Test-Signature',
        'webhook_algo' => 'md5',
    ]);

    $response = $this
        ->withHeaders(['X-Test-Signature' => 'md5=anything'])
        ->postJson("/webhook/{$client->id}/orders", ['id' => 123]);

    $response->assertStatus(500);

    expect(Storage::disk('public')->allFiles())->toBeEmpty();
});
