<?php

declare(strict_types=1);

namespace LvntR\ApiDock\Extensions;

use DateTimeImmutable;
use Dedoc\Scramble\Extensions\OperationExtension;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\RouteInfo;
use LvntR\ApiDock\Attributes\AiChangelog;
use LvntR\ApiDock\Attributes\AiExample;
use LvntR\ApiDock\Attributes\AiHint;
use LvntR\ApiDock\Attributes\AiPitfall;
use LvntR\ApiDock\Attributes\AiTool;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;
use Throwable;

final class AiMetadataOperationExtension extends OperationExtension
{
    public function handle(Operation $operation, RouteInfo $routeInfo): void
    {
        try {
            $class = $this->reflectionClass($routeInfo);
            $method = $routeInfo->reflectionMethod();

            $hint = $this->attributeInstance($method, AiHint::class)
                ?? $this->attributeInstance($class, AiHint::class);

            if ($hint instanceof AiHint) {
                $operation->setExtensionProperty('ai-hint', $hint->hint);
            }

            $examples = [
                ...$this->exampleValues($class),
                ...$this->exampleValues($method),
            ];

            if ($examples !== []) {
                $operation->setExtensionProperty('ai-examples', $examples);
            }

            $pitfalls = $this->sortPitfalls([
                ...$this->pitfallValues($class),
                ...$this->pitfallValues($method),
            ]);

            if ($pitfalls !== []) {
                $operation->setExtensionProperty('ai-pitfalls', $pitfalls);
            }

            $changelog = $this->sortChangelog([
                ...$this->changelogValues($class),
                ...$this->changelogValues($method),
            ]);

            if ($changelog !== []) {
                $operation->setExtensionProperty('api-dock-changelog', $changelog);
            }

            $tool = $this->attributeInstance($method, AiTool::class)
                ?? $this->attributeInstance($class, AiTool::class);

            if ($tool instanceof AiTool) {
                $operation->setExtensionProperty('ai-tool', [
                    'enabled' => $tool->enabled,
                    'name' => $tool->name,
                    'description' => $tool->description,
                ]);
            }
        } catch (Throwable) {
            // Metadata must never prevent the base OpenAPI document from being generated.
        }
    }

    private function reflectionClass(RouteInfo $routeInfo): ?ReflectionClass
    {
        try {
            $className = $routeInfo->className();

            return $className !== null && class_exists($className)
                ? new ReflectionClass($className)
                : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @template T of object
     *
     * @param  ReflectionClass<object>|ReflectionMethod|null  $reflector
     * @param  class-string<T>  $attribute
     * @return T|null
     */
    private function attributeInstance(
        ReflectionClass|ReflectionMethod|null $reflector,
        string $attribute,
    ): ?object {
        if ($reflector === null) {
            return null;
        }

        try {
            $reflectionAttribute = $reflector->getAttributes(
                $attribute,
                ReflectionAttribute::IS_INSTANCEOF,
            )[0] ?? null;

            return $reflectionAttribute?->newInstance();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  ReflectionClass<object>|ReflectionMethod|null  $reflector
     * @return list<array{name: string, request: array<array-key, mixed>, response: array<array-key, mixed>}>
     */
    private function exampleValues(ReflectionClass|ReflectionMethod|null $reflector): array
    {
        if ($reflector === null) {
            return [];
        }

        try {
            return array_values(array_map(
                static function (ReflectionAttribute $attribute): array {
                    /** @var AiExample $example */
                    $example = $attribute->newInstance();

                    return [
                        'name' => $example->name,
                        'request' => $example->request,
                        'response' => $example->response,
                    ];
                },
                $reflector->getAttributes(AiExample::class, ReflectionAttribute::IS_INSTANCEOF),
            ));
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param  ReflectionClass<object>|ReflectionMethod|null  $reflector
     * @return list<array{order: int, text: string}>
     */
    private function pitfallValues(ReflectionClass|ReflectionMethod|null $reflector): array
    {
        if ($reflector === null) {
            return [];
        }

        try {
            return array_values(array_map(
                static function (ReflectionAttribute $attribute): array {
                    /** @var AiPitfall $pitfall */
                    $pitfall = $attribute->newInstance();

                    return [
                        'order' => $pitfall->order,
                        'text' => $pitfall->text,
                    ];
                },
                $reflector->getAttributes(AiPitfall::class, ReflectionAttribute::IS_INSTANCEOF),
            ));
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param  list<array{order: int, text: string}>  $pitfalls
     * @return list<array{order: int, text: string}>
     */
    private function sortPitfalls(array $pitfalls): array
    {
        try {
            $indexed = [];

            foreach ($pitfalls as $position => $pitfall) {
                $indexed[] = ['position' => $position, 'value' => $pitfall];
            }

            usort(
                $indexed,
                static fn (array $left, array $right): int => $left['value']['order'] <=> $right['value']['order']
                    ?: $left['position'] <=> $right['position'],
            );

            return array_values(array_map(
                static fn (array $item): array => $item['value'],
                $indexed,
            ));
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param  ReflectionClass<object>|ReflectionMethod|null  $reflector
     * @return list<array{date: string, summary: string, breaking: bool}>
     */
    private function changelogValues(ReflectionClass|ReflectionMethod|null $reflector): array
    {
        if ($reflector === null) {
            return [];
        }

        try {
            return array_values(array_map(
                static function (ReflectionAttribute $attribute): array {
                    /** @var AiChangelog $entry */
                    $entry = $attribute->newInstance();

                    return [
                        'date' => $entry->date,
                        'summary' => $entry->summary,
                        'breaking' => $entry->breaking,
                    ];
                },
                $reflector->getAttributes(AiChangelog::class, ReflectionAttribute::IS_INSTANCEOF),
            ));
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param  list<array{date: string, summary: string, breaking: bool}>  $changelog
     * @return list<array{date: string, summary: string, breaking: bool}>
     */
    private function sortChangelog(array $changelog): array
    {
        try {
            $indexed = [];

            foreach ($changelog as $position => $entry) {
                $indexed[] = [
                    'position' => $position,
                    'timestamp' => $this->dateTimestamp($entry['date']),
                    'value' => $entry,
                ];
            }

            usort($indexed, static function (array $left, array $right): int {
                if ($left['timestamp'] === null && $right['timestamp'] !== null) {
                    return 1;
                }

                if ($left['timestamp'] !== null && $right['timestamp'] === null) {
                    return -1;
                }

                if ($left['timestamp'] !== $right['timestamp']) {
                    return $right['timestamp'] <=> $left['timestamp'];
                }

                return $left['position'] <=> $right['position'];
            });

            return array_values(array_map(
                static fn (array $item): array => $item['value'],
                $indexed,
            ));
        } catch (Throwable) {
            return [];
        }
    }

    private function dateTimestamp(string $date): ?int
    {
        try {
            $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);

            return $parsed !== false && $parsed->format('Y-m-d') === $date
                ? $parsed->getTimestamp()
                : null;
        } catch (Throwable) {
            return null;
        }
    }
}
