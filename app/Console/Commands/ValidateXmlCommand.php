<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use App\Models\Client;
use App\Models\CadEndpoint;
use App\Models\CadInterfaceStatus;
use App\Services\XmlService;

class ValidateXmlCommand extends Command
{
    protected $signature = 'app:validate-xml';
    protected $description = 'Valida os XMLs recém baixados usando seus XSDs correspondentes';

    public function handle()
    {
        $clients = Client::all();

        foreach ($clients as $client) {

            $clientCode = Str::slug($client->code ?: $client->name);

            $endpoints = CadEndpoint::where('client_id', $client->id)->get();

            foreach ($endpoints as $ep) {

                $endpointName = $ep->nome;
		$endpointExt = Str::slug($ep->extensao);
		$removeNamespace = !(bool) $ep->namespace;

                // Diretórios do pipeline
                $incomingPath  = "clients/{$clientCode}/{$endpointExt}/incoming/{$endpointName}/raw/";
                $validatedPath = "clients/{$clientCode}/{$endpointExt}/incoming/{$endpointName}/validated/";
                $refusedPath   = "clients/{$clientCode}/{$endpointExt}/incoming/{$endpointName}/refused/";

                // Garantir que existam
                Storage::disk('public')->makeDirectory($validatedPath);
                Storage::disk('public')->makeDirectory($refusedPath);

                // Caminho do XSD
                $xsdPath = "clients/{$clientCode}/xsd/{$ep->nome}.xsd";

                if (!Storage::disk('public')->exists($xsdPath)) {
                    $this->error("❌ XSD não encontrado para endpoint {$ep->name}");
                    continue;
                }

                // Listar XMLs brutos
                $xmlFiles = Storage::disk('public')->files($incomingPath);
                foreach ($xmlFiles as $xmlFile) {

                    if (!str_ends_with($xmlFile, '.xml')) continue;

                    $filename = basename($xmlFile);
                    $xmlContent = Storage::disk('public')->get($xmlFile);

		    // Extrair conteúdo interno
		    $xmlService = new \App\Services\XmlService();
		    $payload = $xmlService->extractXmlFromSoap($xmlContent, $removeNamespace);
		    $payload = $xmlService->sanitize($payload);

                    // Carregar DOM
                    $dom = new \DOMDocument();
                    $dom->preserveWhiteSpace = false;
                    $dom->formatOutput = false;
                    $dom->loadXML($payload);

                    libxml_use_internal_errors(true);
                    $isValid = $dom->schemaValidate(Storage::disk('public')->path($xsdPath));
                    $errors = libxml_get_errors();
                    libxml_clear_errors();

                    // Buscar na tabela
                    $cad = CadInterfaceStatus::where('int_arquivo', $filename)->first();

                    if (!$cad) {
                        $this->warn("⚠ Registro de status não encontrado para {$filename}");
                        continue;
                    }

                    // VALIDADO
                    if ($isValid) {

                        Storage::disk('public')->move($xmlFile, $validatedPath . $filename);
                        $cad->int_mensagem = "OK";
		    	if ($xmlService->message) {
    			    // Você pode guardar isso no cad_interface_status
			    $cad->int_mensagem = $xmlService->message;
			}

                        $cad->int_status = 1; // VALIDADO
                        $cad->save();

                        $this->info("✔ VALIDADO: {$filename}");
                    }

                    // RECUSADO
                    else {

                        Storage::disk('public')->move($xmlFile, $refusedPath . $filename);

                        $msg = '';
                        foreach ($errors as $err) {
                            $msg .= " - " . trim($err->message);
                        }

                        $cad->int_status = 2; // RECUSADO
                        $cad->int_mensagem = $msg;
                        $cad->save();

                        $this->error("❌ RECUSADO: {$filename}");
                        $this->line($msg);
                    }
                }
            }
        }
    }
}
