# Try-It Kimlik Bilgisi Kalıcılığı

**Date:** 2026-09-05
**Risk level:** high
**Verify:** `composer test`
**Planning-model:** `opus-5`
**Planning-effort:** high
**Ceremony-ack:** Thin-slice boyut (3 task, 4 dosya) — ayrı `reviewer`/`verifier` task açılmıyor: kırmızı çizgi (secret) diff'i zaten plan sonunda otomatik Codex review + Claude triyajından geçiyor (core §4); Executor tüm task'larda `claude` olduğu için yazan taraf zaten ayrı bir "codex authored" incelemeyi tetiklemiyor.

## Task Özeti

| # | Task | Agent | Model | Effort | Executor | Depends-on |
|---|---|---|---|---|---|---|
| 1 | AuthProfileStore kalıcı depolama modu | `security` | `opus-5` | high | `claude` | — |
| 2 | README / config dokümantasyonu | `docs` | `sonnet-5` | medium | `claude` | Task 1 |
| 3 | Kalıcılık + izolasyon regresyon testleri | `security` | `opus-5` | high | `claude` | Task 1 |

## Amaç

`AuthProfileStore` şu an try-it kimlik bilgilerini PHP session payload'ı içinde tutuyor — profil ömrü login session'ın ömrüyle birebir aynı (`AuthProfileStore.php:13-19`, bilinçli tasarım: "held for exactly as long as the session that created them"). berth-cms'te session ~1-2 saatte düşünce, nadiren değişen API token/anahtarlar sürekli yeniden giriliyor, kullanımı yoruyor. Amaç: nadiren değişen kimlik bilgilerinin session ömründen bağımsız, daha uzun TTL'li (varsayılan 30 gün) kalıcı bir depoda tutulabilmesi — opt-in, varsayılan davranış (session-scoped) değişmeden.

## Kapsam

- Etkilenen katman: sadece paket içi PHP (`src/Support/AuthProfileStore.php`) + config şablonu + dokümantasyon + test.
- Etkilenen dosyalar: `src/Support/AuthProfileStore.php`, `config/api-dock.php`, `README.md`, `tests/Feature/AuthProfileStoreTest.php`.
- **Kapsam dışı:** `AuthProfileController.php` ve `ProxyController.php` — ikisi de `AuthProfileStore`'un genel arayüzünü (`put`/`all`/`find`/`forget`/`flush`/`revealCredentialForOutboundRequest`) çağırıyor, arayüz değişmiyor, bu iki dosyaya dokunulmuyor. Frontend (`resources/js/**`) — profil listesi API sözleşmesi (`id`/`label`/`credential_hint`/…) aynı kalıyor, panel tarafında değişiklik yok. Yeni bir DB tablosu/migration açılmıyor (cache-backed).

## Alternatifler

- **DB tablosu (migration + `expires_at` kolonu):** cache flush'tan etkilenmiyor, ama yeni migration + schema ceremony gerektiriyor, mevcut basit session-store deseninden kopuyor. Bu iş zaten "secret" kırmızı çizgisiyle mandatory plan'a düştüğü için üstüne bir de migration ceremony'si eklemek blast radius'u büyütüyor.
- **Global `SESSION_LIFETIME` uzatma:** kullanıcının kendi ifadesiyle istenmiyor — login session'ın kendisi hâlâ kısa kalsın isteniyor, sadece try-it kimlik bilgileri session'dan bağımsız yaşasın isteniyor.
- **Seçilen: Laravel `Cache` facade, kullanıcı ID'sine keylenmiş, config'ten TTL'li.** Var olan platform özelliğini (Cache) kullanıyor, yeni bağımlılık/migration yok, `AuthProfileStore`'un genel arayüzü ve şifreleme modeli değişmeden kalıyor — sadece depolama backend'i session'dan cache'e taşınıyor (opt-in).

## Tasks

### Task 1: AuthProfileStore kalıcı depolama modu ✅ DONE

**Agent:** `security` · **Model:** `opus-5` · **Effort:** high · **Executor:** `claude`

> Agent/Model/Effort gerekçesi: secret persistence guarantee'sini değiştiren kırmızı çizgi (core §2 "secret") — Codex şeridi kesinlikle yasak (plan-validate hard reject); thin-slice tavanı (Ceremony-ack) `xhigh`'ı engellediği için `high` tavanda tutuluyor, tasarım zaten bu planda netleşti (Alternatifler), task sadece uyguluyor.

#### Amaç
`AuthProfileStore`'a, mevcut session-scoped depolamanın yanına, config ile açılabilen kalıcı (cache-backed, kullanıcıya keylenmiş, TTL'li) bir ikinci depolama modu eklemek — varsayılan davranış (`session`) hiç değişmeden.

#### To-Do
- `config/api-dock.php` → `try_it` bloğuna yeni alt anahtar ekle:
  ```php
  'profile_persistence' => [
      // Off by default: preserves the existing session-scoped guarantee for every
      // consumer of this package. A consuming app opts in explicitly.
      'enabled' => false,
      // Minutes. Default 30 days — long enough that a rarely-rotated API token is
      // not re-entered every login, short enough that a stale credential does not
      // live forever.
      'ttl_minutes' => 60 * 24 * 30,
  ],
  ```
- `src/Support/AuthProfileStore.php`:
  - Inject `Illuminate\Contracts\Cache\Repository` (via `app('cache')->store()` or constructor-injected `CacheFactory`, follow the existing constructor-injection style already used for `Encrypter`).
  - Add a private `persistenceEnabled(): bool` reading `config('api-dock.try_it.profile_persistence.enabled', false)`, and `persistenceTtl(): int` reading `ttl_minutes` (same numeric-guard pattern as `maxProfiles()`).
  - Add a private `cacheKey(): ?string` — `null` when persistence is on but there is no authenticated user (`Auth::id()` is null, e.g. a guest whose only identity is the session); a guest has no stable identity to key persistent storage on, so it must fall back to session storage even with the flag on. Otherwise `'api-dock.try-it.profiles.'.$userId`.
  - `raw()`/`write()` branch on `persistenceEnabled() && cacheKey() !== null`: read/write through the injected cache repository (`get($key, [])` / `put($key, $profiles, now()->addMinutes($ttl))`) instead of `Session::get`/`Session::put`/`Session::forget`. Keep the exact same record shape (`id`/`label`/`base_url`/`server_variables`/`scheme`/`credential_header`/`credential_hint`/`credential`) — every other method (`put`, `all`, `find`, `forget`, `revealCredentialForOutboundRequest`, `withoutCredential`, `serverVariables`, `mask`) stays untouched, they only ever call `raw()`/`write()`.
  - `flush()` branches the same way: `Cache::forget($key)` in persistent mode, `Session::forget(...)` otherwise.
  - Update the class doc-comment (lines 12-30) to state the two modes and the trade-off explicitly: in persistent mode, logging out does **not** purge a stored credential — only `forget()`/`flush()` (an explicit delete) or TTL expiry does. State this as a deliberate, opt-in trade-off, not an oversight.

#### Expected Output
- `config/api-dock.php` carries the new `try_it.profile_persistence` block.
- `AuthProfileStore` supports both modes behind the existing public API, unchanged method signatures.

#### Verification
- `vendor/bin/pest --testsuite="Api Dock" --filter=AuthProfileStoreTest`

#### Risk Notes
- Güvenlik trade-off'u: persistent mod açıkken logout kimlik bilgisini silmiyor — bu doküman + config yorumunda açıkça yazılı, varsayılan kapalı.
- Cache sürücüsü `array`/`file`'sa ve worker'lar arası paylaşılmıyorsa TTL beklendiği gibi çalışmayabilir — README'de not düşülüyor (Task 2).
- Guest (kimliksiz) istek her zaman session moduna düşer — cross-user sızıntı riski yok.

### Task 2: README / config dokümantasyonu ✅ DONE

**Agent:** `docs` · **Model:** `sonnet-5` · **Effort:** medium · **Executor:** `claude`
**Depends-on:** Task 1

> Agent/Model/Effort gerekçesi: saf dokümantasyon, mekanik iş — ama Codex şeridi bu oturumda globalde kapalı (`execution: claude`, review-only), o yüzden `claude` yazıldı; şerit açılırsa bu task normalde Codex whitelist'ine girer.

#### Amaç
Yeni `profile_persistence` config anahtarını ve trade-off'unu README'de belgelemek.

#### To-Do
- `README.md` → "Try it, through your server" bölümüne kısa bir alt paragraf ekle: `api-dock.try_it.profile_persistence.enabled` ne yapar, varsayılanın `false` olduğu, açıldığında logout'un kimlik bilgisini SİLMEDİĞİ, `ttl_minutes` varsayılanı (30 gün), ve cache sürücüsünün worker'lar arası paylaşılan/kalıcı bir sürücü olması gerektiği (örn. `database`/`redis`, `array` çalışma-anı-only olduğu için bu özelliği anlamsızlaştırır).

#### Expected Output
- README'de yeni config anahtarı + trade-off + cache-driver notu.

#### Verification
- `grep -n "profile_persistence" README.md` (satırın gerçekten eklendiğini doğrula)

#### Risk Notes
- Yok.

### Task 3: Kalıcılık + izolasyon regresyon testleri ✅ DONE

**Agent:** `security` · **Model:** `opus-5` · **Effort:** high · **Executor:** `claude`
**Depends-on:** Task 1

> Agent/Model/Effort gerekçesi: secret/red-line domain'de ayrı test task zorunlu (Test Placement tablosu — "the regression test is a security boundary"); yazan task'tan farklı bir dispatch, aynı kodu yazan kendi testini yazmıyor. Thin-slice tavanı burada da `xhigh`'ı engellediği için `high`.

#### Amaç
Yeni kalıcı depolama modunun (a) session invalidation'dan gerçekten bağımsız yaşadığını, (b) TTL süresi dolunca gerçekten kaybolduğunu, (c) kullanıcılar arası sızdırmadığını, (d) flag kapalıyken eski davranışın bire bir aynı kaldığını doğrulamak.

#### To-Do
- `tests/Feature/AuthProfileStoreTest.php`'e ekle (mevcut dosyaya, ayrı dosya açma):
  - `profile_persistence.enabled=false` (varsayılan): mevcut testlerin hepsi değişmeden geçiyor — regresyon yok.
  - `profile_persistence.enabled=true` + authenticated user: bir profil ekle, `Session::flush()` (login session'ı düşür/invalidate et), store'u taze bir istekte tekrar oku — profil hâlâ duruyor.
  - TTL: `ttl_minutes` küçük bir değere set edilip `Carbon::setTestNow()` ile TTL'in ötesine ilerlet — profil artık dönmüyor (cache expiry).
  - İzolasyon: iki farklı `Auth::id()` altında birer profil oluştur, her user sadece kendi profilini görüyor (cache key user'a göre ayrışıyor).
  - Guest (authenticated user yok) + `profile_persistence.enabled=true`: session moduna düştüğünü doğrula (persistent depoda hiçbir şey yazılmadığını, session'da yazıldığını kontrol et).
  - `forget()`/`flush()` persistent modda da çalışıyor (cache'ten siliniyor).

#### Expected Output
- `tests/Feature/AuthProfileStoreTest.php` yukarıdaki 6 senaryoyu kapsıyor.

#### Verification
- `vendor/bin/pest --testsuite="Api Dock" --filter=AuthProfileStoreTest`

#### Risk Notes
- Bu test dosyası kırmızı çizgi (secret) garantisinin regresyon sınırı — bundan sonra flag'i kim dokunursa dokunsun bu testler kırılmadan davranış bozulamaz.

## Geri Alma Stratejisi

Config flag varsayılan `false` — berth-cms açıkça `true` yapmadıkça hiçbir davranış değişmiyor. Geri almak için tek satır: `profile_persistence.enabled` → `false` (veya satırı silmek, default zaten `false`). Yeni migration/schema olmadığı için DB rollback'i yok.

## Doğrulama Stratejisi

- Test: Task 3'teki 6 senaryo + mevcut regresyon paketi (`composer test`).
- Manuel: berth-cms'te `config/api-dock.php` yayınla, `profile_persistence.enabled=true` yap, bir profil ekle, login session'ı düşür (logout/re-login), profilin panelde hâlâ göründüğünü doğrula.
- Regresyon: flag kapalıyken (paketin varsayılan hâli) mevcut session-scoped davranış bire bir aynı — `composer test` yeşil.
