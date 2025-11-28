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
        $tempPath = storage_path('app/public/' . $record->temp_file);

        if (!file_exists($tempPath)) {
            // Se não existir, podemos logar ou lançar exceção
            \Log::error("Arquivo temporário não encontrado: {$tempPath}");
            return;
        }

        // Cliente
        $client = $record->client;
        $clientCode = Str::slug($client->code ?: $client->name);

        // Diretório final
        $finalDir = storage_path("app/public/clients/{$clientCode}/xsd");
        if (!is_dir($finalDir)) {
            mkdir($finalDir, 0777, true);
        }

        // Nome final
        $filename = basename($record->temp_file);
        $finalPath = "{$finalDir}/{$filename}";

        // Mover arquivo
        rename($tempPath, $finalPath);

        // Atualizar registro no banco
        $record->path = "clients/{$clientCode}/xsd/{$filename}";
        $record->save();

        // Opcional: remover temp_file do modelo, se não for mais usado
        $record->temp_file = null;
        $record->save();
    }

}
