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

            $endpoints = CadEndpoint::where('client_id', $client->id)->get();

            foreach ($endpoints as $endpoint) {

                if (!$endpoint->url) {
                    $this->warn("Cliente {$client->name} - endpoint {$endpoint->name} sem URL.");
                    continue;
                }

                $url = $endpoint->url;

                try {
                    /**
                     * 1. Baixar arquivo e seguir redirecionamentos automaticamente
                     */
                    $response = Http::timeout(15)->withOptions([
                        'allow_redirects' => true,
                    ])->get($url);

                    if (!$response->successful()) {
                        $this->error("Erro ao acessar {$url}: HTTP " . $response->status());
                        continue;
                    }

                    $xml = $response->body();

                    /**
                     * 2. Capturar URL final após redirecionamento
                     */
                    $finalUrl = $response->effectiveUri(); // Laravel 10+ suporta isso

                    $filename = basename(parse_url($finalUrl, PHP_URL_PATH));

                    // Caso não tenha nome de arquivo na URL final
                    if (!$filename || $filename === 'xml' || !str_contains($filename, '.')) {
                        $filename = now()->format('YmdHisv') . '.xml';
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
