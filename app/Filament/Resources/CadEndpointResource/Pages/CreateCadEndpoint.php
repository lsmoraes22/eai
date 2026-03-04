<?php

namespace App\Filament\Resources\CadEndpointResource\Pages;

use App\Filament\Resources\CadEndpointResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

class CreateCadEndpoint extends CreateRecord
{
    protected static string $resource = CadEndpointResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // 1. Cria uma instância temporária do modelo CadEndpoint com os dados do formulário.
        // Isso permite que você acesse a função roundedTimestamp() usando o 'timer' 
        // recém-fornecido pelo usuário.
        $tempEndpoint = new \App\Models\CadEndpoint($data);

        // 2. Calcula e define o next_run para o próximo intervalo arredondado.
        // Isso garante que o agendador saiba quando executar esta tarefa pela primeira vez.

	$timer = $timerEndpoint->timer ?? 0;
        $data['next_run'] = $timer == 0 ? null : $tempEndpoint->roundedTimestamp();
        // 3. Retorna os dados modificados para o Filament salvar o novo registro.
        return $data;
    }
}
