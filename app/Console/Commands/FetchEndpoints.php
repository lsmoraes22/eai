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
use Carbon\Carbon;

class FetchEndpoints extends Command
{
    // Um endpoint normalmente usa até 3 tentativas de 30s. Quinze minutos
    // dão margem conservadora sem deixar um lock órfão preso por muito tempo.
    private const ENDPOINT_LOCK_TTL_SECONDS = 900;

    /**
     * O nome e a assinatura do comando.
     * Adicionada a opção --id para execuções forçadas/testes.
     */
    protected $signature = 'app:fetch-endpoints {--id=}';

    protected $description = 'Baixa arquivos XML/JSON dos endpoints agendados, lida com autenticação e salva no storage.';

    public function handle()
    {
        $startTime = microtime(true);
        $endpointId = $this->option('id');

        $this->info("🚀 Iniciando Processamento de Endpoints | " . now()->format('Y-m-d H:i:s'));

        // 1. FILTRAGEM DE ENDPOINTS
        $query = CadEndpoint::where('ativo', true);

        if ($endpointId) {
            $query->where('id', $endpointId);
            $this->warn("⚠️ MODO TESTE: Processando apenas ID {$endpointId} (ignorando agendamento).");
        } else {
            $query->where('next_run', '<=', now());
        }

        $endpoints = $query->get();

        if ($endpoints->isEmpty()) {
            $this->info("☕ Nenhum endpoint para processar agora.");
            return 0;
        }

        $this->info("📦 Encontrados {$endpoints->count()} endpoints prontos para execução.");

        foreach ($endpoints as $endpoint) {
            $this->processEndpoint($endpoint);
        }

        $duration = number_format(microtime(true) - $startTime, 2);
        $this->info("-------------------------------------------------------");
        $this->info("🏁 Finalizado em {$duration} segundos.");

        return 0;
    }

    /**
     * Lógica principal de processamento de um único endpoint.
     */
    private function processEndpoint(CadEndpoint $endpoint)
    {
        $lock = Cache::lock(
            "eai:fetch-endpoint:{$endpoint->id}",
            self::ENDPOINT_LOCK_TTL_SECONDS
        );

        if (!$lock->get()) {
            $this->line("   ℹ️ Endpoint ID {$endpoint->id} já está em processamento. Pulando.");
            return;
        }

        try {
            try {
                $success = $this->processEndpointLocked($endpoint);
            } catch (\Throwable $e) {
                $this->error("   ❌ Erro inesperado no processamento: " . $e->getMessage());
                $success = false;
            }

            $this->updateNextRun($endpoint, $success);
        } finally {
            $lock->release();
        }
    }

    private function processEndpointLocked(CadEndpoint $endpoint): bool
    {
        $client = $endpoint->client;
        if (!$client) {
            $this->error("❌ Erro: Endpoint ID {$endpoint->id} não possui um Cliente vinculado.");
            return false;
        }

        $clientCode = $client->code ?: Str::slug($client->name);
        $this->info("👉 Processando: [{$client->name}] - {$endpoint->nome}");

        if (!$endpoint->url) {
            $this->warn("   ⚠️ Sem URL definida. Pulando.");
            return false;
        }

        // B. PREPARAÇÃO DE HEADERS E AUTENTICAÇÃO
        $headers = is_array($endpoint->headers) ? $endpoint->headers : (json_decode($endpoint->headers, true) ?: []);
        $format = strtolower($endpoint->extensao ?: 'json');

        // LÓGICA DE AUTENTICAÇÃO
        if (!$this->applyAuthentication($endpoint, $client, $headers)) {
            $this->error("   ❌ Não foi possível resolver a autenticação do endpoint.");
            return false;
        }

        // Define Accept default
        if (!isset($headers['Accept'])) {
            $headers['Accept'] = ($format === 'json') ? 'application/json' : 'application/xml';
        }

        // C. EXECUÇÃO DA REQUISIÇÃO
        $method = strtolower($endpoint->metodo ?? 'get');
        $timeout = (int) ($endpoint->timeout ?? 30);
        $attempts = (int) ($endpoint->tentativas ?? 3);

        try {
            $request = Http::withHeaders($headers)->timeout($timeout);

            // Se houver User/Pass estático (Basic Auth tradicional)
            if ($endpoint->auth_user && $endpoint->auth_pass) {
                $request->withBasicAuth($endpoint->auth_user, $endpoint->auth_pass);
            }

            $request->retry($attempts, 200);

            $payload = is_array($endpoint->payload) ? $endpoint->payload : json_decode($endpoint->payload, true);

            // Determina se envia Body (Payload) ou Query Params
            if (in_array($method, ['post', 'put', 'patch'])) {
                $response = $request->{$method}($endpoint->url, $payload ?: []);
            } else {
		// No GET, o Laravel anexa o array do segundo parâmetro como ?chave=valor automaticamente
                $response = $request->{$method}($endpoint->url, $payload ?: []);
            }

            if (!$response->successful()) {
                $this->error("   ❌ Falha HTTP [{$response->status()}] para {$endpoint->url}");
                return false;
            }

            // D. TRATAMENTO DO RETORNO E SALVAMENTO
            return $this->saveResponse($endpoint, $clientCode, $response, $format);

        } catch (\Exception $e) {
            $this->error("   ❌ Erro crítico na requisição: " . $e->getMessage());
            return false;
        }
    }

    private function updateNextRun(CadEndpoint $endpoint, bool $success): void
    {
        try {
            $endpoint->next_run = $success
                ? ($endpoint->timer == 0 ? null : $endpoint->roundedTimestamp())
                : now()->addMinutes(5);
            $endpoint->save();

            if ($endpoint->next_run) {
                $this->line("   📅 Próxima execução: {$endpoint->next_run->format('H:i:s')}");
            }
        } catch (\Exception $e) {
            $this->error("   ❌ Erro ao agendar: " . $e->getMessage());
        }
    }

    /**
     * Aplica a autenticação baseada no tipo configurado.
     */
    private function applyAuthentication($endpoint, $client, &$headers): bool
    {
        $tipoAuth = strtolower($endpoint->autenticacao);

        if ($tipoAuth === 'nenhum') return true;

        if ($tipoAuth === 'basic' && $endpoint->auth_user && $endpoint->auth_pass) {
            return true;
        }

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
                return true;
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
                default: return false;
            }

            return true;
        }

        return false;
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
            $this->error("   ❌ Token ausente ou não escalar em storage/app/public/{$path}");
            return null;
        }

        return trim((string) $token);
    }

    private function saveResponse($endpoint, $clientCode, $response, $format): bool
    {
        $endpointSlug = Str::slug($endpoint->nome);
        $endpointExt = Str::slug($endpoint->extensao ?: 'json');

        // Define diretório baseado na direção
        $direcao = match (strtolower($endpoint->direcao)) {
            'entrada' => 'incoming',
            'saida'   => 'outgoing',
            'auth'    => 'auth',
            default   => 'incoming',
        };

	if($direcao=='auth'){
            // Define nome do arquivo
            $filename = 'auth.txt';
	    $directory = "token/{$clientCode}/{$endpointSlug}";
	} else {
            // Define nome do arquivo
            $filename = now()->format('YmdHisv') . '.' .  $format;
            $directory = "polling/{$clientCode}/{$endpointExt}/{$direcao}/{$endpointSlug}/raw";
	}
        Storage::disk('public')->makeDirectory($directory);

        $path = "{$directory}/{$filename}";
        $response_body = $response->body();
        $saved = Storage::disk('public')->put($path, $response_body);

        if (!$saved) {
            $this->error("   ❌ Falha ao salvar: storage/app/public/{$path}");
            return false;
        }

        $this->info("Salvo: storage/app/public/{$path}");

        // Registro de Status
        CadInterfaceStatus::create([
            'int_direcao'    => ($direcao === 'auth' ? 'auth' : 'entrada'),
            'int_interface'  => $endpoint->nome,
            'int_arquivo'    => $filename,
            'int_idoc'       => pathinfo($filename, PATHINFO_FILENAME),
            'int_status'     => 0,
            'int_data_envio' => now(),
        ]);

        return true;
    }
}
