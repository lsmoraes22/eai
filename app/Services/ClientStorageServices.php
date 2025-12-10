<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;

class ClientStorageServices
{
    private string $base = 'public/clients';

    /**
     * Cria a pasta do cliente se não existir.
     */
    public function ensureClientDir(string $clientId): void
    {
        $path = "{$this->base}/{$clientId}";
        if (!Storage::exists($path)) {
            Storage::makeDirectory($path);
        }
    }

    /**
     * Salva um arquivo dentro do diretório do cliente.
     */
    public function saveFile(string $clientId, string $filename, string $content): string
    {
        $this->ensureClientDir($clientId);

        $path = "{$this->base}/{$clientId}/{$filename}";
        Storage::put($path, $content);

        return $path;
    }

    /**
     * Lista arquivos do cliente.
     */
    public function listFiles(string $clientId): array
    {
        $path = "{$this->base}/{$clientId}";
        return Storage::files($path);
    }

    /**
     * Obtém conteúdo de um arquivo.
     */
    public function getFile(string $clientId, string $filename): ?string
    {
        $path = "{$this->base}/{$clientId}/{$filename}";
        return Storage::exists($path) ? Storage::get($path) : null;
    }

    /**
     * Apaga um arquivo.
     */
    public function deleteFile(string $clientId, string $filename): bool
    {
        $path = "{$this->base}/{$clientId}/{$filename}";
        return Storage::delete($path);
    }

    /**
     * Remove a pasta do cliente.
     */
    public function deleteClientDir(string $clientId): bool
    {
        $path = "{$this->base}/{$clientId}";
        return Storage::deleteDirectory($path);
    }
}
