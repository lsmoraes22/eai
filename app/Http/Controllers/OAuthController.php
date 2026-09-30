<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Services\OAuthState;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OAuthController extends Controller
{
    public function authorizeClient(Request $request, Client $client, OAuthState $states)
    {
        Gate::authorize('update', $client);
        abort_unless($client->auth_url && $client->app_client_id, 422);
        $attempt = $states->issue($request, $client);
        $params = array_merge($attempt, [
            'response_type' => 'code',
            'client_id' => $client->app_client_id,
        ]);

        return redirect()->away($client->auth_url
            . (str_contains($client->auth_url, '?') ? '&' : '?') . http_build_query($params));
    }

    public function token(Request $request, $id, OAuthState $states)
    {
        $attempt = $states->consume($request, (string) $id);
        abort_unless($attempt, 403, 'Invalid or expired OAuth state.');
        $client = Client::findOrFail($id);
        Gate::authorize('update', $client);
        $code = $request->query('code');
        abort_unless(is_string($code) && $code !== '', 400, 'Authorization code required.');

        try {
            $response = Http::asForm()
                ->withBasicAuth($client->app_client_id, $client->app_client_secret)
                ->post($client->token_url, [
                    'grant_type' => 'authorization_code',
                    'code' => $code,
                    'redirect_uri' => $attempt['redirect_uri'],
                    'client_id' => $client->app_client_id,
                    'client_secret' => $client->app_client_secret,
                ]);

            $data = $response->json();
            if ($response->successful() && is_array($data)
                && is_string($data['access_token'] ?? null) && $data['access_token'] !== '') {
                $client->update([
                    'access_token' => $data['access_token'],
                    'refresh_token' => $data['refresh_token'] ?? null,
                    'expires_at' => now()->addSeconds($data['expires_in'] ?? 21600),
                    'account_id' => $data['account_id'] ?? $client->account_id,
                ]);

                return redirect('/admin/clients')->with('success', 'Conta API vinculada com sucesso!');
            }

            Log::warning('OAuth token exchange failed.', ['client_id' => $client->id, 'status' => $response->status()]);
            return response()->json(['error' => 'Falha na autorização com o provedor.'],
                $response->status() === 429 ? 429 : 502);
        } catch (\Exception $e) {
            Log::error('OAuth token exchange exception.', ['client_id' => $client->id, 'type' => $e::class]);
            return response()->json(['error' => 'Falha na autorização com o provedor.'], 502);
        }
    }
}
