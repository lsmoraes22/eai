<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
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
                $incomingPath  = "polling/{$clientCode}/{$endpointExt}/incoming/{$endpointName}/raw/";
                $validatedPath = "polling/{$clientCode}/{$endpointExt}/incoming/{$endpointName}/validated/";
                $refusedPath   = "polling/{$clientCode}/{$endpointExt}/incoming/{$endpointName}/refused/";

                // Garantir que existam
                Storage::disk('public')->makeDirectory($validatedPath);
                Storage::disk('public')->makeDirectory($refusedPath);

                // Caminho do XSD
                $xsdPath = "polling/{$clientCode}/xsd/{$ep->nome}.xsd";

                if (!Storage::disk('public')->exists($xsdPath)) {
                    $this->error("❌ XSD não encontrado para endpoint {$ep->name}");
                    continue;
                }

                // Listar XMLs brutos
                $sourceDisk = Storage::disk('local')->files($incomingPath) ? 'local' : 'public';
                $xmlFiles = Storage::disk($sourceDisk)->files($incomingPath);
                foreach ($xmlFiles as $xmlFile) {

                    if (!str_ends_with($xmlFile, '.xml')) continue;

                    $filename = basename($xmlFile);
                    $xmlContent = $this->readRawFile($sourceDisk, $xmlFile);

                    if ($xmlContent === null) {
                        $this->error("❌ XML criptografado inválido: {$filename}");
                        continue;
                    }

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

	                        $this->moveRawFile($xmlFile, $validatedPath . $filename, $sourceDisk, $xmlContent);
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

	                        $this->moveRawFile($xmlFile, $refusedPath . $filename, $sourceDisk, $xmlContent);

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

    private function readRawFile(string $disk, string $file): ?string
    {
        $content = Storage::disk($disk)->get($file);

        if ($disk !== 'local') {
            return $content;
        }

        try {
            return Crypt::decryptString($content);
        } catch (DecryptException $e) {
            return null;
        }
    }

    private function moveRawFile(string $from, string $to, string $sourceDisk, string $content): void
    {
        if ($sourceDisk === 'public') {
            Storage::disk('public')->move($from, $to);
            return;
        }

        Storage::disk('public')->put($to, $content);
        Storage::disk('local')->delete($from);
	}
}
