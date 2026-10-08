<?php

use App\Models\CadDepara;
use App\Models\CadInterface;
use App\Models\CadProcesso;
use App\Models\CadProcessoTxtLayout;
use Illuminate\Support\Facades\Schema;

it('can query legacy models against their migrated tables', function (string $name) {
    $class = 'App\\Models\\'.$name;
    $model = new $class;

    expect(Schema::hasColumn($model->getTable(), $model->getKeyName()))->toBeTrue();
    if ($model->usesTimestamps()) {
        expect(Schema::hasColumns($model->getTable(), ['created_at', 'updated_at']))->toBeTrue();
    }
    expect($model->newQuery()->get())->toBeInstanceOf(Illuminate\Database\Eloquent\Collection::class);
})->with([
    'CadBloqueioTriagemInfolog', 'CadCodactStorage', 'CadControleAlteracaoPedido',
    'CadDepara', 'CadDevolucao', 'CadHistorico', 'CadHistoricoBatchMaster',
    'CadHistoricoMovimentoLote', 'CadInterfaceM40', 'CadInterfacePlanejamento',
    'CadItensDevolucao', 'CadMotivosBloqueioDesbloqueio', 'CadMovimentoMotivosBloqueio',
    'CadMovimentoSapInfolog', 'CadUploadNota', 'InterfaceError',
]);

it('persists legacy keys and timestamps through the model lifecycle', function () {
    $model = new CadDepara;
    $model->cdp_codpro = 'portfolio-test';
    $model->save();
    $id = $model->getKey();
    expect($id)->not->toBeNull();
    $model->cdp_unipro = 'UN';
    $model->save();
    expect(CadDepara::findOrFail($id)->cdp_unipro)->toBe('UN');
    $model->delete();
    expect(CadDepara::find($id))->toBeNull();
});

it('persists the interface order without nonexistent timestamps', function () {
    $interface = CadInterface::create(['ci_tipo' => 'TEST', 'ci_ot' => 42]);
    expect($interface->fresh()->ci_ot)->toBe(42);
    $interface->update(['ci_ot' => 43]);
    expect($interface->fresh()->ci_ot)->toBe(43);
    $interface->delete();
    expect(CadInterface::find($interface->getKey()))->toBeNull();
});

it('loads only the TXT layouts belonging to the process', function () {
    $first = CadProcesso::create(['name' => 'First', 'user_create_id' => 1, 'active' => true]);
    $second = CadProcesso::create(['name' => 'Second', 'user_create_id' => 1, 'active' => true]);
    $layout = CadProcessoTxtLayout::create(['processo_id' => $first->id, 'section_type' => 'line']);
    CadProcessoTxtLayout::create(['processo_id' => $second->id, 'section_type' => 'header']);
    expect($first->txtLayout->modelKeys())->toBe([$layout->id]);
    expect($layout->processo->is($first))->toBeTrue();
});
