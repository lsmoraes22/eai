<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use App\Models\Client;
use App\Models\CadEndpoint;
use App\Models\CadInterfaceStatus;
use Illuminate\Support\Str;

class SendEndpoints extends Command
{
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
            if(!$endpoint) continue;
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
        $localDisk = Storage::disk('local');
        $publicDisk = Storage::disk('public');
        $sourcePath = "polling/{$clientCode}/{$format}/outgoing/{$endpointSlug}/raw";
        $processedPath = "polling/{$clientCode}/{$format}/outgoing/{$endpointSlug}/processed";
        $tokenPath = "token/{$clientCode}/{$endpointSlug}/auth.txt";
        $token = $this->resolveToken($endpoint, $client, $tokenPath);

        $disk = $localDisk->exists($sourcePath) ? $localDisk : $publicDisk;

        if (!$disk->exists($sourcePath)){
            $this->error(" Exceção: " . "Pasta de origem não existe: storage/app/{$sourcePath}");
            return;
        };

        $files = $disk->files($sourcePath);

        foreach ($files as $filePath) {
            $filename = basename($filePath);
            $content = $disk->get($filePath);

            // 1. Prepara Headers e Auth (Igual ao seu Fetch)
            $headers = is_array($endpoint->headers) ? $endpoint->headers : (json_decode($endpoint->headers, true) ?: []);

            if($endpoint->auth_api_way === 'header' && $token){
                $this->applyAuthentication($endpoint, $client, $headers, $token);
            } else {
                $content = $this->saveTokenIntoBody($endpoint, $content, $token); // Se necessário enviar o token no body, caso o endpoint exija isso (não é comum, mas pode acontecer)
            }

            $this->info(" Enviando: {$filename} para {$endpoint->url}");
            // Define o Content-Type correto para o que está sendo enviado xml json txt csv etc
            switch ($format) {
                case 'xml':  $headers['Content-Type'] = 'application/xml'; break;
                case 'json': $headers['Content-Type'] = 'application/json'; break;
                case 'txt':  $headers['Content-Type'] = 'text/plain'; break;
                case 'csv':  $headers['Content-Type'] = 'text/csv'; break;
                default:     $headers['Content-Type'] = 'application/octet-stream';
            }

            try {
                $method = strtolower($endpoint->metodo ?? 'post');
                // 2. Dispara o conteúdo do arquivo como Body
	                $response = Http::withHeaders($headers)
                    ->timeout($endpoint->timeout ?? 30)
                    ->withBody($content, $headers['Content-Type'])
                    ->{$method}($endpoint->url);

                $message = $this->summarizeExternalResponse($endpoint, $response->status(), $response->body(), $response->headers());

                if ($response->successful()) {
                    CadInterfaceStatus::where('int_arquivo', $filename)
                        ->update([
                            'int_status' => 1, // Enviado/Sucesso
                            'int_mensagem' => $message,
                            'int_data_processamento' => now(),
                        ]);
                    // 3. Sucesso: Move para processados e atualiza status
                    $disk->makeDirectory($processedPath);
                    $disk->move($filePath, "{$processedPath}/{$filename}");
                    $this->info("   ✔ Sucesso!");
                } else {
                    // 4. Erro de API: Loga e mantém na pasta para retry
                    $errorMsg = $message;
                    $this->error(" Falha: " . $errorMsg);

                    CadInterfaceStatus::where('int_arquivo', $filename)
                        ->update([
                            'int_status' => 2, // Erro no Envio
                            'int_mensagem' => $errorMsg,
                            'int_data_processamento' => now(),
                        ]);
                }

            } catch (\Exception $e) {
                $this->error(" Exceção: " . $e->getMessage());
            }
        }
    }

    private function applyAuthentication($endpoint, $client, &$headers, $resolvedToken = null)
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
        $token = $resolvedToken ?? $this->resolveToken($endpoint, $client);

        if (is_scalar($token) && $token !== '') {
            switch ($tipoAuth) {
                case 'bearer': $headers['Authorization'] = 'Bearer ' . $token; break;
                case 'basic':  $headers['Authorization'] = 'Basic ' . $token; break;
                case 'api_key': $headers['Authorization'] = 'Api Key ' . $token; break;
            }
        }
    }

    private function resolveToken($endpoint, $client, ?string $tokenPath = null)
    {
        return match ($endpoint->type_storage_token) {
            'fixed' => $endpoint->auth_token,
            'client_token' => $client->access_token,
            default => $this->readTokenFromStorage(
                $tokenPath ?: "token/" . ($client->code ?: $client->name) . "/" . Str::slug($endpoint->nome) . "/auth.txt",
                $endpoint->auth_token
            ),
        };
    }

    private function readTokenFromStorage(string $tokenPath, ?string $configuredKey = null): ?string
    {
        if (Storage::disk('local')->exists($tokenPath)) {
            try {
                $content = Crypt::decryptString(Storage::disk('local')->get($tokenPath));
            } catch (DecryptException $e) {
                $this->error(" Token inválido ou não descriptografável em storage/app/{$tokenPath}");
                return null;
            }

            return $this->extractToken($content, $configuredKey, $tokenPath);
        }

        if (Storage::disk('public')->exists($tokenPath)) {
            $this->warn(" Token legado lido de storage/app/public/{$tokenPath}; migre para storage privado.");
            return $this->extractToken(Storage::disk('public')->get($tokenPath), $configuredKey, $tokenPath);
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
            $this->error(" Token ausente ou não escalar em storage/app/{$path}");
            return null;
        }

        return trim((string) $token);
    }

    private function saveTokenIntoBody($endpoint, $content, $token) //incomum para enviar token no body, mas pode ser necessário para algum endpoint específico. O ideal é que o token seja enviado via header, mas deixo esse método caso queira usar
    {
        if (is_array($content)) {
            $content[$endpoint->auth_api_way] = $token;
            return json_encode($content);
        }
        // Se for string, tenta decodificar como JSON
        $decoded = json_decode($content, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            $decoded[$endpoint->auth_api_way] = $token;
            return json_encode($decoded);
        }
        // Se não for JSON, retorna o conteúdo original
        return $content;
    }

    private function summarizeExternalResponse($endpoint, int $status, string $body, array $headers): string
    {
        $correlationId = $headers['X-Correlation-ID'][0]
            ?? $headers['X-Request-ID'][0]
            ?? $headers['x-correlation-id'][0]
            ?? $headers['x-request-id'][0]
            ?? 'n/a';

        $summary = $this->sanitizeExternalText($body);

        return Str::limit(
            "HTTP {$status}; endpoint={$endpoint->nome}; correlation_id={$correlationId}; resumo={$summary}",
            250
        );
    }

    private function sanitizeExternalText(string $text): string
    {
        $decoded = json_decode($text, true);

        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return json_encode($this->sanitizeArray($decoded), JSON_UNESCAPED_SLASHES);
        }

        $patterns = [
            '/(authorization\s*[:=]\s*)(bearer\s+)?[^\s,;"]+/i',
            '/([a-z0-9._%+\-]+)@([a-z0-9.\-]+\.[a-z]{2,})/i',
            '/\b\d{3}\.?\d{3}\.?\d{3}-?\d{2}\b/',
            '/\b\d{2}\.?\d{3}\.?\d{3}\/?\d{4}-?\d{2}\b/',
            '/\b(token|access_token|refresh_token|password|senha|secret|api_key)\b\s*[:=]\s*[^\s,;"]+/i',
        ];

        $replacements = [
            '$1[redacted]',
            '[redacted-email]',
            '[redacted-cpf]',
            '[redacted-cnpj]',
            '$1=[redacted]',
        ];

        return preg_replace($patterns, $replacements, $text) ?? '[redacted]';
    }

    private function sanitizeArray(array $data): array
    {
        $sensitiveKeys = [
            'authorization', 'token', 'access_token', 'refresh_token', 'api_key',
            'apikey', 'password', 'passwd', 'senha', 'secret', 'client_secret',
            'cpf', 'cnpj', 'email',
        ];

        foreach ($data as $key => $value) {
            $normalizedKey = Str::of((string) $key)->lower()->replace(['-', ' '], '_')->toString();

            if (in_array($normalizedKey, $sensitiveKeys, true)) {
                $data[$key] = '[redacted]';
                continue;
            }

            if (is_array($value)) {
                $data[$key] = $this->sanitizeArray($value);
            }
        }

        return $data;
    }

}
