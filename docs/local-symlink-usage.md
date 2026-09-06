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

## Before you link: note the current version

If a real version was already installed, check what it was so you can
restore exactly that, not just "a" version:

```bash
composer show lvntr/api-dock | grep versions
```

## Unlink it (back to the old version)

1. Revert the requirement to the version constraint you noted above (e.g.
   `"^0.0.5"`).
2. Delete the `repositories` block.
3. `composer update lvntr/api-dock`.

`vendor/lvntr/api-dock` goes back to a real download and `composer.lock`
records that exact version again.
