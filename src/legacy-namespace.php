<?php

declare(strict_types=1);

use Composer\Autoload\ClassLoader;

// BC shim for the old `LvntR\ApiDock` spelling, removed together with the second PSR-4 prefix.
// That prefix covers normal autoloading, but `--classmap-authoritative` consults only the
// classmap, which lists the declared `Lvntr` spelling. The file is looked up and required
// directly: a nested class_exists() would be refused by PHP's autoload recursion guard,
// which keys on the lowercased name both spellings share.
spl_autoload_register(static function (string $class): void {
    if (! str_starts_with($class, 'LvntR\\ApiDock\\')) {
        return;
    }

    foreach (ClassLoader::getRegisteredLoaders() as $loader) {
        $file = $loader->findFile('Lvntr'.substr($class, 5));

        if ($file !== false) {
            require_once $file;

            return;
        }
    }
});
