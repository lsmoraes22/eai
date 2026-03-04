<?php

namespace App\Filament\Resources\JsonSchemaResource\Pages;

use App\Filament\Resources\JsonSchemaResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditJsonSchema extends EditRecord
{
    protected static string $resource = JsonSchemaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
    	// Só move se houver um arquivo novo na pasta temporária
    	if (str_contains($data['temp_file'], 'temp/json_schema')) {
            return $this->moveFileAndAdjustData($data);
    	}

    	return $data;
    }

    private function moveFileAndAdjustData(array $data): array
    {
    	$client = \App\Models\Client::find($data['client_id']);
    	$clientCode = \Illuminate\Support\Str::slug($client->code ?: $client->name);

    	$tempPath = $data['temp_file'];
    	$filename = basename($tempPath);
    	$finalPath = "polling/{$clientCode}/schema/{$filename}";

    	if (\Illuminate\Support\Facades\Storage::disk('public')->exists($tempPath)) {
    	    \Illuminate\Support\Facades\Storage::disk('public')->move($tempPath, $finalPath);
    	}

    	$data['path'] = $finalPath;
    	$data['filename'] = $filename;

    	return $data;
    }

}
