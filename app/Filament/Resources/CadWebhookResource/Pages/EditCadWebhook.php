<?php

namespace App\Filament\Resources\CadWebhookResource\Pages;

use App\Filament\Resources\CadWebhookResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditCadWebhook extends EditRecord
{
    protected static string $resource = CadWebhookResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
