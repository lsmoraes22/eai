<?php

namespace App\Filament\Resources\CadInterfaceStatusResource\Pages;

use App\Filament\Resources\CadInterfaceStatusResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditCadInterfaceStatus extends EditRecord
{
    protected static string $resource = CadInterfaceStatusResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
