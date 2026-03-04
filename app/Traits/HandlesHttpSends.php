<?php

namespace App\Traits;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

trait HandlesHttpSends
{
    /**
     * Aplica a autenticação baseada no tipo configurado no endpoint/webhook.
     */
    protected function applyAuthentication($model, $client, &$headers)
    {
        // Aceita tanto CadEndpoint quanto CadWebhook se ambos tiverem a coluna 'autenticacao'
        $tipoAuth = strtolower($model->autenticacao ?? 'nenhum');

        if ($tipoAuth === 'nenhum') return;

        // 1. OAuth2 - Centralizado no Model Client
        if ($tipoAuth === 'oauth2') {
            if (!$client->access_token || ($client->expires_at && $client->expires_at->subMinute()->isPast())) {
                $this->comment(" Token expirado para {$client->name}. Renovando...");
                $token = $client->refreshToken();
            } else {
                $token = $client->access_token;
            }

            if ($token) {
                $headers['Authorization'] = 'Bearer ' . $token;
                return;
            }
        }

        // 2. Autenticação via Arquivo ou Direta
        // Se o model tiver um token direto (comum em webhooks), usa ele, senão busca no arquivo
        $token = $model->token ?: $this->getTokenFromFile($client->code ?: $client->name, $model);

        if ($token) {
            switch ($tipoAuth) {
                case 'bearer':  $headers['Authorization'] = 'Bearer ' . $token; break;
                case 'basic':   $headers['Authorization'] = 'Basic ' . $token; break;
                case 'api_key': $headers['Authorization'] = 'Api Key ' . $token; break;
            }
        }
    }

    /**
     * Busca token em arquivo local (legado)
     */
    protected function getTokenFromFile($clientFolder, $model)
    {
        $path = "tokens/{$clientFolder}/" . Str::slug($model->nome) . ".txt";
        if (Storage::disk('public')->exists($path)) {
            return trim(Storage::disk('public')->get($path));
        }
        return null;
    }
}
