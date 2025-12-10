<?php

namespace App\Filament\Resources\CadProcessoResource\Pages;

use App\Filament\Resources\CadProcessoResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListCadProcessos extends ListRecords
{
    protected static string $resource = CadProcessoResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
