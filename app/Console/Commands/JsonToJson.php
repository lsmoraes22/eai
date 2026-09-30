<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use App\Models\Client;
use App\Models\CadProcesso;
use App\Models\CadProcessosDepara;
use App\Models\CadInterfaceStatus;

class JsonToJson extends Command
{
    // A transformação pode criar vários arquivos; 10 minutos acompanha o lock
    // de envio e dá margem ao lote atual sem introduzir renovação nesta etapa.
    private const INPUT_LOCK_TTL_SECONDS = 600;

    // A região crítica cobre apenas exists/get/move de um único target.
    private const TARGET_LOCK_TTL_SECONDS = 60;

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

                    Storage::disk('integrations')->makeDirectory($outgoingPath);
                    $files = Storage::disk('integrations')->files($validatedPath);

                    foreach ($files as $filePath) {
                        $lockKey = 'eai:transform-item:' . hash('sha256', "{$p->id}|{$filePath}");
                        $lock = Cache::lock($lockKey, self::INPUT_LOCK_TTL_SECONDS);

                        if (!$lock->get()) {
                            $this->comment('Arquivo ' . basename($filePath) . ' já está em transformação. Pulando.');
                            continue;
                        }

                        try {
                            $this->processFile($filePath, $outgoingPath, $p);
                        } catch (\Throwable $e) {
                            $this->error('Falha ao transformar ' . basename($filePath) . ': ' . $e->getMessage());
                        } finally {
                            $lock->release();
                        }
                    }
                }
            }
        }
    }

    private function processFile(string $filePath, string $outgoingPath, CadProcesso $process): bool
    {
        $jsonContent = Storage::disk('integrations')->get($filePath);

        try {
            $inputArray = json_decode($jsonContent, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $this->error('JSON inválido em ' . basename($filePath) . ': ' . $e->getMessage());
            return false;
        }

        if (!is_array($inputArray)) {
            $this->error('O documento JSON de entrada deve ser um objeto ou lista.');
            return false;
        }

        $outputMode = $process->output_mode;

        if ($outputMode !== null && !in_array($outputMode, ['single', 'per_item'], true)) {
            $this->error("Modo de saída inválido no processo {$process->name}: {$outputMode}");
            return false;
        }

        $deparas = CadProcessosDepara::where('processo_id', $process->id)
            ->where('active', 1)
            ->orderBy('order')
            ->get();

        if (($outputMode ?? 'single') === 'single') {
            $outputArray = $this->applyDepara($inputArray, $deparas);
            $jsonFinal = json_encode($outputArray, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $filename = basename($filePath);

            if (!Storage::disk('integrations')->put("{$outgoingPath}/{$filename}", $jsonFinal)) {
                $this->error("Falha ao salvar payload transformado: {$outgoingPath}/{$filename}");
                return false;
            }

            return $this->finalizarProcesso($filePath, $filename, $process->name);
        }

        return $this->processCollection($filePath, $outgoingPath, $process, $inputArray, $deparas);
    }

    private function processCollection(string $filePath, string $outgoingPath, CadProcesso $process, array $input, $deparas): bool
    {
        $collectionPath = trim((string) $process->input_collection_path);

        if ($collectionPath === '') {
            $this->error("Caminho da coleção não configurado no processo {$process->name}.");
            return false;
        }

        $missing = new \stdClass();
        $collection = data_get($input, $collectionPath, $missing);

        if ($collection === $missing) {
            $this->error("Caminho da coleção não encontrado: {$collectionPath}");
            return false;
        }

        if ($collection === null) {
            $this->error("Caminho da coleção contém valor nulo: {$collectionPath}");
            return false;
        }

        if (!is_array($collection) || !array_is_list($collection)) {
            $this->error("Caminho da coleção deve conter uma lista JSON: {$collectionPath}");
            return false;
        }

        $outputs = [];
        $inputBase = pathinfo(basename($filePath), PATHINFO_FILENAME);

        foreach ($collection as $index => $item) {
            if (!is_array($item)) {
                $this->error('Item ' . ($index + 1) . " da coleção {$collectionPath} deve ser um objeto JSON.");
                return false;
            }

            $filename = sprintf('%s-item-%06d.json', $inputBase, $index + 1);
            $outputs[$filename] = json_encode(
                $this->applyDepara($item, $deparas),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            );
        }

        if ($outputs === []) {
            return $this->finalizarProcesso($filePath, basename($filePath), $process->name);
        }

        $attemptId = (string) Str::uuid();
        $stagingPath = dirname(rtrim($outgoingPath, '/'))
            . '/staging/' . hash('sha256', "{$process->id}|{$filePath}")
            . "/{$attemptId}";

        try {
            foreach ($outputs as $filename => $content) {
                if (!Storage::disk('integrations')->put("{$stagingPath}/{$filename}", $content)) {
                    $this->error("Falha ao salvar payload em staging: {$stagingPath}/{$filename}");
                    return false;
                }
            }

            foreach ($outputs as $filename => $content) {
                $target = rtrim($outgoingPath, '/') . "/{$filename}";
                $staged = "{$stagingPath}/{$filename}";

                if (!$this->publishStagedOutput($staged, $target, $content)) {
                    return false;
                }
            }

            return $this->finalizarProcesso($filePath, basename($filePath), $process->name);
        } finally {
            Storage::disk('integrations')->deleteDirectory($stagingPath);
        }
    }

    private function publishStagedOutput(string $staged, string $target, string $content): bool
    {
        // Escritores cooperativos do EAI usam a mesma chave por target. O lock
        // não protege contra processos externos que escrevam direto no storage.
        $lockKey = 'eai:transform-target:' . hash('sha256', $target);
        $lock = Cache::lock($lockKey, self::TARGET_LOCK_TTL_SECONDS);

        if (!$lock->get()) {
            $this->error("Output final já está sendo publicado: {$target}");
            return false;
        }

        try {
            if (Storage::disk('integrations')->exists($target)) {
                if (Storage::disk('integrations')->get($target) === $content) {
                    return true;
                }

                $this->error("Output final já existe com conteúdo diferente: {$target}");
                return false;
            }

            if (!Storage::disk('integrations')->move($staged, $target)) {
                $this->error("Falha ao publicar payload: {$target}");
                return false;
            }

            return true;
        } finally {
            $lock->release();
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
        Storage::disk('integrations')->makeDirectory(dirname($targetPath));

        if (!Storage::disk('integrations')->move($oldPath, $targetPath)) {
            $this->error("Falha ao mover arquivo processado: {$oldPath}");
            return false;
        }

        CadInterfaceStatus::where('int_arquivo', basename($oldPath))->update(['int_status' => 3]);
        // ... criar novo status para o arquivo de saída ...

        return true;
    }
}
