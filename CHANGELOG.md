# Changelog

All notable changes to this project are documented in this file.

## Unreleased

### Security

- **Stored try-it state is bound to the signed-in identity.** The panel view now carries an opaque identity stamp (an HMAC of the guard and user id, never the id itself); the form inputs in `localStorage` and the response history in `sessionStorage` are stored under that stamp and are dropped when the next visitor is someone else. State still survives a reload for the same user — the point is that a logout does not leave the previous account's request history in the browser for the next one.
- **Credentials are classified and redacted before they reach web storage.** A parameter recognised as a secret (authorization, token, key, secret, cookie, session, credential names, plus the spec's own API-key parameters) is kept in memory only, and the stored copy of a response has its secret headers and JSON token fields replaced with `***`. A body that was truncated, or that looks like JSON but does not parse, is stored empty rather than half-redacted.
- **The self-host subdomain exemption no longer covers private-network addresses it was not meant to.** A host matching a `self_hosts` entry as a descendant (`tenant.app.test` under `app.test`) keeps the private-address exemption only when every address it resolves to is also an address of the configured entry itself; otherwise it is checked like any other target. An exact match is unchanged, so a zero-config tenant subdomain that points at the same host still works.
- **The try-it proxy accepts only `socks5://` and `socks4://` URLs.** An HTTP proxy — and the remotely resolving `socks5h`/`socks4a` forms — resolves the hostname on its own side, which silently discards the `CURLOPT_RESOLVE` pin that binds the request to the address the guard validated. Configuring anything else is now refused with an explanatory error instead of quietly weakening the check.

### Added

- **API changelog page.** The panel's sidebar gains a Changelog entry that aggregates every operation's `#[AiChangelog]` entries into one date-ordered page, newest first, with a breaking-change count. It appears only when the spec actually carries history, and needs no new source file: project AIs keep writing the attribute on the operation, and the page is the read side of it.
- **A token in a try-it response can be saved as a credential profile in one click.** After a login-style request returns a token, the response panel offers to store it as a Bearer profile and select it for the following requests, instead of copying it into the header by hand.
- **Optional `viewApiDock` access gate.** `gate.enabled` (config key, off by default) adds an authorization check on top of the `middleware` stack, evaluated against the `viewApiDock` Gate ability on every package route. Fails closed: enabling it without defining the ability denies everyone. See [Restricting who can open the panel](README.md#restricting-who-can-open-the-panel).

### Changed

- **Request and response headers open in a modal dialog** rather than crowding the response sidebar, and tooltips are positioned by a `v-tooltip` directive against the viewport, so a tooltip near a panel edge is no longer clipped by an `overflow: hidden` ancestor.

### Fixed

- **Every try-it request failed with `InvalidArgumentException` when the panel was used from a consuming app.** The proxy was pinned on two layers at once, and Guzzle 8 refuses `CURLOPT_PROXY` inside the `curl` request option as a conflict with its own handling. Only the `proxy` option is passed now; an empty value there is Guzzle's final "no proxy" (the environment is not consulted and Guzzle writes the empty `CURLOPT_PROXY` itself), so the address pin is unchanged.
- **The example and credential dialogs keep the keyboard inside them.** Opening one moves focus into the dialog, Tab and Shift+Tab cycle within it instead of walking the page behind, and closing returns focus to the control that opened it.
- **`api-dock:diff` / `api-dock:sync --check` now detect change classes that were previously silent or misclassified.** A consuming CI that was green may fail on the next run — this is the gate catching a real change it used to miss, not a regression. Specifically:
  - A response, request body, or parameter losing one side of a `content`/`headers`/media-type block is now reported (`schema_block_removed`/`schema_block_added`) instead of producing no change at all.
  - Every component section (`parameters`, `requestBodies`, `responses`, `headers`, `securitySchemes`) is compared, not only `schemas`; a removed or changed shared component now reports `component_removed`/`component_added`/`auth_requirement_changed` instead of a blanket `cosmetic_change`.
  - Removing a path parameter is now correctly `breaking` (`parameter_removed`) rather than `additive`.
  - A `$ref` swapped to point at a different component is now `schema_reference_changed` and breaking, instead of `cosmetic`.
  - The declared `servers` list is compared; dropping a server URL is now `server_changed` and breaking.
  - `items`, `contains`, and `additionalProperties` present on only one side are now classified as `schema_constraint_narrowed`/`schema_constraint_widened` instead of being ignored.
  - `type: 'string'` and `type: ['string']` are recognised as equivalent and no longer reported as a spurious `type_narrowed`.
  - `example`/`examples` values are reported as `cosmetic_change` rather than walked as if they were schemas.
- **`llms.txt` and MCP tool export share one `$ref` resolver.** `llms.txt` no longer prints a raw `{"$ref": "..."}` block or reports "No parameters." for a referenced parameter; a parameter typed by a component now shows the resolved type instead of the component name.
- **MCP `inputSchema` spreads `allOf` request bodies.** A request body composed with `allOf` now has every branch's properties spread into the tool's top-level `properties` instead of collapsing into a single opaque `body` property.
- **MCP tool names are sanitised and de-duplicated.** A name not matching `^[A-Za-z0-9_-]{1,64}$` (for example a raw `Controller@method` action id used as `operationId`) is rewritten instead of exported as-is; a name already taken by an earlier tool in the same export gets a deterministic `_2`, `_3`, … suffix.
- **Try-it self-host port handling.** The application's own port (from `APP_URL` or a `try_it.self_hosts` entry) is accepted automatically for that host; `try_it.allowed_ports` continues to bound foreign hosts only.
- **The panel states the credential lifetime it actually has.** With `try_it.profile_persistence.enabled` on, the settings panel no longer claims credentials are tied to the session — it says they outlive logout, names the window, and warns that other sessions of the same user can reach them. **If you publish the package views and use persistent profiles, republish `docs.blade.php`:** an older published copy omits the attribute carrying the mode, and the panel then shows the session wording, which is the wrong promise in that mode.
