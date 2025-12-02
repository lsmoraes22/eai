<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use App\Models\Client;
use App\Models\CadEndpoint;
use App\Models\CadInterfaceStatus;
use Illuminate\Support\Str;

class FetchXmlFromEndpoints extends Command
{
    protected $signature = 'app:fetch-endpoints';
    protected $description = 'Baixa arquivos XML dos endpoints cadastrados e salva em storage/app/public/clients/...';

    public function handle()
    {
        $clients = Client::all();

        foreach ($clients as $client) {

            $endpoints = CadEndpoint::where('client_id', $client->id)
		->where('ativo',true)
		->get();

            foreach ($endpoints as $endpoint) {

                if (!$endpoint->url) {
                    $this->warn("Cliente {$client->name} - endpoint {$endpoint->name} sem URL.");
                    continue;
                }

		// Normaliza headers (suporta JSON ou array armazenado no DB)
		$headers = [];
		if (!empty($endpoint->headers)) {
		    if (is_string($endpoint->headers)) {
		        $decoded = json_decode($endpoint->headers, true);
		        $headers = is_array($decoded) ? $decoded : [];
		    } elseif (is_array($endpoint->headers)) {
		        $headers = $endpoint->headers;
		    }
		}

		// Se houver token, adiciona Authorization Bearer
		if (!empty($endpoint->auth_token)) {
		    $headers['Authorization'] = 'Bearer ' . $endpoint->auth_token;
		}

		// Se quiser forçar Accept/Content-Type JSON quando extensão for XML/JSON
		if (!isset($headers['Accept'])) {
		    $headers['Accept'] = $endpoint->extensao && strtolower($endpoint->extensao) === 'json' ? 'application/json' : 'application/xml';
		}
		if (!isset($headers['Content-Type'])) {
		    // só coloca Content-Type quando for enviar body (POST/PUT/PATCH)
		    // deixei sem setar para GET/DELETE
		}

		// Define método e body (body só para métodos que enviam payload)
		$method = strtolower($endpoint->metodo ?? 'get');
		$hasBody = in_array($method, ['post', 'put', 'patch']);

		$body = null; // você pode preencher com o payload necessário (array ou string)
		$queryParams = []; // use para GET/DELETE se precisar query string

		// Configuração comum do cliente HTTP
		$times = (int) ($endpoint->tentativas ?? 3);   // tentativas
		$sleep = 100; // tempo entre tentativas em ms (ajuste se quiser)
		$timeout = (int) ($endpoint->timeout ?? 30); // segundos
		$format = strtolower($endpoint->extensao);

                $url = $endpoint->url;
                try {
                    $request = Http::withHeaders($headers)
	                ->timeout($timeout)
	                ->withOptions(['allow_redirects' => true]);

		    // se houver basic auth
		    if (!empty($endpoint->auth_user) && !empty($endpoint->auth_pass)) {
		        $request = $request->withBasicAuth($endpoint->auth_user, $endpoint->auth_pass);
		    }

		    // adiciona retry
		    $request = $request->retry($times, $sleep, function ($exception, $request) {
		        // opcional: retornar true para tentar novamente apenas em certos erros
		        return true; // tentar para qualquer erro (à princípio)
		    });

		    // executa a requisição de forma dinâmica
		    if ($hasBody) {
		        // Ex.: post/put/patch -> enviar $body (array será JSON-encoded automaticamente)
			if ($hasBody && !empty($endpoint->payload)) {
			    if (is_string($endpoint->payload)) {
			        $decoded = json_decode($endpoint->payload, true);
			        $body = $decoded ?: $endpoint->payload; // se não for JSON, envia como string
			    } elseif (is_array($endpoint->payload)) {
			        $body = $endpoint->payload;
			    }
			}
		        $response = $request->{$method}($url, $body);
		    } else {
		        // get/delete -> parâmetros de query (se houver)
		        $response = $request->{$method}($url, $queryParams);
		    }

		    if (!$response->successful()) {
		        $this->error("Erro ao acessar {$url}: HTTP " . $response->status());
		        continue;
		    }

		    $xml = $response->body();

		    // pega URL final após redirecionamento
		    $finalUrl = method_exists($response, 'effectiveUri') ? $response->effectiveUri() : $url;

                    $filename = basename(parse_url($finalUrl, PHP_URL_PATH));

                    // Caso não tenha nome de arquivo na URL final
                    if (!$filename || $filename === 'xml' || !str_contains($filename, '.')) {
                        $filename = now()->format('YmdHisv') . '.' . $format;
                    }

                    /**
                     * 3. Construção do diretório
                     * storage/app/public/clients/{client}/xml/incoming/raw/{endpoint}/
                     */
                    $clientCode = $client->code ?: $client->name;
                    $endpointSlug = $endpoint->nome;
		    $endpointExt = Str::slug($endpoint->extensao);

                    $directory = "clients/{$clientCode}/{$endpointExt}/incoming/{$endpointSlug}/raw";

                    Storage::disk('public')->makeDirectory($directory);

                    $path = "{$directory}/{$filename}";

                    /**
                     * 4. Salvar arquivo
                     */
                    Storage::disk('public')->put($path, $xml);

                    $this->info("✔ XML salvo em: storage/app/public/{$path}");

                    /**
                     * 5. Registrar em CadInterfaceStatus
                     */
                    CadInterfaceStatus::create([
                        'int_direcao'            => 'entrada',
                        'int_interface'          => $endpoint->nome,
                        'int_arquivo'            => $filename,
                        'int_idoc'               => pathinfo($filename, PATHINFO_FILENAME),
                        'int_status'             => 0,
                        'int_data_envio' 	 => now(),
                    ]);

                } catch (\Exception $e) {
                    $this->error("Erro ao baixar {$url}: " . $e->getMessage());
                }
            }
        }
    }
}
