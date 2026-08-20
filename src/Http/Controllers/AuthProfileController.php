<?php

declare(strict_types=1);

namespace LvntR\ApiDock\Http\Controllers;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;
use InvalidArgumentException;
use LvntR\ApiDock\Support\AuthProfileStore;
use LvntR\ApiDock\Support\OutboundRequestGuard;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * The panel's only way to hand a credential to the server.
 *
 * The credential arrives here once, is encrypted into the session-scoped store,
 * and is never returned: every read path on this controller answers with the
 * masked hint. The panel therefore never holds a usable credential, which is
 * the whole point of proxying try-it requests through the backend.
 */
final class AuthProfileController
{
    /**
     * A server variable name is substituted into a server template, so the names
     * that can ever match are fixed by the placeholder pattern in
     * {@see OutboundRequestGuard::substituteServerTemplate()}.
     * A name outside that shape is refused here rather than dropped downstream:
     * a caller who cannot see the drop will assume the value was stored, and a
     * name that is not a plain identifier is a foothold on the template itself.
     *
     * `\z` rather than `$`: `$` also matches before a trailing newline, which
     * would let `tenant\n` through as a valid identifier.
     *
     * The bounds come from the store's own public constants rather than a copy:
     * the store silently drops what exceeds them, and these rules turn the same
     * violation into a visible 422 — a second literal would drift back into a
     * silent drop.
     */
    private const SERVER_VARIABLE_NAME = '/\A[A-Za-z0-9_.\-]+\z/';

    public function __construct(private readonly AuthProfileStore $profiles) {}

    public function index(): JsonResponse
    {
        if (! self::enabled()) {
            return self::disabled();
        }

        $sessionKey = self::sessionKey();

        if ($sessionKey === null) {
            return self::error('A session is required to use try-it profiles.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse(['profiles' => $this->profiles->all($sessionKey)]);
    }

    public function store(Request $request): JsonResponse
    {
        if (! self::enabled()) {
            return self::disabled();
        }

        $sessionKey = self::sessionKey();

        if ($sessionKey === null) {
            return self::error('A session is required to use try-it profiles.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $data = $request->validate([
            'label' => ['sometimes', 'nullable', 'string', 'max:64'],
            'base_url' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'server_variables' => [
                'sometimes',
                'nullable',
                'array',
                'max:'.AuthProfileStore::MAX_SERVER_VARIABLES,
                self::serverVariableNamesRule(),
            ],
            'server_variables.*' => ['nullable', 'string', 'max:'.AuthProfileStore::MAX_SERVER_VARIABLE_LENGTH],
            'scheme' => ['sometimes', 'nullable', 'string', 'max:16'],
            'credential' => ['required', 'string', 'max:4096'],
            'credential_header' => ['sometimes', 'nullable', 'string', 'max:64'],
        ]);

        try {
            $profile = $this->profiles->put($sessionKey, [
                'label' => self::stringOr($data['label'] ?? null, 'Profile'),
                'base_url' => self::stringOr($data['base_url'] ?? null, ''),
                'server_variables' => self::stringMap($data['server_variables'] ?? []),
                'scheme' => self::stringOr($data['scheme'] ?? null, 'bearer'),
                'credential' => self::stringOr($data['credential'] ?? null, ''),
                'credential_header' => self::stringOr($data['credential_header'] ?? null, ''),
            ]);
        } catch (InvalidArgumentException $exception) {
            // The store's own messages name the scheme or the header, never the value.
            return self::error($exception->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (Throwable) {
            // $data holds the credential in this frame; an exception renderer prints
            // frame arguments, so nothing from below may propagate out of here.
            return self::error('The try-it profile could not be stored.', Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        // Already masked by the store: `credential_hint`, never `credential`.
        return new JsonResponse(['profile' => $profile], Response::HTTP_CREATED);
    }

    public function destroy(string $profile): JsonResponse
    {
        if (! self::enabled()) {
            return self::disabled();
        }

        $sessionKey = self::sessionKey();

        if ($sessionKey === null) {
            return self::error('A session is required to use try-it profiles.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->profiles->forget($sessionKey, $profile);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    private static function enabled(): bool
    {
        return (bool) config('api-dock.try_it.enabled', false);
    }

    private static function disabled(): JsonResponse
    {
        return self::error(
            'The API Dock try-it proxy is disabled. Enable api-dock.try_it.enabled to use it.',
            Response::HTTP_FORBIDDEN,
        );
    }

    private static function error(string $message, int $status): JsonResponse
    {
        return new JsonResponse(['message' => $message], $status);
    }

    private static function stringOr(mixed $value, string $fallback): string
    {
        return is_string($value) && trim($value) !== '' ? $value : $fallback;
    }

    /**
     * Bounds the map's names. `server_variables.*` covers the values and nothing
     * covers the keys, so without this a name of any shape or length reaches the
     * store and is discarded there without a word.
     *
     * The message states the allowed shape and never repeats the rejected name:
     * this request also carries the credential, and nothing read out of it is
     * echoed back.
     */
    private static function serverVariableNamesRule(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            // A non-array value is already reported by the `array` rule.
            if (! is_array($value)) {
                return;
            }

            foreach (array_keys($value) as $name) {
                $name = (string) $name;

                if (mb_strlen($name) > AuthProfileStore::MAX_SERVER_VARIABLE_LENGTH
                    || preg_match(self::SERVER_VARIABLE_NAME, $name) !== 1) {
                    $fail(sprintf(
                        'Each server variable name must be 1 to %d letters, digits, underscores or dashes.',
                        AuthProfileStore::MAX_SERVER_VARIABLE_LENGTH,
                    ));

                    return;
                }
            }
        };
    }

    /**
     * The validated map minus the entries the rules let through as null. The store
     * bounds this a second time; the rules above are what make a violation visible
     * instead of silent.
     *
     * @return array<string, string>
     */
    private static function stringMap(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $map = [];

        foreach ($value as $key => $item) {
            if (is_string($item)) {
                $map[(string) $key] = $item;
            }
        }

        return $map;
    }

    /**
     * Fails closed on an unstarted session — see the note on the same helper in
     * {@see ProxyController}: without StartSession the id is process-scoped, so
     * a persistent worker would hand every visitor the same credential bucket.
     */
    private static function sessionKey(): ?string
    {
        try {
            if (! Session::isStarted()) {
                return null;
            }

            $id = Session::getId();
        } catch (Throwable) {
            return null;
        }

        return is_string($id) && $id !== '' ? $id : null;
    }
}
