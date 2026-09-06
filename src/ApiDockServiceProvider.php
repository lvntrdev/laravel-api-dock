<?php

declare(strict_types=1);

namespace LvntR\ApiDock;

use Composer\InstalledVersions;
use Dedoc\Scramble\Scramble;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use LvntR\ApiDock\Console\AgentGuideCommand;
use LvntR\ApiDock\Console\DiffCommand;
use LvntR\ApiDock\Console\ExportCommand;
use LvntR\ApiDock\Console\SyncCommand;
use LvntR\ApiDock\Extensions\AiMetadataOperationExtension;
use LvntR\ApiDock\Extensions\FeatureOperationExtension;
use LvntR\ApiDock\Http\Middleware\ApiDockAccess;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

final class ApiDockServiceProvider extends PackageServiceProvider
{
    /**
     * Fallback when the installed version cannot be resolved.
     *
     * The real version is a git tag: Composer packages do not carry a `version`
     * key, and this package is consumed through a VCS repository entry, so the
     * only authority at runtime is Composer's installed metadata.
     */
    public const VERSION = 'dev';

    /**
     * The installed package version, as Composer resolved it.
     *
     * Returns the git tag without its `v` prefix for a tagged install
     * (`0.0.3`), the branch alias for a branch install (`dev-main`), and
     * `self::VERSION` when the package is not a Composer install at all — a
     * local checkout running its own test suite, for instance.
     */
    public static function version(): string
    {
        if (! class_exists(InstalledVersions::class) || ! InstalledVersions::isInstalled('lvntr/api-dock')) {
            return self::VERSION;
        }

        $version = InstalledVersions::getPrettyVersion('lvntr/api-dock');

        // A root package with no tag on HEAD reports `1.0.0+no-version-set`,
        // which is a placeholder rather than a version anyone shipped.
        if (! is_string($version) || $version === '' || str_contains($version, 'no-version-set')) {
            return self::VERSION;
        }

        return ltrim($version, 'v');
    }

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
        $this->registerDefaultAccessGate();

        // The compiled panel lives in `public/vendor/api-dock`, which Composer never touches:
        // without a republish an upgraded package keeps serving the previous bundle. Laravel's
        // own `post-autoload-dump` script republishes everything tagged `laravel-assets` with
        // `--force`, so registering the directory under that tag as well makes `composer update`
        // refresh it on its own. Spatie's `hasAssets()` registers the same directory under
        // `api-dock-assets`, which stays available for a deliberate manual publish.
        if ($this->app->runningInConsole()) {
            $this->publishes([
                $this->package->basePath('/../resources/dist') => public_path('vendor/api-dock'),
            ], 'laravel-assets');
        }

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
     * Register the deny-all `viewApiDock` default, so the optional gate fails closed.
     *
     * Only when `gate.enabled` is on and the application has not defined the ability
     * itself. Laravel already denies an ability nobody defined, so this does not create
     * the refusal — it makes it a deliberate, inspectable one: `Gate::has('viewApiDock')`
     * answers true, and an operator who turns the gate on and misspells the ability name
     * finds a registered refusal rather than a silent fallthrough.
     *
     * The `has()` guard is load-bearing in BOTH boot orders. An auto-discovered package
     * provider boots BEFORE the application's own providers, so `has()` is normally false
     * here and the host's later `Gate::define()` overwrites this default — which is the
     * intent. When this provider is instead listed after the host's provider in
     * `bootstrap/providers.php`, `has()` is already true and we must NOT overwrite:
     * clobbering it would revoke an ability the application deliberately granted and lock
     * out the very admins it was defined for.
     */
    private function registerDefaultAccessGate(): void
    {
        if (! (bool) config('api-dock.gate.enabled', false)) {
            return;
        }

        if (Gate::has(ApiDockAccess::ABILITY)) {
            return;
        }

        // The parameter is nullable so the refusal also covers an unauthenticated
        // request: given a callback whose first parameter does not admit null, Laravel
        // skips it for a guest instead of running it.
        Gate::define(
            ApiDockAccess::ABILITY,
            static fn (?Authenticatable $user): bool => false,
        );
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
