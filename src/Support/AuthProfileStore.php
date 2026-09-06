<?php

declare(strict_types=1);

namespace LvntR\ApiDock\Support;

use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use InvalidArgumentException;
use RuntimeException;

/**
 * Try-it credentials, in one of two storage modes.
 *
 * SESSION MODE (the default, `try_it.profile_persistence.enabled` off). The
 * profiles live IN the session payload, not in the cache beside it: a reader
 * keeps a profile until they log out (or their session lapses), and nothing else
 * has to be kept in sync with the session's own lifetime. There is no separate
 * expiry to configure, and logging out — which invalidates the session — takes
 * every stored credential with it.
 *
 * PERSISTENT MODE (opt-in). The profiles move to the cache under a key derived
 * from the authenticated user id, with a configurable TTL, so a token survives a
 * logout and is still there at the next login. The trade-off is exactly that
 * sentence read as a threat: in this mode logging out does NOT purge a stored
 * credential. Only an explicit delete — `forget()` or `flush()` — or TTL expiry
 * removes it. That is a deliberate, opt-in cost of the feature, not an oversight;
 * a deployment where a shared workstation is in the threat model leaves the flag
 * off and keeps the session guarantee above.
 *
 * A request with no authenticated user falls back to session mode even when the
 * flag is on. A guest's only identity IS the session, so there is nothing stable
 * to key persistent storage on, and any weaker key would let one visitor read the
 * next one's credential. The fallback is fail-closed by construction: the narrower
 * lifetime is the one chosen when identity is missing.
 *
 * The credential is encrypted with the application encrypter before it reaches
 * EITHER driver, and it is only ever handed back in plaintext through the one
 * method whose name says so. Every other read path returns a masked hint, so a
 * credential cannot reach a response, a log line or a debug dump by accident.
 *
 * The session and the user id are read through their facades rather than an
 * injected instance on purpose: the store must always act on the session and the
 * identity of THIS request, and a long-lived worker that reuses a resolved
 * instance would otherwise carry another request's session — or another user's id
 * — into this one. The cache repository carries no such per-request state and is
 * injected normally.
 */
final readonly class AuthProfileStore
{
    /** @var list<string> */
    public const SCHEMES = ['bearer', 'basic', 'header'];

    /**
     * The two storage modes, as the panel names them. The strings are part of the
     * view contract (`data-profile-storage`), so the copy a reader sees about how
     * long their credential lives is derived from this class rather than from a
     * second reading of the config that could drift away from it.
     */
    public const MODE_SESSION = 'session';

    public const MODE_PERSISTENT = 'persistent';

    private const SESSION_KEY = 'api-dock.try-it.profiles';

    /**
     * Persistent-mode key prefix. The authenticated user id is appended, so two
     * users can never read each other's profiles out of a shared cache store.
     */
    private const CACHE_KEY_PREFIX = 'api-dock.try-it.profiles.';

    private const DEFAULT_TTL_MINUTES = 60 * 24 * 30;

    /**
     * Server variables are plain profile data, so nothing else bounds them: they
     * carry no credential and pass through no other guard. Without a ceiling on
     * the entry count and on each name and value, this one field could inflate the
     * session payload that every request unserializes in full.
     */
    public const MAX_SERVER_VARIABLES = 20;

    public const MAX_SERVER_VARIABLE_LENGTH = 255;

    public function __construct(
        private Encrypter $encrypter,
        private CacheRepository $cache,
    ) {}

    /**
     * @param  array{label?: string, base_url?: string, scheme?: string, credential?: string, credential_header?: string, server_variables?: array<string, string>}  $attributes
     * @return array{id: string, label: string, base_url: string, server_variables: array<string, string>, scheme: string, credential_header: string|null, credential_hint: string}
     */
    public function put(array $attributes): array
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
            // Ciphertext only. The plaintext never touches the session driver.
            'credential' => $this->encrypter->encrypt($credential),
        ];

        $this->mutate(function (array $profiles) use ($id, $record): array {
            $profiles[$id] = $record;

            // Every request unserializes the whole session payload, so an uncapped
            // list would grow the cost of every page load in the application. The
            // oldest entries go first.
            $max = self::maxProfiles();

            if (count($profiles) > $max) {
                $profiles = array_slice($profiles, -$max, null, true);
            }

            return $profiles;
        });

        return self::withoutCredential($record);
    }

    /**
     * Masked view of every profile in the session. This is the default read path.
     *
     * @return list<array{id: string, label: string, base_url: string, server_variables: array<string, string>, scheme: string, credential_header: string|null, credential_hint: string}>
     */
    public function all(): array
    {
        return array_values(array_map(
            static fn (array $profile): array => self::withoutCredential($profile),
            $this->raw(),
        ));
    }

    /**
     * Masked view of a single profile.
     *
     * @return array{id: string, label: string, base_url: string, server_variables: array<string, string>, scheme: string, credential_header: string|null, credential_hint: string}|null
     */
    public function find(string $profileId): ?array
    {
        $profile = $this->raw()[$profileId] ?? null;

        return $profile === null ? null : self::withoutCredential($profile);
    }

    public function forget(string $profileId): void
    {
        $this->mutate(function (array $profiles) use ($profileId): array {
            unset($profiles[$profileId]);

            return $profiles;
        });
    }

    public function flush(): void
    {
        // Both stores are cleared, not just the active one — and the persistent
        // key is derived independently of whether persistence is CURRENTLY
        // enabled. Flipping the flag on leaves whatever session mode already
        // wrote behind it; flipping it back off must not strand whatever
        // persistent mode already wrote either, or a re-enable inside the TTL
        // window would resurrect credentials this call claimed to have deleted.
        Session::forget(self::SESSION_KEY);

        $key = self::userCacheKey();

        if ($key !== null) {
            $this->cache->forget($key);
        }
    }

    /**
     * Which store THIS request's profiles actually live in — the value the panel
     * renders its credential-lifetime copy from.
     *
     * It is deliberately not the config flag on its own: a request with no
     * authenticated user falls back to the session even with persistence enabled,
     * and telling that reader their credential outlives logout would be exactly as
     * wrong as the reverse. Reads through the same two predicates the storage path
     * itself uses, so the sentence shown and the store written cannot diverge.
     *
     * @return self::MODE_*
     */
    public static function storageModeForCurrentRequest(): string
    {
        return self::persistenceEnabled() && self::userCacheKey() !== null
            ? self::MODE_PERSISTENT
            : self::MODE_SESSION;
    }

    /**
     * The ONLY method that returns plaintext. Call it while building the outbound
     * request and nowhere else — never to render, log or echo the value back.
     */
    public function revealCredentialForOutboundRequest(string $profileId): ?string
    {
        $profile = $this->raw()[$profileId] ?? null;

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
     * @return array<string, array<string, mixed>>
     */
    private function raw(): array
    {
        $key = $this->cacheKey();

        /** @var mixed $stored */
        $stored = $key === null
            ? Session::get(self::SESSION_KEY)
            : $this->cache->get($key);

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
     * Runs a read-modify-write against the active store as one atomic step.
     *
     * In session mode a request already holds that session's own file lock for
     * its whole lifetime, so two requests against one session already run one
     * after another — no extra lock is needed there. In persistent mode the
     * cache key is shared across every concurrent session of the same user, and
     * a cache store carries no such lock on its own: two overlapping requests
     * could both read the same old array, and the later `put()` would silently
     * discard the other's change — a newly created profile lost, or a deleted
     * one reappearing. Where the store supports an atomic lock (every bundled
     * driver except a few does) the read-modify-write is wrapped in one; where
     * it does not, this stays best-effort rather than claiming a guarantee the
     * store cannot back.
     *
     * @param  callable(array<string, array<string, mixed>>): array<string, array<string, mixed>>  $mutate
     */
    private function mutate(callable $mutate): void
    {
        $key = $this->cacheKey();
        $store = $this->cache->getStore();

        if ($key === null || ! $store instanceof LockProvider) {
            $this->write($mutate($this->raw()));

            return;
        }

        $store->lock($key.':lock', 10)->block(5, function () use ($mutate): void {
            $this->write($mutate($this->raw()));
        });
    }

    /**
     * @param  array<string, array<string, mixed>>  $profiles
     */
    private function write(array $profiles): void
    {
        $key = $this->cacheKey();

        // An empty list is removed rather than stored: a store that carries an
        // empty array forever is a key the driver keeps writing for nothing — and,
        // in persistent mode, one that would keep renewing its own TTL.
        if ($profiles === []) {
            if ($key === null) {
                Session::forget(self::SESSION_KEY);
            } else {
                $this->cache->forget($key);
            }

            return;
        }

        if ($key === null) {
            Session::put(self::SESSION_KEY, $profiles);

            return;
        }

        // The TTL is re-set on every write, so an actively used profile set is not
        // dropped mid-use; an untouched one still expires on schedule.
        //
        // `put()`'s boolean return is checked: a store that no-ops (the `null`
        // driver) or fails outright (a Memcached write error) would otherwise let
        // the caller believe the credential was saved when nothing was written at
        // all. `forget()` below is NOT held to the same check — most drivers return
        // false both when a delete fails AND when the key was simply already gone,
        // and treating that ambiguous case as an error would misfire on every
        // ordinary no-op cleanup.
        if (! $this->cache->put($key, $profiles, now()->addMinutes(self::persistenceTtl()))) {
            throw new RuntimeException('The try-it profile store failed to write to the cache.');
        }
    }

    /**
     * The persistent-mode storage key, or null when this request must use the
     * session instead — the flag is off, or nobody is authenticated.
     */
    private function cacheKey(): ?string
    {
        return self::persistenceEnabled() ? self::userCacheKey() : null;
    }

    /**
     * The persistent-mode key for the current user, regardless of whether
     * persistence is currently enabled. `flush()` uses this form: a delete must
     * reach whatever persistent mode already wrote even if the flag has since
     * been turned off, or turning it back on inside the TTL window would
     * resurrect a credential the user was told was gone. `cacheKey()` (the
     * read/write path) still gates on the flag — those must respect it.
     *
     * Recomputed per call rather than cached on the instance: `Auth::id()` is a
     * property of the request, and a store instance that outlived one request
     * would otherwise write under the previous user's key.
     */
    private static function userCacheKey(): ?string
    {
        /** @var mixed $userId */
        $userId = Auth::id();

        // Anything that is not a plain scalar id — null for a guest, or an exotic
        // key type — falls back to the session. Failing closed to the narrower
        // lifetime is always the safe direction here.
        if (! is_string($userId) && ! is_int($userId)) {
            return null;
        }

        $userId = (string) $userId;

        if ($userId === '') {
            return null;
        }

        // Namespaced by the currently-effective default guard, not just the id:
        // a multi-guard app (`auth:admin,web` with separate providers) or a
        // tenancy package that swaps the default guard per request can resolve
        // two DIFFERENT people to the same numeric id from two different user
        // tables. Without the guard in the key, one of them would read, use and
        // delete the other's stored credential. `shouldUse()` updates this same
        // config value, so it reflects a runtime swap, not just the static
        // `auth.php` default.
        /** @var mixed $guard */
        $guard = config('auth.defaults.guard', 'web');
        $guard = is_string($guard) && $guard !== '' ? $guard : 'web';

        $namespace = self::tenantNamespace();

        return self::CACHE_KEY_PREFIX.($namespace !== null ? $namespace.':' : '').$guard.':'.$userId;
    }

    /**
     * An additional, app-supplied key segment for apps where the guard alone
     * does not make the id unique — a tenant-local user table that restarts ids
     * at 1 per tenant, sharing one cache store across tenants. There is no
     * generic "current tenant" in Laravel itself, so this stays a plain
     * closure the host app configures; the default (none) is correct for the
     * common single-tenant case, where the id is already unique.
     */
    private static function tenantNamespace(): ?string
    {
        /** @var mixed $resolver */
        $resolver = config('api-dock.try_it.profile_persistence.key_namespace');

        if (! is_callable($resolver)) {
            return null;
        }

        $namespace = $resolver();

        return is_string($namespace) && $namespace !== '' ? $namespace : null;
    }

    private static function persistenceEnabled(): bool
    {
        return (bool) config('api-dock.try_it.profile_persistence.enabled', false);
    }

    /**
     * The persistent-mode lifetime in minutes, already sanitised. Public because the
     * panel states it to the reader: a number shown must be the same one the write
     * path applies, not a second reading of the same config key.
     */
    public static function persistenceTtl(): int
    {
        /** @var mixed $ttl */
        $ttl = config('api-dock.try_it.profile_persistence.ttl_minutes', self::DEFAULT_TTL_MINUTES);
        $ttl = is_numeric($ttl) ? (int) $ttl : self::DEFAULT_TTL_MINUTES;

        // A zero or negative TTL makes the cache repository drop the key on the
        // spot instead of storing it, so every save would silently vanish. A
        // misconfigured value falls back to the default rather than doing that.
        return $ttl > 0 ? $ttl : self::DEFAULT_TTL_MINUTES;
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

    private static function maxProfiles(): int
    {
        /** @var mixed $max */
        $max = config('api-dock.try_it.max_profiles', 10);
        $max = is_numeric($max) ? (int) $max : 10;

        return $max > 0 ? $max : 10;
    }
}
