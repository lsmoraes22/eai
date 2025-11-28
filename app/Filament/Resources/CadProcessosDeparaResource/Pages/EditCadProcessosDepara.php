<?php

namespace App\Filament\Resources\CadProcessosDeparaResource\Pages;

use App\Filament\Resources\CadProcessosDeparaResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditCadProcessosDepara extends EditRecord
{
    protected static string $resource = CadProcessosDeparaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
