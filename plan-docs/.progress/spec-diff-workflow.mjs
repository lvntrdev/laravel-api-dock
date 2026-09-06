export const meta = {
  name: '2026-09-05-spec-diff-and-exporter-correctness',
  description: 'Plan 2026-09-05-spec-diff-and-exporter-correctness — 6 task / 6 agent (Depends-on promise-DAG).',
  phases: [
    { title: 'Task 1', detail: 'T1: SpecDiffer eksik-taraf ve `$ref` sınıflandırması' },
    { title: 'Task 2', detail: 'T2: Exporter’larda ortak `$ref` çözümü' },
    { title: 'Task 3', detail: 'T3: Try-it self host port kontrolü' },
    { title: 'Task 4', detail: 'T4: Opsiyonel `viewApiDock` erişim kapısı' },
    { title: 'Task 5', detail: 'T5: Regresyon testleri (diff + erişim kapısı)' },
    { title: 'Task 6', detail: 'T6: Doküman güncellemesi' }
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
Plan dosyası: plan-docs/2026-09-05-spec-diff-and-exporter-correctness.md

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
    return await agent(`${_PRE("", "/Volumes/WorkingDisk/Projects/Berth/api-dock/plan-docs/.progress/2026-09-05-spec-diff-and-exporter-correctness-task-1.md")}Task 1: SpecDiffer eksik-taraf ve \`$ref\` sınıflandırması
Agent/Model/Effort: backend / claude-opus-5 / xhigh
Effort talimatı (xhigh): kırmızı çizgi derinliği (auth/permission/tenant/queue/payment sınıfı): regresyon, yetki/tenant sızıntısı ve edge case'lere özellikle dikkat et.

── Plan bölümü (Task 1) — TAM metin; plan dosyasını ayrıca aramana gerek yok ──
### Task 1: SpecDiffer eksik-taraf ve \`$ref\` sınıflandırması
**Agent:** \`backend\` · **Model:** \`opus-5\` · **Effort:** xhigh · **Executor:** \`claude\`
**Scope-paths:** \`src/Support/SpecDiffer.php\`

> Agent/Model/Effort gerekçesi: tek dosya ama davranış sözleşmesinin yeniden kurulması — on bir ayrı
> sınıflandırma hatası aynı yapısal kökten geliyor, her düzeltme diğerlerinin sınıfını etkiliyor;
> \`main\` tier + xhigh. Codex şeridi kapalı, kod Claude tarafında yazılır.

#### Amaç
\`sync --check\` kapısının sessiz kalmasını bitirmek: bir tarafta olup diğerinde olmayan her schema
anahtarı görünür bir change üretmeli, \`$ref\` kimliği karşılaştırılmalı, şema varyantları pozisyona
göre değil imzaya göre eşleşmeli ve yön (request/response) sınıflandırmaya girmeli.

#### To-Do
- Unify absent-side handling in \`compareSchema\`: introduce one helper that both branches route
  through, so a keyword present on exactly one side always produces a change instead of falling
  into \`compareCosmeticFields\`. This closes: added \`enum\`, added \`maxLength\`/\`minimum\`/\`maxItems\`
  and OpenAPI 3.0 boolean \`exclusiveMinimum\`, \`additionalProperties\` boolean flips, and a removed
  \`items\`. Narrow the terminal cosmetic ignore list to the keys that are genuinely cosmetic.
- Compare \`$ref\` identity: a changed schema reference (\`.../UserFull\` → \`.../UserSlim\`) is a
  change on its own, not \`cosmetic\`. Apply the same to \`parameterKey\`, which currently keys a
  \`$ref\` parameter by its reference string — resolve the reference before keying so a swapped
  parameter is compared by its resolved \`name\`/\`in\`/\`required\`.
- Replace the positional alignment in \`compareSchemaVariants\` (\`allOf\`/\`anyOf\`/\`oneOf\`) with
  signature matching (\`$ref\` string, else \`type\` + shape), so reordering is not a change and a
  swapped variant is.
- Visit keys present only on the \`before\` side in \`compareEmbeddedSchemas\` (it currently iterates
  \`array_intersect(array_keys(...))\`), so a response that loses its \`content\` block or changes
  media type is reported; stop ignoring \`content\`/\`headers\` unconditionally.
- Walk the rest of \`components\` in \`compareComponents\` (currently only \`components.schemas\`), so a
  \`securitySchemes\` change (\`http\`/\`bearer\` → \`apiKey\`) and a changed \`components.parameters\`
  entry are classified rather than swallowed; classify a document-level \`servers\` change and make
  \`compareAuth\` order-insensitive over the \`security\` list.
- Bind direction to \`$context\` where severity is decided: on a response, a widened type and an
  added enum value break consumers and must not be \`additive\`; a removed path parameter is not
  \`additive\` either — the document becomes invalid when a template variable loses its parameter.

#### Expected Output
- \`src/Support/SpecDiffer.php\` classifying every case above, with the existing stable change-type
  slugs preserved and new slugs added only where no existing one fits.
- The class docblock's slug list updated to match.

#### Verification
- \`vendor/bin/pest --filter=SpecDiffer\`
- \`vendor/bin/phpstan analyse src/Support/SpecDiffer.php --no-progress\`

#### Risk Notes
- \`sync --check\` çıkış kodu tüketici CI'larında davranış değiştirir: daha önce yeşil geçen bir
  spec değişikliği artık kırıcı sayılabilir. Bu düzeltmenin amacı bu, ama sürüm notunda açıkça
  duyurulmalı (Task 6).
- Mevcut testler yalnız pinlenen vakaları kapsıyor; bu görevin çıktısı Task 5'in regresyon
  testleriyle birlikte değerlendirilmeli.

── Plan bölümü sonu ──` + _depBlock(_deps), { agentType: 'bosun:backend-engineer', model: 'opus', effort: 'xhigh', label: "Task 1: SpecDiffer eksik-taraf ve `$ref` sınıflandırması · backend opus/xhigh", phase: 'Task 1' })
      .then((report) => ({ task: 1, title: "SpecDiffer eksik-taraf ve `$ref` sınıflandırması", agent: "backend", report }));
  } finally {
    _releaseClaudeLane();
  }
})();

done[2] = (async () => {
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
    phase('Task 2');
    return await agent(`${_PRE("", "/Volumes/WorkingDisk/Projects/Berth/api-dock/plan-docs/.progress/2026-09-05-spec-diff-and-exporter-correctness-task-2.md")}Task 2: Exporter'larda ortak \`$ref\` çözümü
Agent/Model/Effort: backend / claude-opus-5 / high
Effort talimatı (high): standart derinlik: recursive cascade, regresyon ve edge case'leri gözet.

── Plan bölümü (Task 2) — TAM metin; plan dosyasını ayrıca aramana gerek yok ──
### Task 2: Exporter'larda ortak \`$ref\` çözümü
**Agent:** \`backend\` · **Model:** \`opus-5\` · **Effort:** high · **Executor:** \`claude\`
**Scope-paths:** \`src/Export/**\`, \`tests/Unit/McpToolExporterTest.php\`, \`tests/Unit/LlmsTxtExporterTest.php\`

> Agent/Model/Effort gerekçesi: iki dosya arası ortak soyutlama çıkarma + davranış düzeltmesi,
> çapraz kesen ama kırmızı çizgi değil → \`main\` tier / high. Codex şeridi kapalı, Claude yazar.

#### Amaç
İki exporter'ın \`$ref\` konusunda ayrışmasını bitirmek. \`llms.txt\` şu an \`$ref\` parametreleri için
"No parameters." basıyor ve şema referanslarını ham yazıyor — ajana hiçbir şekil bilgisi gitmiyor,
README'nin vaadiyle çelişiyor.

#### To-Do
- Extract the duplicated helpers (\`HTTP_METHODS\`, \`operations()\`, \`stringMap()\`, \`listValue()\`,
  \`stringList()\`/\`nonEmptyString()\`) and the \`$ref\` resolution into one shared support class under
  \`src/Export/\`, and have both exporters use it. The two files diverged precisely inside this
  duplication.
- Resolve parameter and schema \`$ref\`s in \`LlmsTxtExporter\` through that shared resolver, so a
  \`$ref\` parameter renders with its \`name\`/\`in\`/\`required\` and a \`$ref\` response renders the
  resolved shape instead of a fenced \`{"$ref": "..."}\` block.
- Memoise resolution in the shared resolver: \`resolveSchemaNode\` currently inlines each \`$ref\`
  independently, so a reused schema graph expands exponentially (measured on a ~1 KB source:
  depth 6 → 7.5 KB, depth 12 → 483 KB). Return an empty JSON Schema object, not \`[]\`, for a cycle
  or an unresolvable reference — \`[]\` encodes as a JSON array and produces \`"items": []\`.
- Fix the \`McpToolExporter\` defects the resolver does not cover: \`stringMap\` drops integer keys, so
  a body property named \`"2"\` disappears from \`properties\` while still being listed in \`required\`;
  a composed (\`allOf\`) body collapses into a single nested \`body\` property instead of the spread
  README describes; tool names are emitted verbatim with no sanitisation or length cap against the
  MCP tool-name pattern (\`App\\Http\\Controllers\\UserController@index\` currently passes through).
- Extend \`tests/Unit/McpToolExporterTest.php\` and \`tests/Unit/LlmsTxtExporterTest.php\` for each
  case above. Assert the ENCODED artifact (\`json_encode(...)\`) wherever the defect is a JSON shape
  question — the existing empty-\`properties\` test passed for a year while the artifact was wrong
  because it asserted the PHP array.

#### Expected Output
- One shared exporter support class; both exporters delegating to it, with no duplicated helper
  bodies left.
- \`llms.txt\` output that resolves parameter and schema references.
- MCP tool names conforming to the tool-name pattern, integer-keyed body properties preserved,
  \`allOf\` bodies spread.

#### Verification
- \`vendor/bin/pest --filter="McpToolExporter|LlmsTxtExporter|ExportCommand"\`
- \`vendor/bin/phpstan analyse src/Export --no-progress\`

#### Risk Notes
- Tool adı sanitizasyonu mevcut export'lardaki adları değiştirebilir; MCP istemcisi aracı adıyla
  çağırdığı için bu tüketici tarafında kırıcı. Yalnız pattern'e uymayan adlar dönüştürülmeli,
  uyanlar aynen kalmalı.
- \`$ref\` çözümü \`llms.txt\` çıktısını büyütür; memoization olmadan büyük şema grafiklerinde bellek
  sorunu çıkar — memoization bu görevin parçası, sonraya bırakılamaz.

── Plan bölümü sonu ──` + _depBlock(_deps), { agentType: 'bosun:backend-engineer', model: 'opus', effort: 'high', label: "Task 2: Exporter'larda ortak `$ref` çözümü · backend opus/high", phase: 'Task 2' })
      .then((report) => ({ task: 2, title: "Exporter'larda ortak `$ref` çözümü", agent: "backend", report }));
  } finally {
    _releaseClaudeLane();
  }
})();

done[3] = (async () => {
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
    phase('Task 3');
    return await agent(`${_PRE("", "/Volumes/WorkingDisk/Projects/Berth/api-dock/plan-docs/.progress/2026-09-05-spec-diff-and-exporter-correctness-task-3.md")}Task 3: Try-it self host port kontrolü
Agent/Model/Effort: security / claude-opus-5 / xhigh
Effort talimatı (xhigh): kırmızı çizgi derinliği (auth/permission/tenant/queue/payment sınıfı): regresyon, yetki/tenant sızıntısı ve edge case'lere özellikle dikkat et.

── Plan bölümü (Task 3) — TAM metin; plan dosyasını ayrıca aramana gerek yok ──
### Task 3: Try-it self host port kontrolü
**Agent:** \`security\` · **Model:** \`opus-5\` · **Effort:** xhigh · **Executor:** \`claude\`
**Scope-paths:** \`src/Support/OutboundRequestGuard.php\`, \`config/api-dock.php\`, \`tests/Feature/ProxySecurityTest.php\`

> Agent/Model/Effort gerekçesi: SSRF guard'ın sınırını değiştiriyor — kırmızı çizgi, \`security\`
> ajanı + \`main\` tier / xhigh; kırmızı çizgi olduğu için Codex şeridine hiç girmez.

#### Amaç
\`app.url\` standart olmayan bir port taşıdığında (\`http://localhost:8000\`, Docker \`:8080\`) try-it'in
kendi uygulamasına ulaşamamasını gidermek, port allowlist'ini yabancı hostlar için sınır olarak
korumak.

#### To-Do
- Derive the application's own port from \`app.url\` and accept it for the self host only, alongside
  \`try_it.allowed_ports\`. A foreign host keeps the unchanged allowlist: the port check is the only
  boundary left on a self host (the allowlist and the private-address check are already bypassed
  there), so it is narrowed, never removed.
- Derive the port from the configured \`app.url\` and from \`try_it.self_hosts\` entries that carry
  one — never from the request's Host header, matching how the self host itself is resolved.
- Cover in \`tests/Feature/ProxySecurityTest.php\`: self host on the \`app.url\` port is allowed; the
  same self host on a different non-allowlisted port is denied; a foreign host on the \`app.url\`
  port is denied; an \`app.url\` with no explicit port changes nothing.

#### Expected Output
- \`src/Support/OutboundRequestGuard.php\` accepting the application's own port for the self host.
- Config comment on \`try_it.allowed_ports\` stating that the self host's own port is added
  automatically.

#### Verification
- \`vendor/bin/pest --filter="ProxySecurity|ProxyController"\`

#### Risk Notes
- Yanlış uygulanırsa port allowlist'i yabancı hostlar için de gevşer ve guard bir port tarayıcıya
  dönüşür — testin negatif tarafı (yabancı host, aynı port, reddedilir) zorunlu.
- \`app.url\` yapılandırması hatalı bir uygulamada (ör. \`APP_URL\` production'da localhost kalmış)
  türetilen port beklenmedik olabilir; port yalnız self host eşleşmesiyle birlikte geçerli olmalı.

── Plan bölümü sonu ──` + _depBlock(_deps), { agentType: 'bosun:security-auditor', model: 'opus', effort: 'xhigh', label: "Task 3: Try-it self host port kontrolü · security opus/xhigh", phase: 'Task 3' })
      .then((report) => ({ task: 3, title: "Try-it self host port kontrolü", agent: "security", report }));
  } finally {
    _releaseClaudeLane();
  }
})();

done[4] = (async () => {
  const _deps = (await Promise.all([done[3]])).filter(Boolean);
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
    phase('Task 4');
    return await agent(`${_PRE("", "/Volumes/WorkingDisk/Projects/Berth/api-dock/plan-docs/.progress/2026-09-05-spec-diff-and-exporter-correctness-task-4.md")}Task 4: Opsiyonel \`viewApiDock\` erişim kapısı
Agent/Model/Effort: security / claude-opus-5 / xhigh
Effort talimatı (xhigh): kırmızı çizgi derinliği (auth/permission/tenant/queue/payment sınıfı): regresyon, yetki/tenant sızıntısı ve edge case'lere özellikle dikkat et.

── Plan bölümü (Task 4) — TAM metin; plan dosyasını ayrıca aramana gerek yok ──
### Task 4: Opsiyonel \`viewApiDock\` erişim kapısı
**Agent:** \`security\` · **Model:** \`opus-5\` · **Effort:** xhigh · **Executor:** \`claude\`
**Depends-on:** Task 3
**Scope-paths:** \`src/Http/Middleware/ApiDockAccess.php\`, \`config/api-dock.php\`, \`src/ApiDockServiceProvider.php\`
**Merge-ack:** aynı config dosyasına dokundukları için sıralandı; birleştirilemez — biri SSRF port sınırı, diğeri auth kapısı, birleşik To-Do dokuz maddeye ve yedi dosyaya çıkar.

> Agent/Model/Effort gerekçesi: kimin paneli ve try-it proxy'sini kullanabileceğini belirleyen auth
> kapısı — kırmızı çizgi, \`security\` ajanı + \`main\` tier / xhigh; Codex şeridine girmez.

#### Amaç
Panelin ve try-it proxy'sinin kim tarafından kullanılabileceğini uygulamanın belirleyebilmesi.
Kullanıcı kararı: varsayılan kapalı (\`false\`), mevcut hiçbir kurulum kırılmaz.

#### To-Do
- Add \`gate.enabled\` (default \`false\`) to \`config/api-dock.php\`, documented as: when true, every
  API Dock route requires the \`viewApiDock\` Gate to pass.
- Register a permissive default \`viewApiDock\` Gate ability in the service provider only when the
  host application has not defined one, so enabling the config in an app with no ability defined
  fails CLOSED (denies) rather than silently allowing everything. State that in the config comment.
- Enforce it in \`ApiDockAccess\`: when \`gate.enabled\` is true and the Gate denies, respond exactly
  as the disabled package does today (404, so the surface is not even confirmed to exist) — keep
  the existing 404-when-disabled behaviour unchanged for the config-off path.
- Add a README section showing \`Gate::define('viewApiDock', fn ($user) => $user->isAdmin())\` and
  stating that the gate is off by default and the panel is otherwise reachable by anyone who can
  reach the route.

#### Expected Output
- \`config/api-dock.php\` carrying \`gate.enabled\`.
- \`ApiDockAccess\` denying via the Gate when enabled, unchanged when not.
- README section on the gate.

#### Verification
- \`vendor/bin/pest --filter="PackageSetup|ProxyController"\`

#### Risk Notes
- Kapı açıkken ability tanımlı değilse davranış fail-closed olmalı; fail-open bir varsayılan, kapıyı
  açtığını sanan kurulumu koruma altında olduğu yanılgısıyla bırakır.
- Reddedilen istekte 403 yerine 404 dönmek bilinçli: paketin zaten kapalıyken verdiği yanıtla aynı,
  yüzeyin varlığını doğrulamaz.

── Plan bölümü sonu ──` + _depBlock(_deps), { agentType: 'bosun:security-auditor', model: 'opus', effort: 'xhigh', label: "Task 4: Opsiyonel `viewApiDock` erişim kapısı · security opus/xhigh", phase: 'Task 4' })
      .then((report) => ({ task: 4, title: "Opsiyonel `viewApiDock` erişim kapısı", agent: "security", report }));
  } finally {
    _releaseClaudeLane();
  }
})();

done[5] = (async () => {
  const _deps = (await Promise.all([done[1], done[4]])).filter(Boolean);
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
    phase('Task 5');
    return await agent(`${_PRE("", "/Volumes/WorkingDisk/Projects/Berth/api-dock/plan-docs/.progress/2026-09-05-spec-diff-and-exporter-correctness-task-5.md")}Task 5: Regresyon testleri (diff + erişim kapısı)
Agent/Model/Effort: backend / claude-sonnet-5 / high
Effort talimatı (high): standart derinlik: recursive cascade, regresyon ve edge case'leri gözet.

── Plan bölümü (Task 5) — TAM metin; plan dosyasını ayrıca aramana gerek yok ──
### Task 5: Regresyon testleri (diff + erişim kapısı)
**Agent:** \`backend\` · **Model:** \`sonnet-5\` · **Effort:** high · **Executor:** \`claude\`
**Depends-on:** Task 1, Task 4
**Scope-paths:** \`tests/Unit/SpecDifferTest.php\`, \`tests/Feature/PackageSetupTest.php\`

> Agent/Model/Effort gerekçesi: kenar durum ağırlıklı davranışsal test yazımı — üreten görevden ayrı
> ajan, yoksa test uygulamaya doğru bükülür; \`base\` tier ama davranışsal olduğu için high.
> Codex şeridi kapalı, Claude yazar.

#### Amaç
Task 1 ve Task 4'ün kapattığı her sınıf için, üreten görevden bağımsız bir gözle yazılmış regresyon
testi. Mevcut suite yalnız pinlenen vakaları kapsıyor; kapanan sekiz sessiz vaka test edilmezse
aynı boşluk yeniden açılır.

#### To-Do
- Write one \`SpecDifferTest\` case per silent-failure class closed by Task 1, each asserting the
  reported severity AND the change type (not merely \`has_breaking\`): added \`enum\`, added
  \`maxLength\`/\`minimum\`, \`additionalProperties\` \`true\` → \`false\`, removed \`items\`, a response
  losing its \`content\` block, a changed media type, a swapped schema \`$ref\`, a changed
  \`securitySchemes\`, a \`$ref\` parameter becoming required, a removed path parameter, a changed
  \`servers\` entry.
- Write the false-positive cases too: a reordered \`oneOf\` list and a reordered \`security\` list are
  NOT breaking; a swapped \`oneOf\` variant is.
- Write the direction cases: on a response, a widened type and an added enum value are breaking; on
  a request they stay additive.
- Cover the gate in \`tests/Feature/PackageSetupTest.php\`: gate off (default) leaves every route
  reachable; gate on with a denying ability yields 404 on the docs route and on the try-it proxy;
  gate on with an allowing ability leaves them reachable; gate on with no ability defined denies.

#### Expected Output
- \`tests/Unit/SpecDifferTest.php\` extended with the cases above.
- \`tests/Feature/PackageSetupTest.php\` extended with the four gate cases.

#### Verification
- \`vendor/bin/pest --filter="SpecDiffer|PackageSetup"\`

#### Risk Notes
- Test yazarı Task 1'in uygulamasını okumadan, bulgunun tarif ettiği girdi şeklinden yola çıkmalı;
  aksi hâlde bağımsız göz kaybolur.

── Plan bölümü sonu ──` + _depBlock(_deps), { agentType: 'bosun:backend-engineer', model: 'sonnet', effort: 'high', label: "Task 5: Regresyon testleri (diff + erişim kapısı) · backend sonnet/high", phase: 'Task 5' })
      .then((report) => ({ task: 5, title: "Regresyon testleri (diff + erişim kapısı)", agent: "backend", report }));
  } finally {
    _releaseClaudeLane();
  }
})();

done[6] = (async () => {
  const _deps = (await Promise.all([done[1], done[2], done[3], done[4]])).filter(Boolean);
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
    phase('Task 6');
    return await agent(`${_PRE("", "/Volumes/WorkingDisk/Projects/Berth/api-dock/plan-docs/.progress/2026-09-05-spec-diff-and-exporter-correctness-task-6.md")}Task 6: Doküman güncellemesi
Agent/Model/Effort: docs / claude-sonnet-5 / medium
Effort talimatı (medium): taban derinlik: task scope'una sadık kal.

── Plan bölümü (Task 6) — TAM metin; plan dosyasını ayrıca aramana gerek yok ──
### Task 6: Doküman güncellemesi
**Agent:** \`docs\` · **Model:** \`sonnet-5\` · **Effort:** medium · **Executor:** \`claude\`
**Depends-on:** Task 1, Task 2, Task 3, Task 4
**Scope-paths:** \`README.md\`, \`docs/**\`

> Agent/Model/Effort gerekçesi: yalnız düzyazı, mevcut README yapısına oturuyor — \`base\` tier /
> medium. Codex şeridi kapalı, Claude yazar.

#### Amaç
Değişen davranışın dokümana geçmesi; özellikle \`sync --check\` sonuçlarının artık farklı çıkması
tüketici CI'larını etkiliyor ve duyurulmadan bırakılamaz.

#### To-Do
- Update the \`sync --check\` section: list the change classes now detected that were previously
  silent, and warn that a consumer CI which was green may now fail — this is the fix, not a
  regression.
- Update the \`llms.txt\` and MCP export sections for resolved \`$ref\`s, spread \`allOf\` bodies and
  sanitised tool names.
- Update the try-it section: the application's own port is accepted automatically for the self
  host, foreign hosts still bound by \`try_it.allowed_ports\`.
- Add the \`gate.enabled\` row to the configuration table and cross-link it to the gate section Task
  4 adds; state that it is off by default.
- Add a \`CHANGELOG.md\` entry describing all of the above under an Unreleased heading (the repo has
  none yet — create it with that single entry, do not backfill history).

#### Expected Output
- \`README.md\` updated in the four places above.
- \`CHANGELOG.md\` with one Unreleased entry.

#### Verification
- Diff read: every configuration key named in the prose exists in \`config/api-dock.php\`, and every
  command shown resolves against \`src/Console/\`.

#### Risk Notes
- README'nin config tablosu "bunlar shipped edilen tüm anahtarlar" diyor; yeni anahtar eklenirken
  tablonun eksiksizliği korunmalı.

── Plan bölümü sonu ──` + _depBlock(_deps), { agentType: 'bosun:docs-writer', model: 'sonnet', effort: 'medium', label: "Task 6: Doküman güncellemesi · docs sonnet/medium", phase: 'Task 6' })
      .then((report) => ({ task: 6, title: "Doküman güncellemesi", agent: "docs", report }));
  } finally {
    _releaseClaudeLane();
  }
})();

// Tüm unit raporlarını task sırasında topla → orchestrator entegrasyon/doğrulama için görsün.
const _reports = (await Promise.all([done[1], done[2], done[3], done[4], done[5], done[6]])).filter(Boolean);
if (_aborted) return { aborted: true, completed: _reports };
log(`6 task / 6 agent tamamlandı — ${_reports.length} rapor toplandı.`);
return _reports;
