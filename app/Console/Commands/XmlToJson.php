<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use App\Models\Client;
use App\Models\CadEndpoint;
use App\Models\CadProcesso;
use App\Models\CadProcessosDepara;
use App\Models\CadInterfaceStatus;
use App\Services\XmlService;

class XmlToJson extends Command
{
    protected $signature = 'app:convert-xml-json';
    protected $description = 'Pega os XMLs em incoming e convert para json em outgoing';

    public function handle()
    {
        $clients = Client::all();
	$xmlService = new XmlService();

        foreach ($clients as $client) {

            $clientCode = Str::slug($client->code ?: $client->name);
	    $processos = CadProcesso::where('initial_format', 'XML' )
		->where('final_format','JSON')
		->get();

	    // Lista de origens para processar
	    $origens = ['polling', 'webhooks'];

            foreach ($processos as $p) {
		foreach ($origens as $origem) {
	                $processName = $p->name;

	                // Diretórios do pipeline
	                $validatedPath = "{$origem}/{$clientCode}/xml/incoming/{$processName}/validated/";
	                $outgoingPath  = "{$origem}/{$clientCode}/json/outgoing/{$processName}/raw/";
		   	$deparas = CadProcessosDepara::where('processo_id', $p->id)
				->where('active', 1)
				->orderBy('order')
				->get();

	                // Garantir que existam
	                Storage::disk('public')->makeDirectory($validatedPath);
	                Storage::disk('public')->makeDirectory($outgoingPath);

			// Arquivos XML validados
	                $files = Storage::disk('public')->files($validatedPath);
	                foreach ($files as $filePath) {
			    $xmlContent = Storage::disk('public')->get($filePath);
	                    $xmlContent = $xmlService->sanitize($xmlContent);
			    $xmlContent = $xmlService->extractXmlFromSoap($xmlContent);
			    $xmlArray = $this->xmlToArray($xmlContent);

	                    // Aplicar DE/PARA (placeholder)
	                    $jsonArray = $this->applyDepara($xmlArray, $deparas);
			    $cadInt = CadInterfaceStatus::where('int_arquivo',basename($filePath))
				->update(['int_status' => 3]);
	                    // Para JSON final
	                    $json = json_encode($jsonArray, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

	                    // Nome de saída
	                    $filename = pathinfo($filePath, PATHINFO_FILENAME) . '.json';

			    CadInterfaceStatus::create([
	                        'int_direcao'            => 'saida',
	                        'int_interface'          => $processName,
	                        'int_arquivo'            => $filename,
	                        'int_idoc'               => str_replace('.json','',$filename),
	                        'int_status'             => 0,
	                        'int_data_envio'         => now(),
	                    ]);

	                    // Gravar no outgoing/raw
	                    Storage::disk('public')->put("{$outgoingPath}/{$filename}", $json);
			    $processedPath = str_replace('/validated/', '/processed/', $filePath);
	                    // Mover o XML original para processed/success
	                    Storage::disk('public')->move($filePath, $processedPath);

	                    $this->info("Convertido: {$filePath} → {$outgoingPath}/{$filename}");
	                }
		}
            }
        }

        $this->info("Conversão concluída!");
    }

    /**
     * Converte XML string em Array com simplexml + json encode.
     */
    private function xmlToArray(string $xml)
    {
        $obj = simplexml_load_string($xml, "SimpleXMLElement", LIBXML_NOCDATA);
        return json_decode(json_encode($obj), true);
    }

    /**
     * Placeholder: Aplica DE/PARA definidos no banco.
     */
    private function applyDepara(array $xml, $deparas): array
    {
        $output = [];

        foreach ($deparas as $rule) {

            $value = data_get($xml, str_replace(' ','',$rule->input_path));

            if ($value === null && $rule->default_value !== null) {
                $value = $rule->default_value;
            }

            // Cria o caminho de saída no array JSON
            data_set($output, $rule->output_path, $value);
        }

        return $output;
    }

}
