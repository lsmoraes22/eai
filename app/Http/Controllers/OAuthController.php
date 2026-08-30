<?php

namespace App\Http\Controllers;

use App\Models\Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class OAuthController extends Controller
{
    public function token(Request $request, $id)
    {
        $code = $request->query('code');
        $state = $request->query('state');

        // 1. Verificação básica
        if (!$code) {
            Log::error("Tentativa de acesso ao callback sem código. ID Cliente: {$id}");
            return "Erro: Código de autorização não encontrado. Por favor, tente novamente.";
        }

        // 2. Localiza o cliente ou retorna 404
        $client = Client::findOrFail($id);

        if (!$client->isOAuthStateValid($state)) {
            Log::warning('Callback OAuth rejeitado por state ausente, inválido ou expirado.', [
                'client_id' => $client->id,
            ]);

            return response()->json(['error' => 'State OAuth inválido ou expirado.'], 419);
        }

        if (!$this->isSafeHttpsUrl($client->token_url)) {
            Log::warning('OAuth token URL inválida ou insegura.', ['client_id' => $client->id]);

            return response()->json(['error' => 'Configuração OAuth inválida.'], 422);
        }

        try {
            // 3. Faz a troca do código pelo Token (Ato 4)
            // Usamos a URL salva no banco (token_url)
            $response = Http::asForm()
                ->timeout(15)
                ->retry(2, 200, throw: false)
                ->withBasicAuth($client->app_client_id, $client->app_client_secret)
                ->post($client->token_url, [
                    'grant_type'   => 'authorization_code',
                    'code'         => $code,
                    'redirect_uri' => route('oauth.callback', ['id' => $id]),
                    'client_id'     => $client->app_client_id,
                    'client_secret' => $client->app_client_secret,
                ]);

            if ($response->successful()) {
                $data = $response->json();

                // Verificação de segurança: o token realmente existe no JSON?
                if (!isset($data['access_token'])) {
                    Log::error('Provedor OAuth retornou sucesso sem access_token.', [
                        'client_id' => $client->id,
                        'status' => $response->status(),
                    ]);

                    return response()->json(['error' => 'Resposta OAuth inválida.'], 502);
                }

                // 4. Salva os dados no banco usando as colunas da sua tabela
                $client->update([
                    'access_token'  => $data['access_token'],
                    'refresh_token' => $data['refresh_token'] ?? null,
                    'expires_at'    => now()->addSeconds($data['expires_in'] ?? 21600),
                    'account_id'    => $data['account_id'] ?? $client->account_id,
                ]);

                $client->forgetOAuthState($state);

                return redirect('/admin/clients')->with('success', "Bling vinculado com sucesso!");
            }

            // 5. Tratamento de Erros da API
            if ($response->status() === 429) {
                Log::warning("Bling Rate Limit (429) para o cliente {$id}");
                return "O Bling está recebendo muitas requisições. Aguarde 2 minutos e tente novamente.";
            }

            Log::error('Erro na troca de token OAuth.', [
                'client_id' => $client->id,
                'status' => $response->status(),
            ]);

            return response()->json([
                'error' => 'Falha na comunicação com o Bling',
            ], 502);

        } catch (\Exception $e) {
            Log::error('Exceção no OAuthController.', [
                'client_id' => $client->id,
                'message' => $e->getMessage(),
            ]);

            return response('Ocorreu um erro interno ao processar sua autorização.', 500);
        }
    }

    private function isSafeHttpsUrl(?string $url): bool
    {
        if (!$url || !filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        return Str::lower((string) parse_url($url, PHP_URL_SCHEME)) === 'https'
            && filled(parse_url($url, PHP_URL_HOST));
    }
}
