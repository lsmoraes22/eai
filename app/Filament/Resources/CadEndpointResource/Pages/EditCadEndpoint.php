<?php

namespace App\Filament\Resources\CadEndpointResource\Pages;

use App\Filament\Resources\CadEndpointResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditCadEndpoint extends EditRecord
{
    protected static string $resource = CadEndpointResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
