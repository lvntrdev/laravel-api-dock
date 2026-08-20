# api-dock — Scramble Üstüne API Dokümantasyon Paketi

**Date:** 2026-08-17
**Risk level:** high

## Task Özeti

| # | Task | Agent | Model | Effort | Executor | Depends-on |
|---|---|---|---|---|---|---|
| 1 | Paket iskeleti + Scramble entegrasyonu | `backend` | `sonnet-5` | medium | `codex` | — |
| 2 | Attribute seti + OperationExtension + DocumentTransformer | `backend` | `opus-5` | high | `codex` | Task 1 |
| 3 | Snapshot + Differ + konsol komutları | `backend` | `opus-5` | high | `codex` | Task 1 |
| 4 | MCP + llms.txt exporter | `backend` | `opus-5` | high | `codex` | Task 2 |
| 5 | Differ ve exporter davranış testleri | `backend` | `sonnet-5` | medium | `codex` | Task 3, Task 4 |
| 6 | Try-it proxy + auth profil deposu | `security` | `opus-5` | xhigh | `claude` | Task 1 |
| 7 | Proxy güvenlik regresyon testleri | `backend` | `opus-5` | high | `claude` | Task 6 |
| 8 | Vue SPA iskeleti + spec render | `frontend` | `opus-5` | high | `codex` | Task 1 |
| 9 | Try-it, AI, changelog ve diff panelleri | `frontend` | `opus-5` | high | `codex` | Task 6, Task 8, Task 13 |
| 10 | README + kullanım dokümanı | `docs` | `sonnet-5` | medium | `codex` | Task 2, Task 3, Task 4, Task 6, Task 13 |
| 13 | Tuzak + değişiklik geçmişi attribute'ları | `backend` | `opus-5` | high | `codex` | Task 2, Task 4 |
| 11 | Konsolide doğrulama | `verifier` | `sonnet-5` | medium | `claude` | Task 5, Task 7, Task 9, Task 10, Task 13 |
| 12 | Final review | `reviewer` | `opus-5` | xhigh | `claude` | Task 11 |

## Amaç

Kardeş projelerde `dedoc/scramble` kullanılıyor; otomatik OpenAPI üretimi yeterli, üst katman değil. Eksikler: (1) AI ajanlarının API'yi çağırabileceği makine-okunur çıktı yok, (2) try-it paneli JSON gövde + auth ile gerçek istek atamıyor, (3) endpoint'e AI bağlamı yazılacak alan yok, (4) sürümler arası breaking change tespiti yok.

Bu plan `lvntr/api-dock` paketini kuruyor: Scramble'ı **bağımlılık** olarak alıp yalnız public genişletme yüzeyine (`Extensions/`, `Contracts/`, `Attributes/`) yaslanıyor, üstüne AI metadata katmanı, MCP/llms.txt export'ları, snapshot-diff ve kendi Vue SPA'ini + backend proxy'li try-it panelini ekliyor. Tip çıkarımı (`src/Infer`) yeniden yazılmıyor.

## Kapsam

**Etkilenen katmanlar:** PHP paket kaynağı (`src/`), konsol komutları, HTTP (docs/spec/proxy route'ları), Vue SPA (`resources/js/`), paket testleri, paket dokümanı.

**Etkilenen dosyalar:** `composer.json`, `config/api-dock.php`, `routes/api-dock.php`, `src/**`, `resources/js/**`, `tests/**`, `README.md`. Hepsi bu repoda (`api-dock`) — başka repo yok.

**Kapsam dışı:**
- `berth-cms` veya başka tüketen projeye kurulum/entegrasyon (paket bitince ayrı iş)
- Packagist yayını, sürüm etiketi, CI pipeline
- Scramble'ın tip çıkarım motoruna (`src/Infer`) herhangi bir dokunuş veya fork
- Scramble Pro'ya bağımlılık (ticari, kapalı kaynak — paket ona hiç dokunmuyor)
- Dokümana gömülü LLM chat paneli (kullanıcı seçmedi)
- Auth profillerinin DB'de kalıcı saklanması (aşağıdaki varsayıma bakın)
- Çok dilli doküman, tema/branding sistemi, PDF export

## Varsayımlar

1. **Auth profilleri kalıcı DB'de tutulmuyor.** Base URL + token, sunucu tarafı cache'te oturum kimliğine bağlı ve TTL'li saklanır; migration yok, secret diske yazılmaz. Kalıcı depo istenirse sonradan opsiyonel driver olarak eklenir — additive, bu planı geçersizleştirmez. Bu yüzden planın `## Şema Özeti` bölümü yok: hiçbir task DB şemasına dokunmuyor.
2. **Try-it proxy'si prod'da varsayılan kapalı.** `config('api-dock.try_it.enabled')` varsayılanı `false`; açmak explicit karar.
3. **Paket kendi SPA build'ini taşır.** Tüketen proje `npm run build` çalıştırmaz; `dist/` paketle versiyonlanır ve `vendor:publish` ile yayınlanır.
4. **PHP 8.3+, Laravel 12/13, `dedoc/scramble` ^0.12 hedefleniyor** — kurulu sürüm referans alınacak, `composer.json` constraint'i Task 1'de kurulu sürüme göre yazılacak.

## Alternatifler

| Yaklaşım | Neden seçilmedi / seçildi |
|---|---|
| Scramble'ı fork'lamak | MIT izin veriyor ama upstream tip çıkarım düzeltmeleri elle taşınır; bakım borcu kalıcı. **Hayır.** |
| Sıfırdan yazmak | `src/Infer` bir PHP statik analiz motoru; nikic/php-parser üstüne yeniden yazmak ayları alır ve bu planın hiçbir hedefine katkısı yok. **Hayır.** |
| Scramble'ın ürettiği OpenAPI'yi dışarıdan tüketip ayrı araç yazmak | Attribute'lardan gelen AI metadata'sı spec'e giremez, route ile bağ kopar. **Hayır.** |
| **Scramble bağımlılık + public genişletme yüzeyi** | Tip çıkarımı bedava, AI metadata spec'in içinde tek doğruluk kaynağı olarak yaşıyor, upstream güncellemeleri composer ile geliyor. **Seçilen.** |
| Try-it isteğini tarayıcıdan direkt atmak | CORS ayarı gerekir, token localStorage'da durur, istek log'lanamaz. **Hayır** — proxy seçildi. |
| Hazır UI (Scalar/Stoplight) üstüne yama | Try-it ve AI panelleri hazır UI'ın uzatma noktalarının dışında kalıyor. **Hayır** — kendi Vue SPA'i. |

## Tasks

### Task 1: Paket iskeleti ve Scramble entegrasyonu ✅ DONE
**Agent:** `backend` · **Model:** `sonnet-5` · **Effort:** medium · **Executor:** `codex`
**Oversize-ack:** Greenfield paket bootstrap'ı atomik — composer autoload, provider, config, route ve test harness'ından biri eksikse hiçbiri boot etmez ve doğrulanamaz; ikiye bölmek her iki yarıyı da tek başına test edilemez bırakır ve fazladan bir dispatch'e mal olur.

> Agent/Model/Effort gerekçesi: standart Composer paket scaffold'u — provider, config, route, testbench kurulumu; desen net, mekanik üretim → base tier + Codex şeridi (Claude Max kotası yanmaz), dönen patch Claude triyajından geçer.

#### Amaç
Paketin çalışan iskeletini kurmak: Scramble'ın ürettiği OpenAPI dokümanı `api-dock` route'undan JSON olarak servis edilebilsin, test altyapısı ayakta olsun.

#### To-Do
- Create `composer.json` for package `lvntr/api-dock`: PSR-4 autoload `LvntR\ApiDock\` from `src/`, require `php ^8.3`, `dedoc/scramble`, `spatie/laravel-package-tools`, `illuminate/contracts`; require-dev `orchestra/testbench`, `pestphp/pest`, `larastan/larastan`, `laravel/pint`. Pin the `dedoc/scramble` constraint to the version installed in a sibling project's `vendor/dedoc/scramble/composer.json` — read it, do not guess.
- Add MIT `LICENSE` for the package and an attribution line in `composer.json` description noting it builds on `dedoc/scramble` (MIT).
- Create `src/ApiDockServiceProvider.php` using `spatie/laravel-package-tools`: register config, routes, views, publishable assets, and the console command namespace. Register the package's Scramble API definition/config bridge so the generated document is reachable.
- Create `config/api-dock.php` with keys: `enabled`, `route_prefix` (default `api-dock`), `middleware` (array, default `['web']`), `ai` (`export_path`, `include_examples`), `snapshot` (`path`), `try_it` (`enabled` default `false`, `allowed_hosts` empty array, `timeout`, `ttl`).
- Create `routes/api-dock.php` and `src/Http/Controllers/SpecController.php`: a JSON endpoint returning the generated OpenAPI document, gated by `src/Http/Middleware/ApiDockAccess.php` (denies when `enabled` is false, applies the configured middleware stack).
- Set up test infrastructure: `phpunit.xml`, `tests/TestCase.php` extending Testbench with the provider registered, `tests/Pest.php`, and `tests/Feature/PackageSetupTest.php` asserting the provider boots, config publishes, and the spec route returns a valid OpenAPI JSON body for a fixture route.
- Add `pint.json` and `phpstan.neon` (level 8, `src` only).

#### Expected Output
- Installable package skeleton with a booting service provider
- `GET /api-dock/spec` returning the Scramble-generated OpenAPI JSON
- Green `tests/Feature/PackageSetupTest.php`

#### Verification
- `vendor/bin/pest tests/Feature/PackageSetupTest.php`

#### Risk Notes
- Scramble sürüm constraint'i kurulu sürümden okunacak; tahminle yazılan bir `^x.y` ileride sessizce kırar.
- `src/Infer` altına hiçbir referans verilmeyecek — yalnız `Extensions/`, `Contracts/`, `Attributes/` public yüzeyi.

---

### Task 2: Attribute seti, OperationExtension ve DocumentTransformer ✅ DONE
**Agent:** `backend` · **Model:** `opus-5` · **Effort:** high · **Executor:** `codex`
**Depends-on:** Task 1

> Agent/Model/Effort gerekçesi: Scramble'ın extension sözleşmelerine oturan, birden fazla dosyaya yayılan davranışsal iş — attribute okuma, middleware çözümleme, spec'e vendor extension enjeksiyonu. Auth middleware'i yalnız **okunuyor ve dokümana yazılıyor**; hiçbir yetki garantisi değişmiyor → kırmızı çizgi yok, `main` tier + high, Codex şeridi.

#### Amaç
Endpoint'e AI bağlamı ve API özelliği yazılabilsin; bunlar üretilen OpenAPI dokümanına `x-ai-*` / `x-api-dock-*` vendor extension'ları olarak insin. Tek doğruluk kaynağı spec olsun — export'lar buradan türesin.

#### To-Do
- Add attribute classes under `src/Attributes/`: `AiHint` (free-text guidance for an agent), `AiExample` (named request/response example pair), `AiTool` (opt-in flag + tool name/description overrides for MCP export), `ApiFeature` (explicit rate limit / scope / deprecation / stability overrides). All `#[Attribute]`, readonly, constructor-promoted, `declare(strict_types=1)`.
- Create `src/Extensions/AiMetadataOperationExtension.php` extending Scramble's `OperationExtension`: read the attributes from the route's controller method and class, and write them onto the operation as `x-ai-hint`, `x-ai-examples`, `x-ai-tool`.
- Create `src/Extensions/FeatureOperationExtension.php`: derive API features from the route itself — throttle limits from `throttle:` middleware, auth scheme from the auth middleware, permission/ability scope from `can:`/scope middleware, deprecation from the `Deprecated` PHP attribute — and write them as `x-api-dock-features`. An explicit `ApiFeature` attribute always overrides the derived value.
- Create `src/DocumentTransformers/ApiDockTransformer.php` implementing Scramble's `DocumentTransformer`: post-process the whole document — attach package version, generation timestamp source, and normalise the vendor extension block so exporters read one stable shape.
- Register all three in the service provider via Scramble's extension configuration.
- Extend `tests/Feature/` with a test asserting that a fixture controller carrying `AiHint` + `ApiFeature` produces the expected vendor extension keys in the generated document.

#### Expected Output
- Four attribute classes, two operation extensions, one document transformer, all registered
- Generated OpenAPI document carrying `x-ai-*` and `x-api-dock-features` on annotated operations

#### Verification
- `vendor/bin/pest tests/Feature/AiMetadataTest.php`

#### Risk Notes
- Middleware'den özellik çıkarımı sezgisel; okunamayan bir middleware sessizce atlanmalı, exception fırlatmamalı — doküman üretimi hiçbir koşulda patlamamalı.
- Vendor extension anahtar şeması burada donuyor; Task 4 ve Task 9 bu şemayı tüketiyor.

---

### Task 3: Snapshot, Differ ve konsol komutları ✅ DONE
**Agent:** `backend` · **Model:** `opus-5` · **Effort:** high · **Executor:** `codex`
**Depends-on:** Task 1

> Agent/Model/Effort gerekçesi: diff sınıflandırması kenar durum yoğun davranışsal mantık (breaking / additive / kozmetik ayrımı), çok dosyalı; kırmızı çizgi yok → `main` tier + high, Codex şeridi.

#### Amaç
Üretilen spec'in sürümler arası farkı görülebilsin: hangi endpoint eklendi, hangisi silindi, hangi değişiklik istemciyi kırar.

#### To-Do
- Create `src/Support/OpenApiSnapshot.php`: write/read a normalised snapshot of the generated document to the configured path, with a stable key ordering so an unchanged API produces a byte-identical snapshot.
- Create `src/Support/SpecDiffer.php`: compare two snapshots and classify each change as `breaking`, `additive`, or `cosmetic`. Breaking covers at minimum: removed operation, removed response code, new required request parameter or body property, narrowed type, changed path parameter name, removed enum value, changed auth requirement. Additive covers new operation, new optional parameter, new response code, new enum value. Return a structured result object, not a string.
- Create `src/Console/SyncCommand.php` (`api-dock:sync`): regenerate the document, diff it against the stored snapshot, print a grouped summary, and write the new snapshot. Support `--check` (exit code 1 when a breaking change is present, write nothing) for CI use.
- Create `src/Console/DiffCommand.php` (`api-dock:diff`): diff without writing, support `--json` output for machine consumption.
- Register both commands in the service provider.

#### Expected Output
- `php artisan api-dock:sync` and `api-dock:diff` working against a fixture app
- Structured diff result with per-change severity classification

#### Verification
- `vendor/bin/pest tests/Feature/SyncCommandTest.php`

#### Risk Notes
- Snapshot normalizasyonu kararlı değilse her çalıştırma sahte diff üretir — anahtar sıralaması testle sabitlenmeli.
- `--check`'in exit kodu CI sözleşmesi; sonradan değiştirilmesi kırıcı.

---

### Task 4: MCP ve llms.txt exporter'ları ✅ DONE
**Agent:** `backend` · **Model:** `opus-5` · **Effort:** high · **Executor:** `codex`
**Depends-on:** Task 2

> Agent/Model/Effort gerekçesi: OpenAPI operation'ından MCP tool JSON Schema'sına eşleme davranışsal ve şema-detay yoğun (parametre birleştirme, required hesabı); kırmızı çizgi yok → `main` tier + high, Codex şeridi.

#### Amaç
AI ajanı API'yi gerçekten çağırabilsin (MCP tool şeması), ve API tek dosyada LLM'e verilebilsin (llms.txt).

#### To-Do
- Create `src/Export/McpToolExporter.php`: map each OpenAPI operation to an MCP tool definition — tool name from `operationId` or the `AiTool` override, description merging the operation summary with `x-ai-hint`, and a single JSON Schema `inputSchema` merging path + query + header parameters and the request body into one object with a correct `required` array. Skip operations not opted in when `config('api-dock.ai.mcp_opt_in')` is true.
- Create `src/Export/LlmsTxtExporter.php`: render the whole document as one markdown bundle — grouped by tag, one section per operation with method, path, auth, parameters, request/response shape, and the `x-ai-hint` text; include examples when `config('api-dock.ai.include_examples')` is on.
- Create `src/Console/ExportCommand.php` (`api-dock:export`) accepting `--mcp`, `--llms`, `--openapi`, and `--output=` (default from config). Exporting more than one format in a single call is supported.
- Both exporters read the vendor extension shape produced by Task 2's `ApiDockTransformer`; neither re-reads controller attributes.

#### Expected Output
- `php artisan api-dock:export --mcp` writing a valid MCP tool definition file
- `php artisan api-dock:export --llms` writing a single markdown bundle
- Both formats derived only from the generated OpenAPI document

#### Verification
- `vendor/bin/pest tests/Feature/ExportCommandTest.php`

#### Risk Notes
- MCP `inputSchema` tek bir JSON Schema objesi olmak zorunda — path/query/body birleştirmesinde isim çakışması olursa deterministik bir önek kuralı gerekli, sessiz üzerine yazma kabul edilmez.
- Export çıktısına secret sızmamalı: örnek değerler fixture'dan gelir, gerçek config/env'den asla.

---

### Task 5: Differ ve exporter davranış testleri ✅ DONE
**Agent:** `backend` · **Model:** `sonnet-5` · **Effort:** medium · **Executor:** `codex`
**Depends-on:** Task 3, Task 4

> Agent/Model/Effort gerekçesi: davranışsal iki birimin (diff sınıflandırması, şema eşleme) bağımsız yazarla test edilmesi — kendi kodunu test eden yazar tam da kaçırdığı kenarı kaçırır; test yazımı mekanik → base tier + Codex şeridi.

#### Amaç
Diff sınıflandırması ve export şema eşlemesi, üreten task'tan bağımsız bir yazar tarafından kenar durumlarıyla doğrulansın.

#### To-Do
- Write `tests/Unit/SpecDifferTest.php` covering each classification branch with hand-built snapshot fixtures: removed operation, removed response code, optional-to-required parameter, widened vs narrowed type, added and removed enum value, changed auth requirement, renamed path parameter, and a no-change case asserting an empty diff.
- Write `tests/Unit/McpToolExporterTest.php`: parameter merging across path/query/body, the `required` array, an operation with no parameters, a name collision between a query parameter and a body property, and the `AiTool` opt-in filter.
- Write `tests/Unit/LlmsTxtExporterTest.php`: tag grouping, an operation carrying `x-ai-hint`, an operation carrying none, and the `include_examples` toggle in both states.
- Fixtures live under `tests/Fixtures/`; do not modify production code — if a test exposes a defect, report it in the task output instead of patching the implementation.

#### Expected Output
- Three unit test files with fixture-driven coverage of every branch listed above
- Any defect found reported, not silently patched

#### Verification
- `vendor/bin/pest tests/Unit/SpecDifferTest.php tests/Unit/McpToolExporterTest.php tests/Unit/LlmsTxtExporterTest.php`

#### Risk Notes
- Test, implementasyonun şekline değil sözleşmeye yazılmalı; iç metot isimlerine bağlanan test refactor'da kırılır.

---

### Task 6: Try-it proxy ve auth profil deposu ✅ DONE
**Agent:** `security` · **Model:** `opus-5` · **Effort:** xhigh · **Executor:** `claude`
**Depends-on:** Task 1
**Oversize-ack:** Proxy'nin güvenlik yüzeyi tek turda yazılmalı — outbound guard, token deposu ve controller aynı tehdit modelini paylaşır; bölmek paylaşılan bağlamı iki dispatch'e dağıtır ve sınırlardan birinin diğerinden habersiz yazılmasına yol açar.

> Agent/Model/Effort gerekçesi: kırmızı çizgi — SSRF (sunucudan kullanıcı-kontrollü hedefe istek) + secret saklama (auth token'ı); değişen garanti "bizim sunucumuz kimin adına nereye istek atar". `main` tier + xhigh, Codex yasak, Claude şeridi.

#### Amaç
Doküman panelinden JSON gövde + auth bilgisiyle gerçek istek atılabilsin — token tarayıcıya düşmeden, sunucu iç ağa açılmadan.

#### To-Do
- Create `src/Support/OutboundRequestGuard.php` — the SSRF boundary. It MUST: resolve the target host and reject any request whose resolved address is loopback, link-local (169.254.0.0/16, fe80::/10), private (10/8, 172.16/12, 192.168/16, fc00::/7), or a cloud metadata address; reject non-`http`/`https` schemes; require the host to match `config('api-dock.try_it.allowed_hosts')` (empty allowlist means every request is denied, never "allow all"); disable redirect following; enforce the configured timeout and a response size cap. Resolve the host once and connect to the resolved address so a DNS rebind between check and request is not possible.
- Create `src/Support/AuthProfileStore.php`: store per-session try-it profiles (label, base URL, auth scheme, credential) in the cache under a key derived from the session id, encrypted via Laravel's encrypter, with the configured TTL. Never write a credential to disk, log, or the response body; reading a profile back returns the credential masked unless it is being used to build an outbound request.
- Create `src/Http/Controllers/ProxyController.php`: accept method, path, headers, query and JSON body from the panel; refuse when `config('api-dock.try_it.enabled')` is false; run every outbound request through `OutboundRequestGuard`; strip hop-by-hop and forbidden headers from both the outgoing request and the returned response; return status, headers and body to the panel with the credential never echoed back. Register the route behind the existing `ApiDockAccess` middleware plus a dedicated throttle.
- Both the request and the response must be size-capped; a response over the cap is truncated with an explicit flag, not streamed unbounded into memory.
- Support OpenAPI **server variables** in the outbound target: the panel sends the chosen server template plus its variable values (e.g. `https://{kurum}.congress-app.test/api`), and the proxy substitutes them BEFORE the guard runs. Substitution happens on the server, never on the client: a variable value is URL-encoded, rejected if it contains `/`, `@`, `:`, `?`, `#` or a percent-escape that decodes to any of those, and rejected if it is absent from the operation's declared `enum` when the spec declares one. The guard then validates the FULLY substituted URL — allowlist, address class and scheme checks all run on the final host, never on the template. A template with an unsubstituted `{placeholder}` left in it is refused outright.
- Document every default in `config/api-dock.php` inline: `try_it.enabled` false, `allowed_hosts` empty, timeout and caps set conservatively.

#### Expected Output
- Working try-it proxy, disabled by default, deny-by-default host allowlist
- Session-scoped encrypted credential storage with TTL, no persistence to disk
- Explicit SSRF guard with the rejection rules listed above

#### Verification
- `vendor/bin/pest tests/Feature/ProxyControllerTest.php`

#### Risk Notes
- **Boş allowlist = her istek reddedilir.** "Boşsa hepsine izin ver" varsayılanı bu paketi doğrudan bir SSRF aracına çevirir; bu kural pazarlığa açık değil.
- **Server variable ikamesi saldırı yüzeyidir.** `{kurum}` gibi bir değişkene `evil.com/` ya da `x@attacker` yazılırsa host tamamen değişir; bu yüzden ikame sunucuda yapılır, kontrol ikameden SONRAKİ tam URL üstünde koşar ve allowlist eşleşmesi şablona değil çözülmüş host'a bakar.
- DNS rebind: host kontrolü ile bağlantı arasında çözümleme tekrarlanmamalı.
- Credential hiçbir yolla yanıt gövdesine, log'a veya exception mesajına girmemeli — hata yolları dahil.
- Prod'da varsayılan kapalı; açan tarafın bilinçli config kararı olmalı.

---

### Task 7: Proxy güvenlik regresyon testleri ✅ DONE
**Agent:** `backend` · **Model:** `opus-5` · **Effort:** high · **Executor:** `claude`
**Depends-on:** Task 6

> Agent/Model/Effort gerekçesi: kırmızı çizgi regresyon testi güvenlik sınırının kendisi — bağımsız yazar zorunlu, yazarın kendi kodunu test etmesi yasak; kırmızı çizgi domaini → Claude şeridi (codex yasak), `main` tier + high.

#### Amaç
Task 6'nın kurduğu SSRF ve secret sınırlarının, üreten taskan bağımsız testlerle kalıcı olarak korunması.

#### To-Do
- Write `tests/Feature/ProxySecurityTest.php` asserting rejection for each of: loopback host, private-range host, link-local and cloud metadata address, non-http scheme, host absent from the allowlist, empty allowlist, redirect response, over-cap response body, and a request made while `try_it.enabled` is false. Each case asserts the request never left the process.
- Write `tests/Feature/AuthProfileStoreTest.php` asserting: the credential is encrypted at rest in the cache, a read returns it masked, the TTL expires it, one session cannot read another session's profile, and the credential appears in no response body, log line, or exception message on both the success and failure paths.
- Do not modify production code. If a test proves a boundary is missing, report it in the task output — the fix rides a separate change.

#### Expected Output
- Two feature test files covering every rejection rule and every credential-exposure path
- Any missing boundary reported, not patched

#### Verification
- `vendor/bin/pest tests/Feature/ProxySecurityTest.php tests/Feature/AuthProfileStoreTest.php`

#### Risk Notes
- Test bir dış hedefe gerçek ağ isteği atmamalı; reddin isteğin *öncesinde* gerçekleştiği doğrulanmalı.

---

### Task 8: Vue SPA iskeleti ve spec render ✅ DONE
**Agent:** `frontend` · **Model:** `opus-5` · **Effort:** high · **Executor:** `codex`
**Depends-on:** Task 1

> Agent/Model/Effort gerekçesi: paket içi bağımsız build pipeline + OpenAPI şema ağacının özyinelemeli render'ı (`$ref` çözümü, allOf/oneOf) çok dosyalı ve davranışsal; kırmızı çizgi yok → `main` tier + high, Codex şeridi.

#### Amaç
Paketin kendi doküman arayüzü ayağa kalksın: spec'i tüketip endpoint listesi, operasyon detayı ve şema ağacını göstersin.

#### To-Do
- Set up the package-local frontend build: `package.json` and `vite.config.ts` producing a single self-contained bundle into `dist/`, with Vue 3 + TypeScript, no dependency on the consuming app's build. Wire `vendor:publish` to copy `dist/` into the app's public path, and add a Blade view that mounts the bundle and passes the spec URL.
- Build the app shell in `resources/js/`: fetch the spec from the package route, group operations by tag in a sidebar with search, and route to an operation detail view. Handle loading, empty and error states explicitly.
- Build the schema tree component: recursively render an OpenAPI schema — resolve `$ref` against the document, handle `allOf`/`oneOf`/`anyOf`, arrays, enums, nullability, and required markers; guard against circular references with a depth limit rather than crashing.
- Build the operation detail view: method, path, description, the `x-api-dock-features` block (auth, rate limit, deprecation), parameters, request body schema, and per-status response schemas.
- Add component tests under `resources/js/__tests__/` for the schema tree (circular ref, `allOf` merge, enum, nullable) and the tag grouping.
- Leave a mount point for the try-it, AI and diff panels that Task 9 fills; do not implement them here.

#### Expected Output
- Self-contained built SPA bundle in `dist/`, published via `vendor:publish`
- Working spec render: sidebar, operation detail, recursive schema tree
- Passing component tests for the schema tree and grouping

#### Verification
- `npx vitest run resources/js/__tests__/SchemaTree.spec.ts resources/js/__tests__/TagGrouping.spec.ts`

#### Risk Notes
- Döngüsel `$ref` gerçek spec'lerde sık; derinlik sınırı olmadan sonsuz render.
- Bundle self-contained olmalı — tüketen projenin build'ine bağımlılık paketin kurulum vaadini bozar.

---

### Task 9: Try-it, AI ve diff panelleri ✅ DONE
**Agent:** `frontend` · **Model:** `opus-5` · **Effort:** high · **Executor:** `codex`
**Depends-on:** Task 6, Task 8, Task 13

> Agent/Model/Effort gerekçesi: üç ayrı panelin durum yönetimi ve proxy sözleşmesine bağlanması, çok dosyalı davranışsal UI işi; proxy'nin *güvenlik* mantığı Task 6'da bitti, burada yalnız istemci tarafı → kırmızı çizgi yok, `main` tier + high, Codex şeridi.

#### Amaç
Panelden JSON gövde + auth ile istek atılabilsin, AI çıktıları görünür/kopyalanabilir olsun, sürüm farkı okunabilsin.

#### To-Do
- Build the try-it panel: method and path prefilled from the operation, editable path/query parameters, headers, a JSON body editor with syntax validation before send, an environment/profile selector, and a response view showing status, timing, headers and pretty-printed body. All requests go through the package's proxy endpoint — never a direct cross-origin fetch.
- Credential handling in the client: the credential is submitted to the profile endpoint and never held in `localStorage` or query strings; the panel displays it masked and shows a clear disabled state with the reason when `try_it.enabled` is false.
- Build the server-variable controls: read `servers[].variables` from the spec, render one input per variable (a select when the spec declares `enum`, a text input otherwise, prefilled from `default`), let the user pick the server when several are declared, and show the resolved base URL live above the send button. The values are sent to the proxy as data; the client performs no substitution and makes no allow/deny decision of its own.
- Build the AI panel: render `x-ai-hint`, `x-ai-pitfalls` (numbered) and `x-ai-examples` for the operation, plus a single copy-to-clipboard action that yields the whole agent prompt block for that operation, and separate copy actions for its MCP tool definition and its llms.txt section, sourced from the package's export endpoints.
- Build the per-operation changelog view: render `x-api-dock-changelog` newest-first, each entry showing its date and summary, with an intro line stating that the prompt above is always current so an integrator reading it for the first time can skip the history.
- Build the request sample: generate a runnable `curl` command from the panel's CURRENT state — resolved base URL, method, path with substituted path parameters, query string, headers, and the JSON body — regenerated as the user edits. The credential is rendered exactly as the panel holds it (masked), never the stored value, and the sample is copy-to-clipboard.
- Build the diff view: consume the `api-dock:diff --json` output shape and render changes grouped by severity (breaking / additive / cosmetic) with the affected operation and a one-line description per change.
- Add component tests for the JSON body validation path, the disabled try-it state, and the diff severity grouping.

#### Expected Output
- Three working panels mounted into the Task 8 shell
- Every try-it request routed through the backend proxy, credential never in client storage
- Passing component tests for the three behaviours listed above

#### Verification
- `npx vitest run resources/js/__tests__/TryItPanel.spec.ts resources/js/__tests__/DiffView.spec.ts`

#### Risk Notes
- Panel istemci tarafında hiçbir güvenlik kararı vermemeli — allowlist ve reddetme sunucuda; istemci yalnız sunucunun reddini gösterir.
- Yanıt gövdesi büyük olabilir; render sınırlandırılmalı yoksa sekme kilitlenir.

---

### Task 10: README ve kullanım dokümanı ✅ DONE
**Agent:** `docs` · **Model:** `sonnet-5` · **Effort:** medium · **Executor:** `codex`
**Depends-on:** Task 2, Task 3, Task 4, Task 6, Task 13

> Agent/Model/Effort gerekçesi: doküman üretimi, kod yok → base tier + Codex şeridi.

#### Amaç
Paketin kurulum, yapılandırma ve güvenlik sözleşmesi yazılı olsun; tüketen proje neyi açtığını bilerek açsın.

#### To-Do
- Write `README.md`: what the package adds on top of `dedoc/scramble`, installation, `vendor:publish` steps, every config key with its default and effect, and the four attributes with a usage example each.
- Document the console commands: `api-dock:sync` (including `--check` for CI and its exit codes), `api-dock:diff`, `api-dock:export` with all format flags.
- Write a dedicated try-it security section: disabled by default, the allowlist is deny-by-default, credentials are session-scoped and never persisted, and an explicit recommendation to leave it off in production. State plainly what enabling it exposes.
- Add an AI integration guide: how to consume the MCP tool output, how to feed the llms.txt bundle to a model, and what `x-ai-*` keys mean.
- Document the six attributes as ONE authoring guide, not six reference entries: how `AiHint`, `AiPitfall`, `AiChangelog`, `AiExample`, `AiTool` and `ApiFeature` compose into the agent prompt block a consumer copies out of the panel, with a full worked example on one endpoint.
- Document the try-it server variables: how `servers[].variables` reach the panel, that substitution happens server-side, and which values are rejected.
- Add an attribution section: built on `dedoc/scramble` (MIT), with the package's own MIT license noted, and an explicit note that Scramble Pro is not a dependency.

#### Expected Output
- `README.md` covering install, config, attributes, commands, AI integration, security posture, attribution

#### Verification
- Read the diff against the implemented config keys, attributes and command signatures: every documented key, flag and attribute exists in `src/` and `config/api-dock.php`, and every config key present in the file is documented.

#### Risk Notes
- Doküman gerçek imzalarla doğrulanmadan yazılırsa paketin ilk kullanıcı deneyimi yanlış yönlendirilmiş olur.

---

### Task 13: Tuzak ve değişiklik geçmişi attribute'ları ✅ DONE
**Agent:** `backend` · **Model:** `opus-5` · **Effort:** high · **Executor:** `codex`
**Depends-on:** Task 2, Task 4
**Oversize-ack:** İki attribute, onları okuyan tek extension ve iki exporter'ın tükettiği anahtar aynı sözleşmenin parçası — attribute'u yazıp okuyucusunu ayrı dispatch'e bırakmak, üretilmeyen bir anahtarı tüketen yarım bir tur bırakır ve hiçbir yarı tek başına doğrulanamaz.

> Agent/Model/Effort gerekçesi: Task 2'nin dondurduğu vendor extension şemasına iki anahtar daha ekliyor; sıralama, tarih ayrıştırma ve miras (sınıf → metot) kuralları davranışsal. Kırmızı çizgi yok → `main` tier + high, Codex şeridi.

#### Amaç
Bir endpoint'in "bu ucun tuzakları" listesi ve tarihli değişiklik geçmişi, kodun yanında tek doğruluk kaynağı olarak yaşasın ve spec'e insin. Task 3'ün snapshot diff'i sürümler arası **makine** farkını üretir; bu ondan ayrı bir şeydir — entegre eden insana yazılmış, elle bakımlı bir kayıttır.

#### To-Do
- Add `src/Attributes/AiPitfall.php`: `#[Attribute(IS_REPEATABLE)]`, readonly, one free-text pitfall per instance, optional `int $order` for explicit ordering. Class-level instances apply to every operation of the controller; method-level instances are appended after them.
- Add `src/Attributes/AiChangelog.php`: `#[Attribute(IS_REPEATABLE)]`, readonly, `string $date` (`Y-m-d`) and `string $summary`, optional `bool $breaking = false`. An unparsable date is kept verbatim and sorted last rather than throwing — document generation never fails on a malformed attribute.
- Extend `src/Extensions/AiMetadataOperationExtension.php` to emit two more operation-level keys, following the shape frozen in Task 2 (`setExtensionProperty` takes the key WITHOUT the `x-` prefix, and a key is omitted entirely when empty):
  `ai-pitfalls` -> list of `{order: int, text: string}`, stable ascending order
  `api-dock-changelog` -> list of `{date: string, summary: string, breaking: bool}`, newest first
- Extend `src/Export/LlmsTxtExporter.php` and `src/Export/McpToolExporter.php` so the pitfalls ride into the agent-facing output: the llms.txt section gains a "Pitfalls" block, and the MCP tool description appends them after the hint. The changelog is documentation for humans and does NOT enter the MCP tool description.
- Extend the Task 2 test file with a fixture carrying both attributes at class and method level, asserting merge order, newest-first changelog ordering, the malformed-date fallback, and that an operation carrying neither attribute emits neither key.

#### Expected Output
- Two new repeatable attributes, both emitted as vendor extensions on annotated operations
- Pitfalls present in the llms.txt and MCP output; changelog absent from the MCP tool description
- Green extension test covering ordering, inheritance and the malformed-date path

#### Verification
- `vendor/bin/pest tests/Feature/AiMetadataTest.php`

#### Risk Notes
- Anahtar şeması Task 2'de donduruldu; buradaki iki ekleme **additive** olmalı — mevcut anahtarların şekli değişirse Task 4, 9 ve 10 sessizce kırılır.
- Bozuk tarih doküman üretimini patlatmamalı; hiçbir attribute okuma yolu exception fırlatmaz.

---

### Task 11: Konsolide doğrulama ✅ DONE
**Agent:** `verifier` · **Model:** `sonnet-5` · **Effort:** medium · **Executor:** `claude`
**Depends-on:** Task 5, Task 7, Task 9, Task 10, Task 13

> Agent/Model/Effort gerekçesi: bütünleşik test/build/statik analiz koşusu — muhakeme değil yürütme, rol tavanı medium; base tier + Claude şeridi.

#### Amaç
Paketin tamamının tek seferde yeşil olduğunu kanıtlamak.

#### To-Do
- Run the full package test suite and report the output.
- Run static analysis and the formatter check over the package source.
- Run the frontend unit tests and the package bundle build.
- Report every failure with its shortest decisive line; do not modify production code.

#### Expected Output
- Pass/fail report for suite, static analysis, formatter, frontend tests and bundle build

#### Verification
- `vendor/bin/pest`
- `vendor/bin/phpstan analyse --memory-limit=1G`
- `vendor/bin/pint --test`
- `npx vitest run`
- `npm run build`

#### Risk Notes
- Bu task'ta üretim kodu değişmez; kırmızı çıkan kalem rapor edilir, düzeltme ayrı bir değişikliktir.

---

### Task 12: Final review ✅ DONE
**Agent:** `reviewer` · **Model:** `opus-5` · **Effort:** xhigh · **Executor:** `claude`
**Depends-on:** Task 11

> Agent/Model/Effort gerekçesi: plan riski `high` — SSRF sınırı, credential saklama ve dışa açılan public paket yüzeyi; bağımsız göz `review-eye` tier + xhigh, Claude şeridi.

#### Amaç
Kırmızı çizgi yüzeyinin ve paketin public API'sinin bağımsız gözle denetlenmesi.

#### To-Do
- Review the proxy and credential surface against the boundaries stated in Task 6: allowlist semantics, DNS rebind, redirect handling, size caps, credential exposure on every code path including exceptions and logs.
- Review the package's public API for accidental breaking-change surface: attribute constructor signatures, config key names, command flags, vendor extension key shape, and the exported MCP/llms.txt formats.
- Review that nothing in the package reaches into Scramble's non-public namespaces (`src/Infer` and anything not under `Extensions/`, `Contracts/`, `Attributes/`).
- Report findings at `file:line` with a confidence level; change no code.

#### Expected Output
- Findings list at `file:line` with severity and confidence, or an explicit clean verdict with the evidence that supports it

#### Verification
- Diff read end to end; every finding carries a `file:line` anchor and a named failure scenario.

#### Risk Notes
- Temiz verdict kanıt ister; "sorun görülmedi" tek başına kabul edilmez.

---

## Geri Alma Stratejisi

Paket greenfield ve henüz hiçbir projede kurulu değil — geri alma repo düzeyinde ucuz.

- **Task 1–5, 8–10:** dosya bazlı; ilgili commit'in revert'i yeterli, dışarıda tüketici yok.
- **Task 6 (proxy):** `config('api-dock.try_it.enabled')` varsayılanı `false`. Bir sorun çıkarsa kod geri alınmadan önce bu bayrak kapatılarak yüzey anında kapanır; kalıcı geri alma commit revert'i.
- **Auth profil deposu:** cache tabanlı ve TTL'li, kalıcı veri yok — geri almanın veri kaybı bileşeni yok, migration yok.
- **Scramble bağımlılığı:** `composer.json`'dan çıkarıldığında paket derlenmez; bağımlılık kararı geri alınacaksa plan baştan alınır, kısmi geri alma yolu yok. Bu yüzden sürüm constraint'i Task 1'de kurulu sürümden okunuyor.
- Hiçbir task DB şemasına dokunmuyor; migration geri alma yolu gerekmiyor.

## Doğrulama Stratejisi

- **Test:** her task kendi dar `--filter`/dosya kapsamında koşar; konsolide suite yalnız Task 11'in tekeli. Davranışsal birimler (differ, exporter'lar) ve kırmızı çizgi yüzeyi (proxy, credential) bağımsız yazarlarca test edilir — Task 5 ve Task 7.
- **Manuel:** fixture bir Laravel uygulamasında doküman route'u açılır; bir endpoint'e `AiHint` + `ApiFeature` yazılıp panelde göründüğü, `api-dock:export --mcp` çıktısının geçerli tool tanımı ürettiği, try-it panelinin allowlist dışındaki bir host'u reddettiği elle doğrulanır.
- **Regresyon:** `api-dock:sync --check` paketin kendi fixture spec'i üstünde koşturulur; breaking change sınıflandırmasının exit kodu sözleşmesi Task 5'te sabitlenir.
- **Güvenlik:** Task 12 kırmızı çizgi yüzeyini bağımsız gözle denetler; ayrıca `/security-review` yayın öncesi çalıştırılır.
