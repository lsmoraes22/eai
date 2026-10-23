<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use App\Models\Client;
use App\Models\CadProcesso;
use App\Models\CadProcessosDepara;

class XmlToXml extends Command
{
    protected $signature = 'app:convert-xml-xml';

    public function handle()
    {
        // ... Loop de Clientes e Origens (igual aos outros) ...
        $processos = CadProcesso::where('initial_format', 'XML')->where('final_format', 'XML')->get();

        foreach ($processos as $p) {
            $validatedPath = "{$origem}/{$clientCode}/xml/incoming/{$p->name}/validated/";
            $files = Storage::disk('integrations')->files($validatedPath);

            foreach ($files as $filePath) {
                $xmlContent = Storage::disk('integrations')->get($filePath);

                // 1. Converte XML de entrada para Array para poder usar data_get
                $xmlObject = simplexml_load_string($xmlContent);
                $inputArray = json_decode(json_encode($xmlObject), true);

                // 2. Aplica DePara
                $deparas = CadProcessosDepara::where('processo_id', $p->id)->get();
                $outputArray = $this->applyDepara($inputArray, $deparas);

                // 3. Converte de volta para XML usando as novas propriedades do banco
                $xmlFinal = $this->arrayToXml($outputArray, $p->root_element, $p->item_element);

                // 4. Salva no outgoing
                $filename = basename($filePath);
                Storage::disk('integrations')->put("path/to/outgoing/{$filename}", $xmlFinal);
            }
        }
    }

    private function arrayToXml(array $data, $rootElement, $itemElement, $xml = null)
    {
        if ($xml === null) {
            $xml = new \SimpleXMLElement("<?xml version=\"1.0\" encoding=\"UTF-8\"?><$rootElement/>");
        }

        foreach ($data as $key => $value) {
            $tagName = is_numeric($key) ? $itemElement : $key;

            if (is_array($value)) {
                $this->arrayToXml($value, $tagName, $itemElement, $xml->addChild($tagName));
            } else {
                $xml->addChild($tagName, htmlspecialchars((string)$value));
            }
        }
        return $xml->asXML();
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

}
