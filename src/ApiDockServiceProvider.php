<?php

declare(strict_types=1);

namespace LvntR\ApiDock;

use Dedoc\Scramble\Scramble;
use LvntR\ApiDock\Console\AgentGuideCommand;
use LvntR\ApiDock\Console\DiffCommand;
use LvntR\ApiDock\Console\ExportCommand;
use LvntR\ApiDock\Console\SyncCommand;
use LvntR\ApiDock\Extensions\AiMetadataOperationExtension;
use LvntR\ApiDock\Extensions\FeatureOperationExtension;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

final class ApiDockServiceProvider extends PackageServiceProvider
{
    public const VERSION = 'dev';

    public function configurePackage(Package $package): void
    {
        $package
            ->name('api-dock')
            ->hasConfigFile()
            ->hasRoutes('api-dock')
            ->hasViews()
            ->hasAssets()
            ->hasCommands(...$this->commandClasses());
    }

    public function packageBooted(): void
    {
        // Appended to the host application's OWN Scramble API rather than a private
        // one. `Scramble::registerApi()` CLONES the default config at the moment it
        // is called (GeneratorConfigCollection::register), so a package that
        // registers during its own boot silently freezes a copy taken before the
        // application configured Scramble in its AppServiceProvider: its route
        // filter, its servers, its operation transformers — all missing, and the
        // documented base URL pointing at a host the API does not answer on.
        // Appending is order-independent, so it does not matter who boots first.
        //
        // Deferred to `booted` only so the default config is guaranteed to exist.
        $this->app->booted(function (): void {
            Scramble::configure(self::scrambleApi())
                ->withOperationTransformers([
                    AiMetadataOperationExtension::class,
                    FeatureOperationExtension::class,
                ]);
        });
    }

    /**
     * The Scramble API this package documents.
     *
     * `default` is the one an application configures with a bare
     * `Scramble::configure()`. An application that keeps its documented surface in
     * a named API points this at that name instead.
     */
    public static function scrambleApi(): string
    {
        /** @var mixed $api */
        $api = config('api-dock.scramble_api', Scramble::DEFAULT_API);

        return is_string($api) && $api !== '' ? $api : Scramble::DEFAULT_API;
    }

    /**
     * URL for a published package asset, fingerprinted by its own mtime.
     *
     * The published file keeps one filename forever, so without this a browser
     * that cached `api-dock.js` once serves that copy after every upgrade: the
     * operator republishes, sees the new bytes on disk, and the page still runs
     * the old bundle. The mtime changes on exactly the event that matters —
     * `vendor:publish` overwriting the file — and needs no build manifest.
     */
    public static function assetUrl(string $file): string
    {
        $path = public_path('vendor/api-dock/'.$file);
        $version = is_file($path) ? (string) filemtime($path) : self::VERSION;

        return asset('vendor/api-dock/'.$file).'?id='.$version;
    }

    /**
     * Console commands are added here by the command-owning package tasks.
     *
     * @return array<class-string>
     */
    private function commandClasses(): array
    {
        return [
            SyncCommand::class,
            DiffCommand::class,
            ExportCommand::class,
            AgentGuideCommand::class,
        ];
    }
}
