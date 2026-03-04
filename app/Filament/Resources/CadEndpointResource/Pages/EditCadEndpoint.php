<?php

namespace App\Filament\Resources\CadEndpointResource\Pages;

use App\Filament\Resources\CadEndpointResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Carbon;

class EditCadEndpoint extends EditRecord
{
    protected static string $resource = CadEndpointResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // 1. Obtém o modelo atual (o registro que está sendo editado)
        $endpoint = $this->getRecord();

        // 2. Verifica se o 'timer' mudou
        // Se o valor do timer que veio do formulário ($data['timer'])
        // for diferente do valor atual no banco ($endpoint->timer),
        // OU se o next_run estiver nulo (o que pode acontecer em uma nova criação)
        if ($data['timer'] !== $endpoint->timer || empty($data['next_run'])) {

            // Temporariamente, cria um objeto modelo a partir dos novos dados
            // Isso garante que roundedTimestamp use o novo valor de 'timer'
            $tempEndpoint = new \App\Models\CadEndpoint($data);

            // Define o next_run com o timestamp arredondado.
            // O Carbon (retorno da função) será automaticamente convertido para string
            // no formato SQL pelo Laravel antes de salvar.
            $timer = $endpoint->timer ?? 0;
            $data['next_run'] = $timer == 0 ? null : $tempEndpoint->roundedTimestamp();

        }

        // 3. Retorna os dados modificados para o Filament salvar
        return $data;
    }

}
