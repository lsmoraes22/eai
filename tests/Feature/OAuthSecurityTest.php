<?php

use App\Models\Client;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Permission;

function oauthTestClient(): Client
{
    return Client::create([
        'name' => 'Example integration', 'code' => 'example',
        'telefone' => '0000000000', 'endereco' => 'Test address', 'cnpj' => '00000000000000',
        'auth_url' => 'https://provider.test/authorize', 'token_url' => 'https://provider.test/token',
        'app_client_id' => 'test-app', 'app_client_secret' => 'fixture-client-secret',
    ]);
}

function startOAuth($test, Client $client): array
{
    $response = $test->get(route('oauth.authorize', $client));
    $response->assertRedirect();
    parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
    return $query;
}

beforeEach(function () {
    config(['app.url' => 'https://eai.example.test']);
    $this->client = oauthTestClient();
    $this->user = User::factory()->create();
    Permission::findOrCreate('update_client', 'web');
    $this->user->givePermissionTo('update_client');
    $this->actingAs($this->user);
    Http::preventStrayRequests();
});

test('valid callback exchanges the code with the exact original redirect URI and consumes state', function () {
    $query = startOAuth($this, $this->client);
    expect($query['state'])->toMatch('/^[a-f0-9]{64}$/')
        ->and($query['redirect_uri'])->toBe('https://eai.example.test/token/' . $this->client->id);
    // Config changes must not change the URI of an already-issued attempt.
    config(['app.url' => 'https://changed.example.test']);
    Http::fake(['provider.test/*' => Http::response(['access_token' => 'fixture-access', 'refresh_token' => 'fixture-refresh'])]);
    $url = route('oauth.callback', $this->client->id) . '?' . http_build_query(['state' => $query['state'], 'code' => 'fixture-code']);
    $this->get($url)->assertRedirect('/admin/clients');
    Http::assertSent(fn ($request) => $request['redirect_uri'] === $query['redirect_uri'] && $request['code'] === 'fixture-code');
    expect($this->client->refresh()->access_token)->toBe('fixture-access');
    $this->get($url)->assertForbidden();
    Http::assertSentCount(1);
});

test('missing or invalid state never calls the provider', function ($state) {
    startOAuth($this, $this->client);
    Http::fake();
    $this->get(route('oauth.callback', $this->client->id) . '?' . http_build_query(['code' => 'fixture-code', 'state' => $state]))->assertForbidden();
    Http::assertNothingSent();
})->with([null, 'invalid', str_repeat('a', 64)]);

test('expired state is rejected', function () {
    $query = startOAuth($this, $this->client);
    $key = hash('sha256', $query['state']);
    $attempts = session('oauth_attempts');
    $attempts[$key]['expires_at'] = time() - 1;
    $this->withSession(['oauth_attempts' => $attempts]);
    Http::fake();
    $this->get(route('oauth.callback', $this->client->id) . '?state=' . $query['state'] . '&code=test')->assertForbidden();
    Http::assertNothingSent();
});

test('state cannot be used for another client or user', function (string $mismatch) {
    $query = startOAuth($this, $this->client);
    $id = $this->client->id;
    if ($mismatch === 'client') {
        $id++;
    } else {
        $this->actingAs(User::factory()->create());
    }
    Http::fake();
    $this->get(route('oauth.callback', $id) . '?state=' . $query['state'] . '&code=test')->assertForbidden();
    Http::assertNothingSent();
})->with(['client', 'user']);

test('user without client update permission cannot start authorization', function () {
    $this->actingAs(User::factory()->create());
    $this->get(route('oauth.authorize', $this->client))->assertForbidden();
});

test('callback never exposes provider response secrets', function (int $status) {
    $query = startOAuth($this, $this->client);
    Log::spy();
    Http::fake(['provider.test/*' => Http::response(['refresh_token' => 'fixture-sensitive-response'], $status)]);
    $this->get(route('oauth.callback', $this->client->id) . '?state=' . $query['state'] . '&code=test')
        ->assertStatus($status === 429 ? 429 : 502)->assertDontSee('fixture-sensitive-response');
    Log::shouldHaveReceived('warning')->once()->with('OAuth token exchange failed.', ['client_id' => $this->client->id, 'status' => $status]);
})->with([200, 400, 429]);

test('callback never logs transport exception content', function () {
    $query = startOAuth($this, $this->client);
    Log::spy();
    Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('fixture-sensitive-response'));
    $this->get(route('oauth.callback', $this->client->id) . '?state=' . $query['state'] . '&code=test')->assertStatus(502)->assertDontSee('fixture-sensitive-response');
    Log::shouldHaveReceived('error')->once()->with('OAuth token exchange exception.', ['client_id' => $this->client->id, 'type' => \Illuminate\Http\Client\ConnectionException::class]);
});
