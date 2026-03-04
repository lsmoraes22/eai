<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use App\Models\Client;
use App\Models\CadEndpoint;
use App\Models\CadInterfaceStatus;
use JsonSchema\Validator;

class ValidateJsonCommand extends Command
{
    protected $signature = 'app:validate-json';
    protected $description = 'Valida os JSONs usando JSON Schema';

    public function handle()
    {
        $clients = Client::all();

        foreach ($clients as $client) {
            $clientCode = Str::slug($client->code ?: $client->name);
            $endpoints = CadEndpoint::where('client_id', $client->id)
                ->where('extensao', 'json') // Garante que só olha JSON
                ->get();

            foreach ($endpoints as $ep) {
                // Diretórios (Seguindo seu padrão de XML)
                $incomingPath  = "polling/{$clientCode}/json/incoming/{$ep->nome}/raw/";
                $validatedPath = "polling/{$clientCode}/json/incoming/{$ep->nome}/validated/";
                $refusedPath   = "polling/{$clientCode}/json/incoming/{$ep->nome}/refused/";
                $schemaPath    = "polling/{$clientCode}/schema/{$ep->nome}.json";

                Storage::disk('public')->makeDirectory($validatedPath);
                Storage::disk('public')->makeDirectory($refusedPath);

                $files = Storage::disk('public')->files($incomingPath);
                foreach ($files as $file) {
                    $jsonContent = json_decode(Storage::disk('public')->get($file));

                    // Se o arquivo estiver mal formatado (Sintaxe)
                    if (json_last_error() !== JSON_ERROR_NONE) {
                        $this->handleRefusal($file, $refusedPath, "Erro de sintaxe JSON");
                        continue;
                    }

                    // Validação Estrutural (Schema)
                    $validator = new Validator();
                    if (Storage::disk('public')->exists($schemaPath)) {
                        $schema = json_decode(Storage::disk('public')->get($schemaPath));
                        $validator->validate($jsonContent, $schema);
                    }

                    if ($validator->isValid()) {
                        $this->handleSuccess($file, $validatedPath);
                    } else {
                        $errors = "";
                        foreach ($validator->getErrors() as $error) {
                            $errors .= sprintf("[%s] %s. ", $error['property'], $error['message']);
                        }
                        $this->handleRefusal($file, $refusedPath, $errors);
                    }
                }
            }
        }
    }

    private function handleSuccess($file, $path) {
        $filename = basename($file);
        Storage::disk('public')->move($file, $path . $filename);
        CadInterfaceStatus::where('int_arquivo', $filename)->update(['int_status' => 1, 'int_mensagem' => 'OK']);
        $this->info("✔ JSON VALIDADO: {$filename}");
    }

    private function handleRefusal($file, $path, $msg) {
        $filename = basename($file);
        Storage::disk('public')->move($file, $path . $filename);
        CadInterfaceStatus::where('int_arquivo', $filename)->update(['int_status' => 2, 'int_mensagem' => $msg]);
        $this->error("❌ JSON RECUSADO: {$filename} - {$msg}");
    }
}
