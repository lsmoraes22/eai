<?php

namespace App\Filament\Resources\CadProcessosDeparaResource\Pages;

use App\Filament\Resources\CadProcessosDeparaResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListCadProcessosDeparas extends ListRecords
{
    protected static string $resource = CadProcessosDeparaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
