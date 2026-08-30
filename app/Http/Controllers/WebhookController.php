<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\CadWebhook; // Importado corretamente
use App\Models\CadInterfaceStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class WebhookController extends Controller
{
    public function handle(Request $request, $client_id, $interface)
    {
        // 1. Busca o Webhook na nova tabela cad_webhooks
        // Usamos with('client') para evitar múltiplas consultas ao banco (Eager Loading)
        $webhook = CadWebhook::with('client')
            ->where('client_id', $client_id)
            ->where('nome', $interface)
            ->where('ativo', true)
            ->first();

        if (!$webhook) {
            Log::warning("Webhook não configurado ou inativo: Client {$client_id} / Interface: {$interface}");
            return response()->json(['message' => 'Webhook não configurado ou inativo'], 404);
        }


        $content = $request->getContent();

        // 2. Validação HMAC (Agnóstica)
        if ($webhook->webhook_verify) {
            $algorithm = strtolower((string) $webhook->webhook_algo);
            $supportedAlgorithms = ['sha256', 'sha512'];

            if (!in_array($algorithm, $supportedAlgorithms, true) || blank($webhook->client?->app_client_secret)) {
                Log::error('Webhook com validação HMAC mal configurada.', [
                    'client_id' => $client_id,
                    'interface' => $interface,
                ]);

                return response()->json(['message' => 'Webhook mal configurado'], 500);
            }

            $signature = $request->header($webhook->webhook_header);

            if (!$signature) {
                return response()->json(['message' => 'Assinatura ausente'], 401);
            }

            // Remove o prefixo (ex: 'sha256=') para validar apenas o hash puro
            $cleanSignature = Str::startsWith($signature, $algorithm . '=')
                ? Str::after($signature, $algorithm . '=')
                : $signature;

            // Importante: Usamos o client_secret do aplicativo vinculado ao cliente
            $expected = hash_hmac($algorithm, $content, $webhook->client->app_client_secret);

            if (!hash_equals($expected, $cleanSignature)) {
                Log::error("Falha de autenticação HMAC no Webhook: {$interface}");
                return response()->json(['message' => 'Falha na autenticação'], 401);
            }
        }

        // 3. Lógica de Identificação de Cliente e Formato
        $client = $webhook->client;
        $clientCode = $client->code ?: Str::slug($client->name);

        // Sua lógica de extensão (corrigida e agnóstica)
        if ($request->isJson()) {
            $extension = 'json';
        } elseif (Str::contains($content, '<?xml')) {
            $extension = 'xml';
        } else {
            $extension = 'txt';
        }

        // 4. Salvamento do Arquivo
        $filename = now()->format('YmdHisv') . '_webhook.' . $extension;
        $interfaceSlug = Str::slug($webhook->nome);
        $directory = "webhooks/{$clientCode}/{$extension}/incoming/{$interfaceSlug}/raw";

        try {
            Storage::disk('public')->makeDirectory($directory);
            Storage::disk('public')->put("{$directory}/{$filename}", $content);

            // 5. Registro para o Processador
            CadInterfaceStatus::create([
                'int_direcao'    => 'entrada',
                'int_interface'  => $interface,
                'int_arquivo'    => $filename,
                'int_idoc'       => pathinfo($filename, PATHINFO_FILENAME),
                'int_status'     => 0,
                'int_data_envio' => now(),
            ]);

            return response()->json([
                'status' => 'success',
                'interface' => $interface
            ], 200);

        } catch (\Exception $e) {
            Log::critical("Erro ao salvar Webhook: " . $e->getMessage());
            return response()->json(['message' => 'Erro interno ao processar'], 500);
        }
    }
}
