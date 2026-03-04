<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class Client extends Model
{
    protected $fillable = [
        'name', 'code', 'telefone', 'endereco', 'cnpj', 'active',
        'auth_url', 'token_url', 'app_client_id', 'app_client_secret',
        'access_token', 'refresh_token', 'expires_at', 'account_id',
    ];

    protected $casts = [
        'active' => 'boolean',
        'expires_at' => 'datetime',
    ];

    /**
     * Tenta renovar o token de acesso de forma agnóstica.
     */
    public function refreshToken()
    {
        if (!$this->auth_url || !$this->refresh_token) {
            Log::error("Impossível renovar token para Cliente ID {$this->id}: Falta auth_url ou refresh_token.");
            return null;
        }

        // Prioriza as chaves do banco, se não tiver, usa as do .env (config)
        $clientId = $this->app_client_id ?? config('services.bling.client_id');
        $clientSecret = $this->app_client_secret ?? config('services.bling.client_secret');

        try {
            $response = Http::asForm()->post($this->token_url, [
                'grant_type'    => 'refresh_token',
                'refresh_token' => $this->refresh_token,
                'client_id'     => $clientId,
                'client_secret' => $clientSecret,
            ]);

            if ($response->successful()) {
                $data = $response->json();

                $this->update([
                    'access_token'  => $data['access_token'],
                    // OAuth2 pode ou não retornar um novo refresh_token
                    'refresh_token' => $data['refresh_token'] ?? $this->refresh_token,
                    'expires_at'    => now()->addSeconds($data['expires_in'] ?? 3600),
                ]);

                return $data['access_token'];
            }

            Log::error("Erro na renovação (ID {$this->id}): " . $response->body());
        } catch (\Exception $e) {
            Log::error("Exceção na renovação (ID {$this->id}): " . $e->getMessage());
        }

        return null;
    }

    public function endpoints() {
        return $this->hasMany(CadEndpoint::class);
    }

    public function xsdFiles()
    {
    	return $this->hasMany(XsdFile::class);
    }
}
