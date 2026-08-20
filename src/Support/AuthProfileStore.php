<?php

declare(strict_types=1);

namespace LvntR\ApiDock\Support;

use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Encryption\Encrypter;
use InvalidArgumentException;

/**
 * Short-lived, per-session try-it credentials.
 *
 * The credential is encrypted with the application encrypter before it reaches the
 * cache, and it is only ever handed back in plaintext through the one method whose
 * name says so. Every other read path returns a masked hint, so a credential cannot
 * reach a response, a log line or a debug dump by accident.
 */
final readonly class AuthProfileStore
{
    /** @var list<string> */
    public const SCHEMES = ['bearer', 'basic', 'header'];

    private const KEY_PREFIX = 'api-dock:try-it:profiles:';

    /**
     * Server variables are plain profile data, so nothing else bounds them: they
     * carry no credential and pass through no other guard. Without a ceiling on
     * the entry count and on each name and value, this one field could inflate a
     * session bucket that every read unserializes in full.
     */
    public const MAX_SERVER_VARIABLES = 20;

    public const MAX_SERVER_VARIABLE_LENGTH = 255;

    /** Also the fallback for a non-positive or non-numeric configured ttl. */
    private const DEFAULT_TTL = 3600;

    public function __construct(
        private CacheRepository $cache,
        private Encrypter $encrypter,
    ) {}

    /**
     * @param  array{label?: string, base_url?: string, scheme?: string, credential?: string, credential_header?: string, server_variables?: array<string, string>}  $attributes
     * @return array{id: string, label: string, base_url: string, server_variables: array<string, string>, scheme: string, credential_header: string|null, credential_hint: string}
     */
    public function put(string $sessionKey, array $attributes): array
    {
        $scheme = strtolower(trim($attributes['scheme'] ?? 'bearer'));

        if (! in_array($scheme, self::SCHEMES, true)) {
            throw new InvalidArgumentException('Unsupported try-it authentication scheme.');
        }

        $credential = $attributes['credential'] ?? '';

        if (trim($credential) === '') {
            throw new InvalidArgumentException('A try-it profile requires a credential.');
        }

        $header = null;

        if ($scheme === 'header') {
            $header = trim($attributes['credential_header'] ?? '');

            if ($header === '' || ! OutboundRequestGuard::isForwardableHeader($header)) {
                throw new InvalidArgumentException('The credential header name is not usable for an outbound request.');
            }
        }

        $id = bin2hex(random_bytes(16));

        $record = [
            'id' => $id,
            'label' => trim($attributes['label'] ?? 'Profile'),
            'base_url' => trim($attributes['base_url'] ?? ''),
            'server_variables' => self::serverVariables($attributes['server_variables'] ?? []),
            'scheme' => $scheme,
            'credential_header' => $header,
            'credential_hint' => self::mask($credential),
            // Ciphertext only. The plaintext never touches the cache driver.
            'credential' => $this->encrypter->encrypt($credential),
        ];

        $profiles = $this->withBucketLock($sessionKey, function () use ($sessionKey, $id, $record): array {
            $profiles = $this->raw($sessionKey);
            $profiles[$id] = $record;

            // Every write refreshes the bucket's TTL, so without a cap a session that
            // keeps posting keeps an ever-growing array alive indefinitely — and each
            // read unserializes all of it. The oldest entries go first.
            $max = self::maxProfiles();

            if (count($profiles) > $max) {
                $profiles = array_slice($profiles, -$max, null, true);
            }

            $this->cache->put($this->cacheKey($sessionKey), $profiles, $this->ttl());

            return $profiles;
        });

        return self::withoutCredential($profiles[$id]);
    }

    /**
     * Masked view of every profile in the session. This is the default read path.
     *
     * @return list<array{id: string, label: string, base_url: string, server_variables: array<string, string>, scheme: string, credential_header: string|null, credential_hint: string}>
     */
    public function all(string $sessionKey): array
    {
        return array_values(array_map(
            static fn (array $profile): array => self::withoutCredential($profile),
            $this->readRefreshingTtl($sessionKey),
        ));
    }

    /**
     * Masked view of a single profile.
     *
     * @return array{id: string, label: string, base_url: string, server_variables: array<string, string>, scheme: string, credential_header: string|null, credential_hint: string}|null
     */
    public function find(string $sessionKey, string $profileId): ?array
    {
        $profile = $this->readRefreshingTtl($sessionKey)[$profileId] ?? null;

        return $profile === null ? null : self::withoutCredential($profile);
    }

    public function forget(string $sessionKey, string $profileId): void
    {
        $this->withBucketLock($sessionKey, function () use ($sessionKey, $profileId): bool {
            $profiles = $this->raw($sessionKey);

            if (! array_key_exists($profileId, $profiles)) {
                return true;
            }

            unset($profiles[$profileId]);

            if ($profiles === []) {
                $this->cache->forget($this->cacheKey($sessionKey));

                return true;
            }

            $this->cache->put($this->cacheKey($sessionKey), $profiles, $this->ttl());

            return true;
        });
    }

    public function flush(string $sessionKey): void
    {
        $this->cache->forget($this->cacheKey($sessionKey));
    }

    /**
     * The ONLY method that returns plaintext. Call it while building the outbound
     * request and nowhere else — never to render, log or echo the value back.
     */
    public function revealCredentialForOutboundRequest(string $sessionKey, string $profileId): ?string
    {
        $profile = $this->readRefreshingTtl($sessionKey)[$profileId] ?? null;

        if ($profile === null || ! is_string($profile['credential'] ?? null)) {
            return null;
        }

        try {
            $credential = $this->encrypter->decrypt($profile['credential']);
        } catch (DecryptException) {
            return null;
        }

        return is_string($credential) && $credential !== '' ? $credential : null;
    }

    /**
     * Read path with a rolling lifetime: finding a live bucket pushes its expiry
     * out by a full ttl, so a profile that is actually in use does not die in the
     * middle of a session. Only the expiry moves — the stored record, ciphertext
     * included, is re-put exactly as it was read.
     *
     * A missing or already-expired bucket is never recreated, so a read cannot
     * resurrect a credential. The lifetime is therefore idle-based with no
     * absolute ceiling above it: the configured ttl is the only bound on how
     * long a credential survives, which is why that value is chosen carefully.
     *
     * @return array<string, array<string, mixed>>
     */
    private function readRefreshingTtl(string $sessionKey): array
    {
        return $this->withBucketLock($sessionKey, function () use ($sessionKey): array {
            $profiles = $this->raw($sessionKey);

            if ($profiles === []) {
                return [];
            }

            $this->cache->put($this->cacheKey($sessionKey), $profiles, $this->ttl());

            return $profiles;
        });
    }

    /**
     * Serializes the read-modify-write window on a session bucket wherever the cache
     * driver offers a lock. Without it a rolling-ttl read that started before a delete
     * re-puts its own snapshot afterwards, bringing a removed credential back for a
     * full ttl. A driver without lock support, or a lock that cannot be taken in time,
     * falls back to the unsynchronised path rather than failing the request.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    private function withBucketLock(string $sessionKey, callable $callback): mixed
    {
        $store = method_exists($this->cache, 'getStore') ? $this->cache->getStore() : null;

        if (! $store instanceof LockProvider) {
            return $callback();
        }

        try {
            return $store->lock(self::KEY_PREFIX.'lock:'.sha1($sessionKey), 5)->block(1, $callback);
        } catch (LockTimeoutException) {
            return $callback();
        }
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function raw(string $sessionKey): array
    {
        /** @var mixed $stored */
        $stored = $this->cache->get($this->cacheKey($sessionKey));

        if (! is_array($stored)) {
            return [];
        }

        $profiles = [];

        foreach ($stored as $id => $profile) {
            if (is_string($id) && is_array($profile)) {
                $profiles[$id] = $profile;
            }
        }

        return $profiles;
    }

    /**
     * @param  array<string, mixed>  $profile
     * @return array{id: string, label: string, base_url: string, server_variables: array<string, string>, scheme: string, credential_header: string|null, credential_hint: string}
     */
    private static function withoutCredential(array $profile): array
    {
        $header = $profile['credential_header'] ?? null;

        return [
            'id' => is_string($profile['id'] ?? null) ? $profile['id'] : '',
            'label' => is_string($profile['label'] ?? null) ? $profile['label'] : '',
            'base_url' => is_string($profile['base_url'] ?? null) ? $profile['base_url'] : '',
            // Additive field: a profile written before it existed carries no key
            // at all, and decodes to an empty map instead of a type error.
            'server_variables' => self::serverVariables($profile['server_variables'] ?? []),
            'scheme' => is_string($profile['scheme'] ?? null) ? $profile['scheme'] : 'bearer',
            'credential_header' => is_string($header) ? $header : null,
            'credential_hint' => is_string($profile['credential_hint'] ?? null) ? $profile['credential_hint'] : '****',
        ];
    }

    /**
     * Non-secret profile data: the values the panel substitutes into a server
     * template. They are returned in the clear like `base_url` and are never
     * masked — and, for the same reason, never written to a log line, so a value
     * a user pastes in here cannot turn into a leak on some other path.
     *
     * @return array<string, string>
     */
    private static function serverVariables(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $variables = [];

        foreach ($value as $key => $item) {
            if (is_bool($item)) {
                $item = $item ? 'true' : 'false';
            } elseif (is_string($item) || is_int($item) || is_float($item)) {
                $item = trim((string) $item);
            } else {
                continue;
            }

            $name = trim((string) $key);

            // An over-long name or value is dropped rather than truncated: a
            // shortened server variable would silently point the outbound
            // request at a different URL than the one the user typed.
            // A value that is empty after trimming is dropped rather than stored: the
            // substitution treats an empty value as absent and falls back to the spec
            // default, so storing it would point the request at a host the panel shows
            // as empty.
            if ($name === ''
                || $item === ''
                || mb_strlen($name) > self::MAX_SERVER_VARIABLE_LENGTH
                || mb_strlen($item) > self::MAX_SERVER_VARIABLE_LENGTH) {
                continue;
            }

            $variables[$name] = $item;

            if (count($variables) >= self::MAX_SERVER_VARIABLES) {
                break;
            }
        }

        return $variables;
    }

    private static function mask(string $credential): string
    {
        $length = mb_strlen($credential);

        if ($length <= 8) {
            return str_repeat('*', 4);
        }

        return str_repeat('*', 4).mb_substr($credential, -4);
    }

    /**
     * The raw session id is hashed so it never appears in a cache key, which can
     * surface in driver logs.
     */
    private function cacheKey(string $sessionKey): string
    {
        return self::KEY_PREFIX.hash('sha256', $sessionKey);
    }

    private function ttl(): int
    {
        /** @var mixed $ttl */
        $ttl = config('api-dock.try_it.ttl', self::DEFAULT_TTL);
        $ttl = is_numeric($ttl) ? (int) $ttl : self::DEFAULT_TTL;

        return $ttl > 0 ? $ttl : self::DEFAULT_TTL;
    }

    private static function maxProfiles(): int
    {
        /** @var mixed $max */
        $max = config('api-dock.try_it.max_profiles', 10);
        $max = is_numeric($max) ? (int) $max : 10;

        return $max > 0 ? $max : 10;
    }
}
