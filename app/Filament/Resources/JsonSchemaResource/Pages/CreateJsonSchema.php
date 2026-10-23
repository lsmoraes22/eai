<?php

namespace App\Filament\Resources\JsonSchemaResource\Pages;

use App\Filament\Resources\JsonSchemaResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

class CreateJsonSchema extends CreateRecord
{
    protected static string $resource = JsonSchemaResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
    	$client = \App\Models\Client::find($data['client_id']);
    	$clientCode = \Illuminate\Support\Str::slug($client->code ?: $client->name);

    	$tempPath = $data['temp_file'];
    	$filename = basename($tempPath);
    	$finalPath = "polling/{$clientCode}/schema/{$filename}";

    	// Move o arquivo para o local definitivo do pipeline
        \Illuminate\Support\Facades\Storage::disk('integrations')->move($tempPath, $finalPath);

    	$data['path'] = $finalPath;
    	$data['filename'] = $filename;

	return $this->moveFileAndAdjustData($data);
    }

    private function moveFileAndAdjustData(array $data): array
    {
    	$client = \App\Models\Client::find($data['client_id']);
    	$clientCode = \Illuminate\Support\Str::slug($client->code ?: $client->name);

    	$tempPath = $data['temp_file'];
    	$filename = basename($tempPath);
    	$finalPath = "polling/{$clientCode}/schema/{$filename}";

        if (\Illuminate\Support\Facades\Storage::disk('integrations')->exists($tempPath)) {
            \Illuminate\Support\Facades\Storage::disk('integrations')->move($tempPath, $finalPath);
    	}

    	$data['path'] = $finalPath;
    	$data['filename'] = $filename;

    	return $data;
    }

}
