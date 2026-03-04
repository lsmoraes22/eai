<?php

namespace App\Http\Controllers;

use App\Models\Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OAuthController extends Controller
{
    public function token(Request $request, $id)
    {
        $code = $request->query('code');

        // 1. Verificação básica
        if (!$code) {
            Log::error("Tentativa de acesso ao callback sem código. ID Cliente: {$id}");
            return "Erro: Código de autorização não encontrado. Por favor, tente novamente.";
        }

        // 2. Localiza o cliente ou retorna 404
        $client = Client::findOrFail($id);

        try {
            // 3. Faz a troca do código pelo Token (Ato 4)
            // Usamos a URL salva no banco (token_url)
            $response = Http::asForm()
                ->withBasicAuth($client->app_client_id, $client->app_client_secret)
                ->post($client->token_url, [
                    'grant_type'   => 'authorization_code',
                    'code'         => $code,
                    'redirect_uri' => "https://eai.voga2b.com.br/token/{$id}",
		    'client_id'     => $client->app_client_id,
        	    'client_secret' => $client->app_client_secret,
                ]);

	    if ($response->successful()) {
                $data = $response->json();

                // Verificação de segurança: o token realmente existe no JSON?
                if (!isset($data['access_token'])) {
                    Log::error("Bling retornou sucesso mas sem access_token: " . json_encode($data));
                    return "Erro: O Bling não enviou a chave de acesso. Resposta: " . json_encode($data);
                }

                // 4. Salva os dados no banco usando as colunas da sua tabela
                $client->update([
                    'access_token'  => $data['access_token'],
                    'refresh_token' => $data['refresh_token'] ?? null,
                    'expires_at'    => now()->addSeconds($data['expires_in'] ?? 21600),
                    'account_id'    => $data['account_id'] ?? $client->account_id,
                ]);

                return redirect('/admin/clients')->with('success', "Bling vinculado com sucesso!");
            }

            // 5. Tratamento de Erros da API
            if ($response->status() === 429) {
                Log::warning("Bling Rate Limit (429) para o cliente {$id}");
                return "O Bling está recebendo muitas requisições. Aguarde 2 minutos e tente novamente.";
            }

            Log::error("Erro na troca de token Bling: " . $response->body());
            return response()->json([
                'error' => 'Falha na comunicação com o Bling',
                'details' => $response->json()
            ], $response->status());

        } catch (\Exception $e) {
            Log::error("Exceção no OAuthController: " . $e->getMessage());
            return "Ocorreu um erro interno ao processar sua autorização.";
        }
    }
}
