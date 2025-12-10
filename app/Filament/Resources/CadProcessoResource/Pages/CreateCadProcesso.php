<?php

namespace App\Filament\Resources\CadProcessoResource\Pages;

use App\Filament\Resources\CadProcessoResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

class CreateCadProcesso extends CreateRecord
{
    protected static string $resource = CadProcessoResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['user_create_id'] = auth()->id();
        return $data;
    }
}
