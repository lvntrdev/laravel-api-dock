<?php

declare(strict_types=1);

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use LvntR\ApiDock\Support\AuthProfileStore;
use LvntR\ApiDock\Support\OutboundRequestGuard;

/**
 * Cover for the credential store: what it puts in the session, what it hands
 * back, how long it survives, and whose it is.
 *
 * The credential below is an obvious dummy. The whole point of these assertions
 * is that the literal string must appear in exactly two places — the encrypted
 * session value and the outbound Authorization header — and nowhere else.
 */
const API_DOCK_STORE_CREDENTIAL = 'dummy-token-1234';

/** Same masking rule as the store: four stars plus the last four characters. */
const API_DOCK_STORE_HINT = '****1234';

/**
 * Mirrors the store's own private session key. Nothing public exposes it, so a
 * storage assertion has to name it again here.
 */
const API_DOCK_STORE_SESSION_KEY = 'api-dock.try-it.profiles';

/**
 * A session identity for HTTP-level tests only: the store itself no longer
 * takes a session argument, so these two values now do their isolating work
 * purely as `Session::getName()` cookie values switched between requests.
 */
const API_DOCK_STORE_SESSION_A = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

const API_DOCK_STORE_SESSION_B = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

/**
 * Every key a masked profile may carry, and no other. Asserted as a set so a
 * field added beside the credential has to be added here on purpose.
 *
 * @var list<string>
 */
const API_DOCK_STORE_PROFILE_KEYS = [
    'id',
    'label',
    'base_url',
    'server_variables',
    'scheme',
    'credential_header',
    'credential_hint',
];

/**
 * @param  list<string>  $addresses
 */
function apiDockStoreResolvesTo(array $addresses): void
{
    app()->bind(OutboundRequestGuard::class, static fn ($app): OutboundRequestGuard => new OutboundRequestGuard(
        $app->make(HttpFactory::class),
        static fn (string $host): array => $addresses,
    ));
}

/**
 * Everything the session is actually holding for the CURRENT visitor, as a
 * string — the direct replacement for reading the old cache entry.
 */
function apiDockStoreRawEntry(): string
{
    return var_export(session()->get(API_DOCK_STORE_SESSION_KEY), true);
}

/**
 * A server variable map of $count well-formed entries: v0 => 0, v1 => 1, ...
 *
 * @return array<string, string>
 */
function apiDockStoreVariableMap(int $count): array
{
    $variables = [];

    for ($i = 0; $i < $count; $i++) {
        $variables['v'.$i] = (string) $i;
    }

    return $variables;
}

/**
 * Write a profile straight into the session in the record shape that existed
 * BEFORE server_variables did: no such key at all. Nothing but a hand-written
 * record can reproduce that, since the store always writes the field now.
 *
 * @param  array<string, mixed>  $overrides
 */
function apiDockStoreSeedLegacyProfile(string $id, array $overrides = []): void
{
    session()->put(API_DOCK_STORE_SESSION_KEY, [$id => array_merge([
        'id' => $id,
        'label' => 'Legacy',
        'base_url' => 'https://api.example.com',
        'scheme' => 'bearer',
        'credential_header' => null,
        'credential_hint' => API_DOCK_STORE_HINT,
        'credential' => app('encrypter')->encrypt(API_DOCK_STORE_CREDENTIAL),
    ], $overrides)]);
}

/**
 * Collect every log line written while the callback runs.
 *
 * @return list<string>
 */
function apiDockStoreCapturedLogs(Closure $callback): array
{
    $lines = [];

    Log::listen(static function (MessageLogged $logged) use (&$lines): void {
        $lines[] = $logged->message.' '.var_export($logged->context, true);
    });

    $callback();

    return $lines;
}

beforeEach(function (): void {
    config()->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
    config()->set('session.driver', 'array');
    config()->set('cache.default', 'array');
    config()->set('api-dock.try_it.enabled', true);
    config()->set('api-dock.try_it.allowed_hosts', ['api.example.com']);

    app()->forgetInstance('encrypter');

    Http::preventStrayRequests();

    apiDockStoreResolvesTo(['93.184.216.34']);

    // StartSession rebuilds the session id from the request cookie on every call
    // (Store::setId() falls back to a fresh random id when the cookie is absent),
    // so the identity these tests assert against has to travel in that cookie.
    // Setting it on the store instance alone is overwritten by the middleware,
    // which would give every request its own credential bucket.
    // withCredentials() is required as well: postJson()/getJson() send no cookies
    // at all without it, exactly like a fetch() that omits `credentials`.
    $this->withCredentials()->withUnencryptedCookie(Session::getName(), API_DOCK_STORE_SESSION_A);
});

/*
|--------------------------------------------------------------------------
| At rest
|--------------------------------------------------------------------------
*/

it('never writes the credential into the session in plaintext', function (): void {
    $store = app(AuthProfileStore::class);

    $profile = $store->put([
        'label' => 'Dummy profile',
        'scheme' => 'bearer',
        'credential' => API_DOCK_STORE_CREDENTIAL,
    ]);

    $raw = apiDockStoreRawEntry();

    expect($raw)->not->toBeEmpty()
        ->and($raw)->not->toContain(API_DOCK_STORE_CREDENTIAL)
        ->and($raw)->toContain($profile['id']);

    /** @var array<string, array<string, mixed>> $entry */
    $entry = session()->get(API_DOCK_STORE_SESSION_KEY);
    $ciphertext = $entry[$profile['id']]['credential'];

    expect($ciphertext)->toBeString()
        ->and($ciphertext)->not->toBe(API_DOCK_STORE_CREDENTIAL)
        ->and(app('encrypter')->decrypt($ciphertext))->toBe(API_DOCK_STORE_CREDENTIAL);
});

it('keeps every profile under the documented session key and not under the session id itself', function (): void {
    $store = app(AuthProfileStore::class);

    $store->put(['credential' => API_DOCK_STORE_CREDENTIAL]);

    expect(session()->get(API_DOCK_STORE_SESSION_KEY))->toBeArray()
        // A second, redundant scope keyed off the session id itself would be a
        // needless duplicate of the isolation the session already gives for
        // free — and a silent no-op the moment that id rotates.
        ->and(session()->has(Session::getId()))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Read paths
|--------------------------------------------------------------------------
*/

it('returns a masked profile from every ordinary read path', function (): void {
    $store = app(AuthProfileStore::class);

    $created = $store->put([
        'label' => 'Dummy profile',
        'base_url' => 'https://api.example.com',
        'scheme' => 'bearer',
        'credential' => API_DOCK_STORE_CREDENTIAL,
    ]);

    /** @var array<string, array<string, mixed>> $entry */
    $entry = session()->get(API_DOCK_STORE_SESSION_KEY);
    $ciphertext = (string) $entry[$created['id']]['credential'];

    $found = $store->find($created['id']);
    $listed = $store->all();

    foreach ([$created, $found, $listed[0]] as $view) {
        $encoded = (string) json_encode($view);

        expect($view)->not->toHaveKey('credential')
            ->and($view['credential_hint'])->toBe(API_DOCK_STORE_HINT)
            ->and($encoded)->not->toContain(API_DOCK_STORE_CREDENTIAL)
            ->and($encoded)->not->toContain($ciphertext);
    }

    // The one method whose name says it returns plaintext still does.
    expect($store->revealCredentialForOutboundRequest($created['id']))
        ->toBe(API_DOCK_STORE_CREDENTIAL);
});

it('keeps the credential and the ciphertext out of the profile index response', function (): void {
    $created = $this->postJson('/api-dock/try-it/profiles', [
        'label' => 'Dummy profile',
        'scheme' => 'bearer',
        'credential' => API_DOCK_STORE_CREDENTIAL,
    ]);

    $created->assertCreated()->assertJsonPath('profile.credential_hint', API_DOCK_STORE_HINT);

    /** @var array<string, array<string, mixed>> $entry */
    $entry = session()->get(API_DOCK_STORE_SESSION_KEY);
    $ciphertext = (string) $entry[(string) $created->json('profile.id')]['credential'];

    $index = $this->getJson('/api-dock/try-it/profiles');

    $index->assertOk();

    foreach ([$created, $index] as $response) {
        expect((string) $response->getContent())
            ->not->toContain(API_DOCK_STORE_CREDENTIAL)
            ->not->toContain($ciphertext);
    }
});

it('encodes an empty server variable map as a JSON object on every response', function (): void {
    // An empty PHP array encodes as `[]`, and a client that reads this field as a
    // map rejects the profile outright — which is how a stored credential turned
    // into a panel that looked like it had saved nothing at all.
    $created = $this->postJson('/api-dock/try-it/profiles', [
        'credential' => API_DOCK_STORE_CREDENTIAL,
    ]);

    $created->assertCreated();

    $index = $this->getJson('/api-dock/try-it/profiles');

    $index->assertOk();

    expect((string) $created->getContent())->toContain('"server_variables":{}')
        ->and((string) $index->getContent())->toContain('"server_variables":{}')
        ->and((string) $index->getContent())->not->toContain('"server_variables":[]');
});

it('still encodes a populated server variable map as an object', function (): void {
    $this->postJson('/api-dock/try-it/profiles', [
        'credential' => API_DOCK_STORE_CREDENTIAL,
        'server_variables' => ['tenant' => 'acme'],
    ])->assertCreated();

    $this->getJson('/api-dock/try-it/profiles')
        ->assertOk()
        ->assertJsonPath('profiles.0.server_variables.tenant', 'acme');
});

/*
|--------------------------------------------------------------------------
| Removal and capacity
|--------------------------------------------------------------------------
*/

it('caps the profiles one session can keep and evicts the oldest first', function (): void {
    config()->set('api-dock.try_it.max_profiles', 3);

    $store = app(AuthProfileStore::class);

    $ids = [];

    for ($i = 0; $i < 5; $i++) {
        $ids[] = $store->put(['credential' => API_DOCK_STORE_CREDENTIAL])['id'];
    }

    // Without the cap the bucket grows without bound, and it never shrinks
    // while a session keeps posting.
    expect($store->all())->toHaveCount(3)
        ->and($store->find($ids[0]))->toBeNull()
        ->and($store->find($ids[1]))->toBeNull()
        ->and($store->find($ids[4]))->not->toBeNull();
});

it('removes a profile with forget and every profile with flush', function (): void {
    $store = app(AuthProfileStore::class);

    $first = $store->put(['label' => 'One', 'credential' => API_DOCK_STORE_CREDENTIAL]);
    $second = $store->put(['label' => 'Two', 'credential' => API_DOCK_STORE_CREDENTIAL.'-2']);

    $store->forget($first['id']);

    expect($store->find($first['id']))->toBeNull()
        ->and($store->revealCredentialForOutboundRequest($first['id']))->toBeNull()
        ->and(apiDockStoreRawEntry())->not->toContain($first['id'])
        ->and($store->find($second['id']))->not->toBeNull();

    $store->flush();

    expect($store->all())->toBe([])
        ->and($store->revealCredentialForOutboundRequest($second['id']))->toBeNull()
        ->and(session()->has(API_DOCK_STORE_SESSION_KEY))->toBeFalse();
});

it('drops the session entry entirely once forget removes the last profile', function (): void {
    $store = app(AuthProfileStore::class);

    $profile = $store->put(['credential' => API_DOCK_STORE_CREDENTIAL]);

    $store->forget($profile['id']);

    expect(session()->has(API_DOCK_STORE_SESSION_KEY))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Session isolation
|--------------------------------------------------------------------------
*/

it('does not let one session read another session profile', function (): void {
    $store = app(AuthProfileStore::class);

    $profile = $store->put([
        'label' => 'Owned by this visitor',
        'credential' => API_DOCK_STORE_CREDENTIAL,
    ]);

    // Still readable before the switch, so the assertions below are isolation
    // and not simply a store that lost the profile.
    expect($store->find($profile['id']))->not->toBeNull();

    // The store no longer takes a session argument of its own to isolate on —
    // it trusts whatever session is current. Standing in for a second visitor
    // is therefore exactly what flushing the session is: the same thing a
    // logout does to the next reader.
    $this->flushSession();

    expect($store->find($profile['id']))->toBeNull()
        ->and($store->all())->toBe([])
        ->and($store->revealCredentialForOutboundRequest($profile['id']))->toBeNull();
});

it('does not let a second session delete a profile created under a different cookie', function (): void {
    $created = $this->postJson('/api-dock/try-it/profiles', [
        'credential' => API_DOCK_STORE_CREDENTIAL,
    ]);

    $created->assertCreated();

    $profileId = (string) $created->json('profile.id');

    // The array session driver in this test process reuses ONE Store instance
    // across every request it serves, and that Store merges rather than
    // replaces its attributes on each load — flushing before the swap is what
    // keeps the second cookie from inheriting the first cookie's in-memory
    // data. Two real requests never share a Store at all, so this step has no
    // counterpart in production.
    $this->flushSession()->withUnencryptedCookie(Session::getName(), API_DOCK_STORE_SESSION_B);

    // A delete against an id it doesn't own is a no-op, exactly like deleting
    // an id that never existed at all.
    $this->deleteJson('/api-dock/try-it/profiles/'.$profileId)->assertNoContent();

    $this->flushSession()->withUnencryptedCookie(Session::getName(), API_DOCK_STORE_SESSION_A);

    // Back on the first cookie the profile survived the other session's
    // attempt to remove it.
    $this->getJson('/api-dock/try-it/profiles')
        ->assertOk()
        ->assertJsonPath('profiles.0.id', $profileId);
});

it('does not let a second session use a profile id over http', function (): void {
    $created = $this->postJson('/api-dock/try-it/profiles', [
        'label' => 'Owned by the first visitor',
        'credential' => API_DOCK_STORE_CREDENTIAL,
    ]);

    $created->assertCreated();

    $profileId = (string) $created->json('profile.id');

    // See the flushSession() comment above: the flush is a test-process
    // artifact of reusing one Store across cookies, not something a real
    // second browser needs.
    $this->flushSession()->withUnencryptedCookie(Session::getName(), API_DOCK_STORE_SESSION_B);

    Http::fake();

    $index = $this->getJson('/api-dock/try-it/profiles');

    $index->assertOk()->assertJsonPath('profiles', []);

    $proxied = $this->postJson('/api-dock/try-it', [
        'method' => 'GET',
        'url' => 'https://api.example.com/things',
        'profile' => $profileId,
    ]);

    $proxied->assertNotFound();

    expect((string) $proxied->getContent())
        ->toContain('does not exist for this session')
        ->not->toContain(API_DOCK_STORE_CREDENTIAL);

    // Back on A's cookie the profile is still there. Without this the two
    // assertions above would also hold if the cookie were ignored and every
    // request got its own random id — that would be isolation proven by nothing.
    $this->flushSession()->withUnencryptedCookie(Session::getName(), API_DOCK_STORE_SESSION_A);

    $this->getJson('/api-dock/try-it/profiles')
        ->assertOk()
        ->assertJsonPath('profiles.0.id', $profileId);

    Http::assertNothingSent();
});

/*
|--------------------------------------------------------------------------
| The credential must not reach the client, on any path
|--------------------------------------------------------------------------
*/

it('sends the credential upstream and nowhere else on the success path', function (): void {
    $store = app(AuthProfileStore::class);

    $profile = $store->put([
        'scheme' => 'bearer',
        'credential' => API_DOCK_STORE_CREDENTIAL,
    ]);

    Http::fake(['*' => Http::response('{"ok":true}', 200)]);

    $logs = apiDockStoreCapturedLogs(function () use ($profile): void {
        $response = $this->postJson('/api-dock/try-it', [
            'method' => 'GET',
            'url' => 'https://api.example.com/things',
            'profile' => $profile['id'],
        ]);

        $response->assertOk();

        expect((string) $response->getContent())->not->toContain(API_DOCK_STORE_CREDENTIAL);
    });

    foreach ($logs as $line) {
        expect($line)->not->toContain(API_DOCK_STORE_CREDENTIAL);
    }

    Http::assertSent(static fn (ClientRequest $request): bool => $request->hasHeader(
        'Authorization',
        'Bearer '.API_DOCK_STORE_CREDENTIAL,
    ));
});

it('does not leak the credential when the profile id is unknown', function (): void {
    app(AuthProfileStore::class)->put(['credential' => API_DOCK_STORE_CREDENTIAL]);

    Http::fake();

    $response = $this->postJson('/api-dock/try-it', [
        'method' => 'GET',
        'url' => 'https://api.example.com/things',
        'profile' => str_repeat('f', 32),
    ]);

    $response->assertNotFound();

    expect((string) $response->getContent())->not->toContain(API_DOCK_STORE_CREDENTIAL);

    Http::assertNothingSent();
});

it('does not leak the credential while try-it is disabled', function (): void {
    $profile = app(AuthProfileStore::class)->put([
        'credential' => API_DOCK_STORE_CREDENTIAL,
    ]);

    config()->set('api-dock.try_it.enabled', false);

    Http::fake();

    $proxied = $this->postJson('/api-dock/try-it', [
        'method' => 'GET',
        'url' => 'https://api.example.com/things',
        'profile' => $profile['id'],
    ]);

    $index = $this->getJson('/api-dock/try-it/profiles');

    $proxied->assertForbidden();
    $index->assertForbidden();

    expect((string) $proxied->getContent())->not->toContain(API_DOCK_STORE_CREDENTIAL)
        ->and((string) $index->getContent())->not->toContain(API_DOCK_STORE_CREDENTIAL);

    Http::assertNothingSent();
});

it('does not leak the credential when the guard refuses the target', function (): void {
    apiDockStoreResolvesTo(['169.254.169.254']);

    $profile = app(AuthProfileStore::class)->put([
        'credential' => API_DOCK_STORE_CREDENTIAL,
    ]);

    Http::fake();

    $logs = apiDockStoreCapturedLogs(function () use ($profile): void {
        $response = $this->postJson('/api-dock/try-it', [
            'method' => 'GET',
            'url' => 'https://api.example.com/things',
            'profile' => $profile['id'],
        ]);

        $response->assertStatus(422);

        expect((string) $response->getContent())->not->toContain(API_DOCK_STORE_CREDENTIAL);
    });

    foreach ($logs as $line) {
        expect($line)->not->toContain(API_DOCK_STORE_CREDENTIAL);
    }

    Http::assertNothingSent();
});

it('does not leak the credential when the upstream transport fails', function (): void {
    $profile = app(AuthProfileStore::class)->put([
        'credential' => API_DOCK_STORE_CREDENTIAL,
    ]);

    // A transport error that quotes the request it was building is the realistic
    // shape here: cURL and Guzzle both do it. Nothing from that message may reach
    // the panel.
    Http::fake(static function (): void {
        throw new ConnectionException(
            'cURL error 56: failure while sending [Authorization: Bearer '.API_DOCK_STORE_CREDENTIAL.']',
        );
    });

    $logs = apiDockStoreCapturedLogs(function () use ($profile): void {
        $response = $this->postJson('/api-dock/try-it', [
            'method' => 'GET',
            'url' => 'https://api.example.com/things',
            'profile' => $profile['id'],
        ]);

        $response->assertStatus(422);

        expect((string) $response->getContent())
            // Only the exception class survives, never the message it carried.
            ->toContain('ConnectionException')
            ->not->toContain(API_DOCK_STORE_CREDENTIAL)
            ->not->toContain('cURL error 56');
    });

    foreach ($logs as $line) {
        expect($line)->not->toContain(API_DOCK_STORE_CREDENTIAL);
    }
});

it('does not leak the credential when the stored ciphertext cannot be decrypted', function (): void {
    $store = app(AuthProfileStore::class);

    $profile = $store->put(['credential' => API_DOCK_STORE_CREDENTIAL]);

    /** @var array<string, array<string, mixed>> $entry */
    $entry = session()->get(API_DOCK_STORE_SESSION_KEY);
    $entry[$profile['id']]['credential'] = 'not-a-valid-payload';
    session()->put(API_DOCK_STORE_SESSION_KEY, $entry);

    expect($store->revealCredentialForOutboundRequest($profile['id']))->toBeNull();

    Http::fake();

    $response = $this->postJson('/api-dock/try-it', [
        'method' => 'GET',
        'url' => 'https://api.example.com/things',
        'profile' => $profile['id'],
    ]);

    $response->assertStatus(422);

    expect((string) $response->getContent())
        ->toContain('could not be read')
        ->not->toContain(API_DOCK_STORE_CREDENTIAL);

    Http::assertNothingSent();
});

it('does not leak the credential through a store rejection message', function (): void {
    $store = app(AuthProfileStore::class);

    $rejected = $this->postJson('/api-dock/try-it/profiles', [
        'scheme' => 'not-a-scheme',
        'credential' => API_DOCK_STORE_CREDENTIAL,
    ]);

    $rejected->assertStatus(422);

    $headerRejected = $this->postJson('/api-dock/try-it/profiles', [
        'scheme' => 'header',
        'credential_header' => 'Cookie',
        'credential' => API_DOCK_STORE_CREDENTIAL,
    ]);

    $headerRejected->assertStatus(422);

    foreach ([$rejected, $headerRejected] as $response) {
        expect((string) $response->getContent())->not->toContain(API_DOCK_STORE_CREDENTIAL);
    }

    expect($store->all())->toBe([]);
});

it('removes the profile over http and stops honouring it', function (): void {
    $created = $this->postJson('/api-dock/try-it/profiles', [
        'credential' => API_DOCK_STORE_CREDENTIAL,
    ]);

    $created->assertCreated();

    $profileId = (string) $created->json('profile.id');

    $this->deleteJson('/api-dock/try-it/profiles/'.$profileId)->assertNoContent();

    expect(session()->has(API_DOCK_STORE_SESSION_KEY))->toBeFalse();

    Http::fake();

    $this->postJson('/api-dock/try-it', [
        'method' => 'GET',
        'url' => 'https://api.example.com/things',
        'profile' => $profileId,
    ])->assertNotFound();

    Http::assertNothingSent();
});

/*
|--------------------------------------------------------------------------
| Server variables — non-secret profile data added beside the credential
|--------------------------------------------------------------------------
*/

it('round-trips server variables through every masked read path', function (): void {
    $store = app(AuthProfileStore::class);

    $variables = ['region' => 'eu-west', 'api.version' => 'v2'];

    $created = $store->put([
        'label' => 'Dummy profile',
        'scheme' => 'bearer',
        'credential' => API_DOCK_STORE_CREDENTIAL,
        'server_variables' => $variables,
    ]);

    /** @var array<string, array<string, mixed>> $entry */
    $entry = session()->get(API_DOCK_STORE_SESSION_KEY);
    $ciphertext = (string) $entry[$created['id']]['credential'];

    $found = $store->find($created['id']);
    $listed = $store->all();

    foreach ([$created, $found, $listed[0]] as $view) {
        $encoded = (string) json_encode($view);

        // The serialized shape is an allow-list, not a blocklist: pinning the
        // exact key set is what makes a FUTURE field fail this test instead of
        // riding out to the panel unnoticed, the way `server_variables` could
        // have.
        expect(array_keys((array) $view))->toEqualCanonicalizing(API_DOCK_STORE_PROFILE_KEYS)
            ->and($view['server_variables'] ?? null)->toBe($variables)
            ->and($encoded)->not->toContain(API_DOCK_STORE_CREDENTIAL)
            ->and($encoded)->not->toContain($ciphertext);
    }
});

it('keeps the credential out of both endpoints once a profile carries server variables', function (): void {
    $created = $this->postJson('/api-dock/try-it/profiles', [
        'label' => 'Dummy profile',
        'scheme' => 'bearer',
        'credential' => API_DOCK_STORE_CREDENTIAL,
        'server_variables' => ['region' => 'eu-west', 'api.version' => 'v2'],
    ]);

    $created->assertCreated()
        ->assertJsonPath('profile.credential_hint', API_DOCK_STORE_HINT)
        ->assertJsonPath('profile.server_variables', ['region' => 'eu-west', 'api.version' => 'v2']);

    /** @var array<string, array<string, mixed>> $entry */
    $entry = session()->get(API_DOCK_STORE_SESSION_KEY);
    $ciphertext = (string) $entry[(string) $created->json('profile.id')]['credential'];

    $index = $this->getJson('/api-dock/try-it/profiles');

    $index->assertOk()->assertJsonPath('profiles.0.server_variables', ['region' => 'eu-west', 'api.version' => 'v2']);

    expect(array_keys((array) $created->json('profile')))->toEqualCanonicalizing(API_DOCK_STORE_PROFILE_KEYS)
        ->and(array_keys((array) $index->json('profiles.0')))->toEqualCanonicalizing(API_DOCK_STORE_PROFILE_KEYS);

    foreach ([$created, $index] as $response) {
        expect((string) $response->getContent())
            ->not->toContain(API_DOCK_STORE_CREDENTIAL)
            ->not->toContain($ciphertext);
    }
});

it('drops the server variables the store cannot bound', function (): void {
    $store = app(AuthProfileStore::class);

    $created = $store->put([
        'credential' => API_DOCK_STORE_CREDENTIAL,
        'server_variables' => [
            '  region  ' => '  eu-west  ',
            '' => 'a name of nothing',
            '   ' => 'a name of whitespace',
            'toolong' => str_repeat('x', AuthProfileStore::MAX_SERVER_VARIABLE_LENGTH + 1),
            str_repeat('k', AuthProfileStore::MAX_SERVER_VARIABLE_LENGTH + 1) => 'a name past the ceiling',
            'count' => 42,
            'flag' => true,
            'nested' => ['no'],
            'nulled' => null,
        ],
    ]);

    // Direct store calls are trusted-caller territory and drop silently; the
    // HTTP surface below turns the same violations into a visible 422.
    expect($created['server_variables'])->toBe([
        'region' => 'eu-west',
        'count' => '42',
        'flag' => 'true',
    ]);
});

it('caps how many server variables one profile can keep', function (): void {
    $store = app(AuthProfileStore::class);

    $created = $store->put([
        'credential' => API_DOCK_STORE_CREDENTIAL,
        'server_variables' => apiDockStoreVariableMap(AuthProfileStore::MAX_SERVER_VARIABLES * 2),
    ]);

    expect($created['server_variables'])->toHaveCount(AuthProfileStore::MAX_SERVER_VARIABLES)
        ->and(array_key_first($created['server_variables']))->toBe('v0')
        ->and(array_key_last($created['server_variables']))->toBe('v'.(AuthProfileStore::MAX_SERVER_VARIABLES - 1));
});

it('refuses a malformed server variable map over http instead of dropping it silently', function (mixed $map, string $errorKey): void {
    $store = app(AuthProfileStore::class);

    $response = $this->postJson('/api-dock/try-it/profiles', [
        'credential' => API_DOCK_STORE_CREDENTIAL,
        'server_variables' => $map,
    ]);

    $response->assertStatus(422)->assertJsonValidationErrors($errorKey);

    // The rejected request carried the credential; nothing read out of it — the
    // offending name included — may be echoed back in the message.
    expect((string) $response->getContent())->not->toContain(API_DOCK_STORE_CREDENTIAL)
        ->and($store->all())->toBe([]);
})->with([
    'more entries than the cap' => [
        apiDockStoreVariableMap(AuthProfileStore::MAX_SERVER_VARIABLES + 1),
        'server_variables',
    ],
    'a value past the ceiling' => [
        ['region' => str_repeat('x', AuthProfileStore::MAX_SERVER_VARIABLE_LENGTH + 1)],
        'server_variables.region',
    ],
    'a name past the ceiling' => [
        [str_repeat('k', AuthProfileStore::MAX_SERVER_VARIABLE_LENGTH + 1) => 'eu-west'],
        'server_variables',
    ],
    'an empty name' => [['' => 'eu-west'], 'server_variables'],
    'a name with a space' => [['data centre' => 'eu-west'], 'server_variables'],
    // `\z` and not `$`: a trailing newline must not sneak past the anchor.
    'a name with a trailing newline' => [["region\n" => 'eu-west'], 'server_variables'],
    'a name with a brace' => [['{region}' => 'eu-west'], 'server_variables'],
    'a non-string value' => [['count' => 42], 'server_variables.count'],
    'a nested value' => [['region' => ['eu-west']], 'server_variables.region'],
    'a map that is not a map at all' => ['region=eu-west', 'server_variables'],
]);

it('accepts every server variable name a server template can match', function (): void {
    // The same character class as OutboundRequestGuard::substituteServerTemplate()
    // — a dotted name like `api.version` is a real placeholder, not a typo.
    $variables = ['api.version' => 'v2', 'data-centre' => 'eu-west', 'tenant_id' => '42', 'v2' => 'x'];

    $created = $this->postJson('/api-dock/try-it/profiles', [
        'credential' => API_DOCK_STORE_CREDENTIAL,
        'server_variables' => $variables,
    ]);

    $created->assertCreated()->assertJsonPath('profile.server_variables', $variables);
});

it('stores no entry for a server variable the rules let through as null', function (): void {
    $created = $this->postJson('/api-dock/try-it/profiles', [
        'credential' => API_DOCK_STORE_CREDENTIAL,
        'server_variables' => ['region' => null, 'api.version' => 'v2'],
    ]);

    $created->assertCreated()->assertJsonPath('profile.server_variables', ['api.version' => 'v2']);
});

it('lists a profile written before server variables existed', function (): void {
    $store = app(AuthProfileStore::class);

    apiDockStoreSeedLegacyProfile('legacyid');

    $found = $store->find('legacyid');

    expect($found)->not->toBeNull()
        ->and($found['server_variables'] ?? null)->toBe([])
        ->and(array_keys((array) $found))->toEqualCanonicalizing(API_DOCK_STORE_PROFILE_KEYS)
        ->and($store->all()[0]['server_variables'])->toBe([])
        // The record still decrypts: this is a missing key, not a broken profile.
        ->and($store->revealCredentialForOutboundRequest('legacyid'))
        ->toBe(API_DOCK_STORE_CREDENTIAL);

    $index = $this->getJson('/api-dock/try-it/profiles');

    $index->assertOk()->assertJsonPath('profiles.0.server_variables', []);

    expect((string) $index->getContent())->not->toContain(API_DOCK_STORE_CREDENTIAL);
});

it('ignores a server variables value in the session that is not a map', function (): void {
    $store = app(AuthProfileStore::class);

    apiDockStoreSeedLegacyProfile('poisonid', ['server_variables' => 'not-a-map']);

    $found = $store->find('poisonid');

    expect($found['server_variables'] ?? null)->toBe([]);
});

it('caps the profiles a session can keep once the record carries server variables', function (): void {
    config()->set('api-dock.try_it.max_profiles', 3);

    $store = app(AuthProfileStore::class);

    $ids = [];

    for ($i = 0; $i < 5; $i++) {
        $ids[] = $store->put([
            'credential' => API_DOCK_STORE_CREDENTIAL,
            'server_variables' => ['region' => 'eu-west-'.$i],
        ])['id'];
    }

    $listed = $store->all();

    // The surviving records keep their OWN maps: array_slice must carry the
    // values across, not just the ids.
    expect($listed)->toHaveCount(3)
        ->and($store->find($ids[0]))->toBeNull()
        ->and($store->find($ids[1]))->toBeNull()
        ->and(array_column($listed, 'server_variables'))->toBe([
            ['region' => 'eu-west-2'],
            ['region' => 'eu-west-3'],
            ['region' => 'eu-west-4'],
        ]);
});

/*
|--------------------------------------------------------------------------
| Lifetime — the session's own, no ttl of its own
|--------------------------------------------------------------------------
*/

it('ships without a try-it ttl key so the lifetime follows the session', function (): void {
    // The shipped config, not a test override: the key is gone entirely, not
    // merely unset, which is the regression this guards against — a config
    // key left behind invites someone to wire it back up as a real ttl.
    expect(config('api-dock.try_it'))->not->toHaveKey('ttl');
});

it('keeps a profile alive across an arbitrary amount of travelled time, and flush is what finally clears it', function (): void {
    $store = app(AuthProfileStore::class);

    $profile = $store->put(['credential' => API_DOCK_STORE_CREDENTIAL]);

    // No ttl to outlive: a week is no different from a second to a store that
    // has nothing of its own to expire. Dead under the old hard-coded hour,
    // alive under the session's own lifetime.
    $this->travel(7)->days();

    expect($store->find($profile['id']))->not->toBeNull()
        ->and($store->revealCredentialForOutboundRequest($profile['id']))->toBe(API_DOCK_STORE_CREDENTIAL);

    $store->flush();

    expect($store->find($profile['id']))->toBeNull()
        ->and($store->all())->toBe([]);
});

it('drops a server variable whose value is empty after trimming', function (): void {
    $store = app(AuthProfileStore::class);

    $profile = $store->put([
        'credential' => API_DOCK_STORE_CREDENTIAL,
        'server_variables' => ['tenant' => '  ', 'region' => 'eu-west'],
    ]);

    // An empty value is not "the tenant is blank": the substitution reads it as absent
    // and falls back to the spec default, so storing it would point the outbound request
    // at a host the panel renders as empty.
    expect($profile['server_variables'])->toBe(['region' => 'eu-west']);
});
