# Spec diff kapısı, exporter $ref tutarlılığı ve erişim sertleştirmesi

**Date:** 2026-09-05
**Risk level:** high
**Verify:** `composer test && npm run test`
**Planning-model:** `opus-5`
**Planning-effort:** xhigh

<!-- Planning effort xhigh: SpecDiffer'ın sessiz-kalma davranışı yapısal, tek tek keyword yaması
     değil sınıflandırma sözleşmesinin yeniden kurulması gerekiyor; ayrıca SSRF guard ve erişim
     kapısı aynı planda. -->

## Task Özeti

| # | Task | Agent | Model | Effort | Executor | Depends-on |
|---|---|---|---|---|---|---|
| 1 | SpecDiffer eksik-taraf ve `$ref` sınıflandırması | `backend` | `opus-5` | xhigh | `claude` | — |
| 2 | Exporter'larda ortak `$ref` çözümü | `backend` | `opus-5` | high | `claude` | — |
| 3 | Try-it self host port kontrolü | `security` | `opus-5` | xhigh | `claude` | — |
| 4 | Opsiyonel `viewApiDock` erişim kapısı | `security` | `opus-5` | xhigh | `claude` | Task 3 |
| 5 | Regresyon testleri (diff + erişim kapısı) | `backend` | `sonnet-5` | high | `claude` | Task 1, Task 4 |
| 6 | Doküman güncellemesi | `docs` | `sonnet-5` | medium | `claude` | Task 1, Task 2, Task 3, Task 4 |

## Amaç

Proje incelemesinden çıkan kritik ve yüksek bulguları kapatmak. Ana problem: `api-dock:sync --check`
README'de CI kapısı olarak öneriliyor ama sekiz farklı girdi şeklinde exit 0 veriyor ve altısında
hiç change üretmiyor — kırıcı bir spec değişikliği CI'dan sessizce geçiyor. İkinci problem: iki
exporter `$ref` konusunda ayrışmış, `llms.txt` ajanlara boş parametre listesi ve çözülmemiş şema
referansı veriyor. Üçüncüsü: try-it kendi uygulamasına standart olmayan portta ulaşamıyor.
Dördüncüsü: panelin erişim kapısı yok, isteyen projede açılabilir bir kapı eklenir.

## Kapsam

- **Etkilenen katmanlar:** OpenAPI diff motoru, AI export katmanı, try-it SSRF guard, HTTP erişim
  middleware'i, config, testler, doküman.
- **Etkilenen dosyalar:** `src/Support/SpecDiffer.php`, `src/Export/LlmsTxtExporter.php`,
  `src/Export/McpToolExporter.php`, yeni bir paylaşılan exporter yardımcı sınıfı,
  `src/Support/OutboundRequestGuard.php`, `src/Http/Middleware/ApiDockAccess.php`,
  `config/api-dock.php`, `tests/Unit/SpecDifferTest.php`, `tests/Unit/*ExporterTest.php`,
  `tests/Feature/ProxySecurityTest.php`, `tests/Feature/PackageSetupTest.php`, `README.md`.
- **Kapsam dışı:**
  - PHPStan'ın proje genelindeki 17 hatası ve `release.sh`'a geri alınması — ayrı iş.
  - CI workflow'u, CHANGELOG dosyasının kurulması — ayrı iş.
  - Frontend bulguları (AbortController, boş body, clipboard, erişilebilirlik, ölü i18n
    anahtarları, `TryItPanel.vue` bölünmesi) — ayrı plan.
  - `/spec` cache/ETag — ayrı iş.
  - Bu oturumda zaten yapılan küçük düzeltmeler (PSR-4 dosya bölme, DOMPurify `style`, MCP boş
    `properties`, doküman kaymaları) tekrar ele alınmaz.
  - Erişim kapısının varsayılan olarak açılması — kullanıcı kararı: varsayılan `false`, kırıcı
    değişiklik yok.

## Alternatifler

**Erişim kapısı varsayılanı.** Üç seçenek değerlendirildi: (a) non-local ortamda varsayılan zorunlu
(Telescope deseni) — en güvenli ama mevcut production kurulumları paneli kaybederdi; (b) yalnız
uyarı — kimseyi korumaz; (c) config ile opt-in, varsayılan kapalı. Kullanıcı (c)'yi seçti: hiçbir
kurulum kırılmaz, isteyen projede tek satırla açar. Varsayılanın güvensiz kalması bilinçli kabul.

**Self host port kontrolü.** İki seçenek: (a) self host için port kontrolünü tamamen atlamak —
self host zaten allowlist ve private-IP kontrollerini bypass ettiği için port kalan tek sınır,
kaldırmak SSRF yüzeyini genişletir; (b) `app.url`'in kendi portunu türetip yalnız onu kabul etmek.
(b) seçildi: sınır korunur, gerçek kurulum çalışır.

**SpecDiffer yaklaşımı.** Keyword başına ayrı yama yerine eksik-taraf davranışının tek yerde
birleştirilmesi seçildi; bulguların hepsi aynı yapısal kökten çıkıyor (dal bail-out eder, cosmetic
ignore list yutar), tek tek yamalamak aynı hatanın yeni keyword'lerde tekrarlamasına açık bırakır.

## Tasks

### Task 1: SpecDiffer eksik-taraf ve `$ref` sınıflandırması ✅ DONE
**Agent:** `backend` · **Model:** `opus-5` · **Effort:** xhigh · **Executor:** `claude`
**Scope-paths:** `src/Support/SpecDiffer.php`

> Agent/Model/Effort gerekçesi: tek dosya ama davranış sözleşmesinin yeniden kurulması — on bir ayrı
> sınıflandırma hatası aynı yapısal kökten geliyor, her düzeltme diğerlerinin sınıfını etkiliyor;
> `main` tier + xhigh. Codex şeridi kapalı, kod Claude tarafında yazılır.

#### Amaç
`sync --check` kapısının sessiz kalmasını bitirmek: bir tarafta olup diğerinde olmayan her schema
anahtarı görünür bir change üretmeli, `$ref` kimliği karşılaştırılmalı, şema varyantları pozisyona
göre değil imzaya göre eşleşmeli ve yön (request/response) sınıflandırmaya girmeli.

#### To-Do
- Unify absent-side handling in `compareSchema`: introduce one helper that both branches route
  through, so a keyword present on exactly one side always produces a change instead of falling
  into `compareCosmeticFields`. This closes: added `enum`, added `maxLength`/`minimum`/`maxItems`
  and OpenAPI 3.0 boolean `exclusiveMinimum`, `additionalProperties` boolean flips, and a removed
  `items`. Narrow the terminal cosmetic ignore list to the keys that are genuinely cosmetic.
- Compare `$ref` identity: a changed schema reference (`.../UserFull` → `.../UserSlim`) is a
  change on its own, not `cosmetic`. Apply the same to `parameterKey`, which currently keys a
  `$ref` parameter by its reference string — resolve the reference before keying so a swapped
  parameter is compared by its resolved `name`/`in`/`required`.
- Replace the positional alignment in `compareSchemaVariants` (`allOf`/`anyOf`/`oneOf`) with
  signature matching (`$ref` string, else `type` + shape), so reordering is not a change and a
  swapped variant is.
- Visit keys present only on the `before` side in `compareEmbeddedSchemas` (it currently iterates
  `array_intersect(array_keys(...))`), so a response that loses its `content` block or changes
  media type is reported; stop ignoring `content`/`headers` unconditionally.
- Walk the rest of `components` in `compareComponents` (currently only `components.schemas`), so a
  `securitySchemes` change (`http`/`bearer` → `apiKey`) and a changed `components.parameters`
  entry are classified rather than swallowed; classify a document-level `servers` change and make
  `compareAuth` order-insensitive over the `security` list.
- Bind direction to `$context` where severity is decided: on a response, a widened type and an
  added enum value break consumers and must not be `additive`; a removed path parameter is not
  `additive` either — the document becomes invalid when a template variable loses its parameter.

#### Expected Output
- `src/Support/SpecDiffer.php` classifying every case above, with the existing stable change-type
  slugs preserved and new slugs added only where no existing one fits.
- The class docblock's slug list updated to match.

#### Verification
- `vendor/bin/pest --filter=SpecDiffer`
- `vendor/bin/phpstan analyse src/Support/SpecDiffer.php --no-progress`

#### Risk Notes
- `sync --check` çıkış kodu tüketici CI'larında davranış değiştirir: daha önce yeşil geçen bir
  spec değişikliği artık kırıcı sayılabilir. Bu düzeltmenin amacı bu, ama sürüm notunda açıkça
  duyurulmalı (Task 6).
- Mevcut testler yalnız pinlenen vakaları kapsıyor; bu görevin çıktısı Task 5'in regresyon
  testleriyle birlikte değerlendirilmeli.

### Task 2: Exporter'larda ortak `$ref` çözümü ✅ DONE
**Agent:** `backend` · **Model:** `opus-5` · **Effort:** high · **Executor:** `claude`
**Scope-paths:** `src/Export/**`, `tests/Unit/McpToolExporterTest.php`, `tests/Unit/LlmsTxtExporterTest.php`

> Agent/Model/Effort gerekçesi: iki dosya arası ortak soyutlama çıkarma + davranış düzeltmesi,
> çapraz kesen ama kırmızı çizgi değil → `main` tier / high. Codex şeridi kapalı, Claude yazar.

#### Amaç
İki exporter'ın `$ref` konusunda ayrışmasını bitirmek. `llms.txt` şu an `$ref` parametreleri için
"No parameters." basıyor ve şema referanslarını ham yazıyor — ajana hiçbir şekil bilgisi gitmiyor,
README'nin vaadiyle çelişiyor.

#### To-Do
- Extract the duplicated helpers (`HTTP_METHODS`, `operations()`, `stringMap()`, `listValue()`,
  `stringList()`/`nonEmptyString()`) and the `$ref` resolution into one shared support class under
  `src/Export/`, and have both exporters use it. The two files diverged precisely inside this
  duplication.
- Resolve parameter and schema `$ref`s in `LlmsTxtExporter` through that shared resolver, so a
  `$ref` parameter renders with its `name`/`in`/`required` and a `$ref` response renders the
  resolved shape instead of a fenced `{"$ref": "..."}` block.
- Memoise resolution in the shared resolver: `resolveSchemaNode` currently inlines each `$ref`
  independently, so a reused schema graph expands exponentially (measured on a ~1 KB source:
  depth 6 → 7.5 KB, depth 12 → 483 KB). Return an empty JSON Schema object, not `[]`, for a cycle
  or an unresolvable reference — `[]` encodes as a JSON array and produces `"items": []`.
- Fix the `McpToolExporter` defects the resolver does not cover: `stringMap` drops integer keys, so
  a body property named `"2"` disappears from `properties` while still being listed in `required`;
  a composed (`allOf`) body collapses into a single nested `body` property instead of the spread
  README describes; tool names are emitted verbatim with no sanitisation or length cap against the
  MCP tool-name pattern (`App\Http\Controllers\UserController@index` currently passes through).
- Extend `tests/Unit/McpToolExporterTest.php` and `tests/Unit/LlmsTxtExporterTest.php` for each
  case above. Assert the ENCODED artifact (`json_encode(...)`) wherever the defect is a JSON shape
  question — the existing empty-`properties` test passed for a year while the artifact was wrong
  because it asserted the PHP array.

#### Expected Output
- One shared exporter support class; both exporters delegating to it, with no duplicated helper
  bodies left.
- `llms.txt` output that resolves parameter and schema references.
- MCP tool names conforming to the tool-name pattern, integer-keyed body properties preserved,
  `allOf` bodies spread.

#### Verification
- `vendor/bin/pest --filter="McpToolExporter|LlmsTxtExporter|ExportCommand"`
- `vendor/bin/phpstan analyse src/Export --no-progress`

#### Risk Notes
- Tool adı sanitizasyonu mevcut export'lardaki adları değiştirebilir; MCP istemcisi aracı adıyla
  çağırdığı için bu tüketici tarafında kırıcı. Yalnız pattern'e uymayan adlar dönüştürülmeli,
  uyanlar aynen kalmalı.
- `$ref` çözümü `llms.txt` çıktısını büyütür; memoization olmadan büyük şema grafiklerinde bellek
  sorunu çıkar — memoization bu görevin parçası, sonraya bırakılamaz.

### Task 3: Try-it self host port kontrolü ✅ DONE
**Agent:** `security` · **Model:** `opus-5` · **Effort:** xhigh · **Executor:** `claude`
**Scope-paths:** `src/Support/OutboundRequestGuard.php`, `config/api-dock.php`, `tests/Feature/ProxySecurityTest.php`

> Agent/Model/Effort gerekçesi: SSRF guard'ın sınırını değiştiriyor — kırmızı çizgi, `security`
> ajanı + `main` tier / xhigh; kırmızı çizgi olduğu için Codex şeridine hiç girmez.

#### Amaç
`app.url` standart olmayan bir port taşıdığında (`http://localhost:8000`, Docker `:8080`) try-it'in
kendi uygulamasına ulaşamamasını gidermek, port allowlist'ini yabancı hostlar için sınır olarak
korumak.

#### To-Do
- Derive the application's own port from `app.url` and accept it for the self host only, alongside
  `try_it.allowed_ports`. A foreign host keeps the unchanged allowlist: the port check is the only
  boundary left on a self host (the allowlist and the private-address check are already bypassed
  there), so it is narrowed, never removed.
- Derive the port from the configured `app.url` and from `try_it.self_hosts` entries that carry
  one — never from the request's Host header, matching how the self host itself is resolved.
- Cover in `tests/Feature/ProxySecurityTest.php`: self host on the `app.url` port is allowed; the
  same self host on a different non-allowlisted port is denied; a foreign host on the `app.url`
  port is denied; an `app.url` with no explicit port changes nothing.

#### Expected Output
- `src/Support/OutboundRequestGuard.php` accepting the application's own port for the self host.
- Config comment on `try_it.allowed_ports` stating that the self host's own port is added
  automatically.

#### Verification
- `vendor/bin/pest --filter="ProxySecurity|ProxyController"`

#### Risk Notes
- Yanlış uygulanırsa port allowlist'i yabancı hostlar için de gevşer ve guard bir port tarayıcıya
  dönüşür — testin negatif tarafı (yabancı host, aynı port, reddedilir) zorunlu.
- `app.url` yapılandırması hatalı bir uygulamada (ör. `APP_URL` production'da localhost kalmış)
  türetilen port beklenmedik olabilir; port yalnız self host eşleşmesiyle birlikte geçerli olmalı.

### Task 4: Opsiyonel `viewApiDock` erişim kapısı ✅ DONE
**Agent:** `security` · **Model:** `opus-5` · **Effort:** xhigh · **Executor:** `claude`
**Depends-on:** Task 3
**Scope-paths:** `src/Http/Middleware/ApiDockAccess.php`, `config/api-dock.php`, `src/ApiDockServiceProvider.php`
**Merge-ack:** aynı config dosyasına dokundukları için sıralandı; birleştirilemez — biri SSRF port sınırı, diğeri auth kapısı, birleşik To-Do dokuz maddeye ve yedi dosyaya çıkar.

> Agent/Model/Effort gerekçesi: kimin paneli ve try-it proxy'sini kullanabileceğini belirleyen auth
> kapısı — kırmızı çizgi, `security` ajanı + `main` tier / xhigh; Codex şeridine girmez.

#### Amaç
Panelin ve try-it proxy'sinin kim tarafından kullanılabileceğini uygulamanın belirleyebilmesi.
Kullanıcı kararı: varsayılan kapalı (`false`), mevcut hiçbir kurulum kırılmaz.

#### To-Do
- Add `gate.enabled` (default `false`) to `config/api-dock.php`, documented as: when true, every
  API Dock route requires the `viewApiDock` Gate to pass.
- Register a permissive default `viewApiDock` Gate ability in the service provider only when the
  host application has not defined one, so enabling the config in an app with no ability defined
  fails CLOSED (denies) rather than silently allowing everything. State that in the config comment.
- Enforce it in `ApiDockAccess`: when `gate.enabled` is true and the Gate denies, respond exactly
  as the disabled package does today (404, so the surface is not even confirmed to exist) — keep
  the existing 404-when-disabled behaviour unchanged for the config-off path.
- Add a README section showing `Gate::define('viewApiDock', fn ($user) => $user->isAdmin())` and
  stating that the gate is off by default and the panel is otherwise reachable by anyone who can
  reach the route.

#### Expected Output
- `config/api-dock.php` carrying `gate.enabled`.
- `ApiDockAccess` denying via the Gate when enabled, unchanged when not.
- README section on the gate.

#### Verification
- `vendor/bin/pest --filter="PackageSetup|ProxyController"`

#### Risk Notes
- Kapı açıkken ability tanımlı değilse davranış fail-closed olmalı; fail-open bir varsayılan, kapıyı
  açtığını sanan kurulumu koruma altında olduğu yanılgısıyla bırakır.
- Reddedilen istekte 403 yerine 404 dönmek bilinçli: paketin zaten kapalıyken verdiği yanıtla aynı,
  yüzeyin varlığını doğrulamaz.

### Task 5: Regresyon testleri (diff + erişim kapısı) ✅ DONE
**Agent:** `backend` · **Model:** `sonnet-5` · **Effort:** high · **Executor:** `claude`
**Depends-on:** Task 1, Task 4
**Scope-paths:** `tests/Unit/SpecDifferTest.php`, `tests/Feature/PackageSetupTest.php`

> Agent/Model/Effort gerekçesi: kenar durum ağırlıklı davranışsal test yazımı — üreten görevden ayrı
> ajan, yoksa test uygulamaya doğru bükülür; `base` tier ama davranışsal olduğu için high.
> Codex şeridi kapalı, Claude yazar.

#### Amaç
Task 1 ve Task 4'ün kapattığı her sınıf için, üreten görevden bağımsız bir gözle yazılmış regresyon
testi. Mevcut suite yalnız pinlenen vakaları kapsıyor; kapanan sekiz sessiz vaka test edilmezse
aynı boşluk yeniden açılır.

#### To-Do
- Write one `SpecDifferTest` case per silent-failure class closed by Task 1, each asserting the
  reported severity AND the change type (not merely `has_breaking`): added `enum`, added
  `maxLength`/`minimum`, `additionalProperties` `true` → `false`, removed `items`, a response
  losing its `content` block, a changed media type, a swapped schema `$ref`, a changed
  `securitySchemes`, a `$ref` parameter becoming required, a removed path parameter, a changed
  `servers` entry.
- Write the false-positive cases too: a reordered `oneOf` list and a reordered `security` list are
  NOT breaking; a swapped `oneOf` variant is.
- Write the direction cases: on a response, a widened type and an added enum value are breaking; on
  a request they stay additive.
- Cover the gate in `tests/Feature/PackageSetupTest.php`: gate off (default) leaves every route
  reachable; gate on with a denying ability yields 404 on the docs route and on the try-it proxy;
  gate on with an allowing ability leaves them reachable; gate on with no ability defined denies.

#### Expected Output
- `tests/Unit/SpecDifferTest.php` extended with the cases above.
- `tests/Feature/PackageSetupTest.php` extended with the four gate cases.

#### Verification
- `vendor/bin/pest --filter="SpecDiffer|PackageSetup"`

#### Risk Notes
- Test yazarı Task 1'in uygulamasını okumadan, bulgunun tarif ettiği girdi şeklinden yola çıkmalı;
  aksi hâlde bağımsız göz kaybolur.

### Task 6: Doküman güncellemesi ✅ DONE
**Agent:** `docs` · **Model:** `sonnet-5` · **Effort:** medium · **Executor:** `claude`
**Depends-on:** Task 1, Task 2, Task 3, Task 4
**Scope-paths:** `README.md`, `docs/**`

> Agent/Model/Effort gerekçesi: yalnız düzyazı, mevcut README yapısına oturuyor — `base` tier /
> medium. Codex şeridi kapalı, Claude yazar.

#### Amaç
Değişen davranışın dokümana geçmesi; özellikle `sync --check` sonuçlarının artık farklı çıkması
tüketici CI'larını etkiliyor ve duyurulmadan bırakılamaz.

#### To-Do
- Update the `sync --check` section: list the change classes now detected that were previously
  silent, and warn that a consumer CI which was green may now fail — this is the fix, not a
  regression.
- Update the `llms.txt` and MCP export sections for resolved `$ref`s, spread `allOf` bodies and
  sanitised tool names.
- Update the try-it section: the application's own port is accepted automatically for the self
  host, foreign hosts still bound by `try_it.allowed_ports`.
- Add the `gate.enabled` row to the configuration table and cross-link it to the gate section Task
  4 adds; state that it is off by default.
- Add a `CHANGELOG.md` entry describing all of the above under an Unreleased heading (the repo has
  none yet — create it with that single entry, do not backfill history).

#### Expected Output
- `README.md` updated in the four places above.
- `CHANGELOG.md` with one Unreleased entry.

#### Verification
- Diff read: every configuration key named in the prose exists in `config/api-dock.php`, and every
  command shown resolves against `src/Console/`.

#### Risk Notes
- README'nin config tablosu "bunlar shipped edilen tüm anahtarlar" diyor; yeni anahtar eklenirken
  tablonun eksiksizliği korunmalı.

## Geri Alma Stratejisi

- **Task 1 / Task 2** — saf davranış değişikliği, migration yok; commit geri alınırsa eski
  sınıflandırma geri gelir. Tüketici tarafında geri alma gerekçesi yalnız "CI beklenmedik biçimde
  kırıldı" olabilir; o durumda doğru cevap `--check`'i geçici olarak kaldırmak, düzeltmeyi geri
  almak değil.
- **Task 3** — tek koşullu port kabulü; geri alındığında yalnız standart olmayan portta çalışan
  kurulumlar eski hatayı görür.
- **Task 4** — `gate.enabled` varsayılan `false` olduğu için geri alma gerektirmez; kapıyı açmış bir
  kurulum config'i `false` yaparak anında eski davranışa döner.
- Hiçbir görev veri yazmıyor, migration içermiyor, dosya silmiyor.

## Doğrulama Stratejisi

- **Test:** her görevin kendi dar filtresi; plan sonunda tek konsolide `composer test && npm run test`.
- **Manuel:** `php artisan serve` ile ayağa kaldırılmış bir uygulamada try-it'in kendi API'sine
  ulaşabildiği; `gate.enabled` açıkken panelin anonim ziyaretçiye 404 döndüğü.
- **Regresyon:** Task 5'in yazdığı sınıf başına vaka; ayrıca `api-dock:sync --check`'in değişmemiş
  bir dokümanda hâlâ sıfır change bildirdiği (sahte pozitif kontrolü).
