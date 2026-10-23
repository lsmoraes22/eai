<?php

namespace App\Filament\Resources\XsdFileResource\Pages;

use App\Filament\Resources\XsdFileResource;
use Filament\Actions;
use App\Models\Client;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;
use Filament\Resources\Pages\EditRecord;

class EditXsdFile extends EditRecord
{
    protected static string $resource = XsdFileResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return $this->processUpload($data);
    }

    private function processUpload(array $data): array
    {
        if (empty($data['temp_file'])) {
            return $data;
        }

        $client = Client::find($data['client_id']);

        if (!$client) {
            throw new \Exception("Cliente inválido.");
        }

        // gera o path final
        $clientCode = Str::slug($client->code ?: $client->name);
        $uploadedPath = $data['temp_file'];  // "temp/xsd/arquivo.xsd"
        $filename = basename($uploadedPath);
        $finalDir = "polling/{$clientCode}/xsd";
        $finalPath = "{$finalDir}/{$filename}";

        Storage::disk('integrations')->makeDirectory($finalDir);
        Storage::disk('integrations')->move($uploadedPath, $finalPath);

        $data['filename'] = $filename;
        $data['path'] = $finalPath;

        unset($data['temp_file']);

        return $data;
    }


}
