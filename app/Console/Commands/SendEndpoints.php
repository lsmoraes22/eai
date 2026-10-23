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

        $files = Storage::disk('integrations')->files($sourcePath);
        if ($files === []) {
            try {
                app(\App\Services\LegacyIntegrationStorage::class)->importDirectory($sourcePath, false, true);
                $files = Storage::disk('integrations')->files($sourcePath);
            } catch (\Throwable) {
                $this->error('Falha ao importar payload legado para o storage privado.');
                return;
            }
        }

        if (!Storage::disk('integrations')->exists($sourcePath)){
            $this->error(" Exceção: " . "Pasta de origem não existe: storage/app/private/integrations/{$sourcePath}");
            return;
        };

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
        $content = Storage::disk('integrations')->get($filePath);

        $this->info(" Enviando: {$filename} para endpoint ID {$endpoint->id}");

        // 1. Prepara Headers e Auth (Igual ao seu Fetch)
        $headers = is_array($endpoint->headers) ? $endpoint->headers : (json_decode($endpoint->headers, true) ?: []);
        $way = $endpoint->auth_api_way ?: 'header';
        if ($way === 'header') {
            $this->applyAuthentication($endpoint, $client, $headers);
        } elseif (strtolower($endpoint->autenticacao) !== 'nenhum') {
            $token = $this->resolveToken($endpoint, $client);
            $decoded = json_decode($content, true);
            if (!is_scalar($token) || $token === '' || !is_array($decoded)) {
                $this->error('Token ou payload inválido para autenticação no body.');
                return;
            }
            $decoded[$way] = $token;
            $content = json_encode($decoded, JSON_THROW_ON_ERROR);
        }

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
                Storage::disk('integrations')->makeDirectory($processedPath);
                $moved = Storage::disk('integrations')->move($filePath, "{$processedPath}/{$filename}");

                if (!$moved) {
                    $this->error(" Falha ao mover {$filename} para processados. O item permanece pendente.");
                    return;
                }

                CadInterfaceStatus::where('int_arquivo', $filename)
                    ->update([
                        'int_status' => 1, // Enviado/Sucesso
                        'int_mensagem' => $this->summarizeExternalResponse($endpoint, $response->status(), $response->headers())
                    ]);

                $this->info("   ✔ Sucesso!");
            } else {
                // 4. Erro de API: Loga e mantém na pasta para retry
                $errorMsg = $this->summarizeExternalResponse($endpoint, $response->status(), $response->headers());
                $this->error(" Falha: " . $errorMsg);

                CadInterfaceStatus::where('int_arquivo', $filename)
                    ->update([
                        'int_status' => 2, // Erro no Envio
                        'int_mensagem' => Str::limit($errorMsg, 250)
                    ]);
            }

        } catch (\Exception $e) {
            $this->error(" Exceção: " . $e::class
                . ($e instanceof \Illuminate\Http\Client\RequestException ? ' HTTP ' . $e->response->status() : ''));
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

        try {
            $content = app(\App\Services\LegacyIntegrationStorage::class)->token($path);
            if ($content !== null) {
                return $this->extractToken($content, $endpoint->auth_token, $path);
            }
        } catch (\Illuminate\Contracts\Encryption\DecryptException) {
            $this->error('Token legado não descriptografável.');
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
            $this->error(" Token ausente ou não escalar em storage/app/private/integrations/{$path}");
            return null;
        }

        return trim((string) $token);
    }

    private function resolveToken(CadEndpoint $endpoint, Client $client): ?string
    {
        if (strtolower($endpoint->autenticacao) === 'oauth2') {
            return !$client->access_token || ($client->expires_at && $client->expires_at->copy()->subMinute()->isPast())
                ? $client->refreshToken() : $client->access_token;
        }
        return match ($endpoint->type_storage_token) {
            'fixed' => $endpoint->auth_token,
            'client_token' => $client->access_token,
            default => $this->getTokenFromFile($client->code ?: $client->name, $endpoint),
        };
    }

    private function summarizeExternalResponse(CadEndpoint $endpoint, int $status, array $headers): string
    {
        $correlation = $headers['X-Correlation-ID'][0] ?? $headers['X-Request-ID'][0]
            ?? $headers['x-correlation-id'][0] ?? $headers['x-request-id'][0] ?? 'n/a';
        // Keep diagnostic metadata, never provider bodies or signed URLs.
        $correlation = preg_match('/\A[a-zA-Z0-9_-]{1,64}\z/', $correlation) ? $correlation : 'redacted';
        return Str::limit("HTTP {$status}; endpoint ID={$endpoint->id}; correlation_id={$correlation}", 250);
    }
}
