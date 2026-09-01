<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use App\Models\Client;
use App\Models\CadProcesso;
use App\Models\CadProcessosDepara;
use App\Models\CadInterfaceStatus;

class JsonToJson extends Command
{
    protected $signature = 'app:convert-json-json';
    protected $description = 'Mapeia um JSON de entrada para outro formato JSON de saída';

    public function handle()
    {
        $clients = Client::all();
        $origens = ['polling', 'webhooks'];

        foreach ($clients as $client) {
            $clientCode = Str::slug($client->code ?: $client->name);
            $processos = CadProcesso::where('initial_format', 'JSON')
                ->where('final_format', 'JSON')
                ->where('active', true)
                ->with(['inputEndpoint', 'outputEndpoint'])
                ->get();

            foreach ($processos as $p) {
                $inputEndpoint = $p->inputEndpoint;
                $outputEndpoint = $p->outputEndpoint;

                if ($inputEndpoint && $inputEndpoint->client_id !== $client->id) {
                    continue;
                }

                if (!$inputEndpoint && $outputEndpoint && $outputEndpoint->client_id !== $client->id) {
                    continue;
                }

                if ($inputEndpoint && $outputEndpoint && $inputEndpoint->client_id !== $outputEndpoint->client_id) {
                    $this->error("Endpoints de entrada e saída do processo {$p->name} pertencem a clientes diferentes.");
                    continue;
                }

                $inputName = $inputEndpoint ? Str::slug($inputEndpoint->nome) : $p->name;
                $outputName = $outputEndpoint ? Str::slug($outputEndpoint->nome) : $p->name;
                $processOrigins = ($inputEndpoint || $outputEndpoint) ? ['polling'] : $origens;

                foreach ($processOrigins as $origem) {
                    $validatedPath = "{$origem}/{$clientCode}/json/incoming/{$inputName}/validated/";
                    $outgoingPath  = "{$origem}/{$clientCode}/json/outgoing/{$outputName}/raw/";

                    Storage::disk('public')->makeDirectory($outgoingPath);
                    $files = Storage::disk('public')->files($validatedPath);

                    foreach ($files as $filePath) {
                        $jsonContent = Storage::disk('public')->get($filePath);
                        $inputArray = json_decode($jsonContent, true);

                        $deparas = CadProcessosDepara::where('processo_id', $p->id)->where('active', 1)->orderBy('order')->get();

                        // O segredo está aqui: applyDepara gera o novo array
                        $outputArray = $this->applyDepara($inputArray, $deparas);
                        $jsonFinal = json_encode($outputArray, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

                        $filename = basename($filePath);
                        $saved = Storage::disk('public')->put("{$outgoingPath}/{$filename}", $jsonFinal);

                        if (!$saved) {
                            $this->error("Falha ao salvar payload transformado: {$outgoingPath}/{$filename}");
                            continue;
                        }

                        // Atualiza Status e Move Arquivo (mesma lógica do seu JsonToXml)
                        $this->finalizarProcesso($filePath, $filename, $p->name);
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

    private function finalizarProcesso($oldPath, $newFilename, $interface): bool {
        $targetPath = str_replace('/validated/', '/processed/', $oldPath);
        Storage::disk('public')->makeDirectory(dirname($targetPath));

        if (!Storage::disk('public')->move($oldPath, $targetPath)) {
            $this->error("Falha ao mover arquivo processado: {$oldPath}");
            return false;
        }

        CadInterfaceStatus::where('int_arquivo', basename($oldPath))->update(['int_status' => 3]);
        // ... criar novo status para o arquivo de saída ...

        return true;
    }
}
