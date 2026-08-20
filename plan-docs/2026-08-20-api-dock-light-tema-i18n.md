# API Dock light-mode tasarımı ve TR/EN dil desteği

**Date:** 2026-08-20
**Risk level:** medium

## Task Özeti

| # | Task | Agent | Model | Effort | Executor | Depends-on |
|---|---|---|---|---|---|---|
| 1 | Tasarım token katmanı ve ikon/font bağımlılıkları | `frontend` | `opus-5` | medium | `codex` | — |
| 2 | i18n çalışma zamanı ve sözlükler | `frontend` | `opus-5` | medium | `codex` | — |
| 3 | Tema anahtarı ve sunucu tarafı varsayılanların bağlanması | `frontend` | `sonnet-5` | medium | `codex` | Task 1, Task 2 |
| 4 | Uygulama kabuğu ve endpoint sidebar'ı | `frontend` | `opus-5` | medium | `codex` | Task 1, Task 3 |
| 5 | JsonTree komponenti | `frontend` | `sonnet-5` | medium | `codex` | Task 1 |
| 6 | Operasyon detayı — tab kabuğu, başlık, parametreler, yanıtlar | `frontend` | `opus-5` | high | `codex` | Task 2, Task 4 |
| 7 | Try it, AI prompt ve Spec diff panelleri | `frontend` | `opus-5` | high | `codex` | Task 5, Task 6 |
| 8 | Mevcut vitest spec'lerinin yeni yüzeye uyarlanması | `frontend` | `sonnet-5` | medium | `codex` | Task 7 |
| 9 | Konsolide doğrulama | `verifier` | `sonnet-5` | medium | `claude` | Task 8 |

## Amaç

`API Dock.dc.html` Claude Design projesi, paketin dokümantasyon arayüzü için yeni bir görsel dil tanımlıyor: koyu sidebar + light içerik alanı, PrimeVue "Material" token seti, operasyon detayının sekmeli kabuğa taşınması, katlanabilir JSON ağacı ve PrimeIcons ikonografisi. Mevcut arayüz bunların hiçbirini taşımıyor — tek sütunlu, kendi ürettiği koyu paletle çalışan bir sayfa. Bu plan tasarımı uygular ve aynı geçişte arayüzü Türkçe/İngilizce çift dilli hale getirir; bugün her metin Vue şablonlarına İngilizce gömülü durumda.

## Kapsam

**Etkilenen katmanlar:** UI (Vue 3 SPA) · build (vite, npm bağımlılıkları) · paket config'i ve Blade giriş noktası.

**Etkilenen dosyalar:**
- `package.json`, `vite.config.ts`
- `resources/js/style.css`, `resources/js/main.ts`
- `resources/js/lib/i18n.ts` (yeni), `resources/js/lib/theme.ts` (yeni)
- `resources/js/App.vue`, `resources/js/components/*.vue`, `resources/js/components/JsonTree.vue` (yeni)
- `resources/views/docs.blade.php`, `config/api-dock.php`
- `resources/js/__tests__/*.spec.ts`

**Kararlar (kullanıcı onaylı):**
- İkonlar: `primeicons` npm paketi, font `resources/dist/` içine gömülür ve `vendor:publish --tag=api-dock-assets` ile yayınlanır (`hasAssets()` tüm dizini yayınlıyor, ek publish kaydı gerekmez).
- Dil desteği yalnızca Vue arayüzünde; PHP tarafı, artisan çıktıları ve export metinleri İngilizce kalır.
- Light varsayılan, `.dark` sınıfıyla koyu tema anahtarı korunur; tercih `localStorage`'da.

**Kararlar (plan yazarının varsayımı):**
- Marka yazı tipi `Instrument Sans` `@fontsource/instrument-sans` ile self-host edilir. Tasarımın kendi CSS'i Google Fonts `@import`'unu yalnızca taşınabilirlik için kullandığını not düşüyor; yayınlanan bir dokümantasyon sayfasının üçüncü taraf font isteği atması istenmez.
- Tasarımdaki `{kurum}` alt alan adı, `X-Api-Key`, `20 / minute` gibi değerler congress-app'e ait örnek verilerdir; paket bunları OpenAPI dokümanından ve `x-api-dock-features` uzantısından okur, sabitlemez.
- Tasarımda bulunmayan ama pakette var olan yüzeyler (request body sekmesi, sunucu değişkeni seçimi, kimlik profili yönetimi, yanıt başlıkları) kaldırılmaz; yeni token setine taşınır.

**Kapsam dışı:**
- PHP tarafında davranış değişikliği yok: route, controller, guard, export ve attribute'lara dokunulmaz.
- Yeni bir dil eklenmez (yalnız `tr` ve `en`), çeviri için harici i18n kütüphanesi kurulmaz.
- `plan-docs/2026-08-17-api-dock-paketi.md` planının tamamlanma kaydı bu planın konusu değildir.
- Spec içeriğinin (operasyon açıklamaları, örnekler) çevrilmesi kapsam dışı — çevrilen yalnızca arayüz metinleridir.

## Alternatifler

- **`vue-i18n` paketi vs. elle yazılan sözlük:** paket ~12 KB gzip ekliyor ve çoğul/tarih biçimlemesi gibi hiç kullanılmayacak yüzeyi getiriyor. Arayüzde ~90 dizge var, hepsi düz metin veya tek parametreli; `lib/i18n.ts` içindeki tip güvenli düz sözlük hem daha küçük hem de `keyof` üzerinden eksik anahtarı derleme zamanında yakalıyor.
- **Inline SVG ikon seti vs. PrimeIcons:** kullanıcı PrimeIcons'u seçti; tasarımla birebir uyum ve ileride yeni ikon eklerken sıfır maliyet karşılığında ~35 KB woff2 kabul edildi.
- **Tasarımın inline style'larını olduğu gibi taşımak vs. CSS sınıflarına çevirmek:** tasarım dosyası bir canvas çıktısı ve her kuralı element üzerinde taşıyor. Bunu Vue şablonlarına kopyalamak koyu tema anahtarını imkânsız kılardı (inline style `.dark` ile geçersiz kılınamaz). Tokenlar `style.css`'e, düzen kuralları sınıflara çevrilir.

## Tasks

### Task 1: ✅ DONE — Tasarım token katmanı ve ikon/font bağımlılıkları
**Agent:** `frontend` · **Model:** `opus-5` · **Effort:** medium · **Executor:** `codex`
**Oversize-ack:** Eski paletin sökülmesi ile yeni token setinin ve font/ikon bağımlılıklarının bağlanması atomik bir build-then-swap çifti; yarısı uygulanırsa ağaç kırık kalır (kaldırılmış değişkene referans veya çözümlenmeyen import).

> Agent/Model/Effort gerekçesi: `style.css` tüm arayüzün görsel temeli ve 1582 satırlık koyu palet baştan yazılıyor — mekanik değil, token eşlemesi kararı taşıyor; kırmızı çizgiye değmeyen izole frontend işi olduğu için codex şeridi.

#### Amaç
Tasarımın renk/tipografi token setini paketin stil katmanına taşımak, PrimeIcons ve Instrument Sans'ı build'e bağlamak, `style.css`'i light-mode temelli yeniden yazmak.

#### To-Do
- Add `primeicons` and `@fontsource/instrument-sans` to `package.json` dependencies and install them.
- Rewrite `resources/js/style.css` on the design's token model — the design system file is `colors_and_type.css` in the Claude Design project and its token block is the source of truth: radius scale, `--primary-*`, `--slate-*`, `--gray-*`, severity palettes, the light-mode semantic block on `:root`, the `.dark` override block, and the `.ds-*` convenience classes.
- Drop the design file's Google Fonts `@import`; the face arrives through the fontsource package instead.
- Keep the existing `--color-border` alias — Scramble-authored docblocks reach for it in inline styles.
- Set `html { font-size: 0.875rem }`, body font `var(--font-sans)`, body background `var(--content-bg)`, and `color-scheme: light dark`.
- Replace every rule that referenced the retired dark palette (`--ink`, `--paper*`, `--signal*`, `--line*`) with the new semantic tokens; no rule may still reference a removed variable.
- In `resources/js/main.ts` import `primeicons/primeicons.css` and `@fontsource/instrument-sans` before `./style.css`.
- In `vite.config.ts` keep the `api-dock.css` / `api-dock.js` output names and confirm woff2/ttf assets land flat in the dist directory with the emitted CSS referencing them relatively.

#### Expected Output
- A rewritten stylesheet carrying the design's token model, light by default with a working `.dark` block.
- A built CSS bundle containing `--primary-500`, the PrimeIcons `@font-face` and the Instrument Sans faces, with the font files emitted alongside it.

#### Verification
- `npm run build && node -e "const c=require('fs').readFileSync('resources/dist/api-dock.css','utf8'); if(!/--primary-500/.test(c)) throw new Error('tokens missing'); if(!/pi-chevron-down/.test(c)) throw new Error('primeicons missing'); if(/--paper-raised|--signal-soft/.test(c)) throw new Error('retired dark palette still referenced');"`

#### Risk Notes
- `hasAssets()` dist dizininin tamamını yayınlıyor; yeni font dosyaları otomatik gelir ama tüketen uygulamada `vendor:publish --tag=api-dock-assets --force` çalıştırılmadan görünmez. Bu adım kullanıcının.
- Font dosyaları yayınlanan varlıkları ~90 KB büyütür.

---

### Task 2: ✅ DONE — i18n çalışma zamanı ve sözlükler
**Agent:** `frontend` · **Model:** `opus-5` · **Effort:** medium · **Executor:** `codex`

> Agent/Model/Effort gerekçesi: arayüzün tüm metin yüzeyinin tek sözleşmesi burada kuruluyor, eksik anahtarı derleme zamanında yakalayan tip tasarımı karar taşıyor; kırmızı çizgiye değmiyor → codex şeridi.

#### Amaç
Arayüz metinlerinin tek kaynağı olan tip güvenli sözlüğü ve dil tercihinin çözümlenmesi/saklanmasını kurmak.

#### To-Do
- Create `resources/js/lib/i18n.ts` exporting `type Locale = 'tr' | 'en'`.
- Type the `en` dictionary as the source of truth (`export type MessageKey = keyof typeof en`) and the `tr` dictionary as `Record<MessageKey, string>`, so a missing Turkish key is a compile error.
- Expose a module-level `locale` ref plus `setLocale(next)` that persists to `localStorage['api-dock:locale']` and writes `document.documentElement.lang`.
- Expose `t(key, params?)` replacing `{name}` placeholders; an unknown placeholder is left untouched.
- Expose `resolveInitialLocale(fallback?)`: stored value wins, then the server-supplied fallback, then the `navigator.language` prefix, then `'en'`; anything outside `tr`/`en` collapses to `'en'`.
- Seed both dictionaries with every string the later tasks need: brand subtitle, search placeholder, loading/error/empty states, tab labels, section headings, field and column labels, button labels, and the try-it / AI-panel / diff copy currently hardcoded in the components. Later tasks add keys here; they do not invent a second mechanism.
- Write `resources/js/__tests__/i18n.spec.ts` covering identical key sets across both dictionaries, parameter interpolation, fallback from an unsupported stored locale, and that `setLocale` persists and updates the document language.

#### Expected Output
- `resources/js/lib/i18n.ts` with the reactive singleton, the two dictionaries and the resolver.
- A passing i18n spec.

#### Verification
- `npx vitest run resources/js/__tests__/i18n.spec.ts`

#### Risk Notes
- Sözlük anahtarları sonraki task'ların sözleşmesi; adlandırma yüzeye göre gruplanmalı (`sidebar.*`, `tabs.*`, `tryIt.*`) yoksa Task 7'de çakışır.

---

### Task 3: ✅ DONE — Tema anahtarı ve sunucu tarafı varsayılanların bağlanması
**Agent:** `frontend` · **Model:** `sonnet-5` · **Effort:** medium · **Executor:** `codex`
**Depends-on:** Task 1, Task 2

> Agent/Model/Effort gerekçesi: desen net, dört dosyada mekanik bağlama işi — base tier yeter; kırmızı çizgiye değmiyor → codex şeridi.

#### Amaç
Koyu/açık tema tercihini yönetmek ve dil/tema varsayılanlarını paket config'inden Blade üzerinden uygulamaya taşımak.

#### To-Do
- Create `resources/js/lib/theme.ts`: `type Theme = 'light' | 'dark'`, a `theme` ref, `setTheme`, `toggleTheme`, persistence under `localStorage['api-dock:theme']`, and applying or removing the `dark` class on the document element. The initial value is the stored one, else the server default, else `prefers-color-scheme`.
- Add a `ui` block to `config/api-dock.php` with `locale` (null = derive from the app locale) and `theme` (`'light'`), each carrying a one-line comment in the file's existing style.
- In `resources/views/docs.blade.php` pass `data-locale` and `data-theme` from that config, with the locale falling back to `app()->getLocale()`; set `<html lang>` to the resolved locale and replace the hardcoded dark `color-scheme` meta with `light dark`.
- In `resources/js/main.ts` read the two new data attributes and hand them to `resolveInitialLocale` and the theme initialiser before mounting. They are optional: a missing attribute must not throw the way the existing required trio does.
- Write `resources/js/__tests__/theme.spec.ts` covering: a stored preference wins over the server default; `toggleTheme` adds and removes the `dark` class on the document element and persists the new value; an unrecognised stored value falls back to the server default.

#### Expected Output
- `resources/js/lib/theme.ts` with the reactive theme singleton, plus its passing spec.
- Config, Blade view and mount script threading a locale and theme default into the app.

#### Verification
- `npx vitest run resources/js/__tests__/theme.spec.ts`

#### Risk Notes
- Config'e yeni anahtar eklemek tüketen uygulamadaki yayınlanmış kopyayı güncellemez; okuma `??` ile varsayılana düşmeli ki eski kopya patlamasın.

---

### Task 4: ✅ DONE — Uygulama kabuğu ve endpoint sidebar'ı
**Agent:** `frontend` · **Model:** `opus-5` · **Effort:** medium · **Executor:** `codex`
**Depends-on:** Task 1, Task 3

> Agent/Model/Effort gerekçesi: iki komponentin düzeni tasarıma göre yeniden kuruluyor ve dil/tema anahtarları buraya oturuyor; desen net ama kararlar tasarımdan okunuyor → codex şeridi.

#### Amaç
300 px koyu sidebar'ı (arama, katlanabilir tag grupları, metot rozetli iki satırlı öğeler, durum ayağı) ve onu saran kabuğu tasarımdaki hâline getirmek; dil ve tema anahtarlarını yerleştirmek.

#### To-Do
- Rebuild the brand block in `resources/js/components/EndpointSidebar.vue`: a 32 px `--radius-lg` primary square with `pi pi-box`, the product name, and the localized "Spec explorer" subtitle.
- Restyle the search field per the design (`--surface-800` background, `pi pi-search`, a `/` keycap) and add a global `/` handler that focuses it — the handler must stay inert while another input or textarea has focus.
- Turn group headers into buttons carrying a chevron (`pi pi-chevron-down` / `pi pi-chevron-right`) and an operation count; collapsed state is local component state and is force-opened while a query is active.
- Render operation rows on two lines — method badge plus summary, then the path — with the active row taking the `--primary-500` left border, `--surface-800` background and white text, as the design's two branches draw it.
- Keep the footer's OpenAPI version, document version and green status dot on the new tokens.
- Rebuild `resources/js/App.vue` as the design's shell: flex layout, sticky full-height sidebar, main column centred at `max-width: 900px` with the design's padding, and localized loading / error / empty screens.
- Add a compact control cluster at the top of the main column — a `TR | EN` locale switch and a theme toggle using `pi pi-sun` / `pi pi-moon` — wired to the runtimes from Tasks 2 and 3.
- Put the layout rules for both components into the stylesheet as classes; do not copy inline `style` attributes out of the design file, because `.dark` cannot override them.

#### Expected Output
- A sidebar and shell rendering the design's layout, fully localized, with working locale and theme switches.

#### Verification
- `npx vitest run resources/js/__tests__/TagGrouping.spec.ts`

#### Risk Notes
- `/` kısayolu bir input veya textarea odaktayken tetiklenmemeli.

---

### Task 5: ✅ DONE — JsonTree komponenti
**Agent:** `frontend` · **Model:** `sonnet-5` · **Effort:** medium · **Executor:** `codex`
**Depends-on:** Task 1

> Agent/Model/Effort gerekçesi: dışa bağımlılığı olmayan, saf girdi/çıktı üreten tek bir komponent — izole frontend üretimi, base tier yeter; codex şeridi.

#### Amaç
Tasarımdaki katlanabilir, renklendirilmiş JSON görüntüleyicisini bağımsız bir komponent olarak yazmak; hem AI örnekleri hem try-it yanıtı bunu kullanacak.

#### To-Do
- Create `resources/js/components/JsonTree.vue` accepting either `value: unknown` (already-parsed JSON) or `source: string` (raw text parsed internally; unparseable input renders as a `<pre>` fallback instead of throwing).
- Port the design's `flattenJson` logic: each row carries an indent, an optional caret toggle, an optional quoted key, and a value coloured by type (string / number / boolean / null / punctuation) using the design's palette mapped onto CSS custom properties so the dark code surface works in both themes.
- Keep collapse state inside the component keyed by node path; a collapsed object or array renders `{ … }` / `[ … ]` with its trailing comma preserved.
- Cap total rendered rows (2000) and depth (20), rendering a localized notice through `t()` when a cap trips.
- Write `resources/js/__tests__/JsonTree.spec.ts` covering nested flattening order and indentation, collapsing a node, array indices rendering without a key, distinct leaf colouring for string vs number, the malformed-source fallback, and the row-cap notice.

#### Expected Output
- `resources/js/components/JsonTree.vue` plus its passing spec.

#### Verification
- `npx vitest run resources/js/__tests__/JsonTree.spec.ts`

#### Risk Notes
- Test üretim koduyla aynı task'ta yazılıyor; yazarın kendi kodunu test etme riski, iddiaların iç yapıya değil işlenen satırlara bakmasıyla sınırlanıyor.

---

### Task 6: ✅ DONE — Operasyon detayı — tab kabuğu, başlık, parametreler, yanıtlar
**Agent:** `frontend` · **Model:** `opus-5` · **Effort:** high · **Executor:** `codex`
**Depends-on:** Task 2, Task 4

> Agent/Model/Effort gerekçesi: planın en geniş yüzeyi — tek sütunlu yığından sekmeli kabuğa geçiş, sekme kümesi operasyonun içeriğine göre değişiyor; çok dosyalı ve karar yoğun ama kırmızı çizgiye değmiyor → codex şeridi, high effort.

#### Amaç
Operasyon detayını tasarımdaki hâline getirmek: "Operation" üst başlığı, metot rozetli URL şeridi, açıklama kartı ve özellik şeridi, altında sekmeli içerik kabuğu.

#### To-Do
- Create `resources/js/components/OperationTabs.vue`: the design's tab strip (`--surface-100` bar with `--radius-xl` top corners, the active tab lifted onto the card background) driven by a `tabs` prop of `{ id, label, icon }` and a `v-model` for the active id. Labels arrive already localized from the caller.
- Rebuild the header of `resources/js/components/OperationDetail.vue`: localized kicker, `h1` from summary/operationId/path, then the URL pill — method badge plus the resolved server URL with the path in bold.
- Put `MarkdownText` inside the description card and render the feature strip beneath it as a grid of cells from `x-api-dock-features` (auth, scopes, rate limit, stability, deprecated), one cell per declared feature and the whole section dropped when none exist.
- Build the tab set conditionally: Parameters (only with parameters), Request body (only when declared — absent from the design, kept because the package documents it), Responses, Try it, AI prompt (only with AI metadata), Spec diff; icons `pi pi-sliders-h`, `pi pi-file-edit`, `pi pi-server`, `pi pi-play`, `pi pi-sparkles`, `pi pi-code`. When the active tab disappears after an operation switch, fall back to Responses.
- Restyle the Parameters pane as the design's dashed-divider card and the Responses pane as collapsible cards with a status pill (`--green-100` for 2xx, `--amber-100` otherwise), the content-type bar and the schema rows.
- Restyle the row in `resources/js/components/SchemaNode.vue` to the design's schema row — em-dash bullet, monospace name, then type/required/nullable tags in the design's tag colour map. Behaviour (depth caps, `$ref` resolution, cycle guard) is unchanged.
- Route every literal string in both components through `t()`, adding any missing keys to both dictionaries.

#### Expected Output
- A tab strip component plus a tabbed, localized operation detail and a restyled schema row.

#### Verification
- `npx vitest run resources/js/__tests__/SchemaTree.spec.ts`

#### Risk Notes
- Paneller artık aynı anda DOM'da değil; kendi state'ini tutan komponentler sekme değişiminde `v-show` ile korunmalı, `v-if` ile sıfırlanmamalı.
- Operasyon değişince aktif sekme kaybolabilir; geri düşüş yolu yazılmazsa boş içerik alanı kalır.

---

### Task 7: ✅ DONE — Try it, AI prompt ve Spec diff panelleri
**Agent:** `frontend` · **Model:** `opus-5` · **Effort:** high · **Executor:** `codex`
**Depends-on:** Task 5, Task 6

> Agent/Model/Effort gerekçesi: arayüzdeki metinlerin çoğu ve en karmaşık üç panel burada; yalnız sunum ve metin katmanı değişiyor, proxy sözleşmesine dokunulmuyor → codex şeridi, high effort.

#### Amaç
Üç paneli sekme içeriği olarak yeni token setine ve i18n sözlüğüne taşımak; try-it ve AI örneklerinin JSON gövdelerini `JsonTree` ile göstermek.

#### To-Do
- Restyle `resources/js/components/TryItPanel.vue` onto the design's request bar — method select, path field, profile select and a primary Send button on one row, parameter inputs beneath, and the curl sample on the `--surface-900` block with a `pi pi-copy` button. Drop its own section heading, since the tab now owns it. Turn the response block into the design's two-column card (request payload left, response right) with `JsonTree` for a JSON body and the existing `<pre>` kept for anything else. Every existing behaviour, `data-testid` and guard message stays intact — only markup, classes and copy change.
- Restyle `resources/js/components/AiPanel.vue`: the hint as the design's primary-bordered quote block, pitfalls as the numbered card, examples as the accordion showing request and response side by side with `JsonTree` on the response, and the two artifact buttons plus the changelog card on the new tokens. The render-limit truncation notices stay, localized.
- Restyle `resources/js/components/DiffView.vue`: the paste textarea and the severity groups onto the new tokens.
- Replace every remaining hardcoded English literal across the three panels with `t()` calls, adding the keys to both dictionaries in the i18n module.

#### Expected Output
- Three panels rendering as tab content in the new design, fully localized, using `JsonTree` for JSON response bodies.

#### Verification
- `npx vitest run resources/js/__tests__/TryItPanel.spec.ts resources/js/__tests__/AiPanel.spec.ts resources/js/__tests__/DiffView.spec.ts`

#### Risk Notes
- Panellerin `data-testid` değerleri sözleşmedir; yeniden adlandırılırsa Task 8'deki spec güncellemesi gereksiz yere büyür.
- Kimlik ipucu (`credential_hint`) dışında hiçbir gizli değer ekrana yazılmamalı — mevcut davranış korunur.

---

### Task 8: ✅ DONE — Mevcut vitest spec'lerinin yeni yüzeye uyarlanması
**Agent:** `frontend` · **Model:** `sonnet-5` · **Effort:** medium · **Executor:** `codex`
**Depends-on:** Task 7
**Oversize-ack:** Tek bir yüzey değişikliğinin altı spec dosyasına yansıması; bölmek aynı bağlamı iki kez kurdurur ve hiçbir dosyayı kolaylaştırmaz.

> Agent/Model/Effort gerekçesi: iddiaları yeni DOM'a ve çeviri anahtarlarına taşıyan mekanik uyarlama işi, base tier yeter; codex şeridi.

#### Amaç
Metinleri İngilizce sabit dizgelere göre eşleyen mevcut spec'leri, sekmeli ve çevrilmiş arayüze uyarlamak.

#### To-Do
- Go through every existing spec under `resources/js/__tests__` (the AI panel, diff view, try-it, schema tree, tag grouping and try-it server specs).
- Where a test asserts on a literal English string, assert on the value the translator returns for that key instead, or on a `data-testid` — a translation is never frozen into a test.
- Where a test mounted a panel that now lives behind a tab, mount the component directly or activate its tab first; the assertion being made must not change.
- Do not weaken an assertion to make it pass. If a behaviour genuinely regressed, report it rather than editing the expectation.

#### Expected Output
- Every existing spec passing against the new components, asserting on keys and test ids rather than English literals.

#### Verification
- `npx vitest run resources/js/__tests__`

#### Risk Notes
- Bir iddianın "geçsin diye" gevşetilmesi bu task'ın tek gerçek riski; regresyon varsa rapor edilir, beklenti düşürülmez.

---

### Task 9: ✅ DONE — Konsolide doğrulama
**Agent:** `verifier` · **Model:** `sonnet-5` · **Effort:** medium · **Executor:** `claude`
**Depends-on:** Task 8

> Agent/Model/Effort gerekçesi: konsolide test/build koşusu verifier'ın tekelinde; kod yazmadığı için codex şeridine girmez.

#### Amaç
Planın bıraktığı ağacın bütün olarak yeşil olduğunu kanıtlamak.

#### To-Do
- Run the full frontend and backend suites plus the production build and the formatter, and report the actual output.
- Report bundle size deltas for the built JS and CSS against the pre-plan figures (110.90 kB JS / 20.27 kB CSS).
- Do not fix what fails — report it with the failing output.

#### Expected Output
- A report carrying the vitest result, the Pest result, the build result with sizes, and the Pint result.

#### Verification
- `npx vitest run`
- `vendor/bin/pest --testsuite="Api Dock"`
- `npm run build`
- `vendor/bin/pint --test --dirty`

#### Risk Notes
- `vendor/bin/pest` filtresiz çalıştırılmaz; `--testsuite="Api Dock"` zorunlu.

## Geri Alma Stratejisi

Plan hiçbir migration, route veya PHP davranışı değiştirmiyor; geri alma tamamen dosya düzeyinde.

- Çalışan ağaç henüz commit'lenmemiş durumda. Tümünü geri almak için değişen `resources/`, `config/api-dock.php`, `package.json` ve `vite.config.ts` dosyalarını plan öncesi hâline döndürmek yeterli.
- Config'e eklenen `ui` bloğu geriye dönük uyumlu: tüketen uygulamada eski bir yayınlanmış config kopyası varsa okuma `??` ile varsayılana düşer, hata vermez.
- Tüketen uygulama (`congress-app`) yalnız yayınlanan varlıklardan etkilenir. Eski görünüme dönmek için paket geri alınıp `php artisan vendor:publish --tag=api-dock-assets --force` tekrar çalıştırılır — bu adım kullanıcının.
- npm bağımlılıkları (`primeicons`, `@fontsource/instrument-sans`) kaldırılırsa mount script'indeki iki import da silinmelidir; aksi halde build kırılır.

## Doğrulama Stratejisi

- **Test:** her task kendi dar `vitest` filtresiyle doğrulanır; Task 9 konsolide koşuyu yapar. PHP tarafına dokunulmadığı için Pest yalnız regresyon kanıtı olarak koşar.
- **Manuel:** `congress-app` üzerinde `vendor:publish --tag=api-dock-assets --force` sonrası `/api-dock` açılır ve şunlar gözle doğrulanır: sidebar arama ve grup katlama, sekme geçişleri, TR/EN anahtarının tüm arayüzü çevirmesi, koyu/açık tema anahtarı, JSON ağacının katlanması, try-it isteğinin hâlâ 200 dönmesi.
- **Regresyon:** try-it proxy sözleşmesi (`data-testid` değerleri, profil oluşturma, curl örneği) ve markdown açıklama render'ı bu planın kırmamak zorunda olduğu iki yüzey; ikisinin de mevcut spec'leri Task 8'de korunur.
