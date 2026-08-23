<?php

declare(strict_types=1);

namespace LvntR\ApiDock\Support;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Support\Facades\Session;
use InvalidArgumentException;

/**
 * Try-it credentials, held for exactly as long as the session that created them.
 *
 * The profiles live IN the session payload, not in the cache beside it: a reader
 * keeps a profile until they log out (or their session lapses), and nothing else
 * has to be kept in sync with the session's own lifetime. There is no separate
 * expiry to configure, and logging out — which invalidates the session — takes
 * every stored credential with it.
 *
 * The credential is encrypted with the application encrypter before it reaches the
 * session driver, and it is only ever handed back in plaintext through the one
 * method whose name says so. Every other read path returns a masked hint, so a
 * credential cannot reach a response, a log line or a debug dump by accident.
 *
 * The session is read and written through the facade rather than an injected
 * instance on purpose: the store must always act on the session THIS request
 * started, and a long-lived worker that reuses a resolved instance would
 * otherwise carry another request's session into this one.
 */
final readonly class AuthProfileStore
{
    /** @var list<string> */
    public const SCHEMES = ['bearer', 'basic', 'header'];

    private const SESSION_KEY = 'api-dock.try-it.profiles';

    /**
     * Server variables are plain profile data, so nothing else bounds them: they
     * carry no credential and pass through no other guard. Without a ceiling on
     * the entry count and on each name and value, this one field could inflate the
     * session payload that every request unserializes in full.
     */
    public const MAX_SERVER_VARIABLES = 20;

    public const MAX_SERVER_VARIABLE_LENGTH = 255;

    public function __construct(private Encrypter $encrypter) {}

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

        $profiles = $this->raw();
        $profiles[$id] = $record;

        // Every request unserializes the whole session payload, so an uncapped list
        // would grow the cost of every page load in the application. The oldest
        // entries go first.
        $max = self::maxProfiles();

        if (count($profiles) > $max) {
            $profiles = array_slice($profiles, -$max, null, true);
        }

        $this->write($profiles);

        return self::withoutCredential($profiles[$id]);
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
        $profiles = $this->raw();

        if (! array_key_exists($profileId, $profiles)) {
            return;
        }

        unset($profiles[$profileId]);

        $this->write($profiles);
    }

    public function flush(): void
    {
        Session::forget(self::SESSION_KEY);
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
        /** @var mixed $stored */
        $stored = Session::get(self::SESSION_KEY);

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
     * @param  array<string, array<string, mixed>>  $profiles
     */
    private function write(array $profiles): void
    {
        // An empty list is removed rather than stored: a session that carries an
        // empty array forever is a key the driver keeps writing for nothing.
        if ($profiles === []) {
            Session::forget(self::SESSION_KEY);

            return;
        }

        Session::put(self::SESSION_KEY, $profiles);
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
