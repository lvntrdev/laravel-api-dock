export const meta = {
  name: '2026-09-05-try-it-credential-persistence',
  description: 'Plan 2026-09-05-try-it-credential-persistence — 3 task / 3 agent (Depends-on promise-DAG).',
  phases: [
    { title: 'Task 1', detail: 'T1: AuthProfileStore kalıcı depolama modu' },
    { title: 'Task 2', detail: 'T2: README / config dokümantasyonu' },
    { title: 'Task 3', detail: 'T3: Kalıcılık + izolasyon regresyon testleri' }
  ],
};

// bosun plan-to-workflow tarafından üretildi — elle düzenleme yerine yeniden derle.
// Promise-DAG: her unit yalnız kendi Depends-on promise'larını bekler; seviye barrier'ı yoktur.
// Deterministik: zaman/rastgelelik çağrısı içermez (Workflow kısıtı) — çeşitlilik task numarasına göre.
// Guard 1: her agent() AÇIK model taşır (pinli) — oturum modeli MİRAS ALINMAZ ([1m]/max sızıntısı kapalı).
// Guard 5: her unit dependency sınırında token devre-kesici (hook-bağımsız fren) — tavan aşılırsa yeni fan-out durur.

// Gövde DÜZ: Workflow runtime meta'yı ayırıp geri kalanını async gövde olarak derler —
// ikinci bir 'export' anahtar kelimesi SyntaxError olur. Dönüş değeri top-level return'dür.
// CLAUDE_PARALLEL (3): ortak working tree'ye aynı anda yazan Workflow ajanlarını sınırlar.
const CLAUDE_PARALLEL = 3;
const _claudeLaneWaiters = [];
let _claudeLaneActive = 0;
const _acquireClaudeLane = () => new Promise((resolve) => {
  if (_claudeLaneActive < CLAUDE_PARALLEL) {
    _claudeLaneActive += 1;
    resolve();
    return;
  }
  _claudeLaneWaiters.push(resolve);
});
const _releaseClaudeLane = () => {
  const next = _claudeLaneWaiters.shift();
  if (next) {
    next();
    return;
  }
  _claudeLaneActive -= 1;
};

// WORKFLOW_TOKEN_CEILING — GÜVENLİK TAVANI, kalite bütçesi DEĞİL (normal koşunun üstü,
// runaway'in altı). Her unit dependency sınırında token yanması tavanı aşarsa fan-out durur —
// hook ateşlemese bile çalışan fren. Etkin sınır, kullanıcı bütçesi (budget.total, "+Nk") ile
// bu tavanın küçüğüdür; BOSUN_ALLOW_WORKFLOW_CEILING_OVERRIDE=1 bilinçli tavan opt-out'udur.
// BOSUN_WORKFLOW_TOKEN_CEILING env ile
// override (eski ad AI_KIT_WORKFLOW_TOKEN_CEILING hâlâ okunur — DEPRECATED fallback;
// runtime'da okunur → script metni env'den bağımsız, deterministik kalır).
// lib/env.mjs BURADA import EDİLEMEZ: script Workflow sandbox'ında koşar, kit
// modüllerini göremez → aynı iki-adlı öncelik satır içinde tekrarlanır.
// Pozitif OLMAYAN değer (0, negatif, NaN) tavanı KAPATMAZ — kapatmanın tek yolu
// BOSUN_ALLOW_WORKFLOW_CEILING_OVERRIDE=1. `-1` sessizce sonsuz tavan demek olurdu.
const _CEILING_ENV = Number(
  (typeof process !== 'undefined' && process.env
    && (process.env.BOSUN_WORKFLOW_TOKEN_CEILING
      || process.env.AI_KIT_WORKFLOW_TOKEN_CEILING)) || 0,
);
const WORKFLOW_TOKEN_CEILING = _CEILING_ENV > 0 ? _CEILING_ENV : 400_000;
const _CEILING_OVERRIDE = (typeof process !== 'undefined' && process.env
  && process.env.BOSUN_ALLOW_WORKFLOW_CEILING_OVERRIDE === '1') || false;

// Tavan BU workflow'un KENDİ yakımına uygulanır. `budget.spent()` turn kümülatifidir
// (ana döngü + aynı turn'deki tüm workflow'lar), o yüzden mutlak karşılaştırma uzun bir
// oturumda İLK kapıda tetikler: her unit null döner, 0 agent koşar — sahte "runaway".
// Taban script girişinde alınır; fren yalnız bu koşunun yaktığı token'ı görür.
const _BURN_BASELINE = budget.spent();

// Bağımlı task raporları: ajan ayrı bağlamdır, önceki task'ın bulduğunu GÖRMEZ.
// Kuyruk özeti prompt SONUNA eklenir (cache prefix'i bozulmasın).
const _DEP_CHARS = 700;
const _depBlock = (deps) => {
  if (!deps || !deps.length) return "";
  const lines = deps.map((d) => {
    const body = String((d && d.report) || "").trim().replace(/\s+/g, " ");
    return `Task ${d.task} (${d.agent}) — ${d.title}: ${body.slice(-_DEP_CHARS)}`;
  });
  return "\n\n── Bağımlı task'ların sonucu (bağlam — TEKRAR KEŞFETME) ──\n" + lines.join("\n");
};
// Cold verifier bağımlılıkları: yalnız task kimliği; üretici raporu KESİNLİKLE yok.
const _depBlockCold = (deps) => {
  if (!deps || !deps.length) return "";
  const lines = deps.map((d) => `Task ${d.task} (${d.agent}) — ${d.title}`);
  return "\n\n── Bağımlı task'lar (cold verification — rapor yok) ──\n" + lines.join("\n");
};

// Sabit prompt preamble (scope · ortak ağaç · değişmez sınırlar · execution floor ·
// repo kökü/haritası · retry notu kuralları) — HER agent() prompt'unda AYNI metin,
// script'te BİR KEZ. `_x` = alias'a özgü ek satır(lar), `_n` = ilerleme notu yolu.
const _PRE = (_x, _n) => `── bosun sabit preamble ──
- YALNIZ kendi task scope'un: aşağıdaki plan bölümünün dışına çıkma; başka task'ın dosyalarına dokunma; scope creep yok.
- Paralel çakışma yaratma: aynı aşamadaki diğer ajanlarla ORTAK working tree paylaşıyorsun (worktree izolasyonu yok) — yalnız kendi dosya kümene dokun.
- Değişmez sınırlar (çekirdeği GÖRMÜYORSUN — kural burada, link değil): commit'lenmiş migration DÜZENLENMEZ (yenisi eklenir); tam DB reset (migrate:fresh/refresh/reset, db:wipe) YASAK; secret verbatim tekrarlanmaz (yalnız dosya:satır ile atıf); \`git commit\`/\`push\`/\`reset --hard\`/\`clean -fd\` onaysız YASAK.
── Execution floor (binding — applies to this task) ──
1. Non-terminating / interactive foreground commands are FORBIDDEN. Every Bash command self-terminates or has a tight timeout/gtimeout wrap.
   Never run bare tinker, serve, dev, watch, tail -f, journalctl -f, or a REPL (node/python/mysql/psql without a terminating flag); use node -e, python -c, mysql -e, or tinker --execute=.
2. Do NOT background your own Bash command. Verification runs FOREGROUND and SHORT, and you read its output;
   no run_in_background plus long-poll Monitor.
3. Cross-layer / full-suite build is FORBIDDEN. Verify only your own layer with the narrow --filter of the changed surface;
   the consolidated build/test runs ONCE at the end of the plan, in the controller's own foreground call — not in this task, and not in a verifier dispatch.
4. Convergence budget: if the same command returns the same error repeatedly, STOP and report the partial state plus blocker;
   never enter an infinite retry loop.
5. Checkpoint discipline — do NOT gamble the whole task on one heroic turn. A turn that is cut off mid-stream (API stall,
   interruption) restarts from ZERO, so after each To-Do item lands: bring the tree to a coherent state and append that item
   to the progress note named in this prompt. The next attempt reads that note and continues instead of redoing your work.${_x}

Bir task list kullanırsan her item başına [<agent> <model>/<effort>] etiketi ekle.
Sonda: değişen dosyaları (mutlak yol) + doğrulama komutları ve GERÇEK çıktılarını listele. Kanıtsız "çalışıyor" deme.

Mutlak repo kökü: /Volumes/WorkingDisk/Projects/Berth/api-dock
(Tüm dosya yollarını bu köke göre MUTLAK ver; çalışma dizinini varsayma.)
Plan dosyası: plan-docs/2026-09-05-try-it-credential-persistence.md

── Önceki deneme kontrolü (retry güvenliği) ──
İlerleme notun: ${_n}
- BAŞLAMADAN önce bu dosyaya bak. VARSA: bu task'ın bir önceki denemesi yarıda kesilmiş demektir (turn mid-stream ölür ve baştan başlatılır). Biten maddeleri TEKRARLAMA, kaldığı yerden devam et.
- Not bir İPUCUDUR, kanıt değil: her maddeyi gerçek ağaçla doğrula — \`git status --porcelain -- <kendi To-Do dosyaların>\` ve dosyayı OKU.
- \`git status\`u YALNIZ kendi To-Do dosyalarınla sınırla: ortak working tree'de başka bir ajanın uçuştaki değişikliği senin "önceki denemen" DEĞİLDİR; onu devralmak başka bir task'ın işini bozar.
- Her To-Do maddesi bitince nota tek satır ekle (\`- [x] <madde> — <dosya>\`); dizin yoksa oluştur (\`mkdir -p\`).
- Not GEÇİCİ çalışma dosyasıdır: stage ETME, commit ETME, teslim ettiğin değişiklik kümesine sayma (repo \`.gitignore\`'unda olmayabilir).
- Task tamamen bitince notu SİL — yarım kalmışlık işareti odur, biten task'ta kalırsa sonraki koşuyu yanıltır.

`;

const done = {};
let _aborted = false;

done[1] = (async () => {
  const _deps = (await Promise.all([])).filter(Boolean);
  const _userExhausted = budget.total && budget.remaining() <= 0;
  const _ceilingHit = WORKFLOW_TOKEN_CEILING > 0 && (budget.spent() - _BURN_BASELINE) > WORKFLOW_TOKEN_CEILING;
  if (_aborted || _userExhausted || (_ceilingHit && !_CEILING_OVERRIDE)) {
    if (!_aborted) {
      // Hangi dal tetiklediyse ONU raporla: kullanıcı bütçesi tükendiğinde bu
      // workflow'un kendi yakımı 0k olabilir — tavanı suçlamak yanlış teşhis olur.
      log(_userExhausted
        ? `⛔ Devre-kesici: kullanıcı bütçesi tükendi (${Math.round(budget.spent()/1000)}k/${Math.round(budget.total/1000)}k). Fan-out durduruldu — kısmi sonuç dönüyor.`
        : `⛔ Devre-kesici: ${Math.round((budget.spent() - _BURN_BASELINE)/1000)}k token yandı (tavan ${WORKFLOW_TOKEN_CEILING/1000}k). Fan-out durduruldu — kısmi sonuç dönüyor. Muhtemel asılma/retry döngüsü: /workflows + plan-report ile incele.`);
    }
    _aborted = true;
    return null;
  }
  await _acquireClaudeLane();
  try {
    const _userExhausted = budget.total && budget.remaining() <= 0;
    const _ceilingHit = WORKFLOW_TOKEN_CEILING > 0 && (budget.spent() - _BURN_BASELINE) > WORKFLOW_TOKEN_CEILING;
    if (_aborted || _userExhausted || (_ceilingHit && !_CEILING_OVERRIDE)) {
      if (!_aborted) {
        // Hangi dal tetiklediyse ONU raporla: kullanıcı bütçesi tükendiğinde bu
        // workflow'un kendi yakımı 0k olabilir — tavanı suçlamak yanlış teşhis olur.
        log(_userExhausted
          ? `⛔ Devre-kesici: kullanıcı bütçesi tükendi (${Math.round(budget.spent()/1000)}k/${Math.round(budget.total/1000)}k). Fan-out durduruldu — kısmi sonuç dönüyor.`
          : `⛔ Devre-kesici: ${Math.round((budget.spent() - _BURN_BASELINE)/1000)}k token yandı (tavan ${WORKFLOW_TOKEN_CEILING/1000}k). Fan-out durduruldu — kısmi sonuç dönüyor. Muhtemel asılma/retry döngüsü: /workflows + plan-report ile incele.`);
      }
      _aborted = true;
      return null;
    }
    phase('Task 1');
    return await agent(`${_PRE("", "/Volumes/WorkingDisk/Projects/Berth/api-dock/plan-docs/.progress/2026-09-05-try-it-credential-persistence-task-1.md")}Task 1: AuthProfileStore kalıcı depolama modu
Agent/Model/Effort: security / claude-opus-5 / high
Effort talimatı (high): standart derinlik: recursive cascade, regresyon ve edge case'leri gözet.

── Plan bölümü (Task 1) — TAM metin; plan dosyasını ayrıca aramana gerek yok ──
### Task 1: AuthProfileStore kalıcı depolama modu

**Agent:** \`security\` · **Model:** \`opus-5\` · **Effort:** high · **Executor:** \`claude\`

> Agent/Model/Effort gerekçesi: secret persistence guarantee'sini değiştiren kırmızı çizgi (core §2 "secret") — Codex şeridi kesinlikle yasak (plan-validate hard reject); thin-slice tavanı (Ceremony-ack) \`xhigh\`'ı engellediği için \`high\` tavanda tutuluyor, tasarım zaten bu planda netleşti (Alternatifler), task sadece uyguluyor.

#### Amaç
\`AuthProfileStore\`'a, mevcut session-scoped depolamanın yanına, config ile açılabilen kalıcı (cache-backed, kullanıcıya keylenmiş, TTL'li) bir ikinci depolama modu eklemek — varsayılan davranış (\`session\`) hiç değişmeden.

#### To-Do
- \`config/api-dock.php\` → \`try_it\` bloğuna yeni alt anahtar ekle:
  \`\`\`php
  'profile_persistence' => [
      // Off by default: preserves the existing session-scoped guarantee for every
      // consumer of this package. A consuming app opts in explicitly.
      'enabled' => false,
      // Minutes. Default 30 days — long enough that a rarely-rotated API token is
      // not re-entered every login, short enough that a stale credential does not
      // live forever.
      'ttl_minutes' => 60 * 24 * 30,
  ],
  \`\`\`
- \`src/Support/AuthProfileStore.php\`:
  - Inject \`Illuminate\\Contracts\\Cache\\Repository\` (via \`app('cache')->store()\` or constructor-injected \`CacheFactory\`, follow the existing constructor-injection style already used for \`Encrypter\`).
  - Add a private \`persistenceEnabled(): bool\` reading \`config('api-dock.try_it.profile_persistence.enabled', false)\`, and \`persistenceTtl(): int\` reading \`ttl_minutes\` (same numeric-guard pattern as \`maxProfiles()\`).
  - Add a private \`cacheKey(): ?string\` — \`null\` when persistence is on but there is no authenticated user (\`Auth::id()\` is null, e.g. a guest whose only identity is the session); a guest has no stable identity to key persistent storage on, so it must fall back to session storage even with the flag on. Otherwise \`'api-dock.try-it.profiles.'.$userId\`.
  - \`raw()\`/\`write()\` branch on \`persistenceEnabled() && cacheKey() !== null\`: read/write through the injected cache repository (\`get($key, [])\` / \`put($key, $profiles, now()->addMinutes($ttl))\`) instead of \`Session::get\`/\`Session::put\`/\`Session::forget\`. Keep the exact same record shape (\`id\`/\`label\`/\`base_url\`/\`server_variables\`/\`scheme\`/\`credential_header\`/\`credential_hint\`/\`credential\`) — every other method (\`put\`, \`all\`, \`find\`, \`forget\`, \`revealCredentialForOutboundRequest\`, \`withoutCredential\`, \`serverVariables\`, \`mask\`) stays untouched, they only ever call \`raw()\`/\`write()\`.
  - \`flush()\` branches the same way: \`Cache::forget($key)\` in persistent mode, \`Session::forget(...)\` otherwise.
  - Update the class doc-comment (lines 12-30) to state the two modes and the trade-off explicitly: in persistent mode, logging out does **not** purge a stored credential — only \`forget()\`/\`flush()\` (an explicit delete) or TTL expiry does. State this as a deliberate, opt-in trade-off, not an oversight.

#### Expected Output
- \`config/api-dock.php\` carries the new \`try_it.profile_persistence\` block.
- \`AuthProfileStore\` supports both modes behind the existing public API, unchanged method signatures.

#### Verification
- \`vendor/bin/pest --testsuite="Api Dock" --filter=AuthProfileStoreTest\`

#### Risk Notes
- Güvenlik trade-off'u: persistent mod açıkken logout kimlik bilgisini silmiyor — bu doküman + config yorumunda açıkça yazılı, varsayılan kapalı.
- Cache sürücüsü \`array\`/\`file\`'sa ve worker'lar arası paylaşılmıyorsa TTL beklendiği gibi çalışmayabilir — README'de not düşülüyor (Task 2).
- Guest (kimliksiz) istek her zaman session moduna düşer — cross-user sızıntı riski yok.

── Plan bölümü sonu ──` + _depBlock(_deps), { agentType: 'bosun:security-auditor', model: 'opus', effort: 'high', label: "Task 1: AuthProfileStore kalıcı depolama modu · security opus/high", phase: 'Task 1' })
      .then((report) => ({ task: 1, title: "AuthProfileStore kalıcı depolama modu", agent: "security", report }));
  } finally {
    _releaseClaudeLane();
  }
})();

done[2] = (async () => {
  const _deps = (await Promise.all([done[1]])).filter(Boolean);
  const _userExhausted = budget.total && budget.remaining() <= 0;
  const _ceilingHit = WORKFLOW_TOKEN_CEILING > 0 && (budget.spent() - _BURN_BASELINE) > WORKFLOW_TOKEN_CEILING;
  if (_aborted || _userExhausted || (_ceilingHit && !_CEILING_OVERRIDE)) {
    if (!_aborted) {
      // Hangi dal tetiklediyse ONU raporla: kullanıcı bütçesi tükendiğinde bu
      // workflow'un kendi yakımı 0k olabilir — tavanı suçlamak yanlış teşhis olur.
      log(_userExhausted
        ? `⛔ Devre-kesici: kullanıcı bütçesi tükendi (${Math.round(budget.spent()/1000)}k/${Math.round(budget.total/1000)}k). Fan-out durduruldu — kısmi sonuç dönüyor.`
        : `⛔ Devre-kesici: ${Math.round((budget.spent() - _BURN_BASELINE)/1000)}k token yandı (tavan ${WORKFLOW_TOKEN_CEILING/1000}k). Fan-out durduruldu — kısmi sonuç dönüyor. Muhtemel asılma/retry döngüsü: /workflows + plan-report ile incele.`);
    }
    _aborted = true;
    return null;
  }
  await _acquireClaudeLane();
  try {
    const _userExhausted = budget.total && budget.remaining() <= 0;
    const _ceilingHit = WORKFLOW_TOKEN_CEILING > 0 && (budget.spent() - _BURN_BASELINE) > WORKFLOW_TOKEN_CEILING;
    if (_aborted || _userExhausted || (_ceilingHit && !_CEILING_OVERRIDE)) {
      if (!_aborted) {
        // Hangi dal tetiklediyse ONU raporla: kullanıcı bütçesi tükendiğinde bu
        // workflow'un kendi yakımı 0k olabilir — tavanı suçlamak yanlış teşhis olur.
        log(_userExhausted
          ? `⛔ Devre-kesici: kullanıcı bütçesi tükendi (${Math.round(budget.spent()/1000)}k/${Math.round(budget.total/1000)}k). Fan-out durduruldu — kısmi sonuç dönüyor.`
          : `⛔ Devre-kesici: ${Math.round((budget.spent() - _BURN_BASELINE)/1000)}k token yandı (tavan ${WORKFLOW_TOKEN_CEILING/1000}k). Fan-out durduruldu — kısmi sonuç dönüyor. Muhtemel asılma/retry döngüsü: /workflows + plan-report ile incele.`);
      }
      _aborted = true;
      return null;
    }
    phase('Task 2');
    return await agent(`${_PRE("", "/Volumes/WorkingDisk/Projects/Berth/api-dock/plan-docs/.progress/2026-09-05-try-it-credential-persistence-task-2.md")}Task 2: README / config dokümantasyonu
Agent/Model/Effort: docs / claude-sonnet-5 / medium
Effort talimatı (medium): taban derinlik: task scope'una sadık kal.

── Plan bölümü (Task 2) — TAM metin; plan dosyasını ayrıca aramana gerek yok ──
### Task 2: README / config dokümantasyonu

**Agent:** \`docs\` · **Model:** \`sonnet-5\` · **Effort:** medium · **Executor:** \`claude\`
**Depends-on:** Task 1

> Agent/Model/Effort gerekçesi: saf dokümantasyon, mekanik iş — ama Codex şeridi bu oturumda globalde kapalı (\`execution: claude\`, review-only), o yüzden \`claude\` yazıldı; şerit açılırsa bu task normalde Codex whitelist'ine girer.

#### Amaç
Yeni \`profile_persistence\` config anahtarını ve trade-off'unu README'de belgelemek.

#### To-Do
- \`README.md\` → "Try it, through your server" bölümüne kısa bir alt paragraf ekle: \`api-dock.try_it.profile_persistence.enabled\` ne yapar, varsayılanın \`false\` olduğu, açıldığında logout'un kimlik bilgisini SİLMEDİĞİ, \`ttl_minutes\` varsayılanı (30 gün), ve cache sürücüsünün worker'lar arası paylaşılan/kalıcı bir sürücü olması gerektiği (örn. \`database\`/\`redis\`, \`array\` çalışma-anı-only olduğu için bu özelliği anlamsızlaştırır).

#### Expected Output
- README'de yeni config anahtarı + trade-off + cache-driver notu.

#### Verification
- \`grep -n "profile_persistence" README.md\` (satırın gerçekten eklendiğini doğrula)

#### Risk Notes
- Yok.

── Plan bölümü sonu ──` + _depBlock(_deps), { agentType: 'bosun:docs-writer', model: 'sonnet', effort: 'medium', label: "Task 2: README / config dokümantasyonu · docs sonnet/medium", phase: 'Task 2' })
      .then((report) => ({ task: 2, title: "README / config dokümantasyonu", agent: "docs", report }));
  } finally {
    _releaseClaudeLane();
  }
})();

done[3] = (async () => {
  const _deps = (await Promise.all([done[1]])).filter(Boolean);
  const _userExhausted = budget.total && budget.remaining() <= 0;
  const _ceilingHit = WORKFLOW_TOKEN_CEILING > 0 && (budget.spent() - _BURN_BASELINE) > WORKFLOW_TOKEN_CEILING;
  if (_aborted || _userExhausted || (_ceilingHit && !_CEILING_OVERRIDE)) {
    if (!_aborted) {
      // Hangi dal tetiklediyse ONU raporla: kullanıcı bütçesi tükendiğinde bu
      // workflow'un kendi yakımı 0k olabilir — tavanı suçlamak yanlış teşhis olur.
      log(_userExhausted
        ? `⛔ Devre-kesici: kullanıcı bütçesi tükendi (${Math.round(budget.spent()/1000)}k/${Math.round(budget.total/1000)}k). Fan-out durduruldu — kısmi sonuç dönüyor.`
        : `⛔ Devre-kesici: ${Math.round((budget.spent() - _BURN_BASELINE)/1000)}k token yandı (tavan ${WORKFLOW_TOKEN_CEILING/1000}k). Fan-out durduruldu — kısmi sonuç dönüyor. Muhtemel asılma/retry döngüsü: /workflows + plan-report ile incele.`);
    }
    _aborted = true;
    return null;
  }
  await _acquireClaudeLane();
  try {
    const _userExhausted = budget.total && budget.remaining() <= 0;
    const _ceilingHit = WORKFLOW_TOKEN_CEILING > 0 && (budget.spent() - _BURN_BASELINE) > WORKFLOW_TOKEN_CEILING;
    if (_aborted || _userExhausted || (_ceilingHit && !_CEILING_OVERRIDE)) {
      if (!_aborted) {
        // Hangi dal tetiklediyse ONU raporla: kullanıcı bütçesi tükendiğinde bu
        // workflow'un kendi yakımı 0k olabilir — tavanı suçlamak yanlış teşhis olur.
        log(_userExhausted
          ? `⛔ Devre-kesici: kullanıcı bütçesi tükendi (${Math.round(budget.spent()/1000)}k/${Math.round(budget.total/1000)}k). Fan-out durduruldu — kısmi sonuç dönüyor.`
          : `⛔ Devre-kesici: ${Math.round((budget.spent() - _BURN_BASELINE)/1000)}k token yandı (tavan ${WORKFLOW_TOKEN_CEILING/1000}k). Fan-out durduruldu — kısmi sonuç dönüyor. Muhtemel asılma/retry döngüsü: /workflows + plan-report ile incele.`);
      }
      _aborted = true;
      return null;
    }
    phase('Task 3');
    return await agent(`${_PRE("", "/Volumes/WorkingDisk/Projects/Berth/api-dock/plan-docs/.progress/2026-09-05-try-it-credential-persistence-task-3.md")}Task 3: Kalıcılık + izolasyon regresyon testleri
Agent/Model/Effort: security / claude-opus-5 / high
Effort talimatı (high): standart derinlik: recursive cascade, regresyon ve edge case'leri gözet.

── Plan bölümü (Task 3) — TAM metin; plan dosyasını ayrıca aramana gerek yok ──
### Task 3: Kalıcılık + izolasyon regresyon testleri

**Agent:** \`security\` · **Model:** \`opus-5\` · **Effort:** high · **Executor:** \`claude\`
**Depends-on:** Task 1

> Agent/Model/Effort gerekçesi: secret/red-line domain'de ayrı test task zorunlu (Test Placement tablosu — "the regression test is a security boundary"); yazan task'tan farklı bir dispatch, aynı kodu yazan kendi testini yazmıyor. Thin-slice tavanı burada da \`xhigh\`'ı engellediği için \`high\`.

#### Amaç
Yeni kalıcı depolama modunun (a) session invalidation'dan gerçekten bağımsız yaşadığını, (b) TTL süresi dolunca gerçekten kaybolduğunu, (c) kullanıcılar arası sızdırmadığını, (d) flag kapalıyken eski davranışın bire bir aynı kaldığını doğrulamak.

#### To-Do
- \`tests/Feature/AuthProfileStoreTest.php\`'e ekle (mevcut dosyaya, ayrı dosya açma):
  - \`profile_persistence.enabled=false\` (varsayılan): mevcut testlerin hepsi değişmeden geçiyor — regresyon yok.
  - \`profile_persistence.enabled=true\` + authenticated user: bir profil ekle, \`Session::flush()\` (login session'ı düşür/invalidate et), store'u taze bir istekte tekrar oku — profil hâlâ duruyor.
  - TTL: \`ttl_minutes\` küçük bir değere set edilip \`Carbon::setTestNow()\` ile TTL'in ötesine ilerlet — profil artık dönmüyor (cache expiry).
  - İzolasyon: iki farklı \`Auth::id()\` altında birer profil oluştur, her user sadece kendi profilini görüyor (cache key user'a göre ayrışıyor).
  - Guest (authenticated user yok) + \`profile_persistence.enabled=true\`: session moduna düştüğünü doğrula (persistent depoda hiçbir şey yazılmadığını, session'da yazıldığını kontrol et).
  - \`forget()\`/\`flush()\` persistent modda da çalışıyor (cache'ten siliniyor).

#### Expected Output
- \`tests/Feature/AuthProfileStoreTest.php\` yukarıdaki 6 senaryoyu kapsıyor.

#### Verification
- \`vendor/bin/pest --testsuite="Api Dock" --filter=AuthProfileStoreTest\`

#### Risk Notes
- Bu test dosyası kırmızı çizgi (secret) garantisinin regresyon sınırı — bundan sonra flag'i kim dokunursa dokunsun bu testler kırılmadan davranış bozulamaz.

── Plan bölümü sonu ──` + _depBlock(_deps), { agentType: 'bosun:security-auditor', model: 'opus', effort: 'high', label: "Task 3: Kalıcılık + izolasyon regresyon testleri · security opus/high", phase: 'Task 3' })
      .then((report) => ({ task: 3, title: "Kalıcılık + izolasyon regresyon testleri", agent: "security", report }));
  } finally {
    _releaseClaudeLane();
  }
})();

// Tüm unit raporlarını task sırasında topla → orchestrator entegrasyon/doğrulama için görsün.
const _reports = (await Promise.all([done[1], done[2], done[3]])).filter(Boolean);
if (_aborted) return { aborted: true, completed: _reports };
log(`3 task / 3 agent tamamlandı — ${_reports.length} rapor toplandı.`);
return _reports;
