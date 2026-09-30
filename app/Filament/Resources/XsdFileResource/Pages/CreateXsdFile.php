<?php

namespace App\Filament\Resources\XsdFileResource\Pages;

use App\Filament\Resources\XsdFileResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class CreateXsdFile extends CreateRecord
{
    protected static string $resource = XsdFileResource::class;

    protected function afterCreate(): void
    {
        $record = $this->record;

        // Caminho temporário completo
        $tempPath = Storage::disk('integrations')->path($record->temp_file);

        if (!file_exists($tempPath)) {
            // Se não existir, podemos logar ou lançar exceção
            \Log::error("Arquivo temporário não encontrado: {$tempPath}");
            return;
        }

        // Cliente
        $client = $record->client;
        $clientCode = Str::slug($client->code ?: $client->name);

        // Diretório final
        $finalDir = Storage::disk('integrations')->path("polling/{$clientCode}/xsd");
        if (!is_dir($finalDir)) {
            mkdir($finalDir, 0700, true);
        }

        // Nome final
        $filename = basename($record->temp_file);
        $finalPath = "{$finalDir}/{$filename}";

        // Mover arquivo
        rename($tempPath, $finalPath);

        // Atualizar registro no banco
        $record->path = "polling/{$clientCode}/xsd/{$filename}";
        $record->save();

        // Opcional: remover temp_file do modelo, se não for mais usado
        $record->temp_file = null;
        $record->save();
    }

}
