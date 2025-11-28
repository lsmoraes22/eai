<?php

namespace App\Filament\Resources\CadEndpointResource\Pages;

use App\Filament\Resources\CadEndpointResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListCadEndpoints extends ListRecords
{
    protected static string $resource = CadEndpointResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}


