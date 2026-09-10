# Try-it Codex Güvenlik Bulguları — Geçmişte Secret, Subdomain Muafiyeti, Proxy Çözümlemesi

**Date:** 2026-09-10
**Risk level:** high
**Verify:** `composer test && npx vitest run && npm run build`
**Planning-model:** `fable-5-1`
**Planning-effort:** high

**Ceremony-ack:** Dört task, iki bağımsız zincir — ince dilim ölçüsünde, ama üçü de Codex güvenlik raporunun (`CODEX_SECURITY_REPORT.md`) kırmızı çizgi bulgusu: tarayıcıda kalan secret ve SSRF güven sınırı. Ayrı `reviewer` task'ı YOK: diff'i Claude yazıyor, bağımsız göz plan sonunda otomatik koşan Codex cross-review (core §4). Executor şeridi bu oturumda KAPALI, her task `claude`.

## Task Özeti

| # | Task | Agent | Model | Effort | Executor | Depends-on |
|---|---|---|---|---|---|---|
| 1 | Try-it depolamasını kimliğe bağla | `frontend` | `opus-5` | high | `claude` | — |
| 2 | Form girdisi ve yanıt kopyasından secret'ı ayıkla | `frontend` | `opus-5` | xhigh | `claude` | Task 1 |
| 3 | Subdomain muafiyetini adres kümesine daralt | `security` | `opus-5` | xhigh | `claude` | — |
| 4 | Ortam proxy'sini kapat, `try_it.proxy` ile açık seçim | `security` | `opus-5` | high | `claude` | Task 3 |

## Amaç

Codex raporu try-it panelinde üç açık buldu:

1. **Orta — geçmişte secret.** `tryItSession.ts` her form girdisini (login gövdesindeki parola, elle yazılan API key parametresi dahil) `localStorage`'a, `tryItResponses.ts` başarılı yanıtın tam gövdesini (`access_token` dahil) `sessionStorage`'a yazıyor. İkisi de kullanıcı/oturum sınırı taşımıyor: aynı tarayıcıda sonraki hesap önceki hesabın secret'ını geri yükleyebiliyor.
2. **Orta — subdomain muafiyeti.** `OutboundRequestGuard::matchSelfHost` `app.url` host'unun her alt alan adını "ben" sayıyor; "ben" olan hedef allowlist'i VE `isDeniedAddress` kapısını atlıyor. `internal.example.com` gibi aynı alan altındaki bir iç servis veya delege edilmiş bir alt DNS, try-it üzerinden iç ağa ulaşabiliyor.
3. **Düşük — proxy çözümlemesi.** `send()` doğrulanan adresleri `CURLOPT_RESOLVE` ile pinliyor ama ortam proxy'sini (`HTTP_PROXY`/`HTTPS_PROXY`, `socks5h://`) kapatmıyor. Uzakta çözümleyen bir proxy hostname'i yeniden çözer; pin boşa düşer.

Kullanıcı kararı (AskUserQuestion, 2026-09-10): **form girdisi ve yanıt geçmişi sayfa yenilemede KAYBOLMAYACAK.** Depolama kalır; içindeki secret kalmaz ve depolama kimliğe bağlanır.

## Kapsam

- Etkilenen katmanlar: try-it panel depolaması (TS/Vue), blade mount verisi, try-it proxy güvenlik sınırı (PHP), paket config'i, testler
- Etkilenen dosyalar: `resources/js/lib/tryItSession.ts`, `resources/js/lib/tryItResponses.ts`, `resources/js/lib/tryItToken.ts`, `resources/js/components/TryItPanel.vue`, `resources/js/main.ts`, `resources/js/App.vue`, `resources/views/docs.blade.php`, `resources/js/__tests__/tryItSession.spec.ts`, `resources/js/__tests__/TryItPanel.spec.ts`, `src/Support/OutboundRequestGuard.php`, `config/api-dock.php`, `tests/Feature/ProxySecurityTest.php`, `README.md` (config notu), `resources/dist/*` (rebuild)
- Kapsam dışı: `allowed_hosts` eşleşme mantığı · kimlik bilgisi profillerinin saklanması (`AuthProfileStore`, zaten sunucu tarafı ve şifreli) · `ApiDockAccess` gate'i · captured-token → profile akışı (token yine sunucuya gider, tarayıcıda kalmaz; bu plan yalnızca depolanan kopyayı maskeler) · `CODEX_SECURITY_REPORT.md` dosyasının repo'da kalıp kalmayacağı (kullanıcı kararı, plan dışı)

## Alternatifler

**Bulgu 1 — geçmiş**
1. *Her şey bellekte* — reddedildi: kullanıcı yenilemede kaybı açıkça istemiyor.
2. *Config ile varsayılan kapalı* — reddedildi: aynı riski taşıyıp kullanıcıya "aç" dedirtir; secret yine yazılır.
3. **Seçilen: sınıflandır + maskele + kimliğe bağla.** Form girdisinde secret sınıfına giren alan hiç yazılmaz (parametre `in: header` ve security-scheme adıyla eşleşen, şema `format: password` / `writeOnly: true`, gövde JSON'unda secret-benzeri anahtar). Yanıt kopyası yazılmadan önce `tryItToken.ts`'in zaten tanıdığı token alanları maskelenir (`extractToken` ile aynı anahtar listesi; ekranda gösterilen canlı yanıt değil, yalnızca depolanan kopya). Her iki depo tek bir `identity` damgası taşır (sunucunun verdiği HMAC, blade'den `data-identity`); damga uyuşmazsa eski kayıt okunmadan silinir.

**Bulgu 2 — subdomain**
1. *Yalnız exact host "ben", subdomain için `.alan.com` baş-nokta opt-in* — reddedildi: mevcut tenant kurulumu (`{tenant}.congress-app.test`) sıfır config ile çalışıyor; bunu bir config satırına bağlamak sessiz bir kırılma (try-it tenant'ta 403 verir, kimse neden bilmez).
2. **Seçilen: subdomain "ben" sayılır ama muafiyet adres kümesiyle sınırlanır.** Exact eşleşen host (`app.url` veya `self_hosts` girdisi) bugünkü tam muafiyeti korur. Subdomain eşleşmesi allowlist'i yine atlar, ama `isDeniedAddress` muafiyetini yalnızca çözdüğü HER adres exact host'un çözdüğü adres kümesinin içindeyse alır; değilse normal adres-sınıfı kapısından geçer. Tenant subdomain'i aynı sunucuya baktığı için hiçbir kurulum değişmez; `internal.example.com` gibi başka bir iç adrese bakan alt alan adı reddedilir. Aynı sunucuda `localhost:8000` senaryosu: `app.url` host'u da 127.0.0.1'e çözdüğünden geçer.

**Bulgu 3 — proxy**
1. *Dokümante et, kodu bırak* — reddedildi: pin bir güvenlik garantisi olarak sunuluyor; garantinin ortama bağlı olması kabul edilemez.
2. **Seçilen: proxy varsayılan KAPALI (`CURLOPT_PROXY => ''` + Guzzle `proxy => ''`), `try_it.proxy` config'i ile açıkça bir proxy adresi verilebilir.** Böylece pin her ortamda tutar; egress proxy zorunlu kurulum bunu bilerek yazar. `socks5h`/`https` uzaktan çözümleyen proxy verildiğinde guard bunu logla değil kodla söyler: uzaktan çözümleyen şema (`socks5h`, `socks4a`) reddedilir — pin yalnızca lokal çözümlemeyle anlamlı.

## Tasks

### Task 1: Try-it depolamasını kimliğe bağla ✅ DONE
**Agent:** `frontend` · **Model:** `opus-5` · **Effort:** high · **Executor:** `claude`
**Oversize-ack:** dosya sayısı tek bir prop-threading zincirinin uzunluğu (blade → main.ts → App.vue → TryItPanel → iki store); bölmek attribute'u okuyan ama kullanmayan yarım ara durum üretir, her dosyaya değişiklik 3-10 satır.

> Agent/Model/Effort gerekçesi: blade → main.ts → App → TryItPanel → iki depolama modülü boyunca tek bir kimlik damgasının taşınması; çok dosyalı ama deseni net, `main`/`high`; şerit kapalı, `claude`.

#### Amaç
Bir hesabın try-it geçmişi aynı tarayıcıdaki başka bir hesaba görünmesin: her iki depo sunucunun verdiği kimlik damgasını taşısın, damga uyuşmazsa kayıt okunmadan silinsin.

#### To-Do
- `resources/views/docs.blade.php`: emit `data-identity="{{ ... }}"` — `substr(hash_hmac('sha256', (string) Auth::id(), (string) config('app.key')), 0, 16)` when a user is authenticated, `''` for a guest. Never the raw id; read `Auth::id()` per request the way `AuthProfileStore` does.
- `resources/js/main.ts` + `resources/js/App.vue`: read `dataset.identity` (default `''`) and pass it to `TryItPanel` as prop `identity: string`.
- `resources/js/lib/tryItSession.ts` and `resources/js/lib/tryItResponses.ts`: add `identity` to the stored envelope; export `bindIdentity(identity: string)` which sets the module's current identity and, if the stored envelope's identity differs (or is absent — an older envelope), removes the storage key and resets to defaults. Every later read/write is stamped with the bound identity. Guest `''` matches only guest.
- `resources/js/components/TryItPanel.vue`: call `bindIdentity(props.identity)` for both modules at setup, before the first restore.
- Tests: `resources/js/__tests__/tryItSession.spec.ts` — envelope written under identity `a` is removed when bound to `b`; envelope without `identity` is removed. New `resources/js/__tests__/tryItResponses.spec.ts` — same two cases for the response store.

#### Expected Output
- `data-identity` attribute in the blade mount, HMAC-derived.
- Both stores stamp and check `identity`; mismatch purges.
- Four new vitest cases green.

#### Verification
- `npx vitest run resources/js/__tests__/tryItSession.spec.ts resources/js/__tests__/tryItResponses.spec.ts`

#### Risk Notes
- Kimlik damgası `Auth::id()`'ye bağlı; guest'ler arası geçmiş bugünkü gibi paylaşılır (aşılacak auth sınırı yok).
- İlk yüklemede eski envelope silinir: kullanıcı bir kez geçmişini kaybeder, sonra kalıcı.

### Task 2: Form girdisi ve yanıt kopyasından secret'ı ayıkla ✅ DONE
**Agent:** `frontend` · **Model:** `opus-5` · **Effort:** xhigh · **Executor:** `claude`
**Depends-on:** Task 1
**Oversize-ack:** sınıflandırıcı (session), maskeleyici (token) ve tüketici (panel) aynı sözleşmeyi paylaşıyor; ayrı task'lara bölünürse ilk task'ın çıktısı ikinci gelene kadar hiçbir şeyi korumaz. Testler değişen üç modülün kendi spec dosyaları.

> Agent/Model/Effort gerekçesi: hangi alanın secret sayılacağı ve depolanan yanıt kopyasında neyin maskeleneceği kırmızı çizgi kararı, `xhigh`; Task 1 ile aynı dosyalara dokunur, sıralı; şerit kapalı, `claude`.

#### Amaç
Form girdisi ve yanıt geçmişi yenilemede kalmaya devam etsin ama depolanan kopyada hiçbir parola/token bulunmasın; ekrandaki canlı yanıt değişmesin.

#### To-Do
- `resources/js/lib/tryItToken.ts`: next to `extractToken`, add `redactTokens(body: string): string` — same `TOKEN_KEYS`, same depth walk (≤ 4); every matching string value becomes `"***"`; a body that is not JSON is returned unchanged. Add `isSecretKey(name: string): boolean` covering `TOKEN_KEYS` plus `password`, `passwd`, `secret`, `client_secret`, `api_key`, `apiKey`, `refresh_token`, `private_key` (case-insensitive).
- `resources/js/lib/tryItSession.ts`: export a pure `classifyInputs(inputs, parameters, securitySchemes)` used before persisting. A parameter is dropped when `in === 'header'`, when its schema has `format: 'password'` or `writeOnly: true`, or when its name equals an apiKey security scheme `name`. The body is stored as `''` (not partially redacted — a half body restores as a broken request) when it parses as JSON and any key at depth ≤ 4 satisfies `isSecretKey`.
- `resources/js/components/TryItPanel.vue`: route the `setOperationInputs` watcher through `classifyInputs` with the operation's parameters and `document.components?.securitySchemes`.
- `resources/js/lib/tryItResponses.ts`: in `setStoredResponse`, write `redactTokens(body)` and a headers copy with `set-cookie`, `authorization` and any header name containing `token` removed. The in-memory value handed back to the panel stays the live response.
- Mark the name-list ceiling with a `ponytail:` comment (an unschema'd password field is caught by name only).
- Tests: `resources/js/__tests__/tryItToken.spec.ts` — `redactTokens` masks nested `access_token`, leaves non-JSON alone; `resources/js/__tests__/TryItPanel.spec.ts` — a `format: password` body field and an `in: header` parameter never reach `localStorage`; a login body with `password` is stored as `''` while a search body is stored verbatim; a response with `access_token` is stored as `"***"` and the on-screen response still shows the real value.
- Rebuild: `npm run build` (dist is committed).

#### Expected Output
- `redactTokens` / `isSecretKey` in `tryItToken.ts`, `classifyInputs` in `tryItSession.ts`, both pure and exported.
- Stored request/response copies carry no secret; live UI unchanged.
- Five new vitest cases green; `resources/dist/api-dock.js|css` rebuilt.

#### Verification
- `npx vitest run resources/js/__tests__/tryItToken.spec.ts resources/js/__tests__/TryItPanel.spec.ts resources/js/__tests__/tryItSession.spec.ts`

#### Risk Notes
- Login gövdesi yenilemede geri gelmez (boş kaydedilir) — bulgunun kendisi; parola için yol kimlik bilgisi profili.
- `format: password` / `writeOnly` şemaya bağlı; şemasız alan yalnızca ad eşleşmesiyle yakalanır. Ad listesi kaçırırsa değer yazılır — bilinçli ceiling.

### Task 3: Subdomain muafiyetini adres kümesine daralt ✅ DONE
**Agent:** `security` · **Model:** `opus-5` · **Effort:** xhigh · **Executor:** `claude`

> Agent/Model/Effort gerekçesi: try-it proxy'nin dış ağ güven sınırı — SSRF, kırmızı çizgi; "hangi alt alan adı iç ağa ulaşabilir" her tüketici topolojisinde doğru olmak zorunda, `xhigh`; şerit kapalı, `claude`.

#### Amaç
Alt alan adı yalnızca uygulamanın kendisine (aynı adreslere) baktığında iç adres muafiyeti alsın; başka bir iç adrese bakan alt alan adı yabancı host gibi denetlensin.

#### To-Do
- `src/Support/OutboundRequestGuard.php` — `matchSelfHost()`: return the match KIND — `exact` (host equals an entry) or `descendant` (host ends with `.entry`) — plus the matched entry host, alongside `ports`; the one-direction rule and port carry-over stay unchanged.
- `inspect()` address gate: `exact` keeps today's behaviour (skip `isDeniedAddress`). For `descendant`, resolve the matched entry host once via `resolveHost` and grant the skip only when every resolved address of the target is contained in the entry's resolved address set; otherwise apply `isDeniedAddress` as for a foreign host. The allowlist skip stays for both kinds. Denial message names the host only, never addresses. An IP-literal entry has no descendants (already true; assert it).
- `config/api-dock.php` `self_hosts` comment: a subdomain of an entry is trusted only while it resolves to the same addresses as the entry; a subdomain pointing elsewhere is checked like a foreign host.
- Tests in `tests/Feature/ProxySecurityTest.php` with the injected resolver: (1) `app.url = https://example.com` → `203.0.113.10`; `internal.example.com` → `10.0.0.5` DENIED; (2) `tenant.example.com` → `203.0.113.10` ALLOWED without an `allowed_hosts` entry; (3) `app.url = http://localhost:8000` → `127.0.0.1`, `tenant.localhost:8000` → `127.0.0.1` ALLOWED; (4) a subdomain resolving to `203.0.113.10` AND `10.0.0.5` DENIED; (5) update the existing `reaches a tenant subdomain of the application host with no allowlist entry` case so the tenant resolves to the same address as `app.url` — read its resolver stub first, do not delete it.

#### Expected Output
- `matchSelfHost()` returns a match kind + entry; `inspect()` grants the private-address exemption to a descendant only inside the entry's own address set.
- Config comment updated; five Pest cases green.

#### Verification
- `vendor/bin/pest tests/Feature/ProxySecurityTest.php`

#### Risk Notes
- Alt alan adı uygulamayla aynı sunucuya değil de CDN/başka bir public IP'ye bakıyorsa artık adres-sınıfı kapısından geçer; public adrese yine ulaşır, yalnızca iç adres reddedilir. Kırılma yalnız "alt alan adım iç IP'ye bakıyor" kurulumunda — o da bulgunun ta kendisi.
- Ek DNS çözümlemesi yalnız `descendant` eşleşmesinde, istek başına bir kez.

### Task 4: Ortam proxy'sini kapat, `try_it.proxy` ile açık seçim ✅ DONE
**Agent:** `security` · **Model:** `opus-5` · **Effort:** high · **Executor:** `claude`
**Depends-on:** Task 3
**Oversize-ack:** altı dosyanın ikisi tek satırlık doküman aynası (config yorumu, README paragrafı), biri pint; kod değişikliği tek metodda (`send()`) ve tek test dosyasında.

> Agent/Model/Effort gerekçesi: aynı guard dosyası ve aynı test dosyası (Task 3'ten sonra sıralı); kural net — proxy kapalı, açık seçimde uzaktan çözen şema reddedilir — `high` yeter; kırmızı çizgi dosyası, şerit kapalı, `claude`.

#### Amaç
`CURLOPT_RESOLVE` pini her ortamda tutsun: ambient proxy asla seçilmesin, egress proxy zorunlu kurulum bunu config'de açıkça yazsın.

#### To-Do
- `src/Support/OutboundRequestGuard.php` `send()`: add `'proxy' => ''` to the Guzzle options AND `CURLOPT_PROXY => ''` to the curl option array (both, so neither Guzzle's env detection nor curl's own env lookup picks a proxy). When `config('api-dock.try_it.proxy')` is a non-empty string, pass it as `'proxy'` instead; `deny()` a remotely resolving scheme (`socks5h://`, `socks4a://`) and anything not `http://`, `https://`, `socks5://`, `socks4://`.
- `config/api-dock.php` `try_it`: add `'proxy' => null` with a comment: the default disables every ambient proxy for the try-it request because the guard pins the checked address and a remotely resolving proxy would resolve the name again; remote-resolving schemes are refused.
- `README.md`: one paragraph under the try-it config section mirroring the two new config comments (self_hosts subdomain rule from Task 3, proxy rule from this task). No new section.
- Tests in `tests/Feature/ProxySecurityTest.php` (every new test name contains the word `proxy` so the filter below selects them): (1) with `HTTPS_PROXY`/`HTTP_PROXY` set for the test process, the sent request carries `proxy === ''` and curl `CURLOPT_PROXY === ''` (assert via the fake HTTP client's recorded options); (2) `try_it.proxy = 'socks5h://127.0.0.1:1080'` is denied before any request is sent; (3) `try_it.proxy = 'http://proxy.test:3128'` is passed through as the proxy option.
- Run `vendor/bin/pint` on the changed PHP files once at the end.

#### Expected Output
- `send()` never uses an ambient proxy; explicit `try_it.proxy` honoured, remote-resolving schemes refused.
- Config + README document both rules; three Pest cases green.

#### Verification
- `vendor/bin/pest tests/Feature/ProxySecurityTest.php --filter=proxy`

#### Risk Notes
- Egress proxy zorunlu kurulumda try-it, `try_it.proxy` yazılana kadar dış host'a ulaşamaz (kendi host'u genelde proxy'siz). Config yorumu ve README bunu söylüyor.

## Geri Alma Stratejisi

- Task 1–2: yalnız frontend + blade attribute; geri alma = commit revert + `npm run build`. Eski envelope'lar kimlik damgası taşımadığı için ilk yüklemede zaten temizlenir; geri dönüşte kayıp yalnız o oturumun geçmişidir.
- Task 3–4: `try_it.proxy` config'i ile proxy davranışı; subdomain daraltması için config anahtarı YOK (bilerek — bir "eski davranışa dön" anahtarı SSRF'i geri açar). Geri alma = commit revert.

## Doğrulama Stratejisi

- Test: header `**Verify:**` (`composer test && npx vitest run && npm run build`), task başına dar filtreler yukarıda.
- Manuel: berth-cms'te login endpoint'ine parola gönder, sayfayı yenile — form gövdesi boş dönmeli, yanıt paneli `access_token` alanını `***` göstermeli (ekranda gönderim anında gerçek değer görünür); DevTools → Application → Local/Session Storage'da parola/token aranınca sonuç sıfır olmalı. Başka kullanıcıyla giriş → önceki geçmiş görünmemeli.
- Regresyon: tenant subdomain'i (`{tenant}.congress-app.test`) üzerinden try-it eskisi gibi çalışmalı.
