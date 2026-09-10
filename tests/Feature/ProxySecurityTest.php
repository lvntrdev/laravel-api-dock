<?php

declare(strict_types=1);

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use LvntR\ApiDock\Support\OutboundRequestGuard;

/**
 * Regression cover for the outbound boundary, written against the *observable*
 * behaviour of the try-it route rather than against the guard's internals.
 *
 * Two rules hold for every rejection case in this file:
 *
 *  1. the HTTP client is faked and stray requests are prevented, so no assertion
 *     here can reach — or accidentally depend on — the real network;
 *  2. a refusal is only a refusal if the request never left the process, so the
 *     status assertion is always paired with Http::assertNothingSent(). A 422
 *     returned *after* the packet went out would be a complete failure of the
 *     boundary, and only the second assertion can tell the two apart.
 */

/** Documentation-range address; stands in for "an ordinary public host". */
const API_DOCK_SECURITY_PUBLIC_IP = '93.184.216.34';

/**
 * Rebind the guard with a fixed resolver.
 *
 * Must be called before the first request of a test: Illuminate\Routing\Route
 * caches the controller instance it builds, so a guard rebound afterwards is
 * never picked up. Every case below therefore lives in its own it() block.
 *
 * @param  list<string>  $addresses
 */
function apiDockSecurityResolvesTo(array $addresses): void
{
    app()->bind(OutboundRequestGuard::class, static fn ($app): OutboundRequestGuard => new OutboundRequestGuard(
        $app->make(HttpFactory::class),
        static fn (string $host): array => $addresses,
    ));
}

/**
 * Resolver that answers PER HOST, so a subdomain can point somewhere its parent
 * does not — the whole question the self-host address gate has to decide. A
 * host the map does not name resolves to nothing and is denied on that alone.
 *
 * @param  array<string, list<string>>  $map
 */
function apiDockSecurityResolvesPerHost(array $map): void
{
    app()->bind(OutboundRequestGuard::class, static fn ($app): OutboundRequestGuard => new OutboundRequestGuard(
        $app->make(HttpFactory::class),
        static fn (string $host): array => $map[$host] ?? [],
    ));
}

/** Resolver that answers with the host itself, for IP-literal targets. */
function apiDockSecurityResolvesToItself(): void
{
    app()->bind(OutboundRequestGuard::class, static fn ($app): OutboundRequestGuard => new OutboundRequestGuard(
        $app->make(HttpFactory::class),
        static fn (string $host): array => [$host],
    ));
}

beforeEach(function (): void {
    config()->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
    config()->set('session.driver', 'array');
    config()->set('cache.default', 'array');
    config()->set('api-dock.try_it.enabled', true);
    config()->set('api-dock.try_it.allowed_hosts', ['api.example.com']);

    app()->forgetInstance('encrypter');

    // Fail loudly rather than dial out if a test ever forgets to fake.
    Http::preventStrayRequests();

    apiDockSecurityResolvesTo([API_DOCK_SECURITY_PUBLIC_IP]);
});

/*
|--------------------------------------------------------------------------
| Address classes
|--------------------------------------------------------------------------
*/

it('does not send a request to an allowlisted host that resolves into a blocked range', function (string $address): void {
    apiDockSecurityResolvesTo([$address]);

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
    'loopback v4, non canonical' => '127.99.12.5',
    'loopback v6' => '::1',
    'private 10/8' => '10.0.0.7',
    'private 172.16/12 low' => '172.16.0.1',
    'private 172.16/12 high' => '172.31.255.254',
    'private 192.168/16' => '192.168.1.1',
    'unique local fc00::/7' => 'fc00::1',
    'unique local fd00::/8' => 'fd12:3456:789a::1',
    'link local v4 169.254/16' => '169.254.10.1',
    'link local v6 fe80::/10' => 'fe80::1',
    'cloud metadata v4' => '169.254.169.254',
    'cloud metadata v6' => 'fd00:ec2::254',
    'v4 mapped v6 loopback' => '::ffff:127.0.0.1',
    'v4 mapped v6 metadata' => '::ffff:169.254.169.254',
    'v4 mapped v6 private' => '::ffff:10.0.0.1',
]);

it('does not follow a hostname whose dns answer points at loopback', function (): void {
    // The allowlist is satisfied and the name looks entirely ordinary; only the
    // resolved address gives it away. This is the DNS-rebind shape.
    apiDockSecurityResolvesTo(['127.0.0.1']);

    Http::fake();

    $response = $this->postJson('/api-dock/try-it', [
        'method' => 'GET',
        'url' => 'https://api.example.com/things',
    ]);

    $response->assertStatus(422);

    expect((string) $response->json('message'))->toContain('may not be reached');

    Http::assertNothingSent();
});

it('does not send a request to a blocked address written as an ip literal', function (string $url, string $host): void {
    // The literal is put on the allowlist on purpose, so what refuses the request
    // is the address-class check and not the allowlist standing in front of it.
    config()->set('api-dock.try_it.allowed_hosts', [$host]);

    apiDockSecurityResolvesToItself();

    Http::fake();

    $this->postJson('/api-dock/try-it', [
        'method' => 'GET',
        'url' => $url,
    ])->assertStatus(422);

    Http::assertNothingSent();
})->with([
    'loopback v4' => ['http://127.0.0.1/x', '127.0.0.1'],
    'loopback v6' => ['http://[::1]/x', '::1'],
    'v4 mapped v6 loopback' => ['http://[::ffff:127.0.0.1]/x', '::ffff:127.0.0.1'],
    'private 10/8' => ['http://10.1.2.3/x', '10.1.2.3'],
    'private 172.16/12' => ['http://172.16.5.9/x', '172.16.5.9'],
    'private 192.168/16' => ['http://192.168.1.1/x', '192.168.1.1'],
    'unique local v6' => ['http://[fc00::1]/x', 'fc00::1'],
    'link local v6' => ['http://[fe80::1]/x', 'fe80::1'],
    'cloud metadata' => ['http://169.254.169.254/latest/meta-data/', '169.254.169.254'],
]);

it('fails closed when only one of several resolved addresses is blocked', function (): void {
    // All-or-nothing: a host that answers with one good address and one bad one
    // must be refused outright, not raced or partially trusted.
    apiDockSecurityResolvesTo([API_DOCK_SECURITY_PUBLIC_IP, '169.254.169.254']);

    Http::fake();

    $this->postJson('/api-dock/try-it', [
        'method' => 'GET',
        'url' => 'https://api.example.com/things',
    ])->assertStatus(422);

    Http::assertNothingSent();
});

it('fails closed when the blocked address is last in a long answer', function (): void {
    apiDockSecurityResolvesTo([
        API_DOCK_SECURITY_PUBLIC_IP,
        '93.184.216.35',
        '2606:2800:220:1::1',
        '127.0.0.1',
    ]);

    Http::fake();

    $this->postJson('/api-dock/try-it', [
        'method' => 'GET',
        'url' => 'https://api.example.com/things',
    ])->assertStatus(422);

    Http::assertNothingSent();
});

/*
|--------------------------------------------------------------------------
| Scheme and authority
|--------------------------------------------------------------------------
*/

it('does not send a request for a scheme other than http or https', function (string $url): void {
    config()->set('api-dock.try_it.allowed_hosts', ['api.example.com']);

    Http::fake();

    $this->postJson('/api-dock/try-it', [
        'method' => 'GET',
        'url' => $url,
    ])->assertStatus(422);

    Http::assertNothingSent();
})->with([
    'file' => 'file:///etc/passwd',
    'gopher' => 'gopher://api.example.com/1',
    'ftp' => 'ftp://api.example.com/x',
    'dict' => 'dict://api.example.com:11211/stat',
    'data' => 'data:text/plain,hello',
    'schemeless' => '//api.example.com/x',
    'credentials in authority' => 'https://user:dummy-pass-1234@api.example.com/x',
]);

/*
|--------------------------------------------------------------------------
| Allowlist
|--------------------------------------------------------------------------
*/

it('denies an ordinary public host while the allowlist is empty', function (): void {
    // Deny by default. "Empty means allow everything" would make this package a
    // general purpose SSRF tool for anyone who can open the docs page.
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

it('denies a host that is absent from a populated allowlist', function (): void {
    config()->set('api-dock.try_it.allowed_hosts', ['api.example.com', 'api.other.test']);

    Http::fake();

    $this->postJson('/api-dock/try-it', [
        'method' => 'GET',
        'url' => 'https://api.third.test/things',
    ])->assertStatus(422);

    Http::assertNothingSent();
});

it('never lets a bare allowlist entry be satisfied by a near miss', function (string $host): void {
    config()->set('api-dock.try_it.allowed_hosts', ['example.com']);

    Http::fake();

    $this->postJson('/api-dock/try-it', [
        'method' => 'GET',
        'url' => 'https://'.$host.'/things',
    ])->assertStatus(422);

    Http::assertNothingSent();
})->with([
    'prefix collision' => 'evil-example.com',
    'suffix collision' => 'example.com.attacker.test',
    'subdomain without the leading dot entry' => 'api.example.com',
    'label collision' => 'notexample.com',
]);

it('lets a leading dot entry admit a subdomain', function (): void {
    config()->set('api-dock.try_it.allowed_hosts', ['.example.com']);

    Http::fake(['*' => Http::response('{"ok":true}', 200)]);

    $this->postJson('/api-dock/try-it', [
        'method' => 'GET',
        'url' => 'https://api.example.com/things',
    ])->assertOk()->assertJsonPath('status', 200);

    Http::assertSent(static fn (ClientRequest $request): bool => $request->url() === 'https://api.example.com/things');
});

it('reaches the application own host with no allowlist entry at all', function (): void {
    // Zero config: the docs are served BY this host, so trying its own endpoints
    // is not an outbound reach anyone has to authorise.
    config()->set('api-dock.try_it.allowed_hosts', []);
    config()->set('app.url', 'https://congress-app.test');

    // Herd and every other local stack answer on loopback; refusing that would
    // make try-it useless exactly where it is used most.
    apiDockSecurityResolvesTo(['127.0.0.1']);

    Http::fake(['*' => Http::response('{"ok":true}', 200)]);

    $this->postJson('/api-dock/try-it', [
        'method' => 'GET',
        'url' => 'https://congress-app.test/api/things',
    ])->assertOk();
});

it('reaches a tenant subdomain of the application host with no allowlist entry', function (): void {
    config()->set('api-dock.try_it.allowed_hosts', []);
    config()->set('app.url', 'https://congress-app.test');

    // Was a single resolver answering 127.0.0.1 for every host, which made the
    // tenant share the app's address by accident. Spelled out now, because that
    // sharing is precisely what earns the exemption.
    apiDockSecurityResolvesPerHost([
        'congress-app.test' => ['127.0.0.1'],
        'test-kurum.congress-app.test' => ['127.0.0.1'],
    ]);

    Http::fake(['*' => Http::response('{"ok":true}', 200)]);

    $this->postJson('/api-dock/try-it', [
        'method' => 'GET',
        'url' => 'https://test-kurum.congress-app.test/api/things',
    ])->assertOk();
});

it('reaches a tenant subdomain that answers at the application own address', function (): void {
    config()->set('api-dock.try_it.allowed_hosts', []);
    config()->set('app.url', 'https://example.com');

    // Same server, second label: the tenant is this application, so it keeps the
    // exemption even at an address the class gate would otherwise refuse.
    apiDockSecurityResolvesPerHost([
        'example.com' => ['203.0.113.10'],
        'tenant.example.com' => ['203.0.113.10'],
    ]);

    Http::fake(['*' => Http::response('{"ok":true}', 200)]);

    $this->postJson('/api-dock/try-it', [
        'method' => 'GET',
        'url' => 'https://tenant.example.com/api/things',
    ])->assertOk()->assertJsonPath('status', 200);

    Http::assertSent(static fn (ClientRequest $request): bool => $request->url() === 'https://tenant.example.com/api/things');
});

it('does not let a subdomain of the application host reach an internal address', function (): void {
    config()->set('api-dock.try_it.allowed_hosts', []);
    config()->set('app.url', 'https://example.com');

    // The suffix is the app's; the address is not. Anyone who can publish one
    // record under the apex would otherwise have had the whole private range,
    // which is the reach the exemption was never meant to hand out.
    apiDockSecurityResolvesPerHost([
        'example.com' => ['203.0.113.10'],
        'internal.example.com' => ['10.0.0.5'],
    ]);

    Http::fake();

    $response = $this->postJson('/api-dock/try-it', [
        'method' => 'GET',
        'url' => 'https://internal.example.com/things',
    ]);

    $response->assertStatus(422);

    // The refusal names the host; quoting the address back would answer the
    // internal-network question the boundary just declined to answer.
    expect((string) $response->json('message'))
        ->toContain('internal.example.com')
        ->not->toContain('10.0.0.5');

    Http::assertNothingSent();
});

it('does not exempt a subdomain that answers at the application address and one more', function (): void {
    config()->set('api-dock.try_it.allowed_hosts', []);
    config()->set('app.url', 'https://example.com');

    // A superset is not the same set. With one shared record accepted as proof,
    // a second A record pointing inward would ride in beside it — and cURL is
    // pinned to BOTH addresses, so the internal one is genuinely reachable.
    apiDockSecurityResolvesPerHost([
        'example.com' => ['203.0.113.10'],
        'tenant.example.com' => ['203.0.113.10', '10.0.0.5'],
    ]);

    Http::fake();

    $this->postJson('/api-dock/try-it', [
        'method' => 'GET',
        'url' => 'https://tenant.example.com/things',
    ])->assertStatus(422);

    Http::assertNothingSent();
});

it('reaches a tenant subdomain of a loopback application host', function (): void {
    config()->set('api-dock.try_it.allowed_hosts', []);
    config()->set('app.url', 'http://localhost:8000');

    // The local-stack shape, and the one the narrowing must not break: both
    // names are loopback, `.localhost` is on the internal-suffix list, and the
    // port comes from the entry. Only the shared address makes it self.
    apiDockSecurityResolvesPerHost([
        'localhost' => ['127.0.0.1'],
        'tenant.localhost' => ['127.0.0.1'],
    ]);

    Http::fake(['*' => Http::response('{"ok":true}', 200)]);

    $this->postJson('/api-dock/try-it', [
        'method' => 'GET',
        'url' => 'http://tenant.localhost:8000/api/things',
    ])->assertOk()->assertJsonPath('status', 200);

    Http::assertSent(static fn (ClientRequest $request): bool => $request->url() === 'http://tenant.localhost:8000/api/things');
});

it('gives an address literal self entry no descendants', function (): void {
    config()->set('api-dock.try_it.allowed_hosts', []);
    config()->set('app.url', 'https://congress-app.test');
    config()->set('api-dock.try_it.self_hosts', ['10.0.0.5']);

    // The entry is dropped before any matching happens, so the name below is a
    // foreign host — not a descendant whose addresses get compared to it.
    apiDockSecurityResolvesPerHost([
        '10.0.0.5' => ['10.0.0.5'],
        'internal.10.0.0.5' => ['10.0.0.5'],
    ]);

    Http::fake();

    $this->postJson('/api-dock/try-it', [
        'method' => 'GET',
        'url' => 'https://internal.10.0.0.5/things',
    ])->assertStatus(422);

    Http::assertNothingSent();
});

it('still denies a foreign host that merely resembles the application host', function (): void {
    config()->set('api-dock.try_it.allowed_hosts', []);
    config()->set('app.url', 'https://congress-app.test');

    Http::fake();

    $this->postJson('/api-dock/try-it', [
        'method' => 'GET',
        'url' => 'https://evil-congress-app.test/things',
    ])->assertStatus(422);

    Http::assertNothingSent();
});

it('does not let the self host exemption reach an unrelated private address', function (): void {
    config()->set('api-dock.try_it.allowed_hosts', []);
    config()->set('app.url', 'https://congress-app.test');

    apiDockSecurityResolvesTo(['169.254.169.254']);

    Http::fake();

    // The exemption follows the NAME, not the address class: a host that is not
    // this application is still measured against the address gate.
    $this->postJson('/api-dock/try-it', [
        'method' => 'GET',
        'url' => 'https://metadata.example.net/latest/meta-data/',
    ])->assertStatus(422);

    Http::assertNothingSent();
});

it('does not treat a forged host header as this application', function (): void {
    config()->set('api-dock.try_it.allowed_hosts', []);
    config()->set('app.url', 'https://congress-app.test');

    apiDockSecurityResolvesToItself();

    Http::fake();

    // The forged host has to arrive through the request URI, not through a Host
    // header: Symfony rebuilds HTTP_HOST from the URI it is handed, so a header
    // passed to postJson() is overwritten with `localhost` and the spoof never
    // happens — leaving a green test that proves nothing.
    $response = $this->postJson('http://169.254.169.254/api-dock/try-it', [
        'method' => 'GET',
        'url' => 'http://169.254.169.254/latest/meta-data/',
    ]);

    // Asserted first, because it is what makes the case above a test at all: the
    // request the pipeline saw really did name the metadata endpoint as its own
    // host, which is exactly the value the self check must not consult.
    expect(app('request')->getHost())->toBe('169.254.169.254');

    $response->assertStatus(422);

    expect((string) $response->json('message'))->toContain('allowlist');

    Http::assertNothingSent();
});

it('does not let a forged host header widen the allowlist to its subdomains', function (): void {
    config()->set('api-dock.try_it.allowed_hosts', []);
    config()->set('app.url', 'https://congress-app.test');

    Http::fake();

    // The second shape of the same attack: rather than naming the target host
    // outright, the forged host names its parent and rides the subdomain rule.
    $response = $this->postJson('http://attacker.test/api-dock/try-it', [
        'method' => 'GET',
        'url' => 'https://internal.attacker.test/things',
    ]);

    expect(app('request')->getHost())->toBe('attacker.test');

    $response->assertStatus(422);

    Http::assertNothingSent();
});

it('denies the parent domain of the application host', function (): void {
    config()->set('api-dock.try_it.allowed_hosts', []);
    config()->set('app.url', 'https://docs.congress-app.test');

    Http::fake();

    // The self match runs in one direction only. Serving the docs from a
    // subdomain must not exempt the apex, and with it every sibling hanging off
    // it — that reverse form was a hole, not a convenience.
    $this->postJson('/api-dock/try-it', [
        'method' => 'GET',
        'url' => 'https://congress-app.test/api/things',
    ])->assertStatus(422);

    Http::assertNothingSent();
});

it('reaches a host the operator declared in the self hosts config', function (): void {
    config()->set('api-dock.try_it.allowed_hosts', []);
    config()->set('api-dock.try_it.self_hosts', ['ikinci-alan.test']);

    apiDockSecurityResolvesTo(['127.0.0.1']);

    Http::fake(['*' => Http::response('{"ok":true}', 200)]);

    // A deployment answering on a second domain names it in config, server side,
    // instead of the request naming itself.
    $this->postJson('/api-dock/try-it', [
        'method' => 'GET',
        'url' => 'https://alt.ikinci-alan.test/api/things',
    ])->assertOk()->assertJsonPath('status', 200);

    Http::assertSent(static fn (ClientRequest $request): bool => $request->url() === 'https://alt.ikinci-alan.test/api/things');
});

it('ignores a self hosts entry that is not a bare hostname', function (): void {
    config()->set('api-dock.try_it.allowed_hosts', []);
    config()->set('app.url', 'https://congress-app.test');
    config()->set('api-dock.try_it.self_hosts', ['', '169.254.169.254', 'not a host']);

    apiDockSecurityResolvesToItself();

    Http::fake();

    // A literal address is the entry that matters here: being self skips the
    // allowlist, the internal-host list AND the post-DNS address gate at once,
    // so one line of config would otherwise hand over the metadata endpoint.
    $this->postJson('/api-dock/try-it', [
        'method' => 'GET',
        'url' => 'http://169.254.169.254/latest/meta-data/',
    ])->assertStatus(422);

    Http::assertNothingSent();
});

it('ignores a self hosts entry that spells a literal address non canonically', function (string $entry): void {
    config()->set('api-dock.try_it.allowed_hosts', []);
    config()->set('app.url', 'https://congress-app.test');
    config()->set('api-dock.try_it.self_hosts', [$entry]);

    Http::fake();

    // FILTER_VALIDATE_IP only recognises the canonical dotted quad, but a
    // resolver reads every one of these as loopback. If the entry were accepted
    // as a name, the target below would be self and would skip the address gate.
    $this->postJson('/api-dock/try-it', [
        'method' => 'GET',
        'url' => 'http://'.$entry.'/latest/meta-data/',
    ])->assertStatus(422);

    Http::assertNothingSent();
})->with([
    'dotted short form' => '127.1',
    'decimal integer' => '2130706433',
    'hexadecimal' => '0x7f000001',
]);

it('never lets a self hosts entry be satisfied by a near miss', function (string $host): void {
    config()->set('api-dock.try_it.allowed_hosts', []);
    config()->set('api-dock.try_it.self_hosts', ['ikinci-alan.test']);

    Http::fake();

    $this->postJson('/api-dock/try-it', [
        'method' => 'GET',
        'url' => 'https://'.$host.'/things',
    ])->assertStatus(422);

    Http::assertNothingSent();
})->with([
    'prefix collision' => 'evil-ikinci-alan.test',
    'suffix collision' => 'ikinci-alan.test.attacker.test',
]);

it('lets a leading dot entry admit the apex it names', function (): void {
    config()->set('api-dock.try_it.allowed_hosts', ['.example.com']);

    Http::fake(['*' => Http::response('{"ok":true}', 200)]);

    // Requiring a second entry for the apex bought no safety; it only produced
    // allowlists with the obvious host missing.
    $this->postJson('/api-dock/try-it', [
        'method' => 'GET',
        'url' => 'https://example.com/things',
    ])->assertOk();
});

it('never lets a leading dot entry be satisfied by a near miss', function (string $host): void {
    config()->set('api-dock.try_it.allowed_hosts', ['.example.com']);

    Http::fake();

    $this->postJson('/api-dock/try-it', [
        'method' => 'GET',
        'url' => 'https://'.$host.'/things',
    ])->assertStatus(422);

    Http::assertNothingSent();
})->with([
    'prefix collision' => 'evil-example.com',
    'suffix collision' => 'example.com.attacker.test',
]);

/*
|--------------------------------------------------------------------------
| Ports
|--------------------------------------------------------------------------
|
| On a self host the port is the LAST boundary standing: the allowlist, the
| internal-host list and the post-DNS address gate are all bypassed there. So
| every case below pairs the widening with its negative — the same host on
| another port, and a foreign host on the same port — because a port rule that
| leaks off the self host turns this guard into a port scanner.
|
*/

it('reaches the application own host on the port app url names', function (): void {
    config()->set('api-dock.try_it.allowed_hosts', []);
    config()->set('app.url', 'http://congress-app.test:8000');

    apiDockSecurityResolvesTo(['127.0.0.1']);

    Http::fake(['*' => Http::response('{"ok":true}', 200)]);

    // `php artisan serve`, Sail, a Docker port mapping: the app answers on a
    // port no allowlist mentions, and it is its own documented API.
    $this->postJson('/api-dock/try-it', [
        'method' => 'GET',
        'url' => 'http://congress-app.test:8000/api/things',
    ])->assertOk()->assertJsonPath('status', 200);

    Http::assertSent(static fn (ClientRequest $request): bool => $request->url() === 'http://congress-app.test:8000/api/things');
});

it('reaches a subdomain of the application host on the port app url names', function (): void {
    config()->set('api-dock.try_it.allowed_hosts', []);
    config()->set('app.url', 'http://congress-app.test:8000');

    apiDockSecurityResolvesTo(['127.0.0.1']);

    Http::fake(['*' => Http::response('{"ok":true}', 200)]);

    // The port follows the entry, and the entry already covers its subdomains.
    $this->postJson('/api-dock/try-it', [
        'method' => 'GET',
        'url' => 'http://test-kurum.congress-app.test:8000/api/things',
    ])->assertOk();
});

it('denies the application own host on a port nothing declared', function (): void {
    config()->set('api-dock.try_it.allowed_hosts', []);
    config()->set('app.url', 'http://congress-app.test:8000');

    apiDockSecurityResolvesTo(['127.0.0.1']);

    Http::fake();

    // Being self buys exactly ONE port. Redis on the same box is still off
    // limits, which is the whole reason the check is narrowed and not removed.
    $response = $this->postJson('/api-dock/try-it', [
        'method' => 'GET',
        'url' => 'http://congress-app.test:6379/',
    ]);

    $response->assertStatus(422);

    expect((string) $response->json('message'))->toContain('port');

    Http::assertNothingSent();
});

it('does not lend the application own port to a foreign host', function (): void {
    config()->set('api-dock.try_it.allowed_hosts', ['api.example.com']);
    config()->set('app.url', 'http://congress-app.test:8000');

    Http::fake();

    // The derived port is bound to the self match. An allowlisted foreign host
    // meets the unchanged `allowed_ports`, so the widening cannot be borrowed.
    $response = $this->postJson('/api-dock/try-it', [
        'method' => 'GET',
        'url' => 'http://api.example.com:8000/things',
    ]);

    $response->assertStatus(422);

    expect((string) $response->json('message'))->toContain('port');

    Http::assertNothingSent();
});

it('changes nothing when app url carries no explicit port', function (): void {
    config()->set('api-dock.try_it.allowed_hosts', []);
    config()->set('app.url', 'https://congress-app.test');

    apiDockSecurityResolvesTo(['127.0.0.1']);

    Http::fake();

    // No port is inferred from the scheme: an operator who narrowed
    // `allowed_ports` does not get 80/443 handed back through this path.
    $this->postJson('/api-dock/try-it', [
        'method' => 'GET',
        'url' => 'https://congress-app.test:8000/api/things',
    ])->assertStatus(422);

    Http::assertNothingSent();
});

it('reaches a self hosts entry on the port that entry declares', function (): void {
    config()->set('api-dock.try_it.allowed_hosts', []);
    config()->set('app.url', 'https://congress-app.test');
    config()->set('api-dock.try_it.self_hosts', ['ikinci-alan.test:8080']);

    apiDockSecurityResolvesTo(['127.0.0.1']);

    Http::fake(['*' => Http::response('{"ok":true}', 200)]);

    $this->postJson('/api-dock/try-it', [
        'method' => 'GET',
        'url' => 'http://ikinci-alan.test:8080/api/things',
    ])->assertOk()->assertJsonPath('status', 200);

    Http::assertSent(static fn (ClientRequest $request): bool => $request->url() === 'http://ikinci-alan.test:8080/api/things');
});

it('does not let one self host use the port another self host declared', function (): void {
    config()->set('api-dock.try_it.allowed_hosts', []);
    config()->set('app.url', 'http://congress-app.test:8000');
    config()->set('api-dock.try_it.self_hosts', ['ikinci-alan.test:8080']);

    apiDockSecurityResolvesTo(['127.0.0.1']);

    Http::fake();

    // Both names are self, but the ports are not pooled: each port reaches only
    // the name it was declared with.
    $this->postJson('/api-dock/try-it', [
        'method' => 'GET',
        'url' => 'http://ikinci-alan.test:8000/api/things',
    ])->assertStatus(422);

    Http::assertNothingSent();
});

it('ignores a self hosts entry whose port is out of range', function (string $entry): void {
    config()->set('api-dock.try_it.allowed_hosts', []);
    config()->set('app.url', 'https://congress-app.test');
    config()->set('api-dock.try_it.self_hosts', [$entry]);

    apiDockSecurityResolvesTo(['127.0.0.1']);

    Http::fake();

    // A malformed port drops the whole entry rather than being read as "no
    // port": promoting a typo'd line to a self host would exempt that name from
    // the allowlist and the address gate on the strength of a mistake.
    $this->postJson('/api-dock/try-it', [
        'method' => 'GET',
        'url' => 'https://ikinci-alan.test/api/things',
    ])->assertStatus(422);

    Http::assertNothingSent();
})->with([
    'zero' => 'ikinci-alan.test:0',
    'above the 16-bit range' => 'ikinci-alan.test:99999',
]);

it('still ignores an address literal in a self hosts entry that carries a port', function (string $entry): void {
    config()->set('api-dock.try_it.allowed_hosts', []);
    config()->set('app.url', 'https://congress-app.test');
    config()->set('api-dock.try_it.self_hosts', [$entry.':8080']);

    apiDockSecurityResolvesToItself();

    Http::fake();

    // Splitting the port off must not let a spelling past the checks that
    // rejected it before: the metadata endpoint stays unreachable whether or
    // not the entry that names it carries a port.
    $this->postJson('/api-dock/try-it', [
        'method' => 'GET',
        'url' => 'http://'.$entry.':8080/latest/meta-data/',
    ])->assertStatus(422);

    Http::assertNothingSent();
})->with([
    'canonical quad' => '169.254.169.254',
    'loopback quad' => '127.0.0.1',
    'dotted short form' => '127.1',
    'decimal integer' => '2130706433',
    'hexadecimal' => '0x7f000001',
]);

/*
|--------------------------------------------------------------------------
| Response handling
|--------------------------------------------------------------------------
*/

it('does not follow a redirect the upstream hands back', function (): void {
    // A followed 3xx would re-enter Guzzle *below* the guard, so the second hop
    // would never be inspected at all. The panel gets the 302 to look at instead.
    Http::fake([
        '*api.example.com*' => Http::response('', 302, [
            'Location' => 'http://169.254.169.254/latest/meta-data/',
        ]),
        '*' => Http::response('SECOND-HOP-BODY', 200),
    ]);

    $response = $this->postJson('/api-dock/try-it', [
        'method' => 'GET',
        'url' => 'https://api.example.com/things',
    ]);

    $response->assertOk()->assertJsonPath('status', 302);

    expect((string) $response->getContent())->not->toContain('SECOND-HOP-BODY');

    Http::assertSentCount(1);
    Http::assertNotSent(static fn (ClientRequest $request): bool => str_contains($request->url(), '169.254.169.254'));
});

it('truncates an over cap response body and flags it instead of buffering it whole', function (): void {
    config()->set('api-dock.try_it.max_response_bytes', 64);

    Http::fake(['*' => Http::response(str_repeat('a', 200000), 200)]);

    $response = $this->postJson('/api-dock/try-it', [
        'method' => 'GET',
        'url' => 'https://api.example.com/things',
    ]);

    $response->assertOk()->assertJsonPath('truncated', true);

    expect(strlen((string) $response->json('body')))->toBe(64)
        ->and(strlen((string) $response->getContent()))->toBeLessThan(1000);
});

/*
|--------------------------------------------------------------------------
| Feature switch and verbs
|--------------------------------------------------------------------------
*/

it('does not send a request while try-it is disabled', function (): void {
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

it('does not send a request for a verb outside the configured allowlist', function (string $method): void {
    config()->set('api-dock.try_it.allowed_methods', ['GET', 'HEAD']);

    Http::fake();

    $this->postJson('/api-dock/try-it', [
        'method' => $method,
        'url' => 'https://api.example.com/things',
    ])->assertStatus(422);

    Http::assertNothingSent();
})->with(['POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS']);

it('does not let the method config widen the guard supported verbs', function (): void {
    // Intersected with the guard's own list, so an operator writing TRACE into
    // the config ends up with nothing allowed rather than with TRACE allowed.
    config()->set('api-dock.try_it.allowed_methods', ['TRACE', 'CONNECT']);

    Http::fake();

    $this->postJson('/api-dock/try-it', [
        'method' => 'TRACE',
        'url' => 'https://api.example.com/things',
    ])->assertStatus(422);

    $this->postJson('/api-dock/try-it', [
        'method' => 'GET',
        'url' => 'https://api.example.com/things',
    ])->assertStatus(422);

    Http::assertNothingSent();
});

/*
|--------------------------------------------------------------------------
| Server template variables
|--------------------------------------------------------------------------
*/

it('does not send a request for a server variable that could rewrite the authority', function (string $value): void {
    config()->set('api-dock.try_it.allowed_hosts', ['.congress-app.test']);

    Http::fake();

    $response = $this->postJson('/api-dock/try-it', [
        'method' => 'GET',
        'server' => 'https://{tenant}.congress-app.test/api',
        'server_variables' => ['tenant' => $value],
    ]);

    $response->assertStatus(422);

    expect((string) $response->json('message'))->toContain('not allowed');

    Http::assertNothingSent();
})->with([
    'slash' => 'evil.test/',
    'at sign' => 'x@evil.test',
    'colon' => 'acme:8080',
    'question mark' => 'acme?next=1',
    'hash' => 'acme#fragment',
    'backslash' => 'evil.test\\',
    'square bracket' => 'acme[0]',
    'whitespace' => 'acme evil.test',
    'percent encoded slash' => 'evil.test%2F',
    'percent encoded at sign' => 'evil.test%40',
    'percent encoded colon' => 'acme%3A8080',
    'double percent encoded slash' => 'evil.test%252F',
    'double percent encoded at sign' => 'evil.test%2540',
    'triple percent encoded slash' => 'evil.test%25252F',
]);

it('does not send a request for a server variable outside the declared enum', function (): void {
    config()->set('api-dock.try_it.allowed_hosts', ['.congress-app.test']);

    Http::fake();

    $response = $this->postJson('/api-dock/try-it', [
        'method' => 'GET',
        'server' => 'https://{tenant}.congress-app.test/api',
        'server_variables' => ['tenant' => 'contoso'],
        'server_variable_spec' => ['tenant' => ['enum' => ['acme', 'globex'], 'default' => 'acme']],
    ]);

    $response->assertStatus(422);

    expect((string) $response->json('message'))->toContain('declared by the specification');

    Http::assertNothingSent();
});

it('does not send a request for a server variable with neither value nor default', function (): void {
    config()->set('api-dock.try_it.allowed_hosts', ['.congress-app.test']);

    Http::fake();

    $response = $this->postJson('/api-dock/try-it', [
        'method' => 'GET',
        'server' => 'https://{tenant}.congress-app.test/api',
        'server_variables' => ['tenant' => ''],
    ]);

    $response->assertStatus(422);

    expect((string) $response->json('message'))->toContain('no supplied value and no default');

    Http::assertNothingSent();
});

it('does not send a request for a template that still carries a placeholder', function (): void {
    config()->set('api-dock.try_it.allowed_hosts', ['.congress-app.test']);

    Http::fake();

    $response = $this->postJson('/api-dock/try-it', [
        'method' => 'GET',
        // The spaces keep it out of the substitution pattern, so it survives to
        // the leftover check rather than being replaced.
        'server' => 'https://acme.congress-app.test/api/{ region }',
    ]);

    $response->assertStatus(422);

    expect((string) $response->json('message'))->toContain('unsubstituted placeholder');

    Http::assertNothingSent();
});

/*
|--------------------------------------------------------------------------
| Egress proxy
|--------------------------------------------------------------------------
|
| The pinned address is only a pin while nothing connects on this server's
| behalf. An inherited environment proxy would resolve the name a second time,
| so the boundary disables it on both layers and accepts only a proxy the
| operator declared — never one that resolves remotely.
|
*/

it('sends no ambient environment proxy with the outbound proxy request', function (): void {
    putenv('HTTP_PROXY=http://ambient.test:3128');
    putenv('HTTPS_PROXY=http://ambient.test:3128');

    try {
        $captured = null;

        Http::fake(function (ClientRequest $request, array $options) use (&$captured) {
            $captured = $options;

            return Http::response('{"ok":true}', 200);
        });

        $this->postJson('/api-dock/try-it', [
            'method' => 'GET',
            'url' => 'https://api.example.com/things',
        ])->assertOk();

        expect($captured)->toBeArray()
            // An empty string is Guzzle's final "no proxy": the environment is not
            // consulted, and Guzzle itself writes the empty CURLOPT_PROXY.
            ->and($captured['proxy'])->toBe('')
            ->and($captured['curl'] ?? [])->not->toHaveKey(CURLOPT_PROXY)
            // Guarded together: the pin is the reason the proxy has to be empty.
            ->and($captured['curl'][CURLOPT_RESOLVE])->toBe(['api.example.com:443:'.API_DOCK_SECURITY_PUBLIC_IP]);
    } finally {
        putenv('HTTP_PROXY');
        putenv('HTTPS_PROXY');
    }
});

it('does not send a request through a configured proxy that resolves the host remotely', function (string $configured): void {
    config()->set('api-dock.try_it.proxy', $configured);

    Http::fake();

    $response = $this->postJson('/api-dock/try-it', [
        'method' => 'GET',
        'url' => 'https://api.example.com/things',
    ]);

    $response->assertStatus(422);

    expect((string) $response->json('message'))->toContain('socks5 or socks4');

    Http::assertNothingSent();
})->with([
    'remotely resolving socks5' => ['socks5h://127.0.0.1:1080'],
    'remotely resolving socks4' => ['socks4a://127.0.0.1:1080'],
    // An HTTP proxy is handed the hostname, not the pinned address, and resolves it itself.
    'http proxy' => ['http://proxy.test:3128'],
    'https proxy' => ['https://proxy.test:3128'],
    'no scheme at all' => ['proxy.test:3128'],
]);

it('passes a configured proxy through to the outbound request', function (): void {
    config()->set('api-dock.try_it.proxy', 'socks5://proxy.test:1080');

    $captured = null;

    Http::fake(function (ClientRequest $request, array $options) use (&$captured) {
        $captured = $options;

        return Http::response('{"ok":true}', 200);
    });

    $this->postJson('/api-dock/try-it', [
        'method' => 'GET',
        'url' => 'https://api.example.com/things',
    ])->assertOk();

    expect($captured)->toBeArray()
        ->and($captured['proxy'])->toBe('socks5://proxy.test:1080')
        // Never in the cURL array: Guzzle 8 rejects CURLOPT_PROXY there outright, which
        // is the InvalidArgumentException every try-it request failed with.
        ->and($captured['curl'] ?? [])->not->toHaveKey(CURLOPT_PROXY);
});
