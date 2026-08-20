# API Dock

API Dock turns a Laravel API into documentation that both people and models can read. It builds on
[`dedoc/scramble`](https://github.com/dedoc/scramble) and does not replace it: Scramble keeps deriving the
OpenAPI document from your application code, while API Dock adds the reading surface on top of it.

Install it, visit `/api-dock`, and the API you already have is browsable.

## What you get

- **A documentation browser** — a Vue 3 single-page app served from your own application: endpoint sidebar
  grouped by tag with search, parameter and request-body detail, expandable response and schema trees,
  a light and a dark theme, and an English and Turkish interface.
- **Try it, through your server** — the reader sends the request they are looking at, and the package proxies
  it: host allowlist, DNS-rebinding-safe address pinning, response size cap. Credentials stay server-side,
  encrypted and scoped to the session; the browser only ever holds a masked hint. Disabled until you enable it.
- **An AI prompt tab** — one copy button hands a model the whole operation as context, plus the MCP tool
  definition and the `llms.txt` section for that endpoint on their own.
- **A spec diff tab** — paste the output of `php artisan api-dock:diff --json` and read what changed between
  two versions of the contract, marked breaking, additive or cosmetic.
- **Six PHP attributes** — hints, pitfalls, examples, changelog entries, MCP tool control and feature facts
  (auth, scopes, rate limit, deprecation, stability), authored next to the controller and emitted into the
  OpenAPI document as vendor extensions.
- **Artisan exports** — `llms.txt` and `mcp-tools.json` for agent tooling, plus OpenAPI snapshots and diffs
  you can run in CI.

## Requirements

- PHP `^8.3`
- Laravel components `^12.0` or `^13.0`
- `dedoc/scramble` `^0.13`
- The cURL PHP extension when the try-it proxy is enabled; the proxy fails closed without it because it cannot pin a checked DNS address otherwise

## Installation

The package is not published on Packagist. Point Composer at the repository, then require the package by name:

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/lvntrdev/laravel-api-dock"
        }
    ]
}
```

```bash
composer require lvntr/api-dock:~0.0.1
```

Composer reads the tags of that repository, so a constraint resolves to a released tag exactly as it would through Packagist. The Composer package name stays `lvntr/api-dock` even though the repository is named `laravel-api-dock`. A caret constraint on a `0.0.x` version is exact in Composer — `^0.0.1` allows only `0.0.1` — so this uses `~0.0.1` instead, which accepts every later `0.0.x` release, matching what a plain `composer update` needs while the package is pre-0.1.

Laravel discovers `LvntR\ApiDock\ApiDockServiceProvider` through the package manifest. Publish only what the application needs:

```bash
php artisan vendor:publish --tag=api-dock-config
php artisan vendor:publish --tag=api-dock-assets
php artisan vendor:publish --tag=api-dock-views
```

The `api-dock-config` tag writes `config/api-dock.php`. The `api-dock-assets` tag copies the built SPA files to `public/vendor/api-dock`. The `api-dock-views` tag writes the overridable view to `resources/views/vendor/api-dock`.

The service provider registers the Scramble API under the name `api-dock`. Its route filter includes application routes whose URI starts with `api/`. API Dock then uses that named generator for the spec route, snapshots, diffs, and exports.

With the default `route_prefix`, the package registers these routes:

| Method | Route | Name | Purpose |
| --- | --- | --- | --- |
| `GET` | `/api-dock` | `api-dock.docs` | Documentation SPA |
| `GET` | `/api-dock/spec` | `api-dock.spec` | Generated OpenAPI JSON |
| `POST` | `/api-dock/try-it` | `api-dock.try-it` | Proxied outbound request |
| `GET` | `/api-dock/try-it/profiles` | `api-dock.try-it.profiles.index` | List masked session profiles |
| `POST` | `/api-dock/try-it/profiles` | `api-dock.try-it.profiles.store` | Store a session credential |
| `DELETE` | `/api-dock/try-it/profiles/{profile}` | `api-dock.try-it.profiles.destroy` | Remove a session profile |

All paths change with `route_prefix`. Every route receives the configured `middleware` followed by API Dock's own enabled check. The try-it and profile routes also receive the configured throttle.

## Updating

```bash
composer update lvntr/api-dock
```

That single command is enough. The compiled panel in `public/vendor/api-dock` is republished automatically:
the package registers that directory under Laravel's `laravel-assets` tag, and Laravel's own
`post-autoload-dump` script runs `vendor:publish --tag=laravel-assets --force` after every Composer command.
Nothing to remember.

If an application removed that script from its `composer.json`, republish by hand instead:

```bash
php artisan vendor:publish --tag=api-dock-assets --force
```

The asset URL is fingerprinted with the file's own modification time, so a republished file reaches the
browser immediately — no cache busting of your own is needed.

Composer caches the repository's tag list per project. When a freshly released version stays invisible, run
`composer clear-cache` and update again.

If you published the config file, `composer update` does not touch it. New **top-level** keys fall back to the
package defaults automatically, but a key added inside an existing group — anything under `try_it`, for
example — does not, because the merge is one level deep. After a release that adds configuration, compare your
`config/api-dock.php` with `vendor/lvntr/api-dock/config/api-dock.php` and copy over what is missing. The same
applies to a view you overrode under `resources/views/vendor/api-dock`: it keeps its old markup, including the
asset tags, until you reconcile it yourself.

Until version 1.0 a minor bump may change behaviour. Read the release notes for the tag you are moving to
before rolling it out.

## Before you deploy

- **No authentication ships on the documentation routes.** The only gate is `api-dock.enabled`, and the default
  middleware stack is `['web']`. The generated document exposes your internal API surface, so put the routes
  behind your own auth middleware — the `middleware` config key in
  [Configuration reference](#configuration-reference) — before deploying anywhere public.
- **The try-it proxy is off by default.** Turn it on deliberately, keep `try_it.allowed_hosts` and
  `try_it.allowed_methods` as narrow as the job needs, and read [Try-it security contract](#try-it-security-contract) first.
- Session credentials are stored in the configured cache, encrypted with the application encrypter and scoped
  to the session, and expire after `try_it.ttl` of inactivity. They are not kept in the browser.
- MCP, `llms.txt` and diff artifacts are produced by Artisan, not by HTTP endpoints — nothing extra is exposed
  on the route table.
- A non-text upstream response is not proxied back verbatim. A body that is not valid UTF-8 is replaced with a
  short placeholder and the proxy response carries `binary: true`, because the panel's JSON transport cannot
  carry raw bytes.

## Configuration reference

These are all keys shipped by `config/api-dock.php`.

| Key | Default | Effect |
| --- | --- | --- |
| `enabled` | `true` | Enables the API Dock HTTP surface. When false, API Dock routes return 404. |
| `route_prefix` | `'api-dock'` | Prefix applied to every package route. |
| `middleware` | `['web']` | Host middleware applied before API Dock's access check. Use this to restrict the documentation and proxy surface. |
| `ai.export_path` | `storage_path('api-dock')` | Default directory for generated AI and OpenAPI export files. |
| `ai.include_examples` | `true` | Includes `x-ai-examples` sections in `llms.txt`. It does not remove examples from the OpenAPI document. |
| `ai.mcp_opt_in` | `false` | When false, all operations except those with `AiTool(enabled: false)` become MCP tools. When true, only operations with `AiTool(enabled: true)` are exported. |
| `snapshot.path` | `storage_path('api-dock/openapi.json')` | Stored OpenAPI snapshot read by `sync` and `diff`. |
| `try_it.enabled` | `false` | Enables outbound try-it requests and credential-profile endpoints. |
| `try_it.allowed_hosts` | `[]` | Host allowlist. Empty denies all hosts. A bare entry is exact; a leading-dot entry matches subdomains. |
| `try_it.self_hosts` | `[]` | Additional domains served by this application. Each entry and its subdomains bypass the foreign-host safety gates; the host from `APP_URL` is already included. |
| `try_it.timeout` | `10` | Maximum outbound request duration in seconds. Non-positive or non-numeric values fall back to 10. |
| `try_it.connect_timeout` | `5` | Maximum connection-establishment duration in seconds. Non-positive or non-numeric values fall back to 5. |
| `try_it.max_response_bytes` | `262144` | Maximum proxied response body, 256 KiB by default. Excess content is truncated and reported with `truncated: true`. |
| `try_it.throttle` | `'30,1'` | Laravel throttle parameters (`requests,minutes`) applied to the proxy and profile routes. |
| `try_it.allowed_methods` | `['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS']` | Methods accepted from the panel. Configuration can narrow this fixed supported set, not expand it. |
| `try_it.ttl` | `3600` | Idle lifetime in seconds for session credential profiles: every read and every write pushes the expiry out by this much, so the clock measures inactivity rather than the age of the credential. There is no absolute ceiling above it. Non-positive or non-numeric values fall back to 3600. |
| `try_it.max_profiles` | `10` | Credential profiles kept per session; the oldest is dropped past this. Every read and write refreshes the bucket lifetime, so an uncapped bucket would never expire. |
| `include_generation_timestamp` | `false` | Stamp the generation time into the document. Off by default: it turns every regeneration into a diff. |

For `allowed_hosts`, a bare entry is an exact host name, and a leading dot covers the site and its subdomains: `.example.com` matches both `example.com` and `api.example.com`. A near miss never matches either form — `evil-example.com` and `example.com.attacker.test` are both denied.

## Authoring AI metadata as one operation contract

This section is the mechanics. The editorial contract — which fact belongs in which
attribute, what a finished operation looks like, and how to verify one — is
[`docs/ai-metadata-authoring.md`](docs/ai-metadata-authoring.md), written to be handed
to a coding assistant verbatim when you ask it to document an endpoint.

All six attributes target controller classes and controller methods. Put shared guidance on the class and operation-specific guidance on the method.

`AiExample`, `AiPitfall`, and `AiChangelog` are repeatable. `AiHint`, `AiTool`, and `ApiFeature` are not repeatable on the same target. Their exact constructors are:

```php
new AiHint(string $hint)

new AiPitfall(string $text, int $order = 0)

new AiChangelog(string $date, string $summary, bool $breaking = false)

new AiExample(string $name, array $request = [], array $response = [])

new AiTool(
    bool $enabled = true,
    ?string $name = null,
    ?string $description = null,
)

new ApiFeature(
    ?string $auth = null,
    ?array $scopes = null,
    ?int $rateLimit = null,
    ?string $rateLimitPer = null,
    ?bool $deprecated = null,
    ?string $stability = null,
)
```

Class metadata is read before method metadata. Examples are emitted class first and then method. Pitfalls are collected in that order and then stably sorted by `order`. Changelog entries are collected in that order and then sorted newest first when `date` is a valid `Y-m-d`; malformed dates remain visible at the end. For `AiHint` and `AiTool`, a method instance replaces the class instance. `ApiFeature` begins with facts derived from route middleware, applies the class instance field by field, and then applies the method instance field by field; a `null` field leaves the earlier value unchanged.

Here is one endpoint using the attributes as a single integration contract:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use LvntR\ApiDock\Attributes\AiChangelog;
use LvntR\ApiDock\Attributes\AiExample;
use LvntR\ApiDock\Attributes\AiHint;
use LvntR\ApiDock\Attributes\AiPitfall;
use LvntR\ApiDock\Attributes\AiTool;
use LvntR\ApiDock\Attributes\ApiFeature;

#[AiPitfall('Resource IDs are case-sensitive.', order: 10)]
#[AiTool(name: 'inspect_resource', description: 'Inspect one API resource.')]
#[ApiFeature(
    auth: 'sanctum',
    scopes: ['resources:read'],
    rateLimit: 60,
    rateLimitPer: 'minute',
    stability: 'stable',
)]
final class ResourceController
{
    /** Inspect a resource. */
    #[AiHint('Use the returned ETag for the next conditional request.')]
    #[AiPitfall('A missing resource returns 404, not an empty object.', order: 20)]
    #[AiExample(
        name: 'Ready resource',
        request: ['id' => 'res_42'],
        response: ['id' => 'res_42', 'status' => 'ready'],
    )]
    #[AiChangelog('2026-08-20', 'Added the status field to the response.')]
    #[ApiFeature(deprecated: false)]
    public function show(string $id): array
    {
        return ['id' => $id, 'status' => 'ready'];
    }
}
```

Assuming the route is `GET /api/resources/{id}`, Scramble supplies the ordinary OpenAPI operation and API Dock adds this vendor-extension block:

```json
{
    "x-ai-hint": "Use the returned ETag for the next conditional request.",
    "x-ai-pitfalls": [
        {"order": 10, "text": "Resource IDs are case-sensitive."},
        {"order": 20, "text": "A missing resource returns 404, not an empty object."}
    ],
    "x-ai-examples": [
        {
            "name": "Ready resource",
            "request": {"id": "res_42"},
            "response": {"id": "res_42", "status": "ready"}
        }
    ],
    "x-ai-tool": {
        "enabled": true,
        "name": "inspect_resource",
        "description": "Inspect one API resource."
    },
    "x-api-dock-changelog": [
        {
            "date": "2026-08-20",
            "summary": "Added the status field to the response.",
            "breaking": false
        }
    ],
    "x-api-dock-features": {
        "auth": "sanctum",
        "scopes": ["resources:read"],
        "rate_limit": {"limit": 60, "per": "minute"},
        "deprecated": false,
        "stability": "stable"
    }
}
```

For an OpenAPI path parameter named `id` with schema `{ "type": "string" }`, the MCP exporter produces this tool definition. An explicit `AiTool` description takes precedence over the operation summary and hint; pitfalls are appended to it.

```json
{
    "name": "inspect_resource",
    "description": "Inspect one API resource.\n\nPitfalls:\n1. Resource IDs are case-sensitive.\n2. A missing resource returns 404, not an empty object.",
    "inputSchema": {
        "type": "object",
        "properties": {
            "id": {"type": "string"}
        },
        "required": ["id"]
    }
}
```

The corresponding `llms.txt` operation contains the Scramble summary, AI hint, ordered pitfalls, authentication line, parameters, request body, responses, examples when enabled, and human changelog. A shortened excerpt is:

```text
### GET /api/resources/{id}

Inspect a resource.

**AI hint:** Use the returned ETag for the next conditional request.

#### Pitfalls

1. Resource IDs are case-sensitive.
2. A missing resource returns 404, not an empty object.

**Authentication:** Required (sanctum)

#### Examples

##### Ready resource
...

#### Changelog

- 2026-08-20 — Added the status field to the response.
```

`x-api-dock-features.auth` carries the authentication driver name (`sanctum`) rather than a boolean; the exporter renders any non-null value as required and names the driver in parentheses. An operation with no guard renders `**Authentication:** Not required`.

These values are also the ingredients of an operation's agent prompt block: current summary and schema, `AiHint`, ordered `AiPitfall` entries, examples, feature requirements, and the human changelog. The Vue `AiPanel` composes exactly that block and offers it for copying; `api-dock:export` produces the same content as MCP tool definitions and `llms.txt` for consumption outside the browser.

The snapshot diff and `AiChangelog` deliberately serve different readers. The snapshot diff is the machine-generated delta between OpenAPI versions. `AiChangelog` is a hand-maintained record for the human integrating against the API. Keep both; one does not replace the other.

## Console commands

### Teach this project's coding agents the authoring rules

```text
api-dock:agent-guide
    --file=   Instruction file to write, relative to the project root (repeatable)
    --print   Write the block to output instead of to a file
```

Run this once after installing. It writes a short, marker-delimited block into the
instruction files coding agents already read on their own — `AGENTS.md`, and
`CLAUDE.md` or `GEMINI.md` when the project keeps them — pointing at
[`docs/ai-metadata-authoring.md`](docs/ai-metadata-authoring.md) inside `vendor/`.
From then on "document this endpoint" carries the rules with it and nobody has to
restate them per session. Re-running replaces the block in place rather than adding
a second copy, so it is safe after every upgrade. Nothing outside the markers is
touched, and no file the project has not adopted is created.

### Store or check a snapshot

```text
api-dock:sync
    --check  Exit with code 1 on breaking changes and do not write the snapshot
```

Without `--check`, the command compares the generated document with `snapshot.path`, prints changes grouped as breaking, additive, and cosmetic, writes the new snapshot, and exits 0. With `--check`, it never writes the snapshot: it exits 1 when at least one breaking change exists and 0 otherwise.

Use it as a CI gate:

```yaml
- name: Reject breaking API changes
  run: php artisan api-dock:sync --check
```

The committed baseline at `snapshot.path` must already be available in the CI checkout for this comparison to be meaningful.

### Inspect a diff

```text
api-dock:diff
    --json  Emit the structured diff as JSON
```

The command compares the generated document with the stored snapshot and never writes it. Human output is grouped by severity. `--json` emits one object with this shape:

```json
{
    "has_breaking": true,
    "changes": [
        {
            "severity": "breaking",
            "path": "/api/resources/{id}",
            "operation": "get",
            "type": "response_property_removed",
            "description": "..."
        }
    ]
}
```

`operation` can be `null` for a document- or component-level change. The command exits 0 even when the result contains breaking changes; use `sync --check` for gating.

### Export artifacts

```text
api-dock:export
    --mcp       Write MCP tool definitions
    --llms      Write the llms.txt bundle
    --openapi   Write the generated OpenAPI document
    --output=   Override the export directory
```

Select one or more format flags in the same invocation. Files are named `mcp-tools.json`, `llms.txt`, and `openapi.json`. Without `--output=`, they are written under `ai.export_path`. The command exits 0 on success and 1 when no format is selected or an export fails.

```bash
php artisan api-dock:export --mcp --llms --openapi
php artisan api-dock:export --mcp --output=storage/app/agent-contracts
```

## Try-it security contract

The try-it proxy is disabled by default. Setting `api-dock.try_it.enabled` to `true` is a deliberate operator decision.

**This application's own host needs no allowlist entry.** The self host comes from the host in `APP_URL` (`config('app.url')`), and any subdomain of it is covered automatically. If this application answers on any other domain, list that bare hostname in `try_it.self_hosts`. A subdomain of a `self_hosts` entry also counts as self, but its parent domain does not. Entries are lowercased and trimmed, with a trailing dot removed; empty entries, malformed hostnames, leading-dot forms such as `.example.com`, and address literals in any spelling (`127.0.0.1`, `127.1`, `2130706433`, `0x7f000001`) are ignored.

A self host bypasses `try_it.allowed_hosts`, the internal-service host list, and the private-address check after DNS resolution. Use `self_hosts` only for domains of this application — never for a foreign host or an internal service name. Use `allowed_hosts` for foreign hosts.

**Upgrade note:** the proxy no longer trusts the incoming `Host` header when deciding what is self. After upgrading, a deployment whose `APP_URL` does not match the domain it is served on receives a 422 when trying its own API until `APP_URL` is corrected or that served domain is added to `try_it.self_hosts`.

The host allowlist governs **foreign** hosts only, and is deny-by-default: an empty `allowed_hosts` denies every one of them. Bare entries are exact host names. A leading dot, such as `.example.com`, accepts that site and its subdomains — both `example.com` and `api.example.com` — the way a cookie domain does. It never accepts a near miss: `evil-example.com` and `example.com.attacker.test` are denied by both forms.

Only HTTP and HTTPS URLs are accepted. URLs carrying authority credentials, malformed hosts, conventionally internal host names, and internal DNS suffixes are rejected. After DNS resolution, every address is checked against private, loopback, link-local, shared, unspecified, documentation, benchmark, multicast, reserved, and cloud-metadata-relevant IPv4 and IPv6 ranges. Every resolved address must pass. The checked addresses are pinned with cURL for the actual connection, closing the DNS-rebinding window between validation and connection. Redirects are not followed.

Credentials are scoped to the current Laravel session. They are encrypted with the application's encrypter before being stored in the cache and expire after `try_it.ttl` of inactivity: listing a profile, looking one up, or sending a request with it pushes the expiry out by another full `try_it.ttl`, so credentials survive an active working session, while an abandoned one still expires. A read never revives an already-expired profile, and there is no absolute cap above the idle window, so pick `try_it.ttl` deliberately. A profile also carries `server_variables`, the values substituted into a server template; that map is ordinary non-secret data and is returned in the clear like `base_url`, so a credential must never be put in it. Profile list and lookup responses omit the ciphertext and plaintext; they return only profile metadata and `credential_hint`, which is `****` for credentials of eight characters or fewer and `****` plus the last four characters otherwise. The outbound request path is the only code path that decrypts a credential. Reads never return a usable credential to the browser, so the panel does not regain one after profile creation. The browser keeps only the selected profile id, the server variable values, and the plain base URL under the `api-dock:try-it` key in `localStorage`, so a page reload does not reset the panel. That store never holds a credential, a credential header value, or a `credential_hint`, but it does outlive the session and is shared by every specification on the origin — on a shared browser, clear site data when you are done.

Response bodies are capped at `try_it.max_response_bytes`. Content beyond the cap is discarded, the returned body is truncated to the cap, and the response carries `truncated: true`; the proxy does not buffer an unbounded body. Hop-by-hop headers, cookies, forwarding headers, CSRF headers, proxy-prefixed headers, and browser `Sec-*` headers are stripped in both directions.

Enabling this feature means your Laravel server will make outbound HTTP requests to allowlisted hosts on behalf of anyone who can reach the documentation route. Leave it disabled in production unless that behavior is required. When it is required, restrict the documentation surface itself with `middleware`, for example with the application's authentication and authorization middleware, and keep the host and method lists as narrow as possible.

## Try-it server variables

OpenAPI `servers[].variables` are intended to reach the panel as one control per variable: an enum can be presented as a select and other variables as text inputs, with the declared default prefilled. The client sends the untouched server template, supplied values, and variable `enum`/`default` specifications to the backend. Substitution happens server-side. The client makes no allow or deny decision.

The server rejects a variable value containing any of `/`, `\`, `@`, `:`, `?`, `#`, `[`, `]`, whitespace, or ASCII control characters. It repeats percent-decoding before checking, so encoded separators such as `%2F` and nested forms such as `%252F` are also rejected. Values encoded too deeply to validate within five passes are rejected. It also rejects a value outside a non-empty declared `enum`, a variable with neither a supplied non-empty value nor a `default`, and a template that still contains an unsubstituted `{placeholder}`. Accepted values are inserted with `rawurlencode`; the final URL still passes the full scheme, host, allowlist, DNS, and address-range checks.


## AI integration

Generate artifacts with `php artisan api-dock:export --mcp --llms`. Give `llms.txt` to a model as API context when it needs a readable operation catalogue; it groups operations by their first tag and includes parameters, JSON request and response schemas, hints, pitfalls, optional examples, and changelog entries. Give `mcp-tools.json` to an MCP host that accepts tool definitions with `name`, `description`, and JSON Schema `inputSchema` fields.

The MCP `inputSchema` merges path, query, and header parameters, followed by top-level properties from an `application/json` request body. Local `#/components/...` parameter and schema references are resolved. Path parameters are always required; other parameter and body requirements follow OpenAPI. When names collide, the first property keeps its name and the later property receives a deterministic source prefix: `query_id`, `header_id`, or `body_id`. A further collision appends `_2`, then `_3`, and so on. The renamed property's description records its source.

Tool names use the first available value: `AiTool::$name`, OpenAPI `operationId`, or a lower-case `method_path` fallback. `AiTool(enabled: false)` always excludes an operation. With `ai.mcp_opt_in` enabled, an operation must explicitly carry `AiTool(enabled: true)`.

| Extension | Location and shape | Meaning |
| --- | --- | --- |
| `x-ai-hint` | Operation: `string` | Current model-facing guidance. A method hint replaces a class hint. |
| `x-ai-pitfalls` | Operation: list of `{order: int, text: string}` | Ordered integration hazards. MCP appends their text to the tool description; `llms.txt` renders a numbered section. |
| `x-ai-examples` | Operation: list of `{name: string, request: object/array, response: object/array}` | Named request/response pairs. `ai.include_examples` controls their inclusion in `llms.txt`. |
| `x-ai-tool` | Operation: `{enabled: bool, name: string|null, description: string|null}` | MCP inclusion, tool-name override, and description override. |
| `x-api-dock-features` | Operation: `{auth: string|null, scopes: string[], rate_limit: {limit: int, per: string}|null, deprecated: bool, stability: string|null}` | Authentication, authorization scopes, rate limit, deprecation, and stability derived from middleware and overridden by `ApiFeature`. |
| `x-api-dock-changelog` | Operation: list of `{date: string, summary: string, breaking: bool}` | Human-maintained integration history, newest valid date first. |
| `x-api-dock` | Document: `{version: string}` | API Dock package metadata. The current package fallback version is `dev`. |

The exporters consume the generated OpenAPI document; they do not inspect controller attributes directly. This keeps OpenAPI as the shared contract among the documentation UI, snapshots, MCP tools, and `llms.txt`.

## Attribution and license

API Dock is built on [`dedoc/scramble`](https://github.com/dedoc/scramble), which is distributed under the MIT License. API Dock is also distributed under the MIT License; see [LICENSE](LICENSE).

Scramble Pro is not a dependency.

API Dock is written and maintained by Levent Acar — [lvntr.dev](https://lvntr.dev).
