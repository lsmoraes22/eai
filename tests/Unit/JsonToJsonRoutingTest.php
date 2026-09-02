<?php

use App\Models\CadEndpoint;
use App\Models\CadInterfaceStatus;
use App\Models\CadProcesso;
use App\Models\CadProcessosDepara;
use App\Models\Client;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(Tests\TestCase::class);

function routingClient(array $overrides = []): Client
{
    return Client::create(array_merge([
        'name' => 'Routing Client',
        'code' => 'routing-client-' . uniqid(),
    ], $overrides));
}

function routingEndpoint(Client $client, array $overrides = []): CadEndpoint
{
    return CadEndpoint::create(array_merge([
        'client_id' => $client->id,
        'nome' => 'endpoint',
        'direcao' => 'entrada',
        'extensao' => 'json',
    ], $overrides));
}

function routingProcess(array $overrides = []): CadProcesso
{
    $process = CadProcesso::create(array_merge([
        'name' => 'legacy_process',
        'initial_format' => 'JSON',
        'final_format' => 'JSON',
        'active' => true,
        'user_create_id' => 1,
    ], $overrides));

    CadProcessosDepara::create([
        'processo_id' => $process->id,
        'input_path' => 'source',
        'output_path' => 'target',
        'data_type' => 'string',
        'active' => true,
        'order' => 1,
    ]);

    return $process;
}

function putValidatedJson(Client $client, string $name, string $filename = 'input.json'): string
{
    $path = 'polling/' . Str::slug($client->code ?: $client->name)
        . "/json/incoming/{$name}/validated/{$filename}";
    Storage::disk('public')->put($path, '{"source":"value"}');

    return $path;
}

beforeEach(function () {
    Storage::fake('public');

    Schema::create('clients', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->string('code');
        $table->timestamps();
    });
    Schema::create('cad_endpoints', function (Blueprint $table) {
        $table->id();
        $table->foreignId('client_id');
        $table->string('nome');
        $table->string('direcao');
        $table->string('extensao');
        $table->timestamps();
    });
    Schema::create('cad_processos', function (Blueprint $table) {
        $table->id();
        $table->foreignId('input_endpoint_id')->nullable();
        $table->foreignId('output_endpoint_id')->nullable();
        $table->string('input_collection_path')->nullable();
        $table->string('output_mode')->nullable();
        $table->string('name');
        $table->string('initial_format');
        $table->string('final_format');
        $table->boolean('active');
        $table->bigInteger('user_create_id');
        $table->timestamps();
    });
    Schema::create('cad_processos_deparas', function (Blueprint $table) {
        $table->id();
        $table->foreignId('processo_id');
        $table->string('input_path');
        $table->string('output_path');
        $table->unsignedInteger('order')->default(0);
        $table->string('data_type');
        $table->string('default_value')->nullable();
        $table->boolean('active');
        $table->timestamps();
    });
    Schema::create('cad_interface_status', function (Blueprint $table) {
        $table->id('int_id');
        $table->string('int_arquivo')->nullable();
        $table->integer('int_status')->nullable();
        $table->string('int_mensagem')->nullable();
        $table->timestamps();
    });
});

test('a legacy process still routes by its unchanged name', function () {
    $client = routingClient();
    routingProcess(['name' => 'legacy_process']);
    $input = putValidatedJson($client, 'legacy_process');

    $this->artisan('app:convert-json-json')->assertExitCode(0);

    $output = 'polling/' . $client->code . '/json/outgoing/legacy_process/raw/input.json';
    Storage::disk('public')->assertExists($output);
    expect(json_decode(Storage::disk('public')->get($output), true))->toBe(['target' => 'value']);
    Storage::disk('public')->assertMissing($input);
    Storage::disk('public')->assertExists(str_replace('/validated/', '/processed/', $input));
});

test('explicit endpoints route different names through normalized slugs to the send directory', function () {
    $client = routingClient(['code' => 'customer']);
    $inputEndpoint = routingEndpoint($client, ['nome' => 'algar_faturas', 'direcao' => 'entrada']);
    $outputEndpoint = routingEndpoint($client, ['nome' => 'MK Contas Pagar', 'direcao' => 'saida']);
    routingProcess([
        'name' => 'importar_fatura_mk',
        'input_endpoint_id' => $inputEndpoint->id,
        'output_endpoint_id' => $outputEndpoint->id,
    ]);
    $input = putValidatedJson($client, 'algar-faturas', 'page-000001.json');

    $this->artisan('app:convert-json-json')->assertExitCode(0);

    $sendDirectory = 'polling/customer/json/outgoing/mk-contas-pagar/raw';
    Storage::disk('public')->assertExists("{$sendDirectory}/page-000001.json");
    Storage::disk('public')->assertExists(str_replace('/validated/', '/processed/', $input));
});

test('an explicit single output mode preserves the original filename and one to one behavior', function () {
    $client = routingClient(['code' => 'single-client']);
    $inputEndpoint = routingEndpoint($client, ['nome' => 'single_input', 'direcao' => 'entrada']);
    $outputEndpoint = routingEndpoint($client, ['nome' => 'single output', 'direcao' => 'saida']);
    routingProcess([
        'name' => 'different_process_name',
        'input_endpoint_id' => $inputEndpoint->id,
        'output_endpoint_id' => $outputEndpoint->id,
        'output_mode' => 'single',
    ]);
    $input = putValidatedJson($client, 'single-input', 'original-name.json');

    $this->artisan('app:convert-json-json')->assertExitCode(0);

    Storage::disk('public')->assertExists(
        'polling/single-client/json/outgoing/single-output/raw/original-name.json'
    );
    Storage::disk('public')->assertExists(str_replace('/validated/', '/processed/', $input));
});

test('an inactive json process is not processed', function () {
    $client = routingClient();
    routingProcess([
        'name' => 'inactive_process',
        'active' => false,
        'output_mode' => 'per_item',
        'input_collection_path' => 'data',
    ]);
    $input = putValidatedJson($client, 'inactive_process');

    $this->artisan('app:convert-json-json')->assertExitCode(0);

    Storage::disk('public')->assertExists($input);
    Storage::disk('public')->assertMissing(
        "polling/{$client->code}/json/outgoing/inactive_process/raw/input.json"
    );
});

test('explicit endpoints from different clients are rejected', function () {
    $inputClient = routingClient(['code' => 'input-client']);
    $outputClient = routingClient(['code' => 'output-client']);
    $inputEndpoint = routingEndpoint($inputClient, ['nome' => 'input', 'direcao' => 'entrada']);
    $outputEndpoint = routingEndpoint($outputClient, ['nome' => 'output', 'direcao' => 'saida']);
    routingProcess([
        'name' => 'cross_client',
        'input_endpoint_id' => $inputEndpoint->id,
        'output_endpoint_id' => $outputEndpoint->id,
    ]);
    $input = putValidatedJson($inputClient, 'input');

    $this->artisan('app:convert-json-json')
        ->expectsOutputToContain('pertencem a clientes diferentes')
        ->assertExitCode(0);

    Storage::disk('public')->assertExists($input);
    Storage::disk('public')->assertMissing(
        'polling/input-client/json/outgoing/output/raw/input.json'
    );
});

test('a storage write failure leaves the validated input eligible', function () {
    $client = routingClient();
    routingProcess(['name' => 'write_failure']);
    $input = putValidatedJson($client, 'write_failure');
    $realDisk = Storage::disk('public');
    $disk = Mockery::mock($realDisk);
    $disk->shouldReceive('put')->once()->andReturnFalse();
    Storage::shouldReceive('disk')->with('public')->andReturn($disk);

    $this->artisan('app:convert-json-json')
        ->expectsOutputToContain('Falha ao salvar payload transformado')
        ->assertExitCode(0);

    $realDisk->assertExists($input);
    $realDisk->assertMissing(str_replace('/validated/', '/processed/', $input));
});

test('a storage move failure leaves the input unprocessed and does not mark its status', function () {
    $client = routingClient();
    routingProcess(['name' => 'move_failure']);
    $input = putValidatedJson($client, 'move_failure');
    $status = CadInterfaceStatus::create(['int_arquivo' => 'input.json', 'int_status' => 1]);
    $realDisk = Storage::disk('public');
    $disk = Mockery::mock($realDisk);
    $disk->shouldReceive('move')->once()->andReturnFalse();
    Storage::shouldReceive('disk')->with('public')->andReturn($disk);

    $this->artisan('app:convert-json-json')
        ->expectsOutputToContain('Falha ao mover arquivo processado')
        ->assertExitCode(0);

    $realDisk->assertExists($input);
    expect($status->refresh()->int_status)->toBe(1);
});

test('json validation uses the same slug as fetch endpoints', function () {
    $client = routingClient(['code' => 'customer']);
    routingEndpoint($client, ['nome' => 'algar_faturas', 'direcao' => 'entrada']);
    $raw = 'polling/customer/json/incoming/algar-faturas/raw/page-000001.json';
    Storage::disk('public')->put($raw, '{"source":"value"}');
    Storage::disk('public')->put(
        'polling/customer/schema/algar-faturas.json',
        '{"$schema":"http://json-schema.org/draft-06/schema#","type":"object"}'
    );

    $this->artisan('app:validate-json')->assertExitCode(0);

    Storage::disk('public')->assertMissing($raw);
    Storage::disk('public')->assertExists(
        'polling/customer/json/incoming/algar-faturas/validated/page-000001.json'
    );
});
