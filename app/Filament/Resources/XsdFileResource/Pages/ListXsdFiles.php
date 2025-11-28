<?php

namespace App\Filament\Resources\XsdFileResource\Pages;

use App\Filament\Resources\XsdFileResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListXsdFiles extends ListRecords
{
    protected static string $resource = XsdFileResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
