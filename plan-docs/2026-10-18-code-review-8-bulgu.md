# Code Review 8 Bulgu — Tenant Anahtarı, Profil Geçişi, Silinen Profil, JSON Gövdesi, Diff, Sunucu, MCP Şeması

**Date:** 2026-10-18
**Risk level:** high
**Verify:** `composer test && npx vitest run && npm run build`
**Planning-model:** `opus-5-5`
**Planning-effort:** high

**Ceremony-ack:** Yedi task. Üçü kırmızı çizgi: tenant izolasyonu (Bulgu 1), credential'ın yanlış sunucuya gitmesi (Bulgu 2), silinen credential'ın geri gelmesi (Bulgu 3). Ayrı `reviewer` task'ı YOK: executor şeridi KAPALI, her diff'i Claude yazıyor, bağımsız göz plan sonundaki Codex cross-review (core §4). Bulgu 7 ve 8 aynı fonksiyonda (`AiPanel.vue` → `buildMcpTool`), tek task.

## Task Özeti

| # | Task | Agent | Model | Effort | Executor | Depends-on |
|---|---|---|---|---|---|---|
| 1 | Sayısal tenant namespace'ini kabul et, geçersizde session'a düş | `security` | `opus-5-5` | xhigh | `claude` | — |
| 2 | Silinen profilin eşzamanlı istekle geri gelmesini engelle | `security` | `opus-5-5` | xhigh | `claude` | Task 1 |
| 3 | Profil seçimini ortak akışa taşı, hedef temizliği her yerden çalışsın | `frontend` | `opus-5-5` | xhigh | `claude` | — |
| 4 | Try-it JSON gövdesini metin olarak ilet | `frontend` | `sonnet-5-5` | high | `claude` | Task 3 |
| 5 | Diff motoru parametre style/explode değişikliğini yakalasın | `backend` | `sonnet-5-5` | high | `claude` | — |
| 6 | Path seviyesindeki servers tanımını çözümle | `frontend` | `sonnet-5-5` | high | `claude` | — |
| 7 | MCP şemasında iç içe $ref çöz, ad çakışmalarını benzersizleştir | `frontend` | `sonnet-5-5` | high | `claude` | — |

## Amaç

Code review'da doğrulanmış 8 hata:

1. **P1 — tenant izolasyonu.** `AuthProfileStore::tenantNamespace()` callback'in döndürdüğü integer değeri (`101`) sessizce yok sayıyor. Aynı guard + user id'ye sahip iki tenant aynı cache anahtarını paylaşıyor, tenant 202 tenant 101'in credential'ını kullanabiliyor.
2. **P1 — credential yanlış sunucuya.** Profil değişince önceki profilin `base_url`'ini temizleyen mantık yalnızca `TryItPanel.vue` içindeki watcher'da. Settings açıkken TryItPanel mount edilmemiş oluyor; Settings'ten A→B (adresi boş) geçişinde A'nın adresi kalıyor ve B'nin token'ı A'nın sunucusuna gidiyor.
3. **P2 — silinen profil geri geliyor.** Session modunda Laravel aynı session'ın isteklerini paralel çalıştırıyor. Silmeden önce başlamış bir try-it isteği bittiğinde eski session içeriğini yazıyor ve silinen profil geri geliyor. `mutate()` docblock'u "session dosya kilidi zaten var" diyor, bu yanlış.
4. **P2 — JSON gövdesi bozuluyor.** Panel gövdeyi `JSON.parse` edip nesne olarak gönderiyor; PHP `json_encode` ederken `{}` → `[]` oluyor, `"hello"` tırnaksız gidiyor.
5. **P2 — diff kaçırıyor.** `SpecDiffer::compareParameters` parametrenin `style`/`explode` alanlarını hiç karşılaştırmıyor; `explode: true → false` için `changes: []`, dolayısıyla `sync --check` kırılmayı geçiriyor.
6. **P2 — path servers.** `operationServers()` operation → document sırasıyla bakıyor; path item'ın `servers`'ı atlanıyor.
7. **P2 — MCP $ref.** `AiPanel.vue` yalnızca en üst seviye `$ref`'i çözüyor; `items.$ref` gibi iç içe referanslar kopyalanan şemada çözülmeden kalıyor.
8. **P2 — MCP ad çakışması.** `uniqueName()` önekli adın da dolu olup olmadığına bakmıyor; `path.id` + `path.query_id` + `query.id` olunca üçüncüsü ikincinin üzerine yazıyor.

## Kapsam

- Etkilenen katmanlar: credential store (PHP), route tanımı, try-it panel ve settings panel (Vue/TS), spec diff motoru (PHP), AI paneli (Vue), testler, `resources/dist/*` (rebuild)
- Etkilenen dosyalar: `src/Support/AuthProfileStore.php`, `routes/api-dock.php`, `config/api-dock.php` (yalnız yorum gerekirse), `src/Support/SpecDiffer.php`, `resources/js/components/TryItPanel.vue`, `resources/js/components/SettingsPanel.vue`, `resources/js/lib/tryItSession.ts` veya `resources/js/lib/tryItProfiles.ts`, `resources/js/lib/tryIt.ts`, `resources/js/components/AiPanel.vue`, ilgili `tests/Feature/*`, `tests/Unit/SpecDifferTest.php`, `resources/js/__tests__/*`
- Kapsam dışı: persistent mod TTL/kilit davranışı (bulgu dışı) · `allowReserved` ve diğer parametre serileştirme alanları (yalnız `style`/`explode` raporlandı) · AiPanel'in path-level parametre override'ı (PHP exporter'da var, panelde ayrı konu) · host uygulamanın kendi route'larının aynı session'la yarışması (Bulgu 3 → Alternatifler) · `CHANGELOG.md` girişi (release kapısı ayrıca yönetiyor)

## Alternatifler

**Bulgu 1 — geçersiz namespace**
1. *Exception fırlat* — reddedildi: try-it panelinin her isteği 500 olur, sebep görünmez.
2. **Seçilen: int ve boş olmayan string kabul edilir; callback tanımlı ama başka bir şey dönerse (null, '', array, bool) persistent mod o istek için kapanır, session'a düşülür.** Dosyanın kendi felsefesi bu: kimlik eksikse dar ömür seçilir (fail-closed). Namespace yok sayılıp ortak anahtara yazmak artık hiçbir yolda mümkün değil.

**Bulgu 3 — eşzamanlı session yazımı**
1. *Session modunda da cache kilidi* — reddedildi: yarışan istek profil dizisine hiç dokunmuyor, sadece session'ı kaydediyor; `mutate()` kilidi onu durdurmaz.
2. *Cache'te silinen id listesi (tombstone)* — reddedildi: session modunun "cache'e bağımlı değil" tasarımını bozuyor, ~40 satır yeni durum ekliyor.
3. **Seçilen: Laravel'in yerleşik session blocking'i (`Route::block()`) API Dock route grubuna uygulanıyor.** Böylece aynı session'ın API Dock istekleri sırayla çalışıyor, silme işlemi önceki try-it isteğinin kaydını bekliyor. Kilit süresi outbound `try_it.timeout`'tan uzun olmalı. Bilinen sınır: host uygulamanın kendi route'ları aynı session'la paralel çalışıp eski veriyi yazabilir. Bu Laravel'in session modelinin sınırı, docblock'ta açıkça yazılacak.

**Bulgu 4 — gövde**
- **Seçilen: panel `bodyText`'i (JSON olarak doğrulanmış ham metin) string olarak gönderiyor**, PHP `encodeBody()` string'i zaten aynen geçiriyor. Sunucuda değişiklik yok; nesne gönderen eski istemciler için `json_encode` dalı kalıyor.

## Tasks

### Task 1: Sayısal tenant namespace'ini kabul et, geçersizde session'a düş ✅ DONE
**Agent:** `security` · **Model:** `opus-5-5` · **Effort:** xhigh · **Executor:** `claude`

> Agent/Model/Effort gerekçesi: tenant izolasyonu garantisi (kırmızı çizgi) — `security`/`main`/xhigh; executor şeridi KAPALI, `claude`.

#### Amaç
`key_namespace` callback'i integer döndürünce de anahtara girsin; geçersiz bir değer hiçbir zaman ortak (namespace'siz) anahtara düşmesin.

#### To-Do
- `src/Support/AuthProfileStore.php` `tenantNamespace()`: accept a non-empty string OR an int (cast to string). Distinguish three outcomes: no resolver configured → no segment (today's behaviour); resolver returns a valid value → segment; resolver configured but returns anything else (null, '', bool, array, object, float) → INVALID.
- On INVALID, `userCacheKey()` returns null so the request falls back to session mode (fail-closed, same reasoning as a guest). Make sure `storageModeForCurrentRequest()` therefore reports `session` and `flush()` does not touch a shared key.
- Update the docblocks of `tenantNamespace()` / `userCacheKey()` to state the int acceptance and the fail-closed fallback.
- Check `config/api-dock.php` comment for `key_namespace`; if it says "string", mention int too.
- Tests in `tests/Feature/AuthProfileStoreTest.php`: (a) two tenants returning ints 101 and 202 with same guard + user id do NOT see each other's profile; (b) resolver returning null/'' with persistence on → profile lands in session, cache untouched, `storageModeForCurrentRequest()` = `session`.

#### Expected Output
- `tenantNamespace()` int-aware, invalid value → session fallback; two new tests green.

#### Verification
- `vendor/bin/pest tests/Feature/AuthProfileStoreTest.php`

#### Risk Notes
- Daha önce namespace callback'i int dönen ve persistence açık bir kurulumda kayıtlı profiller eski (namespace'siz) anahtarda kalır; yeni anahtar onları görmez. Kullanıcı profili yeniden kaydeder. Bu kasıtlı: eski anahtar zaten tenant'lar arası paylaşılıyordu. TTL dolunca kendiliğinden silinir.

### Task 2: Silinen profilin eşzamanlı istekle geri gelmesini engelle ✅ DONE
**Agent:** `security` · **Model:** `opus-5-5` · **Effort:** xhigh · **Executor:** `claude`
**Depends-on:** Task 1

> Agent/Model/Effort gerekçesi: silinen credential'ın geri gelmesi bir secret garantisi (kırmızı çizgi) — `security`/`main`/xhigh; Task 1 ile aynı dosyanın docblock'una dokunuyor, sırayla; şerit KAPALI, `claude`.

#### Amaç
Session modunda silme işlemi, daha önce başlamış bir API Dock isteğinin eski session'ı kaydetmesiyle geri alınamasın.

#### To-Do
- In `routes/api-dock.php`, apply Laravel session blocking to every route that starts the session (spec, try-it, profiles index/store/destroy, docs view) — `->block($lockSeconds, $waitSeconds)` per route, or one helper; Laravel has no group-level `block()`.
- `$lockSeconds` must exceed the outbound duration: derive it from `api-dock.try_it.timeout` + `connect_timeout` + a margin, not a magic constant. `$waitSeconds` at least as long, so a delete waits for an in-flight try-it rather than failing.
- Confirm (read framework source in `vendor/laravel/framework/src/Illuminate/Session/Middleware/StartSession.php`) how blocking behaves when the session cache store lacks lock support, and that it is a no-op without a started session; document the requirement in a route comment.
- Rewrite the false claim in `AuthProfileStore::mutate()` docblock ("a request already holds that session's own file lock"): session-mode safety comes from route blocking; the remaining limit is host-app routes sharing the session, which Laravel itself does not serialize.
- Test in `tests/Feature/` (e.g. `PackageSetupTest.php` or a new focused test): every `api-dock.*` route reports a non-null `locksFor()` and `waitsFor()`, and `locksFor()` > configured timeout.

#### Expected Output
- API Dock routes carry session blocking; docblock corrected; route test green.

#### Verification
- `vendor/bin/pest tests/Feature/PackageSetupTest.php tests/Feature/AuthProfileStoreTest.php`

#### Risk Notes
- Aynı session'dan gelen API Dock istekleri artık sırayla çalışır: yavaş bir upstream'e giden try-it sürerken profil silme isteği en fazla timeout süresi kadar bekler. Panel davranışı bozulmaz, sadece gecikir.
- Session cache store'u kilit desteklemiyorsa blocking çalışmayabilir; framework davranışı Task içinde doğrulanacak.

### Task 3: Profil seçimini ortak akışa taşı, hedef temizliği her yerden çalışsın ✅ DONE
**Agent:** `frontend` · **Model:** `opus-5-5` · **Effort:** xhigh · **Executor:** `claude`
**Oversize-ack:** gerçek değişiklik iki bileşen + bir lib modülünde; kalan dosyalar okunacak/yeşil kalacak testler. Bölmek, iki paneli yarım ara durumda (biri ortak fonksiyonda, biri watcher'da) bırakır.

> Agent/Model/Effort gerekçesi: credential'ın hangi sunucuya gittiği garantisi (kırmızı çizgi), iki bileşen + paylaşılan modül durumu — `main`/xhigh; şerit KAPALI, `claude`.

#### Amaç
Profil Settings'ten de TryItPanel'den de değiştirilse önceki profilin adresi ve sunucu değişkenleri aynı kurallarla bırakılsın, yeni profilin değerleri uygulansın.

#### To-Do
- Move `releaseProfileValues` + the apply half of the `watch(selectedProfile, …)` in `resources/js/components/TryItPanel.vue` (lines ~241-262, ~451-477) into one exported function in `resources/js/lib/tryItSession.ts` (it owns `selectedProfileId`, `plainBaseUrl`, `serverVariables`), e.g. `selectProfile(next: StoredTryItProfile | undefined, previous: StoredTryItProfile | undefined, variableDefaults: Record<string, string>)`. It sets the id, releases untouched previous values (same rules as today: only values still equal to what the previous profile supplied), then applies the next profile's `server_variables` and `base_url`.
- Released server variables fall back to a default: TryItPanel passes the selected server's defaults; SettingsPanel passes the defaults of the server it shows (it owns the server picker). Read `SettingsPanel.vue` to find it.
- `SettingsPanel.vue` `selectProfile(id)` resolves previous/next from `profiles` and calls the shared function instead of `setSelectedProfileId` alone.
- `TryItPanel.vue`: the profile picker goes through the same function. Keep a watcher only for the load case (list arrives after mount and the persisted id resolves to a profile) so a reload still applies the profile — make sure it does not double-apply or release on load.
- Check `discardMissingProfile` (deleted profile) — a profile deleted while selected must release its base_url too; route it through the same function if it does not.
- Tests: `resources/js/__tests__/SettingsPanel.spec.ts` — select A (base_url set), then B (base_url ''), from Settings with no TryItPanel mounted → `plainBaseUrl` is ''. A value the user typed over A's address survives the switch. Keep existing `TryItPanel.spec.ts` profile-switch tests green.

#### Expected Output
- One shared selection function; both panels call it; regression test for the Settings path.

#### Verification
- `npx vitest run resources/js/__tests__/SettingsPanel.spec.ts resources/js/__tests__/TryItPanel.spec.ts resources/js/__tests__/tryItSession.spec.ts`

#### Risk Notes
- Yükleme anında uygulama ile kullanıcı seçimi arasındaki fark korunmazsa sayfa yenilemede kullanıcının yazdığı adres profilinkiyle ezilebilir; mevcut TryItPanel testleri bunu kapsıyor, yeşil kalmalı.

### Task 4: Try-it JSON gövdesini metin olarak ilet ✅ DONE
**Agent:** `frontend` · **Model:** `sonnet-5-5` · **Effort:** high · **Executor:** `claude`
**Depends-on:** Task 3
**Oversize-ack:** kod değişikliği tek satır (`TryItPanel.vue`) + bir yorum (`ProxyController.php`); diğer yollar test dosyaları ve bağlam referansı.

> Agent/Model/Effort gerekçesi: tek satırlık payload değişikliği + test, net desen — `base`/high; Task 3 ile aynı dosya (`TryItPanel.vue`), sırayla; şerit KAPALI, `claude`.

#### Amaç
Editörde görülen JSON byte'ı byte'ına upstream'e gitsin: `{}` boş nesne, `"hello"` tırnaklı string olarak kalsın.

#### To-Do
- `resources/js/components/TryItPanel.vue` `sendRequest()`: send `payload.body = bodyText.value` (the raw editor text) instead of `bodyParse.value.value`. Keep the `bodyParse` validation gate as is — invalid JSON is still refused before sending.
- `src/Http/Controllers/ProxyController.php` `encodeBody()` already passes a string through verbatim; leave the array branch for other clients. Add a short comment that the panel sends text precisely so object/array shape survives.
- Tests: `resources/js/__tests__/TryItPanel.spec.ts` — body `{}` and `{"metadata":{}}` and `"hello"` go out as exactly that text in the fetch payload. `tests/Feature/ProxyControllerTest.php` — a string body `{"metadata":{}}` reaches the guard unchanged (use the existing fake-guard pattern in that file).

#### Expected Output
- Raw-text body in the payload; two tests (JS + PHP).

#### Verification
- `npx vitest run resources/js/__tests__/TryItPanel.spec.ts && vendor/bin/pest tests/Feature/ProxyControllerTest.php`

#### Risk Notes
- Global `TrimStrings` middleware gövdenin başındaki/sonundaki boşluğu siler; JSON anlamı değişmez.
- Metin `null` olduğunda artık `null` gövdesi gönderilir (eskiden gövde hiç gönderilmiyordu); editörde yazan budur.

### Task 5: Diff motoru parametre style/explode değişikliğini yakalasın ✅ DONE
**Agent:** `backend` · **Model:** `sonnet-5-5` · **Effort:** high · **Executor:** `claude`

> Agent/Model/Effort gerekçesi: tek fonksiyona eklenen net bir karşılaştırma + yeni slug — `base`/high; şerit KAPALI, `claude`.

#### Amaç
Parametrenin istek formatını değiştiren `style`/`explode` değişikliği breaking olarak raporlansın, `sync --check` geçirmesin.

#### To-Do
- `src/Support/SpecDiffer.php` `compareParameters()`: for a parameter present on both sides, compare the EFFECTIVE `style` and `explode` (OpenAPI defaults: `style` = `form` for query/cookie, `simple` for path/header; `explode` = true when style is `form`, else false). An implicit default vs the same value written explicitly is NOT a change.
- Report a difference as `breaking` with a new slug `parameter_serialization_changed`, pointer `<parameter>.style` / `<parameter>.explode`; add the slug to the class docblock list.
- Make sure the same field is not also reported as `cosmetic_change` by another walk (check `compareEmbeddedSchemas` scalar skip and any cosmetic pass over parameters).
- Tests in `tests/Unit/SpecDifferTest.php`: `explode: true → false` on a query parameter → one breaking change, `has_breaking` true; `style` omitted vs `style: form` on query → no change.

#### Expected Output
- New slug, effective-value comparison, two tests.

#### Verification
- `vendor/bin/pest tests/Unit/SpecDifferTest.php`

#### Risk Notes
- Daha önce sessiz geçen değişiklikler artık `sync --check`'i kırabilir; amaçlanan düzeltme bu.

### Task 6: Path seviyesindeki servers tanımını çözümle ✅ DONE
**Agent:** `frontend` · **Model:** `sonnet-5-5` · **Effort:** high · **Executor:** `claude`

> Agent/Model/Effort gerekçesi: tek fonksiyonda çözüm sırası düzeltmesi + test — `base`/high; şerit KAPALI, `claude`.

#### Amaç
Try-it, OpenAPI'nin tanımladığı operation → path → document sırasına göre sunucu seçsin.

#### To-Do
- `resources/js/lib/tryIt.ts` `operationServers()`: return `entry.operation.servers` if non-empty, else `document.paths?.[entry.path]?.servers` if non-empty, else `document.servers ?? []`.
- `OperationDetail.vue` uses the same function, so it inherits the fix; no change there.
- Test in `resources/js/__tests__/tryItServers.spec.ts`: path-level server wins over document; operation-level wins over path.

#### Expected Output
- Three-level resolution; tests green.

#### Verification
- `npx vitest run resources/js/__tests__/tryItServers.spec.ts`

#### Risk Notes
- Yok; yalnız path servers tanımlayan spec'lerin davranışı değişir.

### Task 7: MCP şemasında iç içe $ref çöz, ad çakışmalarını benzersizleştir ✅ DONE
**Agent:** `frontend` · **Model:** `sonnet-5-5` · **Effort:** high · **Executor:** `claude`

> Agent/Model/Effort gerekçesi: PHP `McpToolExporter`/`SpecReader` mantığının panelde birebir aynısı — desen hazır, `base`/high; iki bulgu aynı fonksiyonda; şerit KAPALI, `claude`.

#### Amaç
Panelden kopyalanan MCP tool şeması kendi başına kullanılabilsin (çözülmemiş `$ref` kalmasın) ve aynı adlı parametreler birbirini ezmesin.

#### To-Do
- `resources/js/components/AiPanel.vue` `buildMcpTool()`: replace the top-level-only `resolveSchema` with a deep resolver mirroring `src/Export/SpecReader.php` `resolveNode`/`resolveReference`: walk every object/array, inline local `#/…` refs, merge sibling keys over the target, a cycle or dangling ref becomes `{}`. Memoize per build so a shared schema graph does not blow up. Reuse `resolvePointer`/`isReference` from `@/lib/schema`.
- Replace `uniqueName()` with the `McpToolExporter::insertProperty()` rule: on collision try `${source}_${name}`, then `${source}_${name}_2`, `_3`… until free; append the origin note to `description` (`(query parameter "id")` / `(request body property "x")`) exactly like PHP.
- Tests in `resources/js/__tests__/AiPanel.spec.ts`: (a) body property `items: { $ref: '#/components/schemas/User' }` → copied schema contains no `$ref`; (b) a self-referencing component terminates; (c) `path.id` + `path.query_id` + `query.id` → three distinct properties, none overwritten.
- Optionally assert parity with `McpToolExporter` output on the same fixture if the spec file already has such a pattern; don't build a new harness.

#### Expected Output
- Deep resolution + collision loop in `AiPanel.vue`; three tests.

#### Verification
- `npx vitest run resources/js/__tests__/AiPanel.spec.ts`

#### Risk Notes
- Büyük ve çok paylaşımlı şemalarda inline edilmiş çıktı büyür; memo bunu PHP tarafındaki gibi sınırlar.

## Geri Alma Stratejisi

- Her task kendi dosya kümesinde; `git revert` task bazında yapılabilir.
- Task 1: geri alınırsa int namespace yine yok sayılır (açık geri döner). Geri alma yerine ileri düzeltme tercih edilir.
- Task 2: `->block()` sorun çıkarırsa (kilit desteklemeyen cache) route değişikliği tek başına geri alınabilir; docblock düzeltmesi kalır.

## Doğrulama Stratejisi

- Test: her task'ın dar komutu + plan sonunda `**Verify:**` (Pest suite + Vitest + build).
- Manuel: Settings'ten adresli profil → adressiz profil geçişi, sonra try-it gönder: hedef spec sunucusu olmalı. `{}` gövdeli POST upstream'de `{}` görünmeli.
- Regresyon: mevcut TryItPanel profil geçişi testleri, AuthProfileStore persistent/session testleri, SpecDiffer suite.
