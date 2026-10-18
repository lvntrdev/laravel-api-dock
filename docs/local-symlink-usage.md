# Local development: symlinking this package into another project

Use this when you're changing `api-dock` and want another Laravel app to pick
up the edits live, without publishing a release first.

## Link it

In the consuming app's `composer.json`:

```json
"repositories": [
    {
        "type": "path",
        "url": "../api-dock",
        "options": { "symlink": true }
    }
],
```

Adjust the `url` to wherever this repo actually sits relative to the
consuming app. Then point the requirement at `@dev`:

```json
"require": {
    "lvntr/api-dock": "@dev"
}
```

Run:

```bash
composer update lvntr/api-dock --with-all-dependencies
```

`vendor/lvntr/api-dock` is now a symlink into this repo — edits here show up
in the consuming app immediately, no `composer update` needed per change.

## Serve the built panel live

The panel is served from `public/vendor/api-dock`, which is a **copy** of this
repo's `resources/dist` made by `vendor:publish` — the Composer symlink does not
reach it. Replace that copy with a symlink once, in the consuming app:

```bash
rm -rf public/vendor/api-dock
ln -s ../../vendor/lvntr/api-dock/resources/dist public/vendor/api-dock
```

Every `npm run build` here is then served immediately. The asset URL carries the
file's modification time, so the browser cache does not hold the old bundle. To
undo it, remove the link and run
`php artisan vendor:publish --tag=api-dock-assets --force`.

## Before you link: note the current version

If a real version was already installed, check what it was so you can
restore exactly that, not just "a" version:

```bash
composer show lvntr/api-dock | grep versions
```

## Unlink it (back to the old version)

1. Revert the requirement to the version constraint you noted above (e.g.
   `"^0.0.5"`).
2. Remove only the `path` entry from `repositories`. Keep the `vcs` entry
   from [Installation](../README.md#installation) — the package is not on
   Packagist, so without it Composer cannot find the package. If you
   replaced it while linking, add it back.
3. `composer update lvntr/api-dock`.

`vendor/lvntr/api-dock` goes back to a real download and `composer.lock`
records that exact version again.
