<?php

use App\Models\CadEndpoint;
use App\Filament\Resources\CadEndpointResource;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Components\Section;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Component;

uses(Tests\UnitTestCase::class);

test('pagination migration adds a nullable json column and is reversible', function () {
    Schema::create('cad_endpoints', function (Blueprint $table) {
        $table->id();
        $table->text('payload')->nullable();
    });

    $migration = require database_path('migrations/2026_09_01_000000_add_pagination_to_cad_endpoints_table.php');
    $migration->up();

    expect(Schema::hasColumn('cad_endpoints', 'pagination'))->toBeTrue();
    $column = collect(DB::select("PRAGMA table_info('cad_endpoints')"))
        ->firstWhere('name', 'pagination');
    expect($column->notnull)->toBe(0);

    DB::table('cad_endpoints')->insert(['payload' => null, 'pagination' => null]);
    expect(DB::table('cad_endpoints')->value('pagination'))->toBeNull();

    $migration->down();
    expect(Schema::hasColumn('cad_endpoints', 'pagination'))->toBeFalse();
});

test('pagination is fillable and cast to an array', function () {
    Schema::create('cad_endpoints', function (Blueprint $table) {
        $table->id();
        $table->json('pagination')->nullable();
        $table->timestamps();
    });
    $pagination = [
        'type' => 'page',
        'current_page_path' => 'meta.current',
        'total_pages_path' => 'meta.total',
    ];
    $endpoint = new CadEndpoint(['pagination' => $pagination]);
    $endpoint->save();

    expect($endpoint->pagination)->toBe($pagination)
        ->and($endpoint->getCasts()['pagination'])->toBe('array')
        ->and(CadEndpoint::findOrFail($endpoint->id)->pagination)->toBe($pagination);
});

test('the endpoint form exposes only the page pagination configuration', function () {
    Schema::create('cad_processos', function (Blueprint $table) {
        $table->id();
        $table->string('name');
    });
    Schema::create('clients', function (Blueprint $table) {
        $table->id();
        $table->string('name');
    });
    $livewire = new class extends Component implements HasForms
    {
        use InteractsWithForms;

        public array $pagination = [];
        public ?string $direcao = null;

        public function render(): string
        {
            return '';
        }
    };
    $form = CadEndpointResource::form(Form::make($livewire));
    $components = $form->getFlatFields(withHidden: true, withAbsolutePathKeys: true);
    $paginationSection = collect($form->getComponents(withHidden: true))
        ->first(fn ($component) => $component instanceof Section && $component->getHeading() === 'Paginação');

    expect($components)->toHaveKeys([
        'pagination.type',
        'pagination.location',
        'pagination.page_param',
        'pagination.page_start',
        'pagination.page_size_param',
        'pagination.page_size',
        'pagination.current_page_path',
        'pagination.total_pages_path',
        'pagination.max_pages',
    ])->not->toHaveKeys([
        'pagination.cursor',
        'pagination.offset',
        'pagination.next_url',
    ]);

    $form->fill(['direcao' => 'entrada', 'pagination' => ['type' => 'none']]);
    expect($components['pagination.page_param']->isHidden())->toBeTrue();

    $configuredPagination = [
        'type' => 'page',
        'location' => 'query',
        'page_param' => 'page',
        'page_start' => 1,
        'page_size_param' => 'size',
        'page_size' => 100,
        'current_page_path' => 'meta.currentPage',
        'total_pages_path' => 'meta.totalPages',
        'max_pages' => 100,
    ];
    $form->fill(['direcao' => 'entrada', 'pagination' => $configuredPagination]);
    expect($components['pagination.page_param']->isVisible())->toBeTrue();
    expect($livewire->pagination)->toBe($configuredPagination);

    $form->fill(['direcao' => 'auth', 'pagination' => $configuredPagination]);
    expect($paginationSection->isHidden())->toBeTrue();
});
