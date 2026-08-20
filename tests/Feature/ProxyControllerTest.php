<?php

declare(strict_types=1);

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Session;
use LvntR\ApiDock\Support\AuthProfileStore;
use LvntR\ApiDock\Support\OutboundRequestGuard;

/**
 * Every test here fakes the HTTP client and injects the resolver, so no assertion
 * in this file depends on — or is able to make — a real outbound network call.
 */

/** Obvious dummy: never a real secret. */
const DUMMY_CREDENTIAL = 'dummy-token-AAAABBBBCCCCDDDD';

const PUBLIC_ADDRESS = '93.184.216.34';

/**
 * @param  list<string>  $addresses
 */
function resolvesTo(array $addresses): void
{
    app()->bind(OutboundRequestGuard::class, static fn ($app): OutboundRequestGuard => new OutboundRequestGuard(
        $app->make(HttpFactory::class),
        static fn (string $host): array => $addresses,
    ));
}

beforeEach(function (): void {
    // Obvious dummy key: the encrypter only has to be deterministic here.
    config()->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
    config()->set('session.driver', 'array');
    config()->set('cache.default', 'array');
    config()->set('api-dock.try_it.enabled', true);
    config()->set('api-dock.try_it.allowed_hosts', ['api.example.com']);

    app()->forgetInstance('encrypter');

    Http::preventStrayRequests();

    resolvesTo([PUBLIC_ADDRESS]);

    // StartSession rebuilds the session id from the request cookie on every call,
    // so pin the current id into that cookie: without it a profile stored under
    // Session::getId() belongs to a session no request in the test ever uses, and
    // the controller correctly answers 404. Each test gets a fresh application,
    // hence a fresh id, so this does not leak identity between tests.
    // withCredentials() is required as well: postJson()/getJson() send no cookies
    // at all without it, exactly like a fetch() that omits `credentials`.
    $this->withCredentials()->withUnencryptedCookie(Session::getName(), Session::getId());
});

it('refuses every request while try-it is disabled', function (): void {
    config()->set('api-dock.try_it.enabled', false);

    Http::fake();

    $response = $this->postJson('/api-dock/try-it', [
        'method' => 'GET',
        'url' => 'https://api.example.com/things',
    ]);

    $response->assertForbidden();

    expect((string) $response->json('message'))->toContain('disabled');

    Http::assertNothingSent();
});

it('denies an ordinary public host while the allowlist is empty', function (): void {
    config()->set('api-dock.try_it.allowed_hosts', []);

    Http::fake();

    $response = $this->postJson('/api-dock/try-it', [
        'method' => 'GET',
        'url' => 'https://api.example.com/things',
    ]);

    $response->assertStatus(422);

    expect((string) $response->json('message'))->toContain('allowlist');

    Http::assertNothingSent();
});

it('denies a host that is not on the allowlist', function (): void {
    Http::fake();

    $this->postJson('/api-dock/try-it', [
        'method' => 'GET',
        'url' => 'https://api.other.test/things',
    ])->assertStatus(422);

    Http::assertNothingSent();
});

it('never treats a bare allowlist entry as a suffix wildcard', function (): void {
    config()->set('api-dock.try_it.allowed_hosts', ['example.com']);

    Http::fake();

    $this->postJson('/api-dock/try-it', [
        'method' => 'GET',
        'url' => 'https://evil-example.com/things',
    ])->assertStatus(422);

    Http::assertNothingSent();
});

it('allows a subdomain only through the explicit leading-dot entry', function (): void {
    config()->set('api-dock.try_it.allowed_hosts', ['.example.com']);

    Http::fake(['*' => Http::response('{"ok":true}', 200)]);

    $this->postJson('/api-dock/try-it', [
        'method' => 'GET',
        'url' => 'https://tenant.example.com/things',
    ])->assertOk()->assertJsonPath('status', 200);
});

it('denies an allowlisted host that resolves into a blocked address range', function (string $address): void {
    resolvesTo([$address]);

    Http::fake();

    $response = $this->postJson('/api-dock/try-it', [
        'method' => 'GET',
        'url' => 'https://api.example.com/things',
    ]);

    $response->assertStatus(422);

    expect((string) $response->json('message'))->toContain('may not be reached');

    Http::assertNothingSent();
})->with([
    'loopback v4' => '127.0.0.1',
    'loopback v6' => '::1',
    'private 10 slash 8' => '10.0.0.5',
    'private 172.16 slash 12' => '172.20.1.1',
    'private 192.168 slash 16' => '192.168.1.1',
    'unique local v6' => 'fc00::1',
    'link local v4' => '169.254.10.1',
    'link local v6' => 'fe80::1',
    'aws metadata v4' => '169.254.169.254',
    'aws metadata v6' => 'fd00:ec2::254',
    'unspecified v4' => '0.0.0.0',
    'unspecified v6' => '::',
    'broadcast' => '255.255.255.255',
    'multicast' => '224.0.0.1',
    'v4 mapped loopback' => '::ffff:127.0.0.1',
    'v4 mapped metadata' => '::ffff:169.254.169.254',
    '6to4 loopback' => '2002:7f00:1::1',
]);

it('denies a request when only one of several resolved addresses is internal', function (): void {
    resolvesTo([PUBLIC_ADDRESS, '169.254.169.254']);

    Http::fake();

    $this->postJson('/api-dock/try-it', [
        'method' => 'GET',
        'url' => 'https://api.example.com/things',
    ])->assertStatus(422);

    Http::assertNothingSent();
});

it('denies a host that names an internal service by convention', function (): void {
    config()->set('api-dock.try_it.allowed_hosts', ['metadata.google.internal']);

    Http::fake();

    $this->postJson('/api-dock/try-it', [
        'method' => 'GET',
        'url' => 'http://metadata.google.internal/computeMetadata/v1/',
    ])->assertStatus(422);

    Http::assertNothingSent();
});

it('rejects a server variable that could rewrite the authority', function (string $value): void {
    config()->set('api-dock.try_it.allowed_hosts', ['.congress-app.test']);

    Http::fake();

    $response = $this->postJson('/api-dock/try-it', [
        'method' => 'GET',
        'server' => 'https://{kurum}.congress-app.test/api',
        'server_variables' => ['kurum' => $value],
    ]);

    $response->assertStatus(422);

    expect((string) $response->json('message'))->toContain('not allowed');

    Http::assertNothingSent();
})->with([
    'slash' => 'evil.com/',
    'at sign' => 'x@attacker.test',
    'colon' => 'acme:8080',
    'question mark' => 'acme?x=1',
    'hash' => 'acme#frag',
    'encoded slash' => 'evil.com%2F',
    'double encoded slash' => 'evil.com%252F',
    'backslash' => 'evil.com\\',
]);

it('rejects a server variable outside the enum declared by the specification', function (): void {
    config()->set('api-dock.try_it.allowed_hosts', ['.congress-app.test']);

    Http::fake();

    $response = $this->postJson('/api-dock/try-it', [
        'method' => 'GET',
        'server' => 'https://{kurum}.congress-app.test/api',
        'server_variables' => ['kurum' => 'contoso'],
        'server_variable_spec' => ['kurum' => ['enum' => ['acme', 'globex'], 'default' => 'acme']],
    ]);

    $response->assertStatus(422);

    expect((string) $response->json('message'))->toContain('declared by the specification');

    Http::assertNothingSent();
});

it('refuses a template that still carries an unsubstituted placeholder', function (): void {
    config()->set('api-dock.try_it.allowed_hosts', ['.congress-app.test']);

    Http::fake();

    $missing = $this->postJson('/api-dock/try-it', [
        'method' => 'GET',
        'server' => 'https://{kurum}.congress-app.test/api',
    ]);

    $missing->assertStatus(422);
    expect((string) $missing->json('message'))->toContain('no supplied value and no default');

    $unresolved = $this->postJson('/api-dock/try-it', [
        'method' => 'GET',
        'server' => 'https://acme.congress-app.test/api/{ region }',
    ]);

    $unresolved->assertStatus(422);
    expect((string) $unresolved->json('message'))->toContain('unsubstituted placeholder');

    Http::assertNothingSent();
});

it('substitutes a server variable from the specification default and validates the final url', function (): void {
    config()->set('api-dock.try_it.allowed_hosts', ['.congress-app.test']);

    Http::fake(['*' => Http::response('{"ok":true}', 200)]);

    $this->postJson('/api-dock/try-it', [
        'method' => 'GET',
        'server' => 'https://{kurum}.congress-app.test/api',
        'path' => 'users',
        'server_variable_spec' => ['kurum' => ['enum' => ['acme'], 'default' => 'acme']],
    ])->assertOk()->assertJsonPath('url', 'https://acme.congress-app.test/api/users');

    Http::assertSent(static fn (ClientRequest $request): bool => $request->url() === 'https://acme.congress-app.test/api/users');
});

it('rejects an http method the client made up', function (string $method): void {
    Http::fake();

    $this->postJson('/api-dock/try-it', [
        'method' => $method,
        'url' => 'https://api.example.com/things',
    ])->assertStatus(422);

    Http::assertNothingSent();
})->with(['TRACE', 'CONNECT', 'anything']);

it('strips hop-by-hop and identity headers in both directions', function (): void {
    Http::fake(['*' => Http::response('{"ok":true}', 200, [
        'Set-Cookie' => 'upstream_session=leaked',
        'Transfer-Encoding' => 'chunked',
        'Proxy-Authenticate' => 'Basic',
        'X-Trace' => 'keep-me',
    ])]);

    $response = $this->postJson('/api-dock/try-it', [
        'method' => 'GET',
        'url' => 'https://api.example.com/things',
        'headers' => [
            'Host' => 'evil.internal',
            'Cookie' => 'app_session=dummy',
            'Connection' => 'keep-alive',
            'Transfer-Encoding' => 'chunked',
            'Proxy-Authorization' => 'Basic dummy',
            'X-Forwarded-For' => '10.0.0.1',
            'X-Custom' => 'kept',
        ],
    ]);

    $response->assertOk();

    $returnedHeaders = array_change_key_case((array) $response->json('headers'));

    expect($returnedHeaders)
        ->toHaveKey('x-trace')
        ->not->toHaveKey('set-cookie')
        ->not->toHaveKey('transfer-encoding')
        ->not->toHaveKey('proxy-authenticate');

    expect((string) $response->getContent())->not->toContain('upstream_session');

    Http::assertSent(static function (ClientRequest $request): bool {
        return $request->hasHeader('X-Custom')
            && ! $request->hasHeader('Cookie')
            && ! $request->hasHeader('Proxy-Authorization')
            && ! $request->hasHeader('X-Forwarded-For')
            && ! in_array('evil.internal', $request->header('Host'), true);
    });
});

it('keeps a stored credential out of the store read path and out of every response', function (): void {
    $store = app(AuthProfileStore::class);
    $sessionKey = Session::getId();

    $profile = $store->put($sessionKey, [
        'label' => 'Dummy profile',
        'base_url' => 'https://api.example.com',
        'scheme' => 'bearer',
        'credential' => DUMMY_CREDENTIAL,
    ]);

    expect($profile)->not->toHaveKey('credential')
        ->and($profile['credential_hint'])->toBe('****DDDD')
        ->and($store->find($sessionKey, $profile['id']))->not->toHaveKey('credential')
        ->and($store->all($sessionKey)[0])->not->toHaveKey('credential')
        ->and($store->revealCredentialForOutboundRequest($sessionKey, $profile['id']))->toBe(DUMMY_CREDENTIAL);

    Http::fake(['*' => Http::response('{"ok":true}', 200)]);

    $success = $this->postJson('/api-dock/try-it', [
        'method' => 'GET',
        'url' => 'https://api.example.com/things',
        'profile' => $profile['id'],
    ]);

    $success->assertOk();
    expect((string) $success->getContent())->not->toContain(DUMMY_CREDENTIAL);

    /* The credential does reach the upstream request, and only there. */
    Http::assertSent(static fn (ClientRequest $request): bool => $request->hasHeader('Authorization', 'Bearer '.DUMMY_CREDENTIAL));
});

/**
 * A separate test on purpose: Illuminate\Routing\Route caches the controller it
 * built, so a guard re-bound after the first request is never picked up.
 */
it('keeps a stored credential out of a refused request as well', function (): void {
    resolvesTo(['169.254.169.254']);

    $store = app(AuthProfileStore::class);
    $profile = $store->put(Session::getId(), [
        'scheme' => 'bearer',
        'credential' => DUMMY_CREDENTIAL,
    ]);

    Http::fake();

    $denied = $this->postJson('/api-dock/try-it', [
        'method' => 'GET',
        'url' => 'https://api.example.com/things',
        'profile' => $profile['id'],
    ]);

    $denied->assertStatus(422);
    expect((string) $denied->getContent())->not->toContain(DUMMY_CREDENTIAL);

    Http::assertNothingSent();
});

it('replaces a client supplied authorization header with the stored profile credential', function (): void {
    $store = app(AuthProfileStore::class);
    $profile = $store->put(Session::getId(), [
        'scheme' => 'bearer',
        'credential' => DUMMY_CREDENTIAL,
    ]);

    Http::fake(['*' => Http::response('{"ok":true}', 200)]);

    $this->postJson('/api-dock/try-it', [
        'method' => 'GET',
        'url' => 'https://api.example.com/things',
        'headers' => ['authorization' => 'Bearer client-supplied-dummy'],
        'profile' => $profile['id'],
    ])->assertOk();

    Http::assertSent(static fn (ClientRequest $request): bool => $request->header('Authorization') === ['Bearer '.DUMMY_CREDENTIAL]);
});

it('truncates an oversized response and flags it instead of streaming it back whole', function (): void {
    config()->set('api-dock.try_it.max_response_bytes', 64);

    Http::fake(['*' => Http::response(str_repeat('a', 5000), 200)]);

    $response = $this->postJson('/api-dock/try-it', [
        'method' => 'GET',
        'url' => 'https://api.example.com/things',
    ]);

    $response->assertOk()->assertJsonPath('truncated', true);

    expect(strlen((string) $response->json('body')))->toBe(64);
});

it('refuses a request body over the configured cap instead of shipping it upstream', function (): void {
    config()->set('api-dock.try_it.max_request_bytes', 64);

    Http::fake();

    $response = $this->postJson('/api-dock/try-it', [
        'method' => 'POST',
        'url' => 'https://api.example.com/things',
        'body' => ['payload' => str_repeat('a', 5000)],
    ]);

    $response->assertUnprocessable();

    expect((string) $response->json('message'))->toContain('exceeds');

    // The cap runs before the outbound call, not after it.
    Http::assertNothingSent();
});

it('answers with a placeholder instead of failing to encode a binary response body', function (): void {
    // A PNG signature: valid bytes, invalid UTF-8. json_encode() rejects it, so
    // without the guard's substitution this path leaves the proxy as a 500.
    Http::fake(['*' => Http::response("\x89PNG\r\n\x1a\n\xff\xd8\xff\xe0", 200)]);

    $response = $this->postJson('/api-dock/try-it', [
        'method' => 'GET',
        'url' => 'https://api.example.com/avatar.png',
    ]);

    $response->assertOk()->assertJsonPath('binary', true);

    expect((string) $response->json('body'))->toContain('binary response body');
});

it('keeps a text body readable when the byte cap severs its last codepoint', function (): void {
    // 'ü' is two bytes; the cap lands between them.
    config()->set('api-dock.try_it.max_response_bytes', 4);

    Http::fake(['*' => Http::response('abcü', 200)]);

    $response = $this->postJson('/api-dock/try-it', [
        'method' => 'GET',
        'url' => 'https://api.example.com/things',
    ]);

    $response->assertOk()
        ->assertJsonPath('truncated', true)
        // Trimmed back to a whole codepoint, not replaced: this body was text.
        ->assertJsonPath('binary', false)
        ->assertJsonPath('body', 'abc');
});

it('does not echo the query string of the proxied url back to the panel', function (): void {
    Http::fake(['*' => Http::response('{"ok":true}', 200)]);

    $response = $this->postJson('/api-dock/try-it', [
        'method' => 'GET',
        'url' => 'https://api.example.com/things',
        'query' => ['token' => 'dummy-query-value'],
    ]);

    $response->assertOk()->assertJsonPath('url', 'https://api.example.com/things');

    expect((string) $response->getContent())->not->toContain('dummy-query-value');
});

it('rejects a scheme that is not http or https', function (string $url): void {
    config()->set('api-dock.try_it.allowed_hosts', ['api.example.com', 'localhost']);

    Http::fake();

    $this->postJson('/api-dock/try-it', [
        'method' => 'GET',
        'url' => $url,
    ])->assertStatus(422);

    Http::assertNothingSent();
})->with([
    'gopher://api.example.com/1',
    'ftp://api.example.com/x',
    'https://user:dummy@api.example.com/x',
    'file://api.example.com/x',
]);
