<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use App\Models\Client;
use App\Models\CadEndpoint;
use App\Models\CadInterfaceStatus;
use Illuminate\Support\Str;

class SendEndpoints extends Command
{
    // One request per item (30s default timeout); 10 minutes leaves conservative
    // headroom for configured timeouts plus Storage and status persistence.
    private const ITEM_LOCK_TTL_SECONDS = 600;

    protected $signature = 'app:send-endpoints {--id=}';
    protected $description = 'Envia arquivos da pasta outgoing/raw para os endpoints de destino.';

    public function handle()
    {
        $this->info("Iniciando Envio de Dados | " . now()->format('Y-m-d H:i:s'));

        $query = CadEndpoint::where('ativo', true)->where('direcao', 'saida');

        if ($this->option('id')) {
            $query->where('id', $this->option('id'));
        }

        $endpoints = $query->get();

        foreach ($endpoints as $endpoint) {
            $this->processSend($endpoint);
        }

        return 0;
    }

    private function processSend(CadEndpoint $endpoint)
    {
        $client = $endpoint->client;
        $clientCode = $client->code ?: Str::slug($client->name);
        $endpointSlug = Str::slug($endpoint->nome);
        $format = strtolower($endpoint->extensao ?: 'json');

        // Pasta onde o motor de De-Para (XmlToXml, JsonToXml) deixou os arquivos
        $sourcePath = "polling/{$clientCode}/{$format}/outgoing/{$endpointSlug}/raw";
        $processedPath = "polling/{$clientCode}/{$format}/outgoing/{$endpointSlug}/processed";

        if (!Storage::disk('public')->exists($sourcePath)){
            $this->error(" Exceção: " . "Pasta de origem não existe: storage/app/public/{$sourcePath}"); 
            return;
        };

        $files = Storage::disk('public')->files($sourcePath);


        foreach ($files as $filePath) {
            $lockKey = 'eai:send-item:' . hash('sha256', "{$endpoint->id}|{$filePath}");
            $lock = Cache::lock($lockKey, self::ITEM_LOCK_TTL_SECONDS);

            if (!$lock->get()) {
                $this->comment(" Item " . basename($filePath) . " já está em processamento. Pulando.");
                continue;
            }

            try {
                $this->processFile($endpoint, $client, $filePath, $processedPath, $format);
            } finally {
                $lock->release();
            }
        }
    }

    private function processFile(CadEndpoint $endpoint, Client $client, string $filePath, string $processedPath, string $format): void
    {
        $filename = basename($filePath);
        $content = Storage::disk('public')->get($filePath);

        $this->info(" Enviando: {$filename} para {$endpoint->url}");

        // 1. Prepara Headers e Auth (Igual ao seu Fetch)
        $headers = is_array($endpoint->headers) ? $endpoint->headers : (json_decode($endpoint->headers, true) ?: []);
        $this->applyAuthentication($endpoint, $client, $headers);

        // Define o Content-Type correto para o que está sendo enviado xml json txt csv etc
        switch ($format) {
            case 'xml': $headers['Content-Type'] = 'application/xml'; break;
            case 'json': $headers['Content-Type'] = 'application/json'; break;
            case 'txt': $headers['Content-Type'] = 'text/plain'; break;
            case 'csv': $headers['Content-Type'] = 'text/csv'; break;
            default: $headers['Content-Type'] = 'application/octet-stream';
        }

        try {
            $method = strtolower($endpoint->metodo ?? 'post');

            // 2. Dispara o conteúdo do arquivo como Body
            $response = Http::withHeaders($headers)
                ->timeout($endpoint->timeout ?? 30)
                ->withBody($content, $headers['Content-Type'])
                ->{$method}($endpoint->url);

            if ($response->successful()) {
                // 3. Sucesso: Move para processados e atualiza status
                Storage::disk('public')->makeDirectory($processedPath);
                $moved = Storage::disk('public')->move($filePath, "{$processedPath}/{$filename}");

                if (!$moved) {
                    $this->error(" Falha ao mover {$filename} para processados. O item permanece pendente.");
                    return;
                }

                CadInterfaceStatus::where('int_arquivo', $filename)
                    ->update([
                        'int_status' => 1, // Enviado/Sucesso
                        'int_mensagem' => 'Enviado com sucesso: ' . $response->status()
                    ]);

                $this->info("   ✔ Sucesso!");
            } else {
                // 4. Erro de API: Loga e mantém na pasta para retry
                $errorMsg = "Erro {$response->status()}: " . $response->body();
                $this->error(" Falha: " . $errorMsg);

                CadInterfaceStatus::where('int_arquivo', $filename)
                    ->update([
                        'int_status' => 2, // Erro no Envio
                        'int_mensagem' => Str::limit($errorMsg, 250)
                    ]);
            }

        } catch (\Exception $e) {
            $this->error(" Exceção: " . $e->getMessage());
        }
    }

    private function applyAuthentication($endpoint, $client, &$headers)
    {
        $tipoAuth = strtolower($endpoint->autenticacao);

        if ($tipoAuth === 'nenhum') return;

        // 1. Caso Especial: OAuth2 - Busca no Model Client
        if ($tipoAuth === 'oauth2') {
            if (!$client->access_token || ($client->expires_at && $client->expires_at->subMinute()->isPast())) {
                $this->comment(" Token expirado. Renovando...");
                // Aqui você chamaria o método de refresh que criamos no Model Client
                $token = $client->refreshToken();
            } else {
                $token = $client->access_token;
            }

            if ($token) {
                $headers['Authorization'] = 'Bearer ' . $token;
                return;
            }
        }

        // 2. Autenticação conforme a origem configurada
        $token = match ($endpoint->type_storage_token) {
            'fixed' => $endpoint->auth_token,
            'client_token' => $client->access_token,
            default => $this->getTokenFromFile($client->code ?: $client->name, $endpoint),
        };

        if (is_scalar($token) && $token !== '') {
            switch ($tipoAuth) {
                case 'bearer': $headers['Authorization'] = 'Bearer ' . $token; break;
                case 'basic':  $headers['Authorization'] = 'Basic ' . $token; break;
                case 'api_key': $headers['Authorization'] = 'Api Key ' . $token; break;
            }
        }
    }

    private function getTokenFromFile($clientCode, $endpoint)
    {
        $endpointSlug = Str::slug($endpoint->nome);
        $path = "token/{$clientCode}/{$endpointSlug}/auth.txt";

        if (Storage::disk('public')->exists($path)) {
            return $this->extractToken(Storage::disk('public')->get($path), $endpoint->auth_token, $path);
        }

        return null;
    }

    private function extractToken(string $content, ?string $configuredKey, string $path): ?string
    {
        $decoded = json_decode($content, true);

        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $this->validateTokenValue(data_get($decoded, $configuredKey ?: 'access_token'), $path);
        }

        if (str_starts_with(ltrim($content), '<')) {
            $xml = simplexml_load_string($content);
            if ($xml !== false) {
                $decoded = json_decode(json_encode($xml), true);
                return $this->validateTokenValue(data_get($decoded, $configuredKey ?: 'access_token'), $path);
            }
        }

        return trim($content);
    }

    private function validateTokenValue($token, string $path): ?string
    {
        if ($token === null || is_array($token) || is_object($token)) {
            $this->error(" Token ausente ou não escalar em storage/app/public/{$path}");
            return null;
        }

        return trim((string) $token);
    }

}
