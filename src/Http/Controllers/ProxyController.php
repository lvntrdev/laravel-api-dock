<?php

declare(strict_types=1);

namespace LvntR\ApiDock\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;
use JsonException;
use LvntR\ApiDock\Support\AuthProfileStore;
use LvntR\ApiDock\Support\OutboundRequestGuard;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Executes a try-it request on behalf of the documentation panel.
 *
 * Every outbound call in this package goes through {@see OutboundRequestGuard};
 * there is no second path. The feature is off by default and only an operator's
 * explicit configuration turns it on.
 */
final class ProxyController
{
    /** @var list<string> */
    private const BODYLESS_METHODS = ['GET', 'HEAD'];

    public function __construct(
        private readonly OutboundRequestGuard $guard,
        private readonly AuthProfileStore $profiles,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {

        if (! (bool) config('api-dock.try_it.enabled', false)) {
            return self::error(
                'The API Dock try-it proxy is disabled. Enable api-dock.try_it.enabled to use it.',
                Response::HTTP_FORBIDDEN,
            );
        }

        $data = $request->validate([
            'method' => ['required', 'string', 'max:10'],
            'url' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'server' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'server_variables' => ['sometimes', 'array'],
            'server_variables.*' => ['nullable', 'string', 'max:255'],
            'server_variable_spec' => ['sometimes', 'array'],
            'path' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'query' => ['sometimes', 'array'],
            'query.*' => ['nullable', 'string', 'max:2048'],
            'headers' => ['sometimes', 'array'],
            'headers.*' => ['nullable', 'string', 'max:4096'],
            'profile' => ['sometimes', 'nullable', 'string', 'max:64'],
        ]);

        $method = strtoupper(trim(is_string($data['method']) ? $data['method'] : ''));

        if (! in_array($method, self::allowedMethods(), true)) {
            return self::error('The requested HTTP method is not allowed.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $target = $this->buildTargetUrl($data);
        } catch (RuntimeException $exception) {
            return self::error($exception->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($target === null) {
            return self::error('A target url or server template is required.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $headers = OutboundRequestGuard::filterRequestHeaders(self::stringMap($data['headers'] ?? []));

        $body = null;
        $contentType = null;

        if ($request->exists('body') && ! in_array($method, self::BODYLESS_METHODS, true)) {
            try {
                $body = self::encodeBody($request->input('body'));
            } catch (JsonException) {
                return self::error('The request body could not be encoded as JSON.', Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            $contentType = self::headerValue($headers, 'content-type') ?? 'application/json';

            // The cap runs in both directions. A throttle bounds how OFTEN the
            // panel can call out, not how much this server buffers and ships per
            // call, so an unbounded body is memory and egress an operator never
            // agreed to.
            $maxRequestBytes = self::maxRequestBytes();

            if ($body !== null && strlen($body) > $maxRequestBytes) {
                return self::error(
                    'The request body exceeds the configured try-it limit of '.$maxRequestBytes.' bytes.',
                    Response::HTTP_UNPROCESSABLE_ENTITY,
                );
            }
        }

        $profileId = is_string($data['profile'] ?? null) ? $data['profile'] : null;

        if ($profileId !== null && $profileId !== '') {
            $applied = $this->applyProfile($headers, $profileId);

            if ($applied instanceof JsonResponse) {
                return $applied;
            }

            $headers = $applied;
        }

        try {
            $result = $this->guard->send($method, $target, $headers, $body, $contentType);
        } catch (RuntimeException $exception) {
            // Guard messages describe the host, scheme or variable that was refused.
            // They never carry a header, and therefore never a credential.
            return self::error($exception->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (Throwable $exception) {
            // The CLASS name only — never the message, and never a chained cause.
            // An upstream exception can quote the request it was building, and the
            // outbound headers are an argument of this stack frame, so nothing
            // beyond the type of failure may leave here. Without this much, every
            // failure looked identical from the panel and was undiagnosable.
            return self::error(
                'The try-it request could not be completed ('.class_basename($exception).').',
                Response::HTTP_BAD_GATEWAY,
            );
        }

        $queryPosition = strpos($result['url'], '?');

        return new JsonResponse([
            'status' => $result['status'],
            'headers' => $result['headers'],
            'body' => $result['body'],
            // True when the upstream body was not text: the guard replaced it with
            // a placeholder rather than let malformed UTF-8 reach json_encode().
            'binary' => $result['binary'],
            'truncated' => $result['truncated'],
            'host' => $result['host'],
            // Query stripped: it is echoed back to the panel and never needs to be.
            'url' => $queryPosition === false ? $result['url'] : substr($result['url'], 0, $queryPosition),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function buildTargetUrl(array $data): ?string
    {
        $server = is_string($data['server'] ?? null) ? trim($data['server']) : '';
        $url = is_string($data['url'] ?? null) ? trim($data['url']) : '';

        if ($server !== '') {
            $target = $this->guard->substituteServerTemplate(
                $server,
                self::stringMap($data['server_variables'] ?? []),
                is_array($data['server_variable_spec'] ?? null) ? $data['server_variable_spec'] : [],
            );
        } elseif ($url !== '') {
            $target = $url;
        } else {
            return null;
        }

        $path = is_string($data['path'] ?? null) ? trim($data['path']) : '';

        if ($path !== '') {
            $target = rtrim($target, '/').(str_starts_with($path, '/') ? $path : '/'.$path);
        }

        $query = self::stringMap($data['query'] ?? []);

        if ($query !== []) {
            $target .= (str_contains($target, '?') ? '&' : '?').http_build_query($query);
        }

        return $target;
    }

    /**
     * @param  array<string, string>  $headers
     * @return array<string, string>|JsonResponse
     */
    private function applyProfile(array $headers, string $profileId): array|JsonResponse
    {
        $sessionKey = self::sessionKey();

        if ($sessionKey === null) {
            return self::error('A session is required to use a stored try-it profile.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $profile = $this->profiles->find($sessionKey, $profileId);

        if ($profile === null) {
            return self::error('The requested try-it profile does not exist for this session.', Response::HTTP_NOT_FOUND);
        }

        $credential = $this->profiles->revealCredentialForOutboundRequest($sessionKey, $profileId);

        if ($credential === null) {
            return self::error('The stored try-it credential could not be read.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $name = $profile['scheme'] === 'header' ? $profile['credential_header'] : 'Authorization';

        if ($name === null) {
            return self::error('The stored try-it profile has no credential header name.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // The client cannot keep its own value alongside the profile's: a duplicate
        // Authorization header would otherwise be sent upstream.
        $headers = self::withoutHeader($headers, $name);

        $headers[$name] = match ($profile['scheme']) {
            'basic' => 'Basic '.base64_encode($credential),
            'header' => $credential,
            default => 'Bearer '.$credential,
        };

        unset($credential);

        return $headers;
    }

    /**
     * @param  array<string, string>  $headers
     * @return array<string, string>
     */
    private static function withoutHeader(array $headers, string $name): array
    {
        $name = strtolower($name);

        return array_filter(
            $headers,
            static fn (string $header): bool => strtolower($header) !== $name,
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * Fails closed on an unstarted session.
     *
     * `Session::getId()` does NOT throw without StartSession — it answers with a
     * process-scoped id. On a persistent worker every visitor would then share
     * one credential bucket if an operator set `api-dock.middleware` to a stack
     * without StartSession. Ownership comes from a real session or from nothing.
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

    private static function maxRequestBytes(): int
    {
        /** @var mixed $max */
        $max = config('api-dock.try_it.max_request_bytes', 262144);
        $max = is_numeric($max) ? (int) $max : 262144;

        return $max > 0 ? $max : 262144;
    }

    /**
     * @return list<string>
     */
    private static function allowedMethods(): array
    {
        /** @var mixed $configured */
        $configured = config('api-dock.try_it.allowed_methods', OutboundRequestGuard::SUPPORTED_METHODS);
        $configured = is_array($configured) ? $configured : [];

        $methods = [];

        foreach ($configured as $method) {
            if (! is_string($method)) {
                continue;
            }

            $method = strtoupper(trim($method));

            // Intersected with the guard's own list so config can narrow the verbs
            // but never widen them.
            if (in_array($method, OutboundRequestGuard::SUPPORTED_METHODS, true)) {
                $methods[] = $method;
            }
        }

        return array_values(array_unique($methods));
    }

    /**
     * @throws JsonException
     */
    private static function encodeBody(mixed $body): ?string
    {
        if ($body === null) {
            return null;
        }

        if (is_string($body)) {
            return $body;
        }

        return json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param  array<string, string>  $headers
     */
    private static function headerValue(array $headers, string $name): ?string
    {
        foreach ($headers as $header => $value) {
            if (strtolower($header) === $name) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    private static function stringMap(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $map = [];

        foreach ($value as $key => $item) {
            if (! is_string($key) || $item === null) {
                continue;
            }

            if (is_string($item) || is_int($item) || is_float($item) || is_bool($item)) {
                $map[$key] = is_bool($item) ? ($item ? 'true' : 'false') : (string) $item;
            }
        }

        return $map;
    }

    private static function error(string $message, int $status): JsonResponse
    {
        return new JsonResponse(['message' => $message], $status);
    }
}
