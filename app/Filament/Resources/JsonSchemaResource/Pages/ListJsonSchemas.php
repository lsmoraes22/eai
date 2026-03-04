<?php

namespace App\Filament\Resources\JsonSchemaResource\Pages;

use App\Filament\Resources\JsonSchemaResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListJsonSchemas extends ListRecords
{
    protected static string $resource = JsonSchemaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
