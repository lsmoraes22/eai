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
                $this->error("   ❌ Erro inesperado no processamento: " . $e::class
                . ($e instanceof \Illuminate\Http\Client\RequestException ? ' HTTP ' . $e->response->status() : ''));
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
            $paginationType = strtolower($endpoint->pagination['type'] ?? 'none');

            if ($paginationType === 'page') {
                if (strtolower($endpoint->direcao) === 'auth') {
                    $this->error('   ❌ Endpoints de autenticação não suportam paginação.');
                    return false;
                }

                return $this->fetchPaginatedPages(
                    $endpoint,
                    $clientCode,
                    $request,
                    $method,
                    $payload ?: [],
                    $format
                );
            }

            if (!in_array($paginationType, ['', 'none'], true)) {
                $this->error("   ❌ Tipo de paginação não suportado: {$paginationType}.");
                return false;
            }

            // Determina se envia Body (Payload) ou Query Params
            if (in_array($method, ['post', 'put', 'patch'])) {
                $response = $request->{$method}($endpoint->url, $payload ?: []);
            } else {
		// No GET, o Laravel anexa o array do segundo parâmetro como ?chave=valor automaticamente
                $response = $request->{$method}($endpoint->url, $payload ?: []);
            }

            if (!$response->successful()) {
                $this->error("   ❌ Falha HTTP [{$response->status()}] para endpoint ID {$endpoint->id}");
                return false;
            }

            // D. TRATAMENTO DO RETORNO E SALVAMENTO
            return $this->saveResponse($endpoint, $clientCode, $response, $format);

        } catch (\Exception $e) {
            $this->error("   ❌ Erro crítico na requisição: " . $e::class
                . ($e instanceof \Illuminate\Http\Client\RequestException ? ' HTTP ' . $e->response->status() : ''));
            return false;
        }
    }

    private function fetchPaginatedPages(
        CadEndpoint $endpoint,
        string $clientCode,
        $request,
        string $method,
        array $payload,
        string $format
    ): bool {
        $pagination = $endpoint->pagination;
        $location = $pagination['location'] ?? 'query';
        if ($location === null || (is_string($location) && trim($location) === '')) {
            $location = 'query';
        } elseif (is_string($location)) {
            $location = strtolower(trim($location));
        }
        $pageParam = $pagination['page_param'] ?? 'page';
        $pageStart = $this->paginationInteger($pagination['page_start'] ?? 1);
        $pageSizeParam = $pagination['page_size_param'] ?? 'size';
        $pageSize = $this->paginationInteger($pagination['page_size'] ?? 100);
        $currentPagePath = $pagination['current_page_path'] ?? null;
        $totalPagesPath = $pagination['total_pages_path'] ?? null;
        $maxPages = $this->paginationInteger($pagination['max_pages'] ?? 100);

        if (
            !is_string($pageParam) || $pageParam === ''
            || !in_array($location, ['query', 'body'], true)
            || !is_string($pageSizeParam) || $pageSizeParam === ''
            || $pageStart === null || $pageStart < 0
            || $pageSize === null || $pageSize < 1
            || $maxPages === null || $maxPages < 1
            || !is_string($currentPagePath) || $currentPagePath === ''
            || !is_string($totalPagesPath) || $totalPagesPath === ''
        ) {
            $this->error('   ❌ Configuração de paginação inválida ou incompleta.');
            return false;
        }

        $requestedPage = $pageStart;
        $pagesFetched = 0;
        $executionBase = now()->format('YmdHisv');

        while (true) {
            if ($pagesFetched >= $maxPages) {
                $this->error("   ❌ Limite de paginação atingido ({$maxPages} páginas).");
                return false;
            }

            try {
                if ($location === 'body') {
                    $params = $payload;
                    data_set($params, $pageParam, $requestedPage);
                    data_set($params, $pageSizeParam, $pageSize);

                    $pageRequest = clone $request;
                    $pageRequest->withBody(
                        json_encode($params, JSON_THROW_ON_ERROR),
                        'application/json'
                    );
                    $response = $pageRequest->{$method}($endpoint->url);
                } else {
                    $params = $payload;
                    $params[$pageParam] = $requestedPage;
                    $params[$pageSizeParam] = $pageSize;

                    if (in_array($method, ['post', 'put', 'patch'], true)) {
                        $pageRequest = clone $request;
                        $pageRequest->withQueryParameters([
                            $pageParam => $requestedPage,
                            $pageSizeParam => $pageSize,
                        ]);
                        $response = $pageRequest->{$method}($endpoint->url, $payload);
                    } else {
                        $response = $request->{$method}($endpoint->url, $params);
                    }
                }
            } catch (\Exception $e) {
                $this->error("   ❌ Erro crítico na página {$requestedPage}: " . $e::class
                . ($e instanceof \Illuminate\Http\Client\RequestException ? ' HTTP ' . $e->response->status() : ''));
                return false;
            }

            if (!$response->successful()) {
                $this->error("   ❌ Falha HTTP [{$response->status()}] na página {$requestedPage} para endpoint ID {$endpoint->id}");
                return false;
            }

            $filename = sprintf('%s-page-%06d.%s', $executionBase, $requestedPage, $format);
            if (!$this->saveResponse($endpoint, $clientCode, $response, $format, $filename)) {
                return false;
            }

            $decoded = json_decode($response->body(), true);
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
                $this->error("   ❌ Resposta da página {$requestedPage} não contém JSON válido para paginação.");
                return false;
            }

            $current = $this->paginationInteger(data_get($decoded, $currentPagePath));
            $total = $this->paginationInteger(data_get($decoded, $totalPagesPath));

            if ($current === null || $total === null) {
                $this->error("   ❌ Metadata de paginação ausente ou não numérica na página {$requestedPage}.");
                return false;
            }

            if ($current < $pageStart || $total < $current || $current !== $requestedPage) {
                $this->error("   ❌ Metadata de paginação incoerente na página {$requestedPage}.");
                return false;
            }

            $pagesFetched++;

            if ($current >= $total) {
                return true;
            }

            if ($pagesFetched >= $maxPages) {
                $this->error("   ❌ Limite de paginação atingido ({$maxPages} páginas).");
                return false;
            }

            $requestedPage++;
        }
    }

    private function paginationInteger($value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?\d+$/', $value)) {
            return (int) $value;
        }

        return null;
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
            $this->error("   ❌ Erro ao agendar: " . $e::class
                . ($e instanceof \Illuminate\Http\Client\RequestException ? ' HTTP ' . $e->response->status() : ''));
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

        if (Storage::disk('integrations')->exists($path)) {
            return $this->extractToken(Storage::disk('integrations')->get($path), $endpoint->auth_token, $path);
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
            $this->error("   ❌ Token ausente ou não escalar em storage/app/private/integrations/{$path}");
            return null;
        }

        return trim((string) $token);
    }

    private function saveResponse($endpoint, $clientCode, $response, $format, ?string $filename = null): bool
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
            $filename ??= now()->format('YmdHisv') . '.' .  $format;
            $directory = "polling/{$clientCode}/{$endpointExt}/{$direcao}/{$endpointSlug}/raw";
	}
        Storage::disk('integrations')->makeDirectory($directory);

        $path = "{$directory}/{$filename}";
        $response_body = $response->body();
        $saved = Storage::disk('integrations')->put($path, $response_body);

        if (!$saved) {
            $this->error("   ❌ Falha ao salvar: storage/app/private/integrations/{$path}");
            return false;
        }

        $this->info("Salvo: storage/app/private/integrations/{$path}");

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
