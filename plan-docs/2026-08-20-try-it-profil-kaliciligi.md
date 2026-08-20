# Try-it profillerinin kalıcı ve global hâle getirilmesi

**Date:** 2026-08-20
**Risk level:** high

## Task Özeti

| # | Task | Agent | Model | Effort | Executor | Depends-on |
|---|---|---|---|---|---|---|
| 1 | Profil modeli: sunucu değişkenleri + yuvarlanan TTL | `security` | `opus-5` | xhigh | `claude` | — |
| 2 | Profil uçları: doğrulama ve yanıt sözleşmesi | `security` | `opus-5` | xhigh | `claude` | Task 1 |
| 3 | Kimlik yüzeyi regresyon testleri | `backend` | `opus-5` | high | `claude` | Task 2 |
| 4 | Paylaşılan try-it oturum deposu | `frontend` | `opus-5` | high | `codex` | Task 2 |
| 5 | Panel: kurum ve temel URL'in profilden gelmesi | `frontend` | `opus-5` | high | `codex` | Task 4 |
| 6 | Vitest spec'leri | `frontend` | `sonnet-5` | medium | `codex` | Task 5 |
| 7 | Konsolide doğrulama | `verifier` | `sonnet-5` | medium | `claude` | Task 6 |
| 8 | Kırmızı çizgi review'ı | `reviewer` | `opus-5` | xhigh | `claude` | Task 7 |

## Amaç

Try-it panelinde profil şu an üç ayrı yerden sızdırıyor ve kullanıcıya her operasyonda aynı bilgiyi
yeniden girdiriyor:

1. **`kurum` profilden gelmiyor.** `kurum` bir OpenAPI sunucu değişkeni; profil modeli yalnızca
   `base_url` taşıyor, `server_variables` alanı yok (`src/Support/AuthProfileStore.php:64-71`). Her
   operasyonda elle yazılıyor.
2. **Operasyon değişince sıfırlanıyor.** `resources/js/App.vue`, `OperationDetail`'i
   `:key="selectedOperation.key"` ile render ediyor; endpoint değişince panel komple remount oluyor
   ve `selectedProfileId` · `serverVariables` · `plainBaseUrl` uçuyor
   (`resources/js/components/TryItPanel.vue:65-69`). "Profil diğer API'lerde de kullanılsın" tam
   burada kırılıyor.
3. **Sayfa yenilenince sıfırlanıyor.** Profiller session id'ye bağlı cache'te ve TTL 300 saniye;
   üstelik TTL **yalnızca yazmada** tazeleniyor, okumada değil (`AuthProfileStore.php:81, 128`).
   Beş dakika yeni profil eklemezsen kova ölüyor. Seçili profilin id'si de hiçbir yerde saklanmıyor.

Bu plan üçünü de kapatır: profil `server_variables` taşır, seçim ve türetilmiş alanlar tarayıcıda
kalıcı ve tüm operasyonlar arasında ortak olur, kimlik bilgisi sunucuda kalmaya devam eder.

## Kapsam

- **Etkilenen katmanlar:** PHP profil deposu ve HTTP uçları · Vue try-it paneli · config · testler.
- **Etkilenen dosyalar:** `src/Support/AuthProfileStore.php` · `src/Http/Controllers/AuthProfileController.php` ·
  `config/api-dock.php` · `README.md` · `tests/Feature/AuthProfileStoreTest.php` ·
  `resources/js/lib/tryItSession.ts` (yeni) · `resources/js/components/TryItPanel.vue` ·
  `resources/js/lib/i18n.ts` · `resources/js/__tests__/TryItPanel.spec.ts` +
  `resources/js/__tests__/tryItSession.spec.ts` (yeni).
- **Kapsam dışı:** Kimlik bilgisinin tarayıcıya inmesi — kullanıcı kararı gereği token sunucuda
  şifreli kalır, `localStorage`'a **hiçbir gizli değer yazılmaz**. Kalıcı DB tablosu ve migration
  yok. `ProxyController`'ın giden istek sözleşmesi değişmiyor: `server_variables` zaten kabul
  ediliyor, profil yalnızca varsayılanı besliyor. Cross-review'da raporlanan ve bu plana ait
  olmayan bulgular (`SpecDiffer`, `LlmsTxtExporter`, `OutboundRequestGuard` self-host istisnası)
  burada ele alınmaz.

## Alternatifler

- **Sunucu değişkenlerini proxy'de profilden doldurmak.** `ProxyController` seçili profili okuyup
  eksik `server_variables` değerlerini kendisi tamamlayabilirdi. Reddedildi: giden istek sözleşmesi
  genişler, kullanıcı ekranda hangi değerin gideceğini göremez ve SSRF yüzeyine yeni bir dolaylı
  girdi eklenir. Profil yalnızca **istemci tarafında varsayılan** besler; ekranda ne yazıyorsa o
  gider.
- **Token'ı `localStorage`'a yazmak.** Kalıcılığı en basit yoldan çözerdi; kullanıcı tarafından
  reddedildi (XSS ile okunabilir hale gelir, paketin "gizli değer tarayıcıya inmez" garantisi
  düşer).
- **Profilleri kullanıcıya bağlı bir tabloya taşımak.** En kalıcı seçenek; reddedildi — paketi
  kuran her uygulamaya migration ve sahiplik/silme akışı yükler.

## Tasks

### Task 1: ✅ DONE — Profil modeli: sunucu değişkenleri + yuvarlanan TTL
**Agent:** `security` · **Model:** `opus-5` · **Effort:** xhigh · **Executor:** `claude`
**Oversize-ack:** Tek bir alan ve TTL kararının deposu, config varsayılanı ve dokümantasyonu; bölmek aynı kimlik-bilgisi bağlamını iki kez kurdurur ve hiçbir dosyayı kolaylaştırmaz.

> Agent/Model/Effort gerekçesi: kimlik bilgisi deposunun alan şeması ve **yaşam süresi garantisi** değişiyor — kırmızı çizgi (secret) → claude şeridi, `main` tier, xhigh effort.

#### Amaç
Profilin `server_variables` taşımasını sağlamak ve kimlik bilgisinin ömrünü yapılandırılabilir,
okumada tazelenen bir TTL'e bağlamak.

#### To-Do
- Add a `server_variables` field to the profile record in `src/Support/AuthProfileStore.php`: a `array<string, string>` map written by `put()` and returned by `withoutCredential()`. Trim keys and values, drop empty keys, and cap both the entry count and each value's length so a session bucket cannot be inflated through this field.
- Default `server_variables` to `[]` in `withoutCredential()` so a profile already sitting in the cache from before this change still decodes — the field is additive, never required.
- Make the TTL rolling: `all()`, `find()` and `revealCredentialForOutboundRequest()` must refresh the bucket's expiry when they find a live bucket, so an actively used profile does not die mid-session. Refresh by re-putting the same array with a fresh TTL; do not touch the stored values.
- Raise the `try_it.ttl` default in `config/api-dock.php` from 300 to a working-session length and update its inline comment to say the lifetime is idle-based, not absolute. Keep the existing non-positive / non-numeric fallback behaviour.
- Update the `try_it.ttl` row and the credential-scope paragraph in `README.md` to describe the rolling lifetime and the new default.
- The credential ciphertext handling, the `revealCredentialForOutboundRequest()` single-plaintext-path invariant and the masking rules stay exactly as they are. `server_variables` is NOT a secret field and must never be masked, but it must also never be allowed to carry a credential-shaped value into a log line — it is returned as plain data like `base_url`.

#### Expected Output
- A profile record that round-trips `server_variables`, and a credential lifetime that survives an active session.

#### Verification
- `vendor/bin/pest --testsuite="Api Dock" --filter=AuthProfileStore`

#### Risk Notes
- Okumada TTL tazelemek, kovayı **okuma trafiğiyle** sonsuza dek canlı tutabilir; bu kabul edilen davranıştır (kullanıcı oturum boyu kalıcılık istedi) ama mutlak bir üst sınır olmadığı için TTL değeri bilinçli seçilmeli.
- Cache'te bu değişiklikten önce yazılmış profiller `server_variables` taşımıyor; varsayılan `[]` yazılmazsa liste ucu tip hatası verir.

---

### Task 2: ✅ DONE — Profil uçları: doğrulama ve yanıt sözleşmesi
**Agent:** `security` · **Model:** `opus-5` · **Effort:** xhigh · **Executor:** `claude`
**Depends-on:** Task 1

> Agent/Model/Effort gerekçesi: kimlik bilgisi kabul eden uç noktanın doğrulama kuralları ve dışa dönük yanıt sözleşmesi değişiyor — kırmızı çizgi (secret + public API) → claude şeridi, xhigh effort.

#### Amaç
`server_variables` alanının uçtan uca doğrulanarak kabul edilmesi ve maskeli yanıtta geri dönmesi.

#### To-Do
- Extend the store validation in `src/Http/Controllers/AuthProfileController.php` with `server_variables` as an optional map of nullable strings, bounded in both key count and value length, mirroring the bounds Task 1 enforces in the store.
- Reject a `server_variables` key that is not a plain identifier (letters, digits, underscore, dash) rather than silently dropping it — an OpenAPI server variable name is always one of those, and a permissive key is a template-injection foothold.
- Keep `base_url` validation as it is and make sure both `base_url` and `server_variables` appear in the index and store responses through the existing masked view, never through a second serialization path.
- Do not widen anything else on this controller: the throttle, the session-key derivation and the `credential` handling stay untouched.

#### Expected Output
- Profil oluşturma ucu `server_variables` kabul eder, liste ucu onu maskeli görünümde döndürür.

#### Verification
- `vendor/bin/pest --testsuite="Api Dock" --filter=AuthProfile`

#### Risk Notes
- Anahtar doğrulaması gevşek bırakılırsa sunucu şablonuna kullanıcı denetimli bir yer tutucu adı girebilir; bu, hedef URL üretimini etkileyen dolaylı bir girdi olur.

---

### Task 3: ✅ DONE — Kimlik yüzeyi regresyon testleri
**Agent:** `backend` · **Model:** `opus-5` · **Effort:** high · **Executor:** `claude`
**Depends-on:** Task 2

> Agent/Model/Effort gerekçesi: kırmızı çizgi regresyon testi bir güvenlik sınırıdır ve yazarından bağımsız bir göz ister (core §4 test tablosu) → ayrı task, claude şeridi.

#### Amaç
Yeni alanın ve yeni TTL davranışının, kimlik bilgisi garantilerini bozmadığını kanıtlamak.

#### To-Do
- Extend `tests/Feature/AuthProfileStoreTest.php`: a profile round-trips `server_variables`; a profile stored before the field existed still lists cleanly with an empty map; oversized or malformed maps are rejected or trimmed exactly as specified.
- Assert the credential invariant explicitly: neither the index nor the store response contains the plaintext credential or its ciphertext, only `credential_hint`.
- Assert the rolling TTL: a bucket read after most of the TTL has elapsed stays alive, and a bucket left untouched past the TTL is gone. Drive the clock with Laravel's time helpers, never with a real wait.
- Assert the `max_profiles` cap still drops the oldest entry once `server_variables` is in the record.
- Do not weaken an existing assertion to accommodate the new field.

#### Expected Output
- Kimlik yüzeyinin yeni davranışını çevreleyen geçen bir regresyon takımı.

#### Verification
- `vendor/bin/pest --testsuite="Api Dock" --filter=AuthProfileStore`

#### Risk Notes
- Gerçek beklemeyle yazılan bir TTL testi süiti dakikalarca yavaşlatır; zaman yardımcıları zorunlu.

---

### Task 4: ✅ DONE — Paylaşılan try-it oturum deposu
**Agent:** `frontend` · **Model:** `opus-5` · **Effort:** high · **Executor:** `codex`
**Depends-on:** Task 2
**Oversize-ack:** Yeni modül ile onu tüketen tek komponent aynı turda yazılmalı; ayrı task'a bölünürse modül bir tur boyunca hiçbir yerden çağrılmayan ölü kod olarak kalır.

> Agent/Model/Effort gerekçesi: panelin state sahipliğini komponentten modül düzeyine taşıyan, remount ve kalıcılık davranışını değiştiren tasarım işi; gizli değer taşımadığı için kırmızı çizgiye değmiyor → codex şeridi, high effort.

#### Amaç
Seçili profilin ve ondan türeyen alanların operasyon değişiminde ve sayfa yenilemesinde yaşamasını
sağlamak.

#### To-Do
- Create `resources/js/lib/tryItSession.ts` following the shape of `resources/js/lib/theme.ts`: module-level refs for the selected profile id, the server-variable map and the plain base URL, plus setters that persist to `localStorage` under an `api-dock:try-it` key.
- Guard every storage access the way `theme.ts` does — a missing, unavailable or malformed `localStorage` must degrade to in-memory state, never throw.
- Persist ONLY non-secret values: profile id, server variables and base URL. A credential, a credential header value or a `credential_hint` must never be written to storage. State this as a comment on the module so the constraint survives the next edit.
- Read the stored state on module init and expose a reset. Drop a stored profile id that no longer appears in the profile list the server returns, so a dead id does not select nothing forever.
- Wire `resources/js/components/TryItPanel.vue` to the module instead of its own `selectedProfileId`, `serverVariables` and `plainBaseUrl` refs. Because the module lives outside the component, the existing `:key="selectedOperation.key"` remount in `App.vue` no longer clears it — do NOT remove that key, the per-operation body and path state must still reset.
- Keep every existing `data-testid`, the proxy payload shape and the guard messages exactly as they are.

#### Expected Output
- Operasyonlar arasında ortak, sayfa yenilemesini atlatan ve hiçbir gizli değer saklamayan bir try-it oturum durumu.

#### Verification
- `npx vitest run resources/js/__tests__/TryItPanel.spec.ts`

#### Risk Notes
- Sunucu şablonu kullanan ve kullanmayan operasyonlar aynı `serverVariables` haritasını paylaşacak; bir operasyonda tanımlı olmayan bir değişken diğerine sızmamalı — harita anahtar bazında uygulanmalı, komple ezilmemeli.
- Depoda kalan profil id'si sunucudaki kova öldüğünde geçersizleşir; listede yoksa temizlenmeli.

---

### Task 5: ✅ DONE — Panel: kurum ve temel URL'in profilden gelmesi
**Agent:** `frontend` · **Model:** `opus-5` · **Effort:** high · **Executor:** `codex`
**Depends-on:** Task 4

> Agent/Model/Effort gerekçesi: profil formu ile istek formu arasındaki veri akışını kuran, çok alanlı ve i18n'e dokunan arayüz işi; gizli değer taşımıyor → codex şeridi, high effort.

#### Amaç
Kullanıcının `kurum` ve temel URL'i profil oluştururken bir kez girmesi, sonra her operasyonda
profilden gelmesi.

#### To-Do
- Add server-variable inputs to the profile creation form in `resources/js/components/TryItPanel.vue`, built from the document's server variable definitions so a spec declaring `kurum` gets a `kurum` field with its description and enum, exactly as the request form renders it today.
- Send the collected map as `server_variables` when creating a profile, alongside the existing `base_url`.
- When a profile is selected, fill the request form's server variables and base URL from it — per key, so a variable the profile does not declare keeps its current value. The fields stay editable; the profile supplies a default, it does not lock the form.
- Show which values came from the profile so an edited field is visibly an override rather than a silent divergence.
- Replace the current one-shot `watch(selectedProfile, …)` that only assigns `plainBaseUrl` when it is still empty; that condition is why a second profile never changes the base URL.
- Route every new string through `t()` and add the keys to both the `en` and `tr` dictionaries in `resources/js/lib/i18n.ts`.

#### Expected Output
- Profil seçimi `kurum` ve temel URL'i dolduran, alanları düzenlenebilir bırakan bir panel.

#### Verification
- `npx vitest run resources/js/__tests__/TryItPanel.spec.ts resources/js/__tests__/tryItServers.spec.ts`

#### Risk Notes
- Profil değerlerini forma yazmak, kullanıcının elle girdiği bir değeri ezebilir; ezme kuralı bilinçli seçilmeli ve ekranda görünür olmalı.

---

### Task 6: ✅ DONE — Vitest spec'leri
**Agent:** `frontend` · **Model:** `sonnet-5` · **Effort:** medium · **Executor:** `codex`
**Depends-on:** Task 5

> Agent/Model/Effort gerekçesi: davranışı yeni kurulan modüle karşı sabitleyen mekanik spec yazımı, base tier yeter → codex şeridi.

#### Amaç
Kalıcılık ve profil-form akışının regresyona karşı sabitlenmesi.

#### To-Do
- Add `resources/js/__tests__/tryItSession.spec.ts`: state survives a simulated reload by reading back from a stubbed `localStorage`; a missing or malformed stored value degrades to defaults without throwing; a stored profile id absent from the profile list is dropped.
- Assert explicitly that nothing resembling a credential, a credential header value or a `credential_hint` is ever written to storage — this is the security assertion of the frontend half.
- Extend `resources/js/__tests__/TryItPanel.spec.ts`: selecting a profile fills the server variables and the base URL; a variable the profile does not declare is left alone; unmounting and remounting the panel keeps the selection.
- Do not weaken an existing assertion to make it pass. Report a real regression rather than editing the expectation.

#### Expected Output
- Kalıcılığı, gizli-değer yasağını ve profil-form akışını çevreleyen geçen spec'ler.

#### Verification
- `npx vitest run resources/js/__tests__/tryItSession.spec.ts resources/js/__tests__/TryItPanel.spec.ts`

#### Risk Notes
- `localStorage` stub'ı jsdom genelinde sızarsa diğer spec'ler etkilenir; her testte izole edilmeli.

---

### Task 7: ✅ DONE — Konsolide doğrulama
**Agent:** `verifier` · **Model:** `sonnet-5` · **Effort:** medium · **Executor:** `claude`
**Depends-on:** Task 6

> Agent/Model/Effort gerekçesi: konsolide test/build koşusu verifier'ın tekelinde; kod yazmadığı için codex şeridine girmez.

#### Amaç
Planın bıraktığı ağacın bütün olarak yeşil olduğunu kanıtlamak.

#### To-Do
- Run the frontend and backend suites plus the production build and the formatter, and report the real output.
- Do not fix what fails — report it with the failing output.

#### Expected Output
- vitest, Pest, build ve Pint sonuçlarını taşıyan bir rapor.

#### Verification
- `npx vitest run`
- `vendor/bin/pest --testsuite="Api Dock"`
- `npm run build`
- `vendor/bin/pint --test --dirty`

#### Risk Notes
- `vendor/bin/pest` filtresiz çalıştırılmaz; `--testsuite="Api Dock"` zorunlu.

---

### Task 8: ✅ DONE — Kırmızı çizgi review'ı
**Agent:** `reviewer` · **Model:** `opus-5` · **Effort:** xhigh · **Executor:** `claude`
**Depends-on:** Task 7
**Codex-skip:** Bu diff'in kırmızı çizgi kısmı Claude şeridinde yazıldı; bağımsız göz kuralı gereği inceleyen taraf yazan taraf olamaz.

> Agent/Model/Effort gerekçesi: plan riski high ve diff kimlik bilgisi yüzeyine dokunuyor — bağımsız göz ayrı bir task olarak yazılır (core §4, `orchestration-doctrine` review yolları).

#### Amaç
Kimlik bilgisi garantilerinin ve yeni girdi yüzeyinin bağımsız bir gözle denetlenmesi.

#### To-Do
- Review the diff of this plan against the credential guarantees: the plaintext still leaves the store through exactly one method; no response path serializes the ciphertext; nothing secret reaches `localStorage`.
- Review `server_variables` as an input surface: key validation, value bounds, and whether any of it can influence the outbound target beyond what the user sees on screen.
- Review the rolling TTL for an unbounded-lifetime or cache-growth path.
- Report findings with `file:line` and confidence. Change no code.

#### Expected Output
- `file:line` taşıyan, önceliklendirilmiş bir bulgu listesi.

#### Verification
- `vendor/bin/pest --testsuite="Api Dock" --filter=AuthProfile`

#### Risk Notes
- Bulgu çıkarsa düzeltme turu YALNIZ doğrulanmış bloklayan bulgular için açılır (core §4).

## Geri Alma Stratejisi

Plan hiçbir migration veya route değişikliği içermiyor; geri alma dosya düzeyinde.

- `config/api-dock.php` → `try_it.ttl` eski değerine (300) döndürülür. **Not:** paketi kuran
  uygulamalar config'i yayımladıysa (congress-app yayımlamış) yeni varsayılan onlara geçmez; TTL'i
  kendi `config/api-dock.php` dosyalarında güncellemeleri gerekir.
- `AuthProfileStore` → `server_variables` alanı additive; kaldırıldığında cache'teki kayıtlar
  `withoutCredential()` varsayılanı sayesinde okunmaya devam eder.
- `resources/js/lib/tryItSession.ts` silinir ve `TryItPanel.vue` kendi ref'lerine döner; tarayıcıda
  kalan `api-dock:try-it` anahtarı okunmaz hale gelir, gizli değer taşımadığı için temizlik şart
  değildir.

## Doğrulama Stratejisi

- **Test:** `vendor/bin/pest --testsuite="Api Dock" --filter=AuthProfile` (kimlik yüzeyi) ·
  `npx vitest run resources/js/__tests__/tryItSession.spec.ts resources/js/__tests__/TryItPanel.spec.ts`
  (kalıcılık ve profil akışı) · Task 7'de konsolide koşu.
- **Manuel:** congress-app'te bir profil oluştur, `kurum` ve temel URL'i profile yaz; başka bir
  operasyona geç ve seçimin korunduğunu gör; sayfayı yenile ve seçimin geldiğini gör; DevTools →
  Application → Local Storage'da `api-dock:try-it` anahtarında hiçbir token parçası olmadığını
  doğrula.
- **Regresyon:** Mevcut `ProxyControllerTest` ve `ProxySecurityTest` süiti değişmeden geçmeli —
  giden istek sözleşmesi bu planda değişmiyor.
