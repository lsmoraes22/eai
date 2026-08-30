<?php

use App\Models\CadEndpoint;
use App\Models\CadInterfaceStatus;
use App\Models\Client;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;

test('validate json reads encrypted local raw files and continues public pipeline', function () {
    Storage::fake('local');
    Storage::fake('public');

    $client = Client::create([
        'name' => 'Cliente Json',
        'code' => 'cliente-json',
        'telefone' => '0000-0000',
        'endereco' => 'Rua Teste',
        'cnpj' => '00000000000000',
        'active' => true,
    ]);

    CadEndpoint::create([
        'client_id' => $client->id,
        'nome' => 'orders',
        'tipo' => 'REST',
        'metodo' => 'GET',
        'url' => 'https://api.test/orders',
        'extensao' => 'json',
        'headers' => [],
        'autenticacao' => 'nenhum',
        'direcao' => 'entrada',
        'timer' => 0,
        'next_run' => now()->subMinute(),
        'type_storage_token' => 'fixed',
        'ativo' => true,
        'timeout' => 5,
        'tentativas' => 2,
        'payload' => '{}',
        'auth_api_way' => 'header',
    ]);

    Storage::disk('local')->put(
        'polling/cliente-json/json/incoming/orders/raw/incoming.json',
        Crypt::encryptString('{"order":123}')
    );

    CadInterfaceStatus::create([
        'int_direcao' => 'entrada',
        'int_interface' => 'orders',
        'int_arquivo' => 'incoming.json',
        'int_idoc' => 'incoming',
        'int_status' => 0,
        'int_data_envio' => now(),
    ]);

    $this->artisan('app:validate-json')
        ->assertExitCode(0);

    expect(Storage::disk('local')->exists('polling/cliente-json/json/incoming/orders/raw/incoming.json'))->toBeFalse()
        ->and(Storage::disk('public')->get('polling/cliente-json/json/incoming/orders/validated/incoming.json'))->toBe('{"order":123}');

    $this->assertDatabaseHas('cad_interface_status', [
        'int_arquivo' => 'incoming.json',
        'int_status' => 1,
    ]);
});
