# Try-it Proxy SSRF — Self-Host Kimliğinin Sunucu Tarafına Taşınması

**Date:** 2026-08-20
**Risk level:** high

**Ceremony-ack:** Dosya sayısı 4 olduğu için plan ince dilim ölçülüyor, ama iş kırmızı çizgi: try-it proxy'sinin dış ağ erişim sınırı yeniden tanımlanıyor. Derinlik dosya adedinde değil, "bu sunucu kim" sorusunun cevabında — bu yüzden Task 1 `xhigh` kalıyor. Ayrı bir `reviewer` task'ı YOK: diff'i Claude yazıyor, bağımsız göz plan sonunda otomatik koşan Codex cross-review (core §4, orchestration-doctrine).

## Task Özeti

| # | Task | Agent | Model | Effort | Executor | Depends-on |
|---|---|---|---|---|---|---|
| 1 | Self-host tespitini sunucu tarafına taşı | `security` | `opus-5` | xhigh | `claude` | — |
| 2 | SSRF regresyon testleri | `backend` | `opus-5` | high | `claude` | Task 1 |
| 3 | README ve config dokümantasyonu | `docs` | `sonnet-5` | medium | `codex` | Task 1 |

## Amaç

`src/Support/OutboundRequestGuard.php` içindeki `selfHosts()`, uygulamanın kendi host'unu belirlerken `request()->getHost()` değerine güveniyor. Bu değer HTTP `Host` header'ından gelir ve Laravel varsayılanında (`TrustHosts` kapalı) istemci tarafından serbestçe belirlenir. Saldırgan `Host: 169.254.169.254` gönderdiğinde hedef "kendi host'umuz" sayılıyor ve üç kapı birden atlanıyor: host allowlist'i (`assertHostIsAllowlisted`), iç servis host listesi (`assertHostIsNotInternal`) ve DNS çözümlemesi sonrası adres sınıfı kontrolü (`isDeniedAddress`). Sonuç: try-it açıkken kimliği doğrulanmamış bir istemci sunucuyu iç ağa proxy olarak kullanabiliyor — bulut metadata servisleri dahil.

Bu plan, "kendi host'um" tanımını tamamen sunucu tarafı yapılandırmaya taşır ve `isSelfHost()` içindeki iki yönlü alt alan adı eşleşmesini tek yöne indirir.

## Kapsam

- Etkilenen katmanlar: try-it proxy güvenlik sınırı (PHP), paket yapılandırması, dokümantasyon
- Etkilenen dosyalar: `src/Support/OutboundRequestGuard.php`, `config/api-dock.php`, `tests/Feature/ProxySecurityTest.php`, `README.md`
- Kapsam dışı: `try_it.allowed_hosts` eşleşme mantığı (leading-dot formu dahil) değişmiyor · port kapısı, gövde boyutu sınırları, throttle değişmiyor · `ApiDockAccess` middleware'ine auth eklenmiyor (ayrı bir karar) · devralınan diğer cross-review bulguları (`SpecDiffer.php`, `LlmsTxtExporter.php`, `tryIt.ts`) bu planın dışında

## Alternatifler

1. **`request()->getHost()` korunsun, ama `TrustHosts` aktifse güvenilsin** — reddedildi: paket, tüketen uygulamanın middleware yapılandırmasını güvenilir biçimde okuyamaz; güvenlik sınırı tüketicinin unutabileceği bir ayara bağlanmış olur (fail-open).
2. **`request()->getHost()` yalnızca `app.url` host'una eşit veya onun alt alan adıysa kabul edilsin** — reddedildi: doğrulama geçtiği anda değer zaten `isSelfHost()` tarafından `app.url` üzerinden eşleşiyor, yani hiçbir şey eklemiyor. Fazladan kod, sıfır kazanç.
3. **Seçilen: `request()->getHost()` tamamen kaldırılsın; kaynak `app.url` + yeni `try_it.self_hosts` olsun** — çok alan adlı kurulumlar operatörün açıkça yazdığı listeyle karşılanır, istemcinin söylediği hiçbir şey güven kazanmaz.

## Tasks

### Task 1: ✅ DONE — Self-host tespitini sunucu tarafına taşı
**Agent:** `security` · **Model:** `opus-5` · **Effort:** xhigh · **Executor:** `claude`

> Agent/Model/Effort gerekçesi: doğrudan SSRF güven sınırı — kırmızı çizgi, codex şeridi yasak; "kendi host'um" tanımının her tüketici kurulumunda ne anlama geldiği xhigh muhakeme istiyor.

#### Amaç
Try-it proxy'sinin dış erişim kapılarını atlayan self-host muafiyetini yalnızca sunucu tarafında tanımlı isimlerle sınırla; `Host` header'ı üzerinden gelen hiçbir değer güven kazanmasın.

#### To-Do
- In `src/Support/OutboundRequestGuard.php`, rewrite `selfHosts()`: drop the `request()->getHost()` branch (and the now-dead `Throwable` catch / `request()` import if unused). Build the list from `config('app.url')` (existing parse) plus a new `config('api-dock.try_it.self_hosts', [])` array.
- Normalise each `self_hosts` entry the way `assertHostIsAllowlisted()` normalises its entries: cast to string, `strtolower`, trim, strip a trailing dot; skip empty entries and entries that do not match the hostname pattern already used at line ~272 (`/^(?!-)[a-z0-9-]{1,63}(?<!-)(\.(?!-)[a-z0-9-]{1,63}(?<!-))*$/`). Malformed entries are skipped silently, never denied — this is operator config, not user input.
- In `isSelfHost()`, remove the reverse match `str_ends_with($self, '.'.$host)`. Keep only `$host === $self` and `str_ends_with($host, '.'.$self)` so the target may be a self host or a subdomain of one, never its parent domain.
- Update the docblocks of both methods to state the new source of truth and why the request host is not one.
- In `config/api-dock.php`, add `'self_hosts' => []` to the `try_it` block, directly under `allowed_hosts`, with an inline comment covering: this app's own extra domains only; `app.url` is already included; a subdomain of any entry counts; entries here skip the allowlist, the internal-host list and the address-class gate, so an entry is a deliberate trust decision.

#### Expected Output
- `OutboundRequestGuard::selfHosts()` returns only `app.url`-derived and `try_it.self_hosts`-derived names.
- `OutboundRequestGuard::isSelfHost()` matches one direction only.
- `config/api-dock.php` carries a documented `try_it.self_hosts` key defaulting to `[]`.

#### Verification
- `vendor/bin/pest --testsuite="Api Dock" --filter=ProxySecurity`

#### Risk Notes
- Kırıcı değişiklik: `app.url` ile servis edilen alan adı farklıysa (ters proxy arkasında yanlış `APP_URL`, çok alan adlı kurulum) try-it kendi sitesine erişimini kaybeder ve 422 döner. Çözüm operatörün `self_hosts` yazması — README bunu açıkça anlatmalı (Task 3).
- Mevcut Pest testleri istek host'unun `localhost` olmasına dolaylı olarak bağlı olabilir; `app.url` set etmeyen bir test kırılırsa doğru düzeltme testin `app.url`'ünü set etmektir, muafiyeti geri açmak değil.
- `self_hosts` girdileri üç kapıyı birden atladığı için bir IP literal'i ya da iç servis adı yazmak açığı bilinçli olarak geri açar; hostname deseni IP literal'lerini zaten eliyor.

### Task 2: ✅ DONE — SSRF regresyon testleri
**Agent:** `backend` · **Model:** `opus-5` · **Effort:** high · **Executor:** `claude`
**Depends-on:** Task 1

> Agent/Model/Effort gerekçesi: kırmızı çizgi regresyon testi güvenlik sınırının kendisidir, bu yüzden kodu yazan gözden ayrı bir task; codex şeridi kırmızı çizgide yasak.

#### Amaç
Host header'ı üzerinden self-host muafiyetinin sömürülemediğini ve meşru kullanımların bozulmadığını kalıcı testlerle sabitle.

#### To-Do
- Add cases to `tests/Feature/ProxySecurityTest.php`, next to the existing self-host block (around line 283).
- Spoofed Host header is not self: with `app.url` set to `https://congress-app.test` and `allowed_hosts` empty, send the proxy request with a `Host: 169.254.169.254` header (Pest: `withHeaders(['Host' => ...])` or the `SERVER_NAME` server var, whichever the request pipeline honours) targeting `http://169.254.169.254/latest/meta-data/`; assert 422 and `Http::assertNothingSent()`.
- Spoofed Host header does not widen the allowlist either: same spoof, target a foreign host that is a subdomain of the spoofed name; assert 422.
- Parent domain of a self host is denied: `app.url` = `https://docs.congress-app.test`, target `https://congress-app.test/api/things`; assert 422 (this is the removed reverse match).
- `try_it.self_hosts` works: set it to `['ikinci-alan.test']`, keep `allowed_hosts` empty, `resolvesTo(['127.0.0.1'])`, target `https://alt.ikinci-alan.test/api/things`; assert 200 and the request was sent.
- Malformed `self_hosts` entries are ignored, not trusted: set it to `['', '169.254.169.254', 'not a host']` and assert a target naming `169.254.169.254` is still 422.
- Confirm the four existing self-host cases at lines 283-340 still pass unchanged; if one only passed via the request host, fix the test by setting `app.url`, never by relaxing the guard.

#### Expected Output
- Six new Pest cases in `tests/Feature/ProxySecurityTest.php` covering Host spoofing, the one-directional subdomain rule, and `self_hosts` handling.

#### Verification
- `vendor/bin/pest --testsuite="Api Dock" --filter=ProxySecurity`

#### Risk Notes
- Laravel test istemcisi `Host` header'ını her zaman `getHost()`'a yansıtmayabilir; yansımıyorsa `server` değişkeniyle (`SERVER_NAME`/`HTTP_HOST`) kurulmalı. Test sahte host'u gerçekten kuramıyorsa bu bir "sorun yok" kanıtı değildir — testin kurulumu düzeltilmeli.

### Task 3: ✅ DONE — README ve config dokümantasyonu
**Agent:** `docs` · **Model:** `sonnet-5` · **Effort:** medium · **Executor:** `codex`
**Depends-on:** Task 1

> Agent/Model/Effort gerekçesi: yalnızca prose, kod dokunuşu yok, desen net — codex şeridi (Claude Max kotası yanmaz); dönen patch Claude triyajından geçer.

#### Amaç
Yeni `try_it.self_hosts` anahtarını ve `APP_URL` bağımlılığını README'de belgele, yükseltme yapan operatörün 422 ile karşılaştığında ne yapacağını bilmesini sağla.

#### To-Do
- In `README.md`, document `try_it.self_hosts` in the try-it configuration section that already covers `allowed_hosts` (around lines 90 and 354 — read both before editing).
- State the operator-facing rule: the app's own host comes from `APP_URL`; a subdomain of it is covered automatically; any OTHER domain this app answers on must be listed in `self_hosts`.
- Add an upgrade note: the proxy no longer trusts the incoming `Host` header, so a deployment whose `APP_URL` does not match the domain it is served on will now get a 422 on its own API until `APP_URL` is corrected or the domain is added to `self_hosts`.
- State plainly that a `self_hosts` entry bypasses the allowlist, the internal-host list and the private-address check, so it is only for domains of this application.

#### Expected Output
- `README.md` documents `self_hosts` alongside `allowed_hosts` and carries the upgrade note.

#### Verification
- `grep -n "self_hosts" README.md config/api-dock.php` shows the key documented in both files and no other config key referenced in the new prose is absent from `config/api-dock.php`.

#### Risk Notes
- Dokümantasyon `self_hosts`'u allowlist'in bir alternatifi gibi anlatırsa operatör yabancı host'ları oraya yazar ve açığı geri açar; metin "yalnızca bu uygulamanın kendi alan adları" sınırını net kurmalı.

## Geri Alma Stratejisi

Kod değişikliği tek dosyada ve toplanabilir: `git revert` ya da `selfHosts()` ile `isSelfHost()`'un eski gövdesinin geri konması yeterli — şema, migration ya da kalıcı veri dokunuşu yok. `try_it.self_hosts` anahtarı geri alınırsa tüketen uygulamada yayımlanmış config dosyasında artık kullanılmayan bir anahtar kalır, zararsızdır.

Yükseltmeden sonra bir kurulum kendi API'sine 422 alıyorsa doğru müdahale `APP_URL`'ü düzeltmek ya da alan adını `self_hosts`'a eklemektir; muafiyeti geri açmak değil. Tüketen uygulama yeni config'i almak için `php artisan vendor:publish --tag=api-dock-config` çalıştırmalı — `--force` ile DEĞİL, mevcut `allowed_hosts` içeriği silinir.

## Doğrulama Stratejisi

- Test: `vendor/bin/pest --testsuite="Api Dock" --filter=ProxySecurity` — hem yeni SSRF vakaları hem mevcut self-host vakaları
- Regresyon: `vendor/bin/pest --testsuite="Api Dock" --filter=Proxy` ile proxy yüzeyinin tamamı, plan sonunda bir kez
- Manuel: try-it açık bir kurulumda kendi API'sine istek atmak 200 dönmeli; `curl` ile sahte `Host` başlığı göndererek `169.254.169.254` hedeflemek 422 dönmeli
- Statik: `vendor/bin/pint --test src config tests` (yalnızca commit öncesi, değişen dosyalarda)
