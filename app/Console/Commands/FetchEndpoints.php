<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use App\Models\Client;
use App\Models\CadEndpoint;
use App\Models\CadInterfaceStatus;
use Illuminate\Support\Str;
use Carbon\Carbon;

class FetchEndpoints extends Command
{
    private const STATUS_PENDING = 0;
    private const STATUS_SUCCESS = 1;
    private const STATUS_FAILED = 2;
    private const STATUS_PROCESSING = 3;
    private const STATUS_DEAD = 5;

    /**
     * O nome e a assinatura do comando.
     * Adicionada a opção --id para execuções forçadas/testes.
     */
    protected $signature = 'app:fetch-endpoints {--id=}';

    protected $description = 'Baixa arquivos XML/JSON dos endpoints agendados, lida com autenticação e salva no storage.';

    // Extensões para busca de arquivos de autenticação legados
    private $auth_extensions = ['json', 'xml', 'txt'];

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
        $lock = Cache::lock("fetch-endpoint:{$endpoint->id}", (int) ($endpoint->timeout ?? 30) + 30);

        if (!$lock->get()) {
            $this->warn("   ⚠️ Endpoint ID {$endpoint->id} já está em processamento.");
            return;
        }

        try {
            $this->processEndpointLocked($endpoint);
        } finally {
            $lock->release();
        }
    }

    private function processEndpointLocked(CadEndpoint $endpoint)
    {
        $client = $endpoint->client;
        if (!$client) {
            $this->error("❌ Erro: Endpoint ID {$endpoint->id} não possui um Cliente vinculado.");
            return;
        }

        $clientCode = $client->code ?: Str::slug($client->name);
        $this->info("👉 Processando: [{$client->name}] - {$endpoint->nome}");

        // A. ATUALIZA PRÓXIMO AGENDAMENTO (Imediatamente para evitar sobreposição)
        try {
            $endpoint->next_run = $endpoint->timer==0 ? null : $endpoint->roundedTimestamp();
            $endpoint->save();
            if($endpoint->next_run){ $this->line("   📅 Próxima execução: {$endpoint->next_run->format('H:i:s')}"); }
        } catch (\Exception $e) {
            $this->error("   ❌ Erro ao agendar: " . $e->getMessage());
            return;
        }

        if (!$endpoint->url) {
            $this->warn("   ⚠️ Sem URL definida. Pulando.");
            return;
        }

        // B. PREPARAÇÃO DE HEADERS E AUTENTICAÇÃO
        $headers = is_array($endpoint->headers) ? $endpoint->headers : (json_decode($endpoint->headers, true) ?: []);
        $format = strtolower($endpoint->extensao ?: 'json');

        // LÓGICA DE AUTENTICAÇÃO
        $this->applyAuthentication($endpoint, $client, $headers);

        // Define Accept default
        if (!isset($headers['Accept'])) {
            $headers['Accept'] = ($format === 'json') ? 'application/json' : 'application/xml';
        }

        // C. EXECUÇÃO DA REQUISIÇÃO
        $method = strtolower($endpoint->metodo ?? 'get');
        $timeout = (int) ($endpoint->timeout ?? 30);
        $attempts = (int) ($endpoint->tentativas ?? 3);
        $payload = is_array($endpoint->payload) ? $endpoint->payload : json_decode($endpoint->payload, true);

        if (!$this->isAllowedMethod($method)) {
            $this->recordStatus($endpoint, null, self::STATUS_FAILED, "Método HTTP não permitido: {$method}");
            $this->error("   ❌ Método HTTP não permitido: {$method}");
            return;
        }

        if ($method === 'get' && $this->containsSensitivePayload($payload)) {
            $this->recordStatus($endpoint, null, self::STATUS_FAILED, 'Payload sensível bloqueado em requisição GET.');
            $this->error('   ❌ Payload sensível bloqueado em requisição GET. Use POST/PUT/PATCH.');
            return;
        }

        $idempotencyKey = $this->makeIdempotencyKey($endpoint, $client, $payload);

        if ($this->hasSuccessfulRun($idempotencyKey)) {
            $this->warn("   ⚠️ Execução idempotente já concluída: {$idempotencyKey}");
            return;
        }

        $status = $this->recordStatus($endpoint, $idempotencyKey, self::STATUS_PROCESSING, 'Processamento iniciado.');

        try {
            $request = Http::withHeaders($headers)->timeout($timeout);

            // Se houver User/Pass estático (Basic Auth tradicional)
            if ($endpoint->auth_user && $endpoint->auth_pass) {
                $request->withBasicAuth($endpoint->auth_user, $endpoint->auth_pass);
            }

            $request->retry($attempts, 200, throw: false);

            // Determina se envia Body (Payload) ou Query Params
            if (in_array($method, ['post', 'put', 'patch'])) {
                $response = $request->{$method}($endpoint->url, $payload ?: []);
            } else {
		// No GET, o Laravel anexa o array do segundo parâmetro como ?chave=valor automaticamente
                $response = $request->{$method}($endpoint->url, $payload ?: []);
            }

            if (!$response->successful()) {
                $this->error("   ❌ Falha HTTP [{$response->status()}] para {$endpoint->url}");
                $this->markFailedOrDead($status, $endpoint, $idempotencyKey, "Falha HTTP {$response->status()}");
                return;
            }

            // D. TRATAMENTO DO RETORNO E SALVAMENTO
            $this->saveResponse($endpoint, $clientCode, $response, $format, $status);

        } catch (\Exception $e) {
            $this->error("   ❌ Erro crítico na requisição: " . $e->getMessage());
            $this->markFailedOrDead($status, $endpoint, $idempotencyKey, 'Exceção na requisição.');
        }
    }

    /**
     * Aplica a autenticação baseada no tipo configurado.
     */
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

        if (Storage::disk('local')->exists($path)) {
            try {
                $content = Crypt::decryptString(Storage::disk('local')->get($path));
            } catch (DecryptException $e) {
                $this->error("   ❌ Token inválido ou não descriptografável em storage/app/{$path}");
                return null;
            }

            return $this->extractToken($content, $endpoint->auth_token, $path);
        }

        if (Storage::disk('public')->exists($path)) {
            $this->warn(" Token legado lido de storage/app/public/{$path}; migre para storage privado.");
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

        if (in_array('xml', $this->auth_extensions, true) && str_starts_with(ltrim($content), '<')) {
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
            $this->error("   ❌ Token ausente ou não escalar em storage/app/{$path}");
            return null;
        }

        return trim((string) $token);
    }

    private function saveResponse($endpoint, $clientCode, $response, $format, ?CadInterfaceStatus $status = null)
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
        Storage::disk('local')->makeDirectory($directory);

        $path = "{$directory}/{$filename}";
        $responseBody = $response->body();
        Storage::disk('local')->put($path, Crypt::encryptString($responseBody));

        $this->info("Salvo: storage/app/{$path}");

        // Registro de Status
        $data = [
            'int_direcao'    => ($direcao === 'auth' ? 'auth' : 'entrada'),
            'int_interface'  => $endpoint->nome,
            'int_arquivo'    => $filename,
            'int_status'     => self::STATUS_SUCCESS,
            'int_data_processamento' => now(),
            'int_data_envio' => now(),
            'int_mensagem' => 'Resposta salva em storage privado.',
        ];

        if ($status) {
            $status->update($data);
            return;
        }

        $data['int_idoc'] = pathinfo($filename, PATHINFO_FILENAME);
        CadInterfaceStatus::create($data);
    }

    private function isAllowedMethod(string $method): bool
    {
        return in_array($method, ['get', 'post', 'put', 'patch'], true);
    }

    private function containsSensitivePayload($payload): bool
    {
        if (!is_array($payload)) {
            return false;
        }

        $sensitiveKeys = [
            'authorization', 'token', 'access_token', 'refresh_token', 'api_key',
            'apikey', 'password', 'passwd', 'senha', 'secret', 'client_secret',
            'cpf', 'cnpj', 'email',
        ];

        foreach ($payload as $key => $value) {
            $normalizedKey = Str::of((string) $key)->lower()->replace(['-', ' '], '_')->toString();

            if (in_array($normalizedKey, $sensitiveKeys, true)) {
                return true;
            }

            if (is_array($value) && $this->containsSensitivePayload($value)) {
                return true;
            }
        }

        return false;
    }

    private function makeIdempotencyKey(CadEndpoint $endpoint, Client $client, ?array $payload): string
    {
        $period = now()->format('YmdHi');
        $payloadHash = hash('sha256', json_encode($payload ?: []));

        return substr(hash('sha256', "{$client->id}:{$endpoint->id}:{$period}:{$payloadHash}"), 0, 30);
    }

    private function hasSuccessfulRun(string $idempotencyKey): bool
    {
        return CadInterfaceStatus::where('int_idoc', $idempotencyKey)
            ->where('int_status', self::STATUS_SUCCESS)
            ->exists();
    }

    private function recordStatus(CadEndpoint $endpoint, ?string $idempotencyKey, int $status, string $message): CadInterfaceStatus
    {
        return CadInterfaceStatus::create([
            'int_direcao' => strtolower($endpoint->direcao) === 'auth' ? 'auth' : 'entrada',
            'int_interface' => $endpoint->nome,
            'int_arquivo' => null,
            'int_idoc' => $idempotencyKey ?: "endpoint-{$endpoint->id}-" . now()->format('YmdHisv'),
            'int_status' => $status,
            'int_mensagem' => Str::limit($message, 255),
            'int_data_processamento' => now(),
            'int_data_envio' => now(),
        ]);
    }

    private function markFailedOrDead(CadInterfaceStatus $status, CadEndpoint $endpoint, string $idempotencyKey, string $message): void
    {
        $failures = CadInterfaceStatus::where('int_interface', $endpoint->nome)
            ->where('int_idoc', $idempotencyKey)
            ->whereIn('int_status', [self::STATUS_FAILED, self::STATUS_DEAD])
            ->count();

        $status->update([
            'int_status' => $failures + 1 >= (int) ($endpoint->tentativas ?? 3) ? self::STATUS_DEAD : self::STATUS_FAILED,
            'int_mensagem' => Str::limit($message, 255),
            'int_data_processamento' => now(),
        ]);
    }
}
