<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
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
                    Storage::disk('public')->move($filePath, "{$processedPath}/{$filename}");

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

        // 2. Autenticação via Arquivo Local (Legada)
        $token = $this->getTokenFromFile($client->code ?: $client->name, $endpoint);

        if ($token) {
            switch ($tipoAuth) {
                case 'bearer': $headers['Authorization'] = 'Bearer ' . $token; break;
                case 'basic':  $headers['Authorization'] = 'Basic ' . $token; break;
                case 'api_key': $headers['Authorization'] = 'Api Key ' . $token; break;
            }
        }
    }

}
