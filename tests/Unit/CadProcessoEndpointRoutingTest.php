<?php

use App\Models\CadEndpoint;
use App\Models\CadProcesso;
use App\Filament\Resources\CadProcessoResource;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Component;

uses(Tests\TestCase::class);

beforeEach(function () {
    Schema::enableForeignKeyConstraints();
    Schema::create('cad_endpoints', function (Blueprint $table) {
        $table->id();
        $table->string('nome');
        $table->string('direcao');
        $table->timestamps();
    });
    Schema::create('cad_processos', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->string('initial_format');
        $table->string('final_format');
        $table->boolean('active');
        $table->bigInteger('user_create_id');
        $table->timestamps();
    });
});

test('endpoint routing migration is nullable and reversible', function () {
    $migration = require database_path('migrations/2026_09_01_020000_add_endpoint_routing_to_cad_processos_table.php');
    $migration->up();

    expect(Schema::hasColumns('cad_processos', ['input_endpoint_id', 'output_endpoint_id']))->toBeTrue();

    $input = CadEndpoint::create(['nome' => 'input', 'direcao' => 'entrada']);
    $output = CadEndpoint::create(['nome' => 'output', 'direcao' => 'saida']);
    $process = CadProcesso::create([
        'name' => 'route',
        'initial_format' => 'JSON',
        'final_format' => 'JSON',
        'active' => 1,
        'user_create_id' => 1,
        'input_endpoint_id' => $input->id,
        'output_endpoint_id' => $output->id,
    ]);

    expect($process->active)->toBeTrue()
        ->and($process->inputEndpoint->is($input))->toBeTrue()
        ->and($process->outputEndpoint->is($output))->toBeTrue()
        ->and($input->inputProcesses()->whereKey($process->id)->exists())->toBeTrue()
        ->and($output->outputProcesses()->whereKey($process->id)->exists())->toBeTrue();

    $migration->down();
    expect(Schema::hasColumn('cad_processos', 'input_endpoint_id'))->toBeFalse()
        ->and(Schema::hasColumn('cad_processos', 'output_endpoint_id'))->toBeFalse();
});

test('routing columns accept null for legacy processes', function () {
    $migration = require database_path('migrations/2026_09_01_020000_add_endpoint_routing_to_cad_processos_table.php');
    $migration->up();

    DB::table('cad_processos')->insert([
        'name' => 'legacy',
        'initial_format' => 'JSON',
        'final_format' => 'JSON',
        'active' => true,
        'user_create_id' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $process = CadProcesso::firstOrFail();
    expect($process->input_endpoint_id)->toBeNull()
        ->and($process->output_endpoint_id)->toBeNull();
});

test('an endpoint without process references can be deleted', function () {
    $migration = require database_path('migrations/2026_09_01_020000_add_endpoint_routing_to_cad_processos_table.php');
    $migration->up();

    $endpoint = CadEndpoint::create(['nome' => 'unused', 'direcao' => 'entrada']);

    expect($endpoint->delete())->toBeTrue()
        ->and(CadEndpoint::find($endpoint->id))->toBeNull();
});

test('an input endpoint cannot be deleted until its process reference is removed', function () {
    $migration = require database_path('migrations/2026_09_01_020000_add_endpoint_routing_to_cad_processos_table.php');
    $migration->up();

    $input = CadEndpoint::create(['nome' => 'input', 'direcao' => 'entrada']);
    $process = CadProcesso::create([
        'name' => 'input_route',
        'initial_format' => 'JSON',
        'final_format' => 'JSON',
        'active' => true,
        'user_create_id' => 1,
        'input_endpoint_id' => $input->id,
    ]);

    expect(fn () => $input->delete())->toThrow(QueryException::class);
    expect($process->refresh()->input_endpoint_id)->toBe($input->id)
        ->and(CadEndpoint::find($input->id))->not->toBeNull();

    $process->update(['input_endpoint_id' => null]);

    expect($input->delete())->toBeTrue()
        ->and(CadEndpoint::find($input->id))->toBeNull();
});

test('an output endpoint cannot be deleted until its process reference is removed', function () {
    $migration = require database_path('migrations/2026_09_01_020000_add_endpoint_routing_to_cad_processos_table.php');
    $migration->up();

    $output = CadEndpoint::create(['nome' => 'output', 'direcao' => 'saida']);
    $process = CadProcesso::create([
        'name' => 'output_route',
        'initial_format' => 'JSON',
        'final_format' => 'JSON',
        'active' => true,
        'user_create_id' => 1,
        'output_endpoint_id' => $output->id,
    ]);

    expect(fn () => $output->delete())->toThrow(QueryException::class);
    expect($process->refresh()->output_endpoint_id)->toBe($output->id)
        ->and(CadEndpoint::find($output->id))->not->toBeNull();

    $process->update(['output_endpoint_id' => null]);

    expect($output->delete())->toBeTrue()
        ->and(CadEndpoint::find($output->id))->toBeNull();
});

test('the process form offers nullable endpoints filtered by direction', function () {
    $input = CadEndpoint::create(['nome' => 'input', 'direcao' => 'entrada']);
    $output = CadEndpoint::create(['nome' => 'output', 'direcao' => 'saida']);
    $auth = CadEndpoint::create(['nome' => 'auth', 'direcao' => 'auth']);
    $livewire = new class extends Component implements HasForms
    {
        use InteractsWithForms;

        public function render(): string
        {
            return '';
        }
    };
    $fields = CadProcessoResource::form(Form::make($livewire))
        ->getFlatFields(withHidden: true, withAbsolutePathKeys: true);

    expect($fields)->toHaveKeys(['input_endpoint_id', 'output_endpoint_id'])
        ->and($fields['input_endpoint_id']->isRequired())->toBeFalse()
        ->and($fields['output_endpoint_id']->isRequired())->toBeFalse()
        ->and($fields['input_endpoint_id']->getOptions())->toBe([$input->id => 'input'])
        ->and($fields['output_endpoint_id']->getOptions())->toBe([$output->id => 'output'])
        ->and($fields['input_endpoint_id']->getOptions())->not->toHaveKey($auth->id)
        ->and($fields['output_endpoint_id']->getOptions())->not->toHaveKey($auth->id);
});
