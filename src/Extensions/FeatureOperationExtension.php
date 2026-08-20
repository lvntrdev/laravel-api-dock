<?php

declare(strict_types=1);

namespace LvntR\ApiDock\Extensions;

use Dedoc\Scramble\Extensions\OperationExtension;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\RouteInfo;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use LvntR\ApiDock\Attributes\ApiFeature;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;
use Throwable;

final class FeatureOperationExtension extends OperationExtension
{
    public function handle(Operation $operation, RouteInfo $routeInfo): void
    {
        try {
            $features = [
                'auth' => null,
                'scopes' => [],
                'rate_limit' => null,
                'deprecated' => $this->isDeprecated($routeInfo),
                'stability' => null,
            ];

            $found = $features['deprecated'];
            $route = $this->resolveRoute($routeInfo, $operation);

            if ($route !== null) {
                foreach ($this->middleware($route) as $middleware) {
                    $auth = $this->authFromMiddleware($middleware);

                    if ($auth !== null) {
                        $features['auth'] = $auth;
                        $found = true;
                    }

                    $scopes = $this->scopesFromMiddleware($middleware);

                    if ($scopes !== []) {
                        $features['scopes'] = array_values(array_unique([
                            ...$features['scopes'],
                            ...$scopes,
                        ]));
                        $found = true;
                    }

                    $rateLimit = $this->rateLimitFromMiddleware($middleware);

                    if ($rateLimit !== null) {
                        $features['rate_limit'] = $rateLimit;
                        $found = true;
                    }
                }
            }

            foreach ($this->featureAttributes($routeInfo) as $attribute) {
                $found = $this->applyOverrides($features, $attribute) || $found;
            }

            if (! $found) {
                return;
            }

            $operation->setExtensionProperty('api-dock-features', $features);
        } catch (Throwable) {
            // Feature discovery is best-effort and must not break document generation.
        }
    }

    /**
     * @return list<string>
     */
    private function middleware(Route $route): array
    {
        try {
            return array_values(array_filter(
                $route->gatherMiddleware(),
                static fn (mixed $middleware): bool => is_string($middleware),
            ));
        } catch (Throwable) {
            return [];
        }
    }

    private function authFromMiddleware(string $middleware): ?string
    {
        try {
            [$name, $arguments] = $this->middlewareParts($middleware);
            $shortName = strtolower(class_basename($name));

            if (in_array($shortName, ['auth.basic', 'authenticatewithbasicauth'], true)) {
                return 'basic';
            }

            if (! in_array($shortName, ['auth', 'authenticate'], true)) {
                return null;
            }

            return $arguments[0] ?? 'auth';
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return list<string>
     */
    private function scopesFromMiddleware(string $middleware): array
    {
        try {
            [$name, $arguments] = $this->middlewareParts($middleware);
            $shortName = strtolower(class_basename($name));

            if ($arguments === []) {
                return [];
            }

            if (in_array($shortName, ['can', 'authorize'], true)) {
                return [$arguments[0]];
            }

            if (! in_array($shortName, [
                'scope',
                'scopes',
                'ability',
                'abilities',
                'permission',
                'permissions',
                'checkabilities',
                'checkforanyability',
                'checkscopes',
                'checkforanyscope',
            ], true)) {
                return [];
            }

            $scopes = [];

            foreach ($arguments as $argument) {
                foreach (preg_split('/[|]/', $argument) ?: [] as $scope) {
                    $scope = trim($scope);

                    if ($scope !== '') {
                        $scopes[] = $scope;
                    }
                }
            }

            return array_values(array_unique($scopes));
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return array{limit: int, per: string}|null
     */
    private function rateLimitFromMiddleware(string $middleware): ?array
    {
        try {
            [$name, $arguments] = $this->middlewareParts($middleware);
            $shortName = strtolower(class_basename($name));

            if (! in_array($shortName, [
                'throttle',
                'throttlerequests',
                'throttlerequestswithredis',
            ], true)) {
                return null;
            }

            $limit = $arguments[0] ?? null;
            $minutes = $arguments[1] ?? '1';

            if ($limit === null || preg_match('/^\d+$/', $limit) !== 1) {
                return null;
            }

            if (preg_match('/^\d+(?:\.\d+)?$/', $minutes) !== 1 || (float) $minutes <= 0) {
                return null;
            }

            return [
                'limit' => (int) $limit,
                'per' => $this->rateLimitPeriod((float) $minutes),
            ];
        } catch (Throwable) {
            return null;
        }
    }

    private function rateLimitPeriod(float $minutes): string
    {
        return match ($minutes) {
            1.0 => 'minute',
            60.0 => 'hour',
            1440.0 => 'day',
            default => (floor($minutes) === $minutes
                ? (string) (int) $minutes
                : rtrim(rtrim(number_format($minutes, 6, '.', ''), '0'), '.'))
                .' minutes',
        };
    }

    /**
     * @return array{string, list<string>}
     */
    private function middlewareParts(string $middleware): array
    {
        [$name, $parameters] = array_pad(explode(':', $middleware, 2), 2, null);

        if ($parameters === null || $parameters === '') {
            return [$name, []];
        }

        return [
            $name,
            array_values(array_filter(
                array_map('trim', explode(',', $parameters)),
                static fn (string $parameter): bool => $parameter !== '',
            )),
        ];
    }

    private function isDeprecated(RouteInfo $routeInfo): bool
    {
        try {
            $method = $routeInfo->reflectionMethod();

            // Guard the null case explicitly: `$method?->getAttributes(...) !== []`
            // evaluates to `null !== []`, i.e. true, for every closure route.
            if ($method !== null && $method->getAttributes('Deprecated') !== []) {
                return true;
            }

            $className = $routeInfo->className();

            return $className !== null
                && class_exists($className)
                && (new ReflectionClass($className))->getAttributes('Deprecated') !== [];
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @return list<ApiFeature>
     */
    private function featureAttributes(RouteInfo $routeInfo): array
    {
        try {
            $attributes = [];
            $className = $routeInfo->className();

            if ($className !== null && class_exists($className)) {
                $attributes = [
                    ...$attributes,
                    ...$this->newFeatureInstances(new ReflectionClass($className)),
                ];
            }

            $method = $routeInfo->reflectionMethod();

            if ($method !== null) {
                $attributes = [
                    ...$attributes,
                    ...$this->newFeatureInstances($method),
                ];
            }

            return $attributes;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param  ReflectionClass<object>|ReflectionMethod  $reflector
     * @return list<ApiFeature>
     */
    private function newFeatureInstances(ReflectionClass|ReflectionMethod $reflector): array
    {
        try {
            return array_values(array_map(
                static function (ReflectionAttribute $attribute): ApiFeature {
                    /** @var ApiFeature $feature */
                    $feature = $attribute->newInstance();

                    return $feature;
                },
                $reflector->getAttributes(ApiFeature::class, ReflectionAttribute::IS_INSTANCEOF),
            ));
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param  array{auth: ?string, scopes: list<string>, rate_limit: ?array{limit: int, per: string}, deprecated: bool, stability: ?string}  $features
     */
    private function applyOverrides(array &$features, ApiFeature $attribute): bool
    {
        $found = false;

        if ($attribute->auth !== null) {
            $features['auth'] = $attribute->auth;
            $found = true;
        }

        if ($attribute->scopes !== null) {
            $features['scopes'] = array_values(array_filter(
                $attribute->scopes,
                static fn (mixed $scope): bool => is_string($scope),
            ));
            $found = true;
        }

        if ($attribute->rateLimit !== null) {
            $features['rate_limit'] = [
                'limit' => $attribute->rateLimit,
                'per' => $attribute->rateLimitPer
                    ?? $features['rate_limit']['per']
                    ?? 'minute',
            ];
            $found = true;
        } elseif ($attribute->rateLimitPer !== null && $features['rate_limit'] !== null) {
            $features['rate_limit']['per'] = $attribute->rateLimitPer;
            $found = true;
        }

        if ($attribute->deprecated !== null) {
            $features['deprecated'] = $attribute->deprecated;
            $found = true;
        }

        if ($attribute->stability !== null) {
            $features['stability'] = $attribute->stability;
            $found = true;
        }

        return $found;
    }

    private function resolveRoute(RouteInfo $routeInfo, Operation $operation): ?Route
    {
        try {
            foreach (get_object_vars($routeInfo) as $value) {
                if ($value instanceof Route) {
                    return $value;
                }
            }

            $router = app(Router::class);

            foreach ($router->getRoutes() as $route) {
                if ($this->matchesController($route, $routeInfo)
                    || $this->matchesOperation($route, $operation)) {
                    return $route;
                }
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    private function matchesController(Route $route, RouteInfo $routeInfo): bool
    {
        $className = $routeInfo->className();
        $methodName = $routeInfo->methodName();

        if ($className === null || $methodName === null) {
            return false;
        }

        return ltrim($route->getActionName(), '\\') === ltrim($className.'@'.$methodName, '\\');
    }

    private function matchesOperation(Route $route, Operation $operation): bool
    {
        $operationMethod = strtoupper((string) $operation->method);

        if (! in_array($operationMethod, $route->methods(), true)) {
            return false;
        }

        $routePath = trim($route->uri(), '/');
        $operationPath = trim((string) $operation->path, '/');

        return $routePath === $operationPath
            || str_ends_with($routePath, '/'.$operationPath);
    }
}
