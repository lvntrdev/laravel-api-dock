<?php

declare(strict_types=1);

namespace LvntR\ApiDock\Support;

use Closure;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Utils as Psr7Utils;
use Illuminate\Http\Client\Factory as HttpFactory;
use Psr\Http\Message\StreamInterface;
use RuntimeException;
use Throwable;

/**
 * The single boundary every try-it request crosses before it leaves the server.
 *
 * Nothing in this package performs an outbound HTTP call outside this class. The
 * checks are deliberately ordered so that the *fully substituted* URL — never a
 * server template — is what gets validated, and so that the address the request
 * actually connects to is the same address that passed the checks.
 */
final class OutboundRequestGuard
{
    /**
     * The only verbs this boundary will ever emit, whatever the operator configures.
     *
     * @var list<string>
     */
    public const SUPPORTED_METHODS = ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'];

    /**
     * Hop-by-hop headers plus anything carrying the host application's identity.
     * Stripped in BOTH directions so the panel can neither forge them on the way
     * out nor observe them on the way back.
     *
     * @var list<string>
     */
    private const FORBIDDEN_HEADERS = [
        'host',
        'connection',
        'keep-alive',
        'te',
        'trailer',
        'trailers',
        'transfer-encoding',
        'upgrade',
        'content-length',
        'expect',
        'cookie',
        'set-cookie',
        'set-cookie2',
        'forwarded',
        'x-forwarded-for',
        'x-forwarded-host',
        'x-forwarded-proto',
        'x-forwarded-port',
        'x-real-ip',
        'x-csrf-token',
        'x-xsrf-token',
    ];

    /** @var list<string> */
    private const FORBIDDEN_HEADER_PREFIXES = ['proxy-', 'sec-'];

    /**
     * Hosts that name an internal service by convention rather than by address.
     *
     * @var list<string>
     */
    private const DENIED_HOSTS = [
        'localhost',
        'localhost.localdomain',
        'metadata',
        'metadata.goog',
        'metadata.google.internal',
        'instance-data',
        'instance-data.ec2.internal',
    ];

    /** @var list<string> */
    private const DENIED_HOST_SUFFIXES = [
        '.localhost',
        '.local',
        '.localdomain',
        '.internal',
        '.home.arpa',
        '.in-addr.arpa',
        '.ip6.arpa',
    ];

    /**
     * Address ranges that must never be reachable through the proxy. Loopback,
     * link-local (which contains 169.254.169.254), every private range, the
     * unspecified address, multicast and the reserved/broadcast block.
     *
     * @var list<string>
     */
    private const DENIED_IPV4_RANGES = [
        '0.0.0.0/8',
        '10.0.0.0/8',
        '100.64.0.0/10',
        '127.0.0.0/8',
        '169.254.0.0/16',
        '172.16.0.0/12',
        '192.0.0.0/24',
        '192.0.2.0/24',
        '192.88.99.0/24',
        '192.168.0.0/16',
        '198.18.0.0/15',
        '198.51.100.0/24',
        '203.0.113.0/24',
        '224.0.0.0/4',
        '240.0.0.0/4',
    ];

    /**
     * fc00::/7 covers unique-local addressing, and with it the AWS IPv6 metadata
     * endpoint fd00:ec2::254.
     *
     * @var list<string>
     */
    private const DENIED_IPV6_RANGES = [
        '::/128',
        '::1/128',
        '100::/64',
        '2001::/32',
        '2001:db8::/32',
        'fc00::/7',
        'fe80::/10',
        'ff00::/8',
    ];

    /** Characters that would let a server variable rewrite the authority of the URL. */
    private const UNSAFE_VARIABLE_PATTERN = '/[\/\\\\@:?#\[\]\s\x00-\x1F\x7F]/';

    /** @var (Closure(string): list<string>)|null */
    private readonly ?Closure $resolver;

    /**
     * @param  (Closure(string): list<string>)|null  $resolver  Injected so address-class
     *                                                          checks stay testable; production resolves through DNS.
     */
    public function __construct(
        private readonly HttpFactory $http,
        ?Closure $resolver = null,
    ) {
        $this->resolver = $resolver;
    }

    /**
     * Replace `{variable}` placeholders in an OpenAPI server template.
     *
     * Substitution happens here — inside the boundary — because a variable filled
     * with `evil.com/` or `x@attacker` rewrites the authority outright. Matching an
     * allowlist against the *template* would therefore pass while the real request
     * left for somewhere else entirely.
     *
     * The enum/default metadata is spec-conformance, not a trust boundary: the
     * allowlist and address-class checks in {@see inspect()} are what actually
     * constrain where the request may go.
     *
     * @param  array<string, mixed>  $values
     * @param  array<string, mixed>  $specifications  Per-variable `enum`/`default` from the OpenAPI server object.
     */
    public function substituteServerTemplate(string $template, array $values = [], array $specifications = []): string
    {
        $template = trim($template);

        if ($template === '') {
            self::deny('No server template was supplied.');
        }

        preg_match_all('/\{([A-Za-z0-9_.\-]+)\}/', $template, $matches);

        $replacements = [];

        foreach (array_unique($matches[1]) as $name) {
            $specification = $specifications[$name] ?? null;
            $specification = is_array($specification) ? $specification : [];

            $value = $values[$name] ?? null;

            if ($value === null || $value === '') {
                $value = $specification['default'] ?? null;
            }

            if (! is_string($value) && ! is_int($value)) {
                self::deny(sprintf('Server variable [%s] has no supplied value and no default.', $name));
            }

            $value = (string) $value;

            if ($value === '') {
                self::deny(sprintf('Server variable [%s] has no supplied value and no default.', $name));
            }

            $enum = $specification['enum'] ?? null;

            if (is_array($enum) && $enum !== []) {
                $allowed = array_map(static fn (mixed $case): string => is_scalar($case) ? (string) $case : '', $enum);

                if (! in_array($value, $allowed, true)) {
                    self::deny(sprintf('Server variable [%s] is not one of the values declared by the specification.', $name));
                }
            }

            self::assertVariableValueIsInert($name, $value);

            $replacements['{'.$name.'}'] = rawurlencode($value);
        }

        $url = strtr($template, $replacements);

        if (preg_match('/\{[^}]*\}/', $url) === 1) {
            self::deny('The server template still contains an unsubstituted placeholder.');
        }

        return $url;
    }

    /**
     * Validate a fully substituted absolute URL and resolve it exactly once.
     *
     * @return array{url: string, scheme: string, host: string, port: int, addresses: list<string>, is_ip_literal: bool}
     */
    public function inspect(string $url): array
    {
        $url = trim($url);

        if ($url === '') {
            self::deny('No target URL was supplied.');
        }

        if (preg_match('/[\x00-\x20\x7F\\\\{}]/', $url) === 1) {
            self::deny('The target URL contains characters that are not allowed.');
        }

        $parts = parse_url($url);

        if (! is_array($parts)) {
            self::deny('The target URL could not be parsed.');
        }

        $scheme = isset($parts['scheme']) ? strtolower((string) $parts['scheme']) : '';

        if ($scheme !== 'http' && $scheme !== 'https') {
            self::deny('Only the http and https schemes may be proxied.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            self::deny('The target URL may not carry credentials in its authority.');
        }

        $host = isset($parts['host']) ? strtolower(rtrim((string) $parts['host'], '.')) : '';

        if ($host === '') {
            self::deny('The target URL has no host.');
        }

        $isIpLiteral = false;

        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);

            if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
                self::deny('The target URL has an invalid IPv6 literal host.');
            }

            $isIpLiteral = true;
        } elseif (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $isIpLiteral = true;
        } elseif (preg_match('/^(?!-)[a-z0-9-]{1,63}(?<!-)(\.(?!-)[a-z0-9-]{1,63}(?<!-))*$/', $host) !== 1) {
            self::deny('The target host is not a well-formed hostname.');
        }

        // The app's OWN host is not an outbound target in any meaningful sense:
        // whoever opened the docs already reaches it, and the documented API lives
        // on it. Making an operator allowlist their own site to try their own
        // endpoints was ceremony that protected nobody, so this is implicit and
        // not configurable away.
        $isSelf = self::isSelfHost($host);

        if (! $isSelf) {
            self::assertHostIsNotInternal($host);
            $this->assertHostIsAllowlisted($host);
        }

        $hasExplicitPort = isset($parts['port']);
        $port = $hasExplicitPort ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80);

        if ($port < 1 || $port > 65535) {
            self::deny('The target URL has an invalid port.');
        }

        // The host allowlist alone is not enough: an allowlisted host usually
        // co-locates internal services, so an unconstrained port turns one
        // allowed name into a port scanner (`https://api.example.com:6379/`).
        if (! in_array($port, self::allowedPorts(), true)) {
            self::deny('The target port is not allowed.');
        }

        $path = isset($parts['path']) ? (string) $parts['path'] : '';

        if ($path === '') {
            $path = '/';
        }

        if (! str_starts_with($path, '/')) {
            self::deny('The target URL has a malformed path.');
        }

        $query = isset($parts['query']) ? (string) $parts['query'] : '';

        $addresses = $this->resolveHost($host);

        if ($addresses === []) {
            self::deny(sprintf('The host [%s] could not be resolved.', $host));
        }

        foreach ($addresses as $address) {
            // A self host skips the address-class gate on purpose. A local or
            // intranet deployment answers on 127.0.0.1 or a private range, and
            // refusing to reach the very server that served this page would make
            // try-it useless exactly where it is used most. The reach is still
            // that one host — nothing else private becomes addressable.
            if (! $isSelf && self::isDeniedAddress($address)) {
                self::deny(sprintf('The host [%s] resolves to an address that may not be reached from this server.', $host));
            }
        }

        $authority = ($isIpLiteral && str_contains($host, ':')) ? '['.$host.']' : $host;

        // Rebuilt from the validated parts, so cURL cannot parse a different
        // authority out of the original string than the one that was checked.
        $target = $scheme.'://'.$authority
            .($hasExplicitPort ? ':'.$port : '')
            .$path
            .($query !== '' ? '?'.$query : '');

        return [
            'url' => $target,
            'scheme' => $scheme,
            'host' => $host,
            'port' => $port,
            'addresses' => $addresses,
            'is_ip_literal' => $isIpLiteral,
        ];
    }

    /**
     * Perform the outbound request against the address that passed {@see inspect()}.
     *
     * @param  array<string, string>  $headers
     * @return array{status: int, headers: array<string, list<string>>, body: string, binary: bool, truncated: bool, url: string, host: string}
     */
    public function send(
        string $method,
        string $url,
        array $headers = [],
        ?string $body = null,
        ?string $contentType = null,
    ): array {
        $method = strtoupper(trim($method));

        if (! in_array($method, self::SUPPORTED_METHODS, true)) {
            self::deny('The requested HTTP method is not supported by the try-it proxy.');
        }

        if (! extension_loaded('curl')) {
            // Without cURL the resolved address cannot be pinned, which reopens the
            // DNS-rebind window between the check and the connection. Fail closed.
            self::deny('The try-it proxy requires the cURL extension so the checked address can be pinned.');
        }

        $target = $this->inspect($url);
        $maxBytes = self::positiveIntConfig('api-dock.try_it.max_response_bytes', 262144);

        // No `protocols` option: Guzzle 7 has no such top-level key and drops it
        // silently, so it was never the guarantee it looked like. The scheme is
        // constrained in `inspect()` and redirects are off, so nothing can hand the
        // handler a second scheme to follow.
        $options = [
            'http_errors' => false,
        ];

        if (! $target['is_ip_literal']) {
            // Pin the exact addresses that were validated. The Host header (and with
            // it SNI/certificate verification) still carries the original hostname.
            $options['curl'] = [
                CURLOPT_RESOLVE => [sprintf(
                    '%s:%d:%s',
                    $target['host'],
                    $target['port'],
                    implode(',', array_map(
                        static fn (string $address): string => str_contains($address, ':') ? '['.$address.']' : $address,
                        $target['addresses'],
                    )),
                )],
            ];
        }

        $written = 0;
        $sink = self::boundedSink($maxBytes, $written);

        if ($sink instanceof StreamInterface) {
            $options['sink'] = $sink;
        }

        $request = $this->http
            ->connectTimeout(self::positiveIntConfig('api-dock.try_it.connect_timeout', 5))
            ->timeout(self::positiveIntConfig('api-dock.try_it.timeout', 10))
            ->withoutRedirecting()
            ->withHeaders(self::filterRequestHeaders($headers))
            ->withOptions($options);

        if ($body !== null && $body !== '' && $method !== 'GET' && $method !== 'HEAD') {
            $request = $request->withBody($body, $contentType ?? 'application/json');
        }

        try {
            $response = $request->send($method, $target['url']);
        } catch (Throwable $exception) {
            // Deliberately not chained and not re-using the original message: an
            // upstream exception can quote the request it was building. Only the
            // exception class is surfaced, never a header or a credential.
            self::deny(sprintf(
                'The outbound request to [%s] failed (%s).',
                $target['host'],
                class_basename($exception),
            ));
        }

        $stream = $response->toPsrResponse()->getBody();

        if ($stream->isSeekable()) {
            $stream->rewind();
        }

        $read = '';

        while (! $stream->eof() && strlen($read) <= $maxBytes) {
            $chunk = $stream->read(8192);

            if ($chunk === '') {
                break;
            }

            $read .= $chunk;
        }

        $truncated = strlen($read) > $maxBytes || $written > $maxBytes;

        if (strlen($read) > $maxBytes) {
            $read = substr($read, 0, $maxBytes);
        }

        // The caller hands this body to a JsonResponse, and `json_encode` refuses
        // malformed UTF-8. Two ordinary responses would otherwise leave the proxy
        // as an unhandled 500: any binary payload, and a perfectly valid text body
        // whose last codepoint the byte cap above severed mid-sequence.
        $read = self::trimPartialUtf8($read);
        $binary = ! mb_check_encoding($read, 'UTF-8');

        if ($binary) {
            $read = '[binary response body: '.strlen($read).' bytes, not shown]';
        }

        /** @var array<string, list<string>> $responseHeaders */
        $responseHeaders = $response->headers();

        return [
            'status' => $response->status(),
            'headers' => self::filterResponseHeaders($responseHeaders),
            'body' => $read,
            'binary' => $binary,
            'truncated' => $truncated,
            'url' => $target['url'],
            'host' => $target['host'],
        ];
    }

    /**
     * @param  array<string, string>  $headers
     * @return array<string, string>
     */
    public static function filterRequestHeaders(array $headers): array
    {
        $filtered = [];

        foreach ($headers as $name => $value) {
            $name = trim((string) $name);

            if (! self::isForwardableHeader($name)) {
                continue;
            }

            if (preg_match('/[\r\n\x00]/', $value) === 1) {
                continue;
            }

            $filtered[$name] = trim($value);
        }

        return $filtered;
    }

    /**
     * @param  array<string, list<string>>  $headers
     * @return array<string, list<string>>
     */
    public static function filterResponseHeaders(array $headers): array
    {
        $filtered = [];

        foreach ($headers as $name => $values) {
            if (! self::isForwardableHeader((string) $name)) {
                continue;
            }

            $filtered[(string) $name] = array_values($values);
        }

        return $filtered;
    }

    /**
     * True when a header may cross the proxy in either direction.
     */
    public static function isForwardableHeader(string $name): bool
    {
        if (preg_match('/^[A-Za-z0-9!#$%&\'*+.^_`|~-]+$/', $name) !== 1) {
            return false;
        }

        $normalised = strtolower($name);

        if (in_array($normalised, self::FORBIDDEN_HEADERS, true)) {
            return false;
        }

        foreach (self::FORBIDDEN_HEADER_PREFIXES as $prefix) {
            if (str_starts_with($normalised, $prefix)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Reject a value that could break out of the placeholder it fills, including
     * one that only becomes dangerous after percent-decoding (`%2F` is a `/`).
     */
    private static function assertVariableValueIsInert(string $name, string $value): void
    {
        $decoded = $value;

        for ($pass = 0; $pass < 5; $pass++) {
            if (preg_match(self::UNSAFE_VARIABLE_PATTERN, $decoded) === 1) {
                self::deny(sprintf('Server variable [%s] contains characters that are not allowed.', $name));
            }

            $next = rawurldecode($decoded);

            if ($next === $decoded) {
                return;
            }

            $decoded = $next;
        }

        self::deny(sprintf('Server variable [%s] is encoded too deeply to be validated.', $name));
    }

    /**
     * True when the target names this very application.
     *
     * Sources, in order: the host of the request being served (so a tenant
     * subdomain the reader is actually on counts), and `app.url`. A subdomain of
     * either counts too — a multi-tenant deployment answers on all of them from
     * the same server. Nothing else does: this is deliberately not a wildcard for
     * "anything nearby", only for the site that served this page.
     */
    private static function isSelfHost(string $host): bool
    {
        foreach (self::selfHosts() as $self) {
            if ($host === $self || str_ends_with($host, '.'.$self) || str_ends_with($self, '.'.$host)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private static function selfHosts(): array
    {
        $hosts = [];

        try {
            $request = request();
            $requestHost = strtolower(trim($request->getHost()));

            if ($requestHost !== '') {
                $hosts[] = $requestHost;
            }
        } catch (Throwable) {
            // No request bound (console, queue): app.url still answers.
        }

        /** @var mixed $appUrl */
        $appUrl = config('app.url');

        if (is_string($appUrl) && $appUrl !== '') {
            $parsed = parse_url($appUrl, PHP_URL_HOST);

            if (is_string($parsed) && $parsed !== '') {
                $hosts[] = strtolower(rtrim($parsed, '.'));
            }
        }

        return array_values(array_unique(array_filter($hosts)));
    }

    private static function assertHostIsNotInternal(string $host): void
    {
        if (in_array($host, self::DENIED_HOSTS, true)) {
            self::deny(sprintf('The host [%s] names an internal service and may not be reached.', $host));
        }

        foreach (self::DENIED_HOST_SUFFIXES as $suffix) {
            if (str_ends_with($host, $suffix)) {
                self::deny(sprintf('The host [%s] names an internal service and may not be reached.', $host));
            }
        }
    }

    /**
     * An empty allowlist denies everything. "Empty means allow all" would turn this
     * package into a general purpose SSRF tool for anyone who can reach the docs.
     */
    /**
     * Ports the proxy may reach. Configurable, but never empty: an empty or
     * malformed setting falls back to the two web ports rather than opening
     * everything, so a typo cannot silently widen the boundary.
     *
     * @return list<int>
     */
    private static function allowedPorts(): array
    {
        /** @var mixed $configured */
        $configured = config('api-dock.try_it.allowed_ports', [80, 443]);
        $ports = [];

        foreach (is_array($configured) ? $configured : [] as $port) {
            if ((is_int($port) || (is_string($port) && ctype_digit($port))) && (int) $port >= 1 && (int) $port <= 65535) {
                $ports[] = (int) $port;
            }
        }

        return $ports === [] ? [80, 443] : array_values(array_unique($ports));
    }

    private function assertHostIsAllowlisted(string $host): void
    {
        /** @var mixed $configured */
        $configured = config('api-dock.try_it.allowed_hosts', []);
        $entries = is_array($configured) ? $configured : [];

        foreach ($entries as $entry) {
            if (! is_string($entry)) {
                continue;
            }

            $entry = strtolower(trim(rtrim(trim($entry), '.')));

            if ($entry === '' || $entry === '.') {
                continue;
            }

            if (str_starts_with($entry, '.')) {
                // The leading-dot form covers the apex AND its subdomains, the way a
                // cookie domain does: an operator who writes `.example.com` means that
                // site, and making them list the apex on a second line bought no safety
                // — it only produced allowlists with the obvious entry missing.
                // A bare `example.com` entry stays an exact match, so no existing
                // config widens, and `evil-example.com` still matches neither form.
                if ($host === substr($entry, 1) || str_ends_with($host, $entry)) {
                    return;
                }

                continue;
            }

            if ($host === $entry) {
                return;
            }
        }

        self::deny(sprintf('The host [%s] is not in the try-it allowlist.', $host));
    }

    /**
     * @return list<string>
     */
    private function resolveHost(string $host): array
    {
        if ($this->resolver !== null) {
            return array_values(array_unique(($this->resolver)($host)));
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $addresses = [];

        // The OPERATING SYSTEM resolver comes first, and `dns_get_record()` is only
        // the fallback — never the other way round. `dns_get_record()` speaks to the
        // nameservers in resolv.conf directly, so it sees neither /etc/hosts nor a
        // split-horizon resolver (macOS /etc/resolver, a container's embedded DNS, a
        // corporate VPN). Against a name only the OS can answer for — a `.test`
        // development host, a service name inside Docker — it does not fail fast: it
        // retries every configured nameserver until PHP's own resolver timeout, which
        // outlasts max_execution_time. The worker was killed mid-request and the panel
        // saw a bare 502 from the web server, with nothing in any application log.
        //
        // `gethostbynamel()` is also the resolver cURL itself uses, so the address
        // pinned into CURLOPT_RESOLVE is the one the request would have reached
        // anyway. It answers IPv4 only, which is why the AAAA path below still exists.
        $resolved = @gethostbynamel($host);

        if (is_array($resolved)) {
            foreach ($resolved as $address) {
                if (is_string($address)) {
                    $addresses[] = $address;
                }
            }
        }

        if ($addresses === []) {
            /** @var mixed $records */
            $records = @dns_get_record($host, DNS_A | DNS_AAAA);

            if (is_array($records)) {
                foreach ($records as $record) {
                    if (! is_array($record)) {
                        continue;
                    }

                    foreach (['ip', 'ipv6'] as $key) {
                        if (isset($record[$key]) && is_string($record[$key])) {
                            $addresses[] = $record[$key];
                        }
                    }
                }
            }
        }

        return array_values(array_unique($addresses));
    }

    private static function isDeniedAddress(string $address): bool
    {
        $binary = @inet_pton($address);

        if (! is_string($binary)) {
            return true;
        }

        if (strlen($binary) === 16) {
            $embedded = self::embeddedIpv4($binary);

            if ($embedded !== null) {
                return self::isDeniedRange($embedded, self::DENIED_IPV4_RANGES);
            }

            return self::isDeniedRange($binary, self::DENIED_IPV6_RANGES);
        }

        return self::isDeniedRange($binary, self::DENIED_IPV4_RANGES);
    }

    /**
     * Unwrap the IPv4 address hidden inside a v4-mapped, v4-compatible, NAT64 or
     * 6to4 address so `::ffff:127.0.0.1` cannot slip past the IPv4 ranges.
     */
    private static function embeddedIpv4(string $binary): ?string
    {
        $zeroes = str_repeat("\x00", 10);

        if (str_starts_with($binary, $zeroes."\xFF\xFF")) {
            return substr($binary, 12, 4);
        }

        if (str_starts_with($binary, str_repeat("\x00", 12))) {
            return substr($binary, 12, 4);
        }

        if (str_starts_with($binary, "\x00\x64\xFF\x9B".str_repeat("\x00", 8))) {
            return substr($binary, 12, 4);
        }

        if (str_starts_with($binary, "\x20\x02")) {
            return substr($binary, 2, 4);
        }

        return null;
    }

    /**
     * @param  list<string>  $ranges
     */
    private static function isDeniedRange(string $binary, array $ranges): bool
    {
        foreach ($ranges as $range) {
            [$network, $prefix] = explode('/', $range, 2);
            $networkBinary = inet_pton($network);

            if (! is_string($networkBinary) || strlen($networkBinary) !== strlen($binary)) {
                continue;
            }

            $bits = (int) $prefix;
            $wholeBytes = intdiv($bits, 8);
            $remainingBits = $bits % 8;

            if ($wholeBytes > 0 && substr($binary, 0, $wholeBytes) !== substr($networkBinary, 0, $wholeBytes)) {
                continue;
            }

            if ($remainingBits === 0) {
                return true;
            }

            $mask = chr((0xFF << (8 - $remainingBits)) & 0xFF);

            if (($binary[$wholeBytes] & $mask) === ($networkBinary[$wholeBytes] & $mask)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Drops an incomplete trailing UTF-8 sequence left behind by the byte cap.
     *
     * Only a severed tail is trimmed: as soon as the last byte is ASCII the body
     * is invalid somewhere else, which means it was never text to begin with.
     */
    private static function trimPartialUtf8(string $body): string
    {
        for ($i = 0; $i < 3 && $body !== '' && ! mb_check_encoding($body, 'UTF-8'); $i++) {
            if ((ord(substr($body, -1)) & 0x80) === 0) {
                break;
            }

            $body = substr($body, 0, -1);
        }

        return $body;
    }

    /**
     * A sink that stops writing once the cap is reached, so an oversized upstream
     * body is never buffered in full — not into memory and not onto disk.
     */
    private static function boundedSink(int $maxBytes, int &$written): ?StreamInterface
    {
        if (! class_exists(FnStream::class) || ! class_exists(Psr7Utils::class)) {
            return null;
        }

        $handle = fopen('php://temp', 'w+');

        if ($handle === false) {
            return null;
        }

        $buffer = Psr7Utils::streamFor($handle);

        return FnStream::decorate($buffer, [
            'write' => static function (string $string) use ($buffer, $maxBytes, &$written): int {
                $length = strlen($string);
                $remaining = $maxBytes - $written;
                $written += $length;

                if ($remaining > 0) {
                    $buffer->write($remaining >= $length ? $string : substr($string, 0, $remaining));
                }

                // Report the full length so cURL keeps draining instead of erroring.
                return $length;
            },
        ]);
    }

    private static function positiveIntConfig(string $key, int $fallback): int
    {
        /** @var mixed $value */
        $value = config($key, $fallback);
        $value = is_numeric($value) ? (int) $value : $fallback;

        return $value > 0 ? $value : $fallback;
    }

    private static function deny(string $message): never
    {
        throw new RuntimeException($message);
    }
}
