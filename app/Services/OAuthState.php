<?php

namespace App\Services;

use App\Models\Client;
use Illuminate\Http\Request;

class OAuthState
{
    public function issue(Request $request, Client $client): array
    {
        $attempts = array_filter($request->session()->get('oauth_attempts', []),
            fn (array $attempt) => $attempt['expires_at'] > time());
        // Bound session size while allowing concurrent authorizations in separate tabs.
        $attempts = array_slice($attempts, -9, null, true);
        $state = bin2hex(random_bytes(32));
        $redirectUri = rtrim(config('app.url'), '/') . route('oauth.callback', ['id' => $client->id], false);
        $attempts[hash('sha256', $state)] = [
            'client_id' => (string) $client->id,
            'user_id' => (string) $request->user()->getAuthIdentifier(),
            'redirect_uri' => $redirectUri,
            'expires_at' => time() + 600,
        ];
        $request->session()->put('oauth_attempts', $attempts);

        return ['state' => $state, 'redirect_uri' => $redirectUri];
    }

    public function consume(Request $request, string $clientId): ?array
    {
        $state = $request->query('state');
        if (!is_string($state) || !preg_match('/\A[a-f0-9]{64}\z/', $state)) {
            return null;
        }

        $attempt = $request->session()->pull('oauth_attempts.' . hash('sha256', $state));
        // Persist consumption before any slow provider request or rejected callback.
        $request->session()->save();
        if (!$attempt || $attempt['expires_at'] <= time()
            || $attempt['client_id'] !== $clientId
            || $attempt['user_id'] !== (string) $request->user()->getAuthIdentifier()) {
            return null;
        }

        return $attempt;
    }
}
