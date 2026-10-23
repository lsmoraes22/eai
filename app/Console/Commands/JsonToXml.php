<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use App\Models\Client;
use App\Models\CadProcesso;
use App\Models\CadProcessosDepara;
use App\Models\CadInterfaceStatus;
use Spatie\ArrayToXml\ArrayToXml; // Dica: use essa lib ou a lógica manual abaixo

class JsonToXml extends Command
{
    protected $signature = 'app:convert-json-xml';
    protected $description = 'Converte JSONs em incoming para XML em outgoing seguindo o pipeline';

    public function handle()
    {
        $clients = Client::all();
        $origens = ['polling', 'webhooks'];

        foreach ($clients as $client) {
            $clientCode = Str::slug($client->code ?: $client->name);
            
            // Busca processos inversos: JSON -> XML
            $processos = CadProcesso::where('initial_format', 'JSON')
                ->where('final_format', 'XML')
                ->get();

            foreach ($processos as $p) {
                foreach ($origens as $origem) {
                    $processName = $p->name;

                    // Diretórios do pipeline inverso
                    $validatedPath = "{$origem}/{$clientCode}/json/incoming/{$processName}/validated/";
                    $outgoingPath  = "{$origem}/{$clientCode}/xml/outgoing/{$processName}/raw/";

                    Storage::disk('integrations')->makeDirectory($validatedPath);
                    Storage::disk('integrations')->makeDirectory($outgoingPath);

                    $files = Storage::disk('integrations')->files($validatedPath);

                    foreach ($files as $filePath) {
                        $jsonContent = Storage::disk('integrations')->get($filePath);
                        $jsonArray = json_decode($jsonContent, true);

                        if (json_last_error() !== JSON_ERROR_NONE) {
                            $this->error("Erro ao decodificar JSON: {$filePath}");
                            continue;
                        }

                        // Busca Regras DePara
                        $deparas = CadProcessosDepara::where('processo_id', $p->id)
                            ->where('active', 1)
                            ->orderBy('order')
                            ->get();

                        // Aplica DePara
                        $xmlArray = $this->applyDepara($jsonArray, $deparas);

                        // Converte Array para XML string
                        // Se não quiser usar lib externa, use o método manual abaixo
                        $xml = $this->arrayToXml($xmlArray, $p->root_element ?: 'root');

                        $filename = pathinfo($filePath, PATHINFO_FILENAME) . '.xml';

                        // Atualiza status do original
                        CadInterfaceStatus::where('int_arquivo', basename($filePath))
                            ->update(['int_status' => 3]);

                        // Cria status do novo XML
                        CadInterfaceStatus::create([
                            'int_direcao'    => 'saida',
                            'int_interface'  => $processName,
                            'int_arquivo'    => $filename,
                            'int_idoc'       => pathinfo($filename, PATHINFO_FILENAME),
                            'int_status'     => 0,
                            'int_data_envio' => now(),
                        ]);

                        // Grava XML final
                        Storage::disk('integrations')->put("{$outgoingPath}/{$filename}", $xml);

                        // Move JSON original para processed
                        $targetPath = str_replace('/validated/', '/processed/', $filePath);
                        Storage::disk('integrations')->makeDirectory(dirname($targetPath));
                        Storage::disk('integrations')->move($filePath, $targetPath);

                        $this->info("Convertido [{$origem}]: {$filePath} → {$filename}");
                    }
                }
            }
        }
    }

    private function applyDepara(array $input, $deparas): array
    {
        $output = [];
        foreach ($deparas as $rule) {
            $value = data_get($input, str_replace(' ', '', $rule->input_path));
            if ($value === null && $rule->default_value !== null) {
                $value = $rule->default_value;
            }
            data_set($output, $rule->output_path, $value);
        }
        return $output;
    }

    /**
     * Converte Array para XML de forma simples e recursiva
     */
    private function arrayToXml(array $data, $rootElement = 'root', $xml = null)
    {
        if ($xml === null) {
            $xml = new \SimpleXMLElement("<?xml version=\"1.0\" encoding=\"UTF-8\"?><$rootElement/>");
        }

        foreach ($data as $key => $value) {
            if (is_numeric($key)) {
                $key = 'item' . $key; // XML não aceita tags puramente numéricas
            }

            if (is_array($value)) {
                $this->arrayToXml($value, $key, $xml->addChild($key));
            } else {
                $xml->addChild($key, htmlspecialchars((string)$value));
            }
        }

        return $xml->asXML();
    }
}
