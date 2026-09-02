<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use App\Models\Client;
use App\Models\CadEndpoint;
use App\Models\CadInterfaceStatus;
use JsonSchema\Validator;

class ValidateJsonCommand extends Command
{
    // A região crítica protege somente a transição de um arquivo no Storage.
    private const TARGET_LOCK_TTL_SECONDS = 60;

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
                $endpointSlug = Str::slug($ep->nome);
                // Diretórios (Seguindo seu padrão de XML)
                $incomingPath  = "polling/{$clientCode}/json/incoming/{$endpointSlug}/raw/";
                $validatedPath = "polling/{$clientCode}/json/incoming/{$endpointSlug}/validated/";
                $refusedPath   = "polling/{$clientCode}/json/incoming/{$endpointSlug}/refused/";
                $schemaPath    = "polling/{$clientCode}/schema/{$endpointSlug}.json";

                Storage::disk('public')->makeDirectory($validatedPath);
                Storage::disk('public')->makeDirectory($refusedPath);

                $files = Storage::disk('public')->files($incomingPath);

                if ($files === []) {
                    continue;
                }

                try {
                    if (!Storage::disk('public')->exists($schemaPath)) {
                        $this->error("Schema ausente para o endpoint {$ep->nome}. Esperado: {$schemaPath}");
                        continue;
                    }

                    $schema = json_decode(
                        Storage::disk('public')->get($schemaPath),
                        false,
                        512,
                        JSON_THROW_ON_ERROR
                    );
                } catch (\JsonException $e) {
                    $this->error("Schema JSON inválido para o endpoint {$ep->nome} em {$schemaPath}: {$e->getMessage()}");
                    continue;
                } catch (\Throwable $e) {
                    // Boundary por endpoint: falhas operacionais ao ler o schema não
                    // podem interromper a validação dos demais endpoints.
                    $this->error("Falha ao carregar schema para o endpoint {$ep->nome} em {$schemaPath}: {$e->getMessage()}");
                    continue;
                }

                foreach ($files as $file) {
                    try {
                        $jsonContent = json_decode(
                            Storage::disk('public')->get($file),
                            false,
                            512,
                            JSON_THROW_ON_ERROR
                        );
                    } catch (\JsonException $e) {
                        $this->handleRefusal($file, $refusedPath, 'Erro de sintaxe JSON');
                        continue;
                    } catch (\Throwable $e) {
                        // Boundary por arquivo para isolar falhas do Storage.
                        $this->error('Falha ao ler JSON ' . basename($file) . ': ' . $e->getMessage());
                        continue;
                    }

                    // Validação Estrutural (Schema)
                    $validator = new Validator();

                    try {
                        $validator->validate($jsonContent, $schema);
                    } catch (\Throwable $e) {
                        // A biblioteca pode lançar tanto exceções próprias quanto SPL
                        // para schemas inválidos; o boundary mantém o RAW reprocessável.
                        $this->error('Falha no schema ao validar ' . basename($file) . ': ' . $e->getMessage());
                        continue;
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

    private function handleSuccess($file, $path): bool
    {
        return $this->moveAndUpdateStatus(
            $file,
            $path,
            1,
            'OK',
            '✔ JSON VALIDADO'
        );
    }

    private function handleRefusal($file, $path, $msg): bool
    {
        return $this->moveAndUpdateStatus(
            $file,
            $path,
            2,
            $msg,
            '❌ JSON RECUSADO'
        );
    }

    private function moveAndUpdateStatus(string $file, string $path, int $status, string $message, string $label): bool
    {
        $filename = basename($file);
        $target = $path . $filename;
        $lock = Cache::lock(
            'eai:validate-target:' . hash('sha256', $target),
            self::TARGET_LOCK_TTL_SECONDS
        );

        if (!$lock->get()) {
            $this->error("Falha ao mover {$filename}: destino em processamento ({$target})");
            return false;
        }

        try {
            if (Storage::disk('public')->exists($target)) {
                $this->error("Falha ao mover {$filename}: destino já existe ({$target})");
                return false;
            }

            if (!Storage::disk('public')->move($file, $target)) {
                $this->error("Falha ao mover {$filename} para {$target}");
                return false;
            }

            CadInterfaceStatus::where('int_arquivo', $filename)->update([
                'int_status' => $status,
                'int_mensagem' => $message,
            ]);

            if ($status === 1) {
                $this->info("{$label}: {$filename}");
            } else {
                $this->error("{$label}: {$filename} - {$message}");
            }

            return true;
        } catch (\Throwable $e) {
            // Boundary da transição: uma falha de Storage não bloqueia os demais
            // arquivos e nunca atualiza status antes de um move confirmado.
            $this->error("Falha ao mover {$filename} para {$target}: {$e->getMessage()}");
            return false;
        } finally {
            $lock->release();
        }
    }
}
