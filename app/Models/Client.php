<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

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

    protected $hidden = [
        'app_client_secret',
        'access_token',
        'refresh_token',
    ];

    public function buildOAuthAuthorizationUrl(): ?string
    {
        if (!$this->app_client_id || !$this->isSafeHttpsUrl($this->auth_url)) {
            return null;
        }
        // State must be issued by the authenticated controller, bound to its session.
        return route('oauth.authorize', ['client' => $this]);
    }

    /**
     * Tenta renovar o token de acesso de forma agnóstica.
     */
    public function refreshToken()
    {
        if (!$this->token_url || !$this->refresh_token) {
            Log::error("Impossível renovar token para Cliente ID {$this->id}: Falta token_url ou refresh_token.");
            return null;
        }

        if (!$this->isSafeHttpsUrl($this->token_url)) {
            Log::warning('URL de renovação OAuth inválida ou insegura.', ['client_id' => $this->id]);
            return null;
        }

        // Prioriza as chaves do banco, se não tiver, usa as do .env (config)
        $clientId = $this->app_client_id ?? config('services.bling.client_id');
        $clientSecret = $this->app_client_secret ?? config('services.bling.client_secret');

        try {
            $response = Http::asForm()
                ->timeout(15)
                ->withoutRedirecting()
                ->retry(2, 200, throw: false)
                ->post($this->token_url, [
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

            Log::error("Erro na renovação (ID {$this->id}), HTTP " . $response->status());
        } catch (\Exception $e) {
            Log::error("Exceção na renovação (ID {$this->id}): " . $e::class);
        }

        return null;
    }

    public function isSafeHttpsUrl(?string $url): bool
    {
        if (!$url || !filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        return Str::lower((string) parse_url($url, PHP_URL_SCHEME)) === 'https'
            && filled(parse_url($url, PHP_URL_HOST));
    }

    public function endpoints() {
        return $this->hasMany(CadEndpoint::class);
    }

    public function xsdFiles()
    {
    	return $this->hasMany(XsdFile::class);
    }
}
