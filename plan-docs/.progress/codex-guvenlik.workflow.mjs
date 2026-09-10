export const meta = {
  name: '2026-09-10-try-it-codex-guvenlik-bulgulari',
  description: 'Plan 2026-09-10-try-it-codex-guvenlik-bulgulari — 4 task / 4 agent (Depends-on promise-DAG).',
  phases: [
    { title: 'Task 1', detail: 'T1: Try-it depolamasını kimliğe bağla' },
    { title: 'Task 2', detail: 'T2: Form girdisi ve yanıt kopyasından secret’ı ayıkla' },
    { title: 'Task 3', detail: 'T3: Subdomain muafiyetini adres kümesine daralt' },
    { title: 'Task 4', detail: 'T4: Ortam proxy’sini kapat, `try_it.proxy` ile açık seçim' }
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
Plan dosyası: plan-docs/2026-09-10-try-it-codex-guvenlik-bulgulari.md

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
    return await agent(`${_PRE("", "/Volumes/WorkingDisk/Projects/Berth/api-dock/plan-docs/.progress/2026-09-10-try-it-codex-guvenlik-bulgulari-task-1.md")}Task 1: Try-it depolamasını kimliğe bağla
Agent/Model/Effort: frontend / claude-opus-5 / high
Effort talimatı (high): standart derinlik: recursive cascade, regresyon ve edge case'leri gözet.

── Plan bölümü (Task 1) — TAM metin; plan dosyasını ayrıca aramana gerek yok ──
### Task 1: Try-it depolamasını kimliğe bağla
**Agent:** \`frontend\` · **Model:** \`opus-5\` · **Effort:** high · **Executor:** \`claude\`
**Oversize-ack:** dosya sayısı tek bir prop-threading zincirinin uzunluğu (blade → main.ts → App.vue → TryItPanel → iki store); bölmek attribute'u okuyan ama kullanmayan yarım ara durum üretir, her dosyaya değişiklik 3-10 satır.

> Agent/Model/Effort gerekçesi: blade → main.ts → App → TryItPanel → iki depolama modülü boyunca tek bir kimlik damgasının taşınması; çok dosyalı ama deseni net, \`main\`/\`high\`; şerit kapalı, \`claude\`.

#### Amaç
Bir hesabın try-it geçmişi aynı tarayıcıdaki başka bir hesaba görünmesin: her iki depo sunucunun verdiği kimlik damgasını taşısın, damga uyuşmazsa kayıt okunmadan silinsin.

#### To-Do
- \`resources/views/docs.blade.php\`: emit \`data-identity="{{ ... }}"\` — \`substr(hash_hmac('sha256', (string) Auth::id(), (string) config('app.key')), 0, 16)\` when a user is authenticated, \`''\` for a guest. Never the raw id; read \`Auth::id()\` per request the way \`AuthProfileStore\` does.
- \`resources/js/main.ts\` + \`resources/js/App.vue\`: read \`dataset.identity\` (default \`''\`) and pass it to \`TryItPanel\` as prop \`identity: string\`.
- \`resources/js/lib/tryItSession.ts\` and \`resources/js/lib/tryItResponses.ts\`: add \`identity\` to the stored envelope; export \`bindIdentity(identity: string)\` which sets the module's current identity and, if the stored envelope's identity differs (or is absent — an older envelope), removes the storage key and resets to defaults. Every later read/write is stamped with the bound identity. Guest \`''\` matches only guest.
- \`resources/js/components/TryItPanel.vue\`: call \`bindIdentity(props.identity)\` for both modules at setup, before the first restore.
- Tests: \`resources/js/__tests__/tryItSession.spec.ts\` — envelope written under identity \`a\` is removed when bound to \`b\`; envelope without \`identity\` is removed. New \`resources/js/__tests__/tryItResponses.spec.ts\` — same two cases for the response store.

#### Expected Output
- \`data-identity\` attribute in the blade mount, HMAC-derived.
- Both stores stamp and check \`identity\`; mismatch purges.
- Four new vitest cases green.

#### Verification
- \`npx vitest run resources/js/__tests__/tryItSession.spec.ts resources/js/__tests__/tryItResponses.spec.ts\`

#### Risk Notes
- Kimlik damgası \`Auth::id()\`'ye bağlı; guest'ler arası geçmiş bugünkü gibi paylaşılır (aşılacak auth sınırı yok).
- İlk yüklemede eski envelope silinir: kullanıcı bir kez geçmişini kaybeder, sonra kalıcı.

── Plan bölümü sonu ──` + _depBlock(_deps), { agentType: 'bosun:frontend-engineer', model: 'opus', effort: 'high', label: "Task 1: Try-it depolamasını kimliğe bağla · frontend opus/high", phase: 'Task 1' })
      .then((report) => ({ task: 1, title: "Try-it depolamasını kimliğe bağla", agent: "frontend", report }));
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
    return await agent(`${_PRE("", "/Volumes/WorkingDisk/Projects/Berth/api-dock/plan-docs/.progress/2026-09-10-try-it-codex-guvenlik-bulgulari-task-3.md")}Task 3: Subdomain muafiyetini adres kümesine daralt
Agent/Model/Effort: security / claude-opus-5 / xhigh
Effort talimatı (xhigh): kırmızı çizgi derinliği (auth/permission/tenant/queue/payment sınıfı): regresyon, yetki/tenant sızıntısı ve edge case'lere özellikle dikkat et.

── Plan bölümü (Task 3) — TAM metin; plan dosyasını ayrıca aramana gerek yok ──
### Task 3: Subdomain muafiyetini adres kümesine daralt
**Agent:** \`security\` · **Model:** \`opus-5\` · **Effort:** xhigh · **Executor:** \`claude\`

> Agent/Model/Effort gerekçesi: try-it proxy'nin dış ağ güven sınırı — SSRF, kırmızı çizgi; "hangi alt alan adı iç ağa ulaşabilir" her tüketici topolojisinde doğru olmak zorunda, \`xhigh\`; şerit kapalı, \`claude\`.

#### Amaç
Alt alan adı yalnızca uygulamanın kendisine (aynı adreslere) baktığında iç adres muafiyeti alsın; başka bir iç adrese bakan alt alan adı yabancı host gibi denetlensin.

#### To-Do
- \`src/Support/OutboundRequestGuard.php\` — \`matchSelfHost()\`: return the match KIND — \`exact\` (host equals an entry) or \`descendant\` (host ends with \`.entry\`) — plus the matched entry host, alongside \`ports\`; the one-direction rule and port carry-over stay unchanged.
- \`inspect()\` address gate: \`exact\` keeps today's behaviour (skip \`isDeniedAddress\`). For \`descendant\`, resolve the matched entry host once via \`resolveHost\` and grant the skip only when every resolved address of the target is contained in the entry's resolved address set; otherwise apply \`isDeniedAddress\` as for a foreign host. The allowlist skip stays for both kinds. Denial message names the host only, never addresses. An IP-literal entry has no descendants (already true; assert it).
- \`config/api-dock.php\` \`self_hosts\` comment: a subdomain of an entry is trusted only while it resolves to the same addresses as the entry; a subdomain pointing elsewhere is checked like a foreign host.
- Tests in \`tests/Feature/ProxySecurityTest.php\` with the injected resolver: (1) \`app.url = https://example.com\` → \`203.0.113.10\`; \`internal.example.com\` → \`10.0.0.5\` DENIED; (2) \`tenant.example.com\` → \`203.0.113.10\` ALLOWED without an \`allowed_hosts\` entry; (3) \`app.url = http://localhost:8000\` → \`127.0.0.1\`, \`tenant.localhost:8000\` → \`127.0.0.1\` ALLOWED; (4) a subdomain resolving to \`203.0.113.10\` AND \`10.0.0.5\` DENIED; (5) update the existing \`reaches a tenant subdomain of the application host with no allowlist entry\` case so the tenant resolves to the same address as \`app.url\` — read its resolver stub first, do not delete it.

#### Expected Output
- \`matchSelfHost()\` returns a match kind + entry; \`inspect()\` grants the private-address exemption to a descendant only inside the entry's own address set.
- Config comment updated; five Pest cases green.

#### Verification
- \`vendor/bin/pest tests/Feature/ProxySecurityTest.php\`

#### Risk Notes
- Alt alan adı uygulamayla aynı sunucuya değil de CDN/başka bir public IP'ye bakıyorsa artık adres-sınıfı kapısından geçer; public adrese yine ulaşır, yalnızca iç adres reddedilir. Kırılma yalnız "alt alan adım iç IP'ye bakıyor" kurulumunda — o da bulgunun ta kendisi.
- Ek DNS çözümlemesi yalnız \`descendant\` eşleşmesinde, istek başına bir kez.

── Plan bölümü sonu ──` + _depBlock(_deps), { agentType: 'bosun:security-auditor', model: 'opus', effort: 'xhigh', label: "Task 3: Subdomain muafiyetini adres kümesine daralt · security opus/xhigh", phase: 'Task 3' })
      .then((report) => ({ task: 3, title: "Subdomain muafiyetini adres kümesine daralt", agent: "security", report }));
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
    return await agent(`${_PRE("", "/Volumes/WorkingDisk/Projects/Berth/api-dock/plan-docs/.progress/2026-09-10-try-it-codex-guvenlik-bulgulari-task-2.md")}Task 2: Form girdisi ve yanıt kopyasından secret'ı ayıkla
Agent/Model/Effort: frontend / claude-opus-5 / xhigh
Effort talimatı (xhigh): kırmızı çizgi derinliği (auth/permission/tenant/queue/payment sınıfı): regresyon, yetki/tenant sızıntısı ve edge case'lere özellikle dikkat et.

── Plan bölümü (Task 2) — TAM metin; plan dosyasını ayrıca aramana gerek yok ──
### Task 2: Form girdisi ve yanıt kopyasından secret'ı ayıkla
**Agent:** \`frontend\` · **Model:** \`opus-5\` · **Effort:** xhigh · **Executor:** \`claude\`
**Depends-on:** Task 1
**Oversize-ack:** sınıflandırıcı (session), maskeleyici (token) ve tüketici (panel) aynı sözleşmeyi paylaşıyor; ayrı task'lara bölünürse ilk task'ın çıktısı ikinci gelene kadar hiçbir şeyi korumaz. Testler değişen üç modülün kendi spec dosyaları.

> Agent/Model/Effort gerekçesi: hangi alanın secret sayılacağı ve depolanan yanıt kopyasında neyin maskeleneceği kırmızı çizgi kararı, \`xhigh\`; Task 1 ile aynı dosyalara dokunur, sıralı; şerit kapalı, \`claude\`.

#### Amaç
Form girdisi ve yanıt geçmişi yenilemede kalmaya devam etsin ama depolanan kopyada hiçbir parola/token bulunmasın; ekrandaki canlı yanıt değişmesin.

#### To-Do
- \`resources/js/lib/tryItToken.ts\`: next to \`extractToken\`, add \`redactTokens(body: string): string\` — same \`TOKEN_KEYS\`, same depth walk (≤ 4); every matching string value becomes \`"***"\`; a body that is not JSON is returned unchanged. Add \`isSecretKey(name: string): boolean\` covering \`TOKEN_KEYS\` plus \`password\`, \`passwd\`, \`secret\`, \`client_secret\`, \`api_key\`, \`apiKey\`, \`refresh_token\`, \`private_key\` (case-insensitive).
- \`resources/js/lib/tryItSession.ts\`: export a pure \`classifyInputs(inputs, parameters, securitySchemes)\` used before persisting. A parameter is dropped when \`in === 'header'\`, when its schema has \`format: 'password'\` or \`writeOnly: true\`, or when its name equals an apiKey security scheme \`name\`. The body is stored as \`''\` (not partially redacted — a half body restores as a broken request) when it parses as JSON and any key at depth ≤ 4 satisfies \`isSecretKey\`.
- \`resources/js/components/TryItPanel.vue\`: route the \`setOperationInputs\` watcher through \`classifyInputs\` with the operation's parameters and \`document.components?.securitySchemes\`.
- \`resources/js/lib/tryItResponses.ts\`: in \`setStoredResponse\`, write \`redactTokens(body)\` and a headers copy with \`set-cookie\`, \`authorization\` and any header name containing \`token\` removed. The in-memory value handed back to the panel stays the live response.
- Mark the name-list ceiling with a \`ponytail:\` comment (an unschema'd password field is caught by name only).
- Tests: \`resources/js/__tests__/tryItToken.spec.ts\` — \`redactTokens\` masks nested \`access_token\`, leaves non-JSON alone; \`resources/js/__tests__/TryItPanel.spec.ts\` — a \`format: password\` body field and an \`in: header\` parameter never reach \`localStorage\`; a login body with \`password\` is stored as \`''\` while a search body is stored verbatim; a response with \`access_token\` is stored as \`"***"\` and the on-screen response still shows the real value.
- Rebuild: \`npm run build\` (dist is committed).

#### Expected Output
- \`redactTokens\` / \`isSecretKey\` in \`tryItToken.ts\`, \`classifyInputs\` in \`tryItSession.ts\`, both pure and exported.
- Stored request/response copies carry no secret; live UI unchanged.
- Five new vitest cases green; \`resources/dist/api-dock.js|css\` rebuilt.

#### Verification
- \`npx vitest run resources/js/__tests__/tryItToken.spec.ts resources/js/__tests__/TryItPanel.spec.ts resources/js/__tests__/tryItSession.spec.ts\`

#### Risk Notes
- Login gövdesi yenilemede geri gelmez (boş kaydedilir) — bulgunun kendisi; parola için yol kimlik bilgisi profili.
- \`format: password\` / \`writeOnly\` şemaya bağlı; şemasız alan yalnızca ad eşleşmesiyle yakalanır. Ad listesi kaçırırsa değer yazılır — bilinçli ceiling.

── Plan bölümü sonu ──` + _depBlock(_deps), { agentType: 'bosun:frontend-engineer', model: 'opus', effort: 'xhigh', label: "Task 2: Form girdisi ve yanıt kopyasından secret'ı ayıkla · frontend opus/xhigh", phase: 'Task 2' })
      .then((report) => ({ task: 2, title: "Form girdisi ve yanıt kopyasından secret'ı ayıkla", agent: "frontend", report }));
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
    return await agent(`${_PRE("", "/Volumes/WorkingDisk/Projects/Berth/api-dock/plan-docs/.progress/2026-09-10-try-it-codex-guvenlik-bulgulari-task-4.md")}Task 4: Ortam proxy'sini kapat, \`try_it.proxy\` ile açık seçim
Agent/Model/Effort: security / claude-opus-5 / high
Effort talimatı (high): standart derinlik: recursive cascade, regresyon ve edge case'leri gözet.

── Plan bölümü (Task 4) — TAM metin; plan dosyasını ayrıca aramana gerek yok ──
### Task 4: Ortam proxy'sini kapat, \`try_it.proxy\` ile açık seçim
**Agent:** \`security\` · **Model:** \`opus-5\` · **Effort:** high · **Executor:** \`claude\`
**Depends-on:** Task 3
**Oversize-ack:** altı dosyanın ikisi tek satırlık doküman aynası (config yorumu, README paragrafı), biri pint; kod değişikliği tek metodda (\`send()\`) ve tek test dosyasında.

> Agent/Model/Effort gerekçesi: aynı guard dosyası ve aynı test dosyası (Task 3'ten sonra sıralı); kural net — proxy kapalı, açık seçimde uzaktan çözen şema reddedilir — \`high\` yeter; kırmızı çizgi dosyası, şerit kapalı, \`claude\`.

#### Amaç
\`CURLOPT_RESOLVE\` pini her ortamda tutsun: ambient proxy asla seçilmesin, egress proxy zorunlu kurulum bunu config'de açıkça yazsın.

#### To-Do
- \`src/Support/OutboundRequestGuard.php\` \`send()\`: add \`'proxy' => ''\` to the Guzzle options AND \`CURLOPT_PROXY => ''\` to the curl option array (both, so neither Guzzle's env detection nor curl's own env lookup picks a proxy). When \`config('api-dock.try_it.proxy')\` is a non-empty string, pass it as \`'proxy'\` instead; \`deny()\` a remotely resolving scheme (\`socks5h://\`, \`socks4a://\`) and anything not \`http://\`, \`https://\`, \`socks5://\`, \`socks4://\`.
- \`config/api-dock.php\` \`try_it\`: add \`'proxy' => null\` with a comment: the default disables every ambient proxy for the try-it request because the guard pins the checked address and a remotely resolving proxy would resolve the name again; remote-resolving schemes are refused.
- \`README.md\`: one paragraph under the try-it config section mirroring the two new config comments (self_hosts subdomain rule from Task 3, proxy rule from this task). No new section.
- Tests in \`tests/Feature/ProxySecurityTest.php\` (every new test name contains the word \`proxy\` so the filter below selects them): (1) with \`HTTPS_PROXY\`/\`HTTP_PROXY\` set for the test process, the sent request carries \`proxy === ''\` and curl \`CURLOPT_PROXY === ''\` (assert via the fake HTTP client's recorded options); (2) \`try_it.proxy = 'socks5h://127.0.0.1:1080'\` is denied before any request is sent; (3) \`try_it.proxy = 'http://proxy.test:3128'\` is passed through as the proxy option.
- Run \`vendor/bin/pint\` on the changed PHP files once at the end.

#### Expected Output
- \`send()\` never uses an ambient proxy; explicit \`try_it.proxy\` honoured, remote-resolving schemes refused.
- Config + README document both rules; three Pest cases green.

#### Verification
- \`vendor/bin/pest tests/Feature/ProxySecurityTest.php --filter=proxy\`

#### Risk Notes
- Egress proxy zorunlu kurulumda try-it, \`try_it.proxy\` yazılana kadar dış host'a ulaşamaz (kendi host'u genelde proxy'siz). Config yorumu ve README bunu söylüyor.

── Plan bölümü sonu ──` + _depBlock(_deps), { agentType: 'bosun:security-auditor', model: 'opus', effort: 'high', label: "Task 4: Ortam proxy'sini kapat, `try_it.proxy` ile açık seçim · security opus/high", phase: 'Task 4' })
      .then((report) => ({ task: 4, title: "Ortam proxy'sini kapat, `try_it.proxy` ile açık seçim", agent: "security", report }));
  } finally {
    _releaseClaudeLane();
  }
})();

// Tüm unit raporlarını task sırasında topla → orchestrator entegrasyon/doğrulama için görsün.
const _reports = (await Promise.all([done[1], done[2], done[3], done[4]])).filter(Boolean);
if (_aborted) return { aborted: true, completed: _reports };
log(`4 task / 4 agent tamamlandı — ${_reports.length} rapor toplandı.`);
return _reports;
