<?php

declare(strict_types=1);

return [
    'enabled' => true, // Enable the API Dock HTTP surface.
    'route_prefix' => 'api-dock', // Prefix used by all package routes.
    'scramble_api' => 'default', // Which Scramble API this package documents. 'default' is the one a bare Scramble::configure() sets up, so your route filter, servers and transformers apply here too. Name a registered API to document that one instead.
    'middleware' => ['web'], // Host middleware applied before API Dock access checks.
    'include_generation_timestamp' => false, // Stamp the generation time into the document. Off by default: it makes every regeneration a diff.

    'ui' => [
        'locale' => null, // Locale used by the documentation UI; null derives it from the host application.
        'theme' => 'light', // Default documentation UI theme before a stored preference is applied.
    ],

    'ai' => [
        'export_path' => storage_path('api-dock'), // Default directory for generated AI artifacts.
        'include_examples' => true, // Include OpenAPI examples in AI-oriented exports.
        'mcp_opt_in' => false, // When true, only operations carrying an AiTool attribute are exported as MCP tools.
    ],

    'snapshot' => [
        'path' => storage_path('api-dock/openapi.json'), // Stored OpenAPI snapshot used by diff commands.
    ],

    'try_it' => [
        'enabled' => false, // Keep outbound try-it requests disabled unless explicitly enabled.
        // This application's own host — and any subdomain of it — is always reachable and needs no entry here; trying your own documented API is the point of the panel.
        // This list is only for FOREIGN hosts. Empty denies every one of them. A bare name ('api.example.com') is an exact match; a leading dot ('.example.com') covers that site and its subdomains, the way a cookie domain does.
        'allowed_hosts' => [],
        // THIS application's own additional domains — nothing else. The host of `app.url` is already treated as self and needs no entry; add a name here only when this app is also served under it (a second domain, a reverse proxy in front of a different `APP_URL`, a tenant domain of yours).
        // A subdomain of any entry counts as self too. An entry skips the allowlist above, the internal-host list AND the address-class check that follows DNS, so every line is a deliberate decision to trust that name: an internal service name here reopens SSRF on purpose. Bare hostnames only — a leading-dot form is ignored, and so is an address literal in any spelling ('127.0.0.1', '127.1', '2130706433', '0x7f000001'), since the last label of a real name starts with a letter.
        'self_hosts' => [],
        'allowed_ports' => [80, 443], // Ports the proxy may reach. An allowlisted host usually co-locates internal services, so an open port range would turn one allowed name into a port scanner. Empty or malformed falls back to these two.
        'timeout' => 10, // Maximum outbound request duration in seconds.
        'connect_timeout' => 5, // Seconds allowed for the connection itself, so an unreachable host fails fast instead of holding a worker.
        'max_request_bytes' => 262144, // 256 KB ceiling on the body the panel may send out. The throttle bounds how often a request leaves, not how much this server buffers and ships per request.
        'max_response_bytes' => 262144, // 256 KB ceiling on the proxied body; anything beyond it is truncated and flagged rather than buffered.
        'throttle' => '30,1', // Rate limit (requests,minutes) on the proxy route, since every call is an outbound request from your server.
        'allowed_methods' => ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'], // Verbs the panel may request; narrow this to widen nothing.
        'ttl' => 3600, // IDLE lifetime in seconds for try-it credentials, not an absolute one: every read and every write pushes the expiry out by this much, so a session in active use keeps its credentials and only an unattended one loses them. There is no ceiling above this, so raise it deliberately. Non-positive or non-numeric falls back to 3600.
        'max_profiles' => 10, // Credential profiles kept per session; the oldest is dropped past this. Every read and write refreshes the bucket TTL, so an uncapped bucket would never expire.
    ],
];
