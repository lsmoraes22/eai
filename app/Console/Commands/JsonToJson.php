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
                ->get();

            foreach ($processos as $p) {
                foreach ($origens as $origem) {
                    $validatedPath = "{$origem}/{$clientCode}/json/incoming/{$p->name}/validated/";
                    $outgoingPath  = "{$origem}/{$clientCode}/json/outgoing/{$p->name}/raw/";

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
                        Storage::disk('public')->put("{$outgoingPath}/{$filename}", $jsonFinal);

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

    private function finalizarProcesso($oldPath, $newFilename, $interface) {
        CadInterfaceStatus::where('int_arquivo', basename($oldPath))->update(['int_status' => 3]);
        // ... criar novo status para o arquivo de saída ...
        $targetPath = str_replace('/validated/', '/processed/', $oldPath);
        Storage::disk('public')->move($oldPath, $targetPath);
    }
}
