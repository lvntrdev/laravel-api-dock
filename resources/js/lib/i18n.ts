import { ref } from 'vue'

const STORAGE_KEY = 'api-dock:locale'

export type Locale = 'tr' | 'en'

export const SUPPORTED_LOCALES = ['tr', 'en'] as const satisfies readonly Locale[]

export const LOCALE_LABELS: Record<Locale, string> = {
  tr: 'Türkçe',
  en: 'English',
}

export const en = {
  // Sidebar
  'sidebar.ariaLabel': 'API operations',
  'sidebar.homeAriaLabel': 'API Dock home',
  'sidebar.brandName': 'API Dock',
  'sidebar.brandSubtitle': 'Spec Explorer',
  'sidebar.searchLabel': 'Search endpoints',
  'sidebar.searchPlaceholder': 'Search path, tag, operation…',
  'sidebar.noMatches': 'No operations match “{query}”.',
  'sidebar.expandGroup': 'Expand {tag}',
  'sidebar.collapseGroup': 'Collapse {tag}',
  'sidebar.openapiVersion': 'OpenAPI {version}',
  'sidebar.untagged': 'Untagged',
  'sidebar.unversioned': 'unversioned',

  // Application shell
  'shell.loadingKicker': 'Reading contract',
  'shell.loadingTitle': 'Charting your API…',
  'shell.loadingDescription': 'Fetching {url}',
  'shell.invalidSpec': 'The spec response is not a valid OpenAPI document.',
  'shell.loadError': 'The API spec could not be loaded.',
  'shell.errorKicker': 'Spec unavailable',
  'shell.errorTitle': 'We lost the signal.',
  'shell.retry': 'Try again',
  'shell.emptyKicker': 'Empty contract',
  'shell.emptyTitle': 'No operations found.',
  'shell.emptyDescription': 'The document loaded, but its paths object has no operations.',
  'shell.language': 'Language',
  'shell.theme': 'Theme',
  'shell.lightTheme': 'Light theme',
  'shell.darkTheme': 'Dark theme',

  // Operation tabs
  'tabs.parameters': 'Parameters',
  'tabs.requestBody': 'Request body',
  'tabs.responses': 'Responses',
  'tabs.tryIt': 'Try it',
  'tabs.aiPrompt': 'AI prompt',
  'tabs.specDiff': 'Spec diff',

  // Operation detail
  'operation.kicker': 'Operation',
  'operation.tabsLabel': 'Operation sections',
  'operation.featuresLabel': 'API Dock features',
  'operation.auth': 'Auth',
  'operation.scopes': 'Scopes',
  'operation.rateLimit': 'Rate limit',
  'operation.writes': 'Writes',
  'operation.stability': 'Stability',
  'operation.deprecated': 'Deprecated',
  'operation.inputKicker': 'Input surface',
  'operation.parameters': 'Parameters',
  'operation.required': 'required',
  'operation.optional': 'optional',
  'operation.payloadKicker': 'Payload',
  'operation.requestBody': 'Request body',
  'operation.body': 'body',
  'operation.contentType': 'Content type',
  'operation.noRequestSchema': 'No request schema declared.',
  'operation.outcomesKicker': 'Outcomes',
  'operation.responses': 'Responses',
  'operation.response': 'Response',
  'operation.noResponses': 'No responses declared.',
  'operation.noResponseSchema': 'No response schema declared.',

  // Schema tree
  'schema.name': 'Name',
  'schema.type': 'Type',
  'schema.description': 'Description',
  'schema.required': 'required',
  'schema.nullable': 'nullable',
  'schema.enum': 'enum',
  'schema.oneOf': 'oneOf',
  'schema.anyOf': 'anyOf',
  'schema.unresolved': 'unresolved',
  'schema.readOnly': 'read-only',
  'schema.writeOnly': 'write-only',
  'schema.noProperties': 'No properties declared.',
  'schema.circularReference': 'circular reference truncated',
  'schema.unresolvedReference': 'Unresolved reference.',
  'schema.depthLimit': 'depth limit reached',
  'schema.rowLimit': 'Showing the first {limit} schema rows.',
  'schema.valuesLimit': 'Showing the first {limit} values.',
  'schema.variantOption': '{variant} option {index}',
  'schema.variantsLimit': 'Showing the first {limit} variants.',
  'schema.items': 'items',
  'schema.propertiesLimit': 'Showing the first {limit} properties.',
  'schema.additionalProperties': 'additional properties',

  // JSON tree
  'json.depthLimit': 'The JSON depth limit was reached.',

  // Try-it panel
  'tryIt.kicker': 'Proxy playground',
  'tryIt.title': 'Try it',
  'tryIt.loadingProfiles': 'Loading profiles…',
  'tryIt.disabled': 'Try-it is disabled',
  'tryIt.method': 'Method',
  'tryIt.path': 'Path',
  'tryIt.server': 'Server',
  'tryIt.baseUrl': 'Base URL',
  'tryIt.baseUrlPlaceholder': 'https://api.example.com',
  'tryIt.resolvedBaseUrl': 'Resolved base URL',
  'tryIt.editTarget': 'Edit target',
  'tryIt.resolvedBaseUrlPlaceholder': 'https://example.test/api',
  'tryIt.pathParameters': 'Path parameters',
  'tryIt.query': 'Query',
  'tryIt.header': 'Header',
  'tryIt.headers': 'Headers',
  'tryIt.jsonBody': 'JSON body',
  'tryIt.invalidJson': 'Invalid JSON.',
  'tryIt.authenticationProfile': 'Authentication profile',
  'tryIt.noProfile': 'No profile',
  'tryIt.deleteProfile': 'Delete profile',
  'tryIt.shortLivedProfile': 'Short-lived profile',
  'tryIt.createShortLivedProfile': 'Create a short-lived profile',
  'tryIt.label': 'Label',
  'tryIt.baseUrlOptional': 'Base URL (optional note)',
  'tryIt.baseUrlOptionalPlaceholder': 'Which API this credential belongs to',
  'tryIt.serverVariables': 'Server variables',
  'tryIt.valueFromProfile': 'From profile',
  'tryIt.profileValueOverridden': 'Override; profile value: {value}',
  'tryIt.scheme': 'Scheme',
  'tryIt.bearer': 'Bearer',
  'tryIt.basic': 'Basic',
  'tryIt.customHeader': 'Custom header',
  'tryIt.credentialHeader': 'Credential header',
  'tryIt.credentialWriteOnly': 'Credential (write-only)',
  'tryIt.saveProfile': 'Save profile',
  'tryIt.profileLoadError': 'Profiles could not be loaded.',
  'tryIt.credentialRequired': 'Credential is required.',
  'tryIt.profileCreateError': 'The profile could not be created.',
  'tryIt.profileDeleteError': 'The profile could not be deleted.',
  'tryIt.targetHint': 'Edit it to send this request somewhere else — a tenant subdomain, a staging host. The proxy re-checks whatever you put here.',
  'tryIt.send': 'Send',
  'tryIt.sending': 'Sending…',
  'tryIt.sendThroughProxy': 'Send through proxy',
  'tryIt.sendError': 'The try-it request could not be sent.',
  'tryIt.proxyHttpError': 'The proxy returned HTTP {status}.',
  'tryIt.currentCurl': 'Current curl sample',
  'tryIt.copyCurl': 'Copy curl',
  'tryIt.copied': 'Copied',
  'tryIt.credentialNote': 'The sample contains only {hint}. The real credential is held server-side; substitute your own credential when running it elsewhere.',
  'tryIt.request': 'Request',
  'tryIt.responseHeaders': 'Response headers',
  'tryIt.responseBody': 'Response body',
  'tryIt.proxyTruncated': 'The proxy truncated the upstream response.',
  'tryIt.responseHeadersTruncated': 'Showing the first {limit} response headers.',
  'tryIt.responseBodyTruncated': 'Showing the first {limit} characters to keep this page responsive.',

  // Settings
  'settings.title': 'Settings',
  'settings.intro': 'Profiles, the request target and how to use the package live here.',
  'settings.profilesTitle': 'Profiles',
  'settings.profilesHint': 'The credential stays on the server, encrypted and tied to this session; the browser keeps only the selected profile’s id. A profile also carries the tenant (server variables) and the base URL, so every operation reuses them.',
  'settings.noProfiles': 'No profile has been created yet.',
  'settings.newProfile': 'New profile',
  'settings.profileActive': 'selected',
  'settings.selectProfile': 'Use',
  'settings.baseUrlOverride': 'Base URL override',
  'settings.baseUrlOverrideHint': 'Leave it empty to use the resolved server URL. A value here is sent as it stands, without the server template.',
  'settings.targetTitle': 'Request target',
  'settings.targetHint': 'These values build the URL of every try-it request; selecting a profile fills them in.',
  'settings.usageTitle': 'How to use it',
  'settings.usageIntro': 'Four steps from a credential to a live response.',
  'settings.usageStep1': 'Pick a profile here, or create one.',
  'settings.usageStep2': 'Choose an endpoint on the left and fill in its parameters on the “Try it” tab.',
  'settings.usageStep3': 'Send runs the request through the application’s own proxy — the credential never reaches the browser, and the curl sample carries only the masked hint.',
  'settings.usageStep4': 'The “Spec diff” tab shows what changed against the stored snapshot.',
  'settings.commandsTitle': 'Artisan commands',
  'settings.commandsIntro': 'These are run in the application’s own project, not here.',
  'settings.commandSync': 'Regenerate the OpenAPI document, compare it with the stored snapshot and save it. --check exits non-zero on a breaking change, for CI.',
  'settings.commandDiff': 'Compare without writing; --json for machine output.',
  'settings.commandExport': 'Write the AI artifacts: --llms the llms.txt bundle, --mcp the MCP tool definitions, --openapi the generated document; --output= overrides the directory. This is what you hand to an assistant so it can call the API without being told the endpoints each time.',
  'settings.commandAgentGuide': 'Install the API Dock authoring rules into the project’s agent instruction files (AGENTS.md, CLAUDE.md, GEMINI.md) so a coding agent documents endpoints the right way by itself.',

  // AI panel
  'ai.kicker': 'Agent-ready context',
  'ai.title': 'AI prompt',
  'ai.copyFullPrompt': 'Copy full prompt',
  'ai.promptCopied': 'Copied',
  'ai.noHint': 'No AI hint is declared for this operation.',
  'ai.hintLabel': 'AI hint',
  'ai.pitfalls': 'Pitfalls',
  'ai.examples': 'Examples',
  'ai.request': 'Request',
  'ai.response': 'Response',
  'ai.renderTruncated': 'Showing the first {limit} characters.',
  'ai.copyMcpDefinition': 'Copy MCP tool definition',
  'ai.mcpCopied': 'MCP tool copied',
  'ai.copyLlmsSection': 'Copy llms.txt section',
  'ai.llmsCopied': 'llms.txt copied',
  'ai.operationChangelog': 'Operation changelog',
  'ai.changelogHint': 'The prompt above is always current; first-time integrators can skip this history.',
  'ai.breaking': 'Breaking',
  'ai.requirements': 'Requirements',
  'ai.scopes': 'Scopes',
  'ai.rateLimit': 'Rate limit',
  'ai.per': 'per',
  'ai.stability': 'Stability',
  'ai.deprecated': 'Deprecated',
  'ai.parameters': 'Parameters',
  'ai.parameterLocation': 'In',
  'ai.required': 'Required',
  'ai.optional': 'optional',
  'ai.type': 'Type',
  'ai.requestBody': 'Request body',
  'ai.responses': 'Responses',
  'ai.changelog': 'Changelog',
  'ai.authenticationRequired': 'Authentication: required ({guard})',
  'ai.authenticationNotRequired': 'Authentication: not required',
  'ai.noParameters': 'No parameters.',
  'ai.noRequestBody': 'No documented JSON request body.',
  'ai.noResponses': 'No documented responses.',
  'ai.noResponseBody': 'No documented JSON response body.',

  // Spec diff
  'diff.kicker': 'Contract evolution',
  'diff.title': 'Spec diff',
  'diff.description': 'Paste the JSON emitted by php artisan api-dock:diff --json. The documentation app does not request a diff endpoint.',
  'diff.jsonLabel': 'Diff JSON',
  'diff.jsonPlaceholder': '{ "has_breaking": false, "changes": [] }',
  'diff.invalidJson': 'The diff JSON is invalid.',
  'diff.invalidShape': 'Expected api-dock:diff JSON with has_breaking and changes fields.',
  'diff.invalidChange': 'Change {index} does not match the api-dock:diff output shape.',
  'diff.noChanges': 'No contract changes were reported.',
  'diff.breaking': 'breaking',
  'diff.additive': 'additive',
  'diff.cosmetic': 'cosmetic',
  'diff.noSeverityChanges': 'No {severity} changes.',
  'diff.showingChanges': 'Showing the first {limit} of {total} changes.',

  // Shared controls and notices
  'common.copy': 'Copy',
  'common.copied': 'Copied',
  'common.close': 'Close',
  'common.cancel': 'Cancel',
  'common.yes': 'Yes',
  'common.no': 'No',
  'common.unknown': 'unknown',
  'common.showingFirst': 'Showing the first {limit} items.',
  'common.showingFirstOf': 'Showing the first {limit} of {total} items.',
} as const

export type MessageKey = keyof typeof en

export const tr: Record<MessageKey, string> = {
  // Sidebar
  'sidebar.ariaLabel': 'API operasyonları',
  'sidebar.homeAriaLabel': 'API Dock ana sayfası',
  'sidebar.brandName': 'API Dock',
  'sidebar.brandSubtitle': 'Spec Explorer',
  'sidebar.searchLabel': 'Endpoint ara',
  'sidebar.searchPlaceholder': 'Yol, etiket veya operasyon ara…',
  'sidebar.noMatches': '“{query}” ile eşleşen operasyon bulunamadı.',
  'sidebar.expandGroup': '{tag} grubunu genişlet',
  'sidebar.collapseGroup': '{tag} grubunu daralt',
  'sidebar.openapiVersion': 'OpenAPI {version}',
  'sidebar.untagged': 'Etiketsiz',
  'sidebar.unversioned': 'sürümsüz',

  // Application shell
  'shell.loadingKicker': 'Sözleşme okunuyor',
  'shell.loadingTitle': 'API haritanız çıkarılıyor…',
  'shell.loadingDescription': '{url} getiriliyor',
  'shell.invalidSpec': 'Spec yanıtı geçerli bir OpenAPI belgesi değil.',
  'shell.loadError': 'API spec yüklenemedi.',
  'shell.errorKicker': 'Spec kullanılamıyor',
  'shell.errorTitle': 'Bağlantı sinyalini kaybettik.',
  'shell.retry': 'Tekrar dene',
  'shell.emptyKicker': 'Boş sözleşme',
  'shell.emptyTitle': 'Operasyon bulunamadı.',
  'shell.emptyDescription': 'Belge yüklendi ancak paths nesnesinde operasyon yok.',
  'shell.language': 'Dil',
  'shell.theme': 'Tema',
  'shell.lightTheme': 'Açık tema',
  'shell.darkTheme': 'Koyu tema',

  // Operation tabs
  'tabs.parameters': 'Parametreler',
  'tabs.requestBody': 'İstek gövdesi',
  'tabs.responses': 'Yanıtlar',
  'tabs.tryIt': 'Dene',
  'tabs.aiPrompt': 'AI prompt',
  'tabs.specDiff': 'Spec farkı',

  // Operation detail
  'operation.kicker': 'Operasyon',
  'operation.tabsLabel': 'Operasyon bölümleri',
  'operation.featuresLabel': 'API Dock özellikleri',
  'operation.auth': 'Kimlik doğrulama',
  'operation.scopes': 'Yetki kapsamları',
  'operation.rateLimit': 'İstek sınırı',
  'operation.writes': 'Yazma işlemi',
  'operation.stability': 'Kararlılık',
  'operation.deprecated': 'Kullanımdan kaldırıldı',
  'operation.inputKicker': 'Girdi yüzeyi',
  'operation.parameters': 'Parametreler',
  'operation.required': 'zorunlu',
  'operation.optional': 'isteğe bağlı',
  'operation.payloadKicker': 'İstek verisi',
  'operation.requestBody': 'İstek gövdesi',
  'operation.body': 'gövde',
  'operation.contentType': 'İçerik türü',
  'operation.noRequestSchema': 'İstek şeması tanımlanmamış.',
  'operation.outcomesKicker': 'Sonuçlar',
  'operation.responses': 'Yanıtlar',
  'operation.response': 'Yanıt',
  'operation.noResponses': 'Yanıt tanımlanmamış.',
  'operation.noResponseSchema': 'Yanıt şeması tanımlanmamış.',

  // Schema tree
  'schema.name': 'Ad',
  'schema.type': 'Tür',
  'schema.description': 'Açıklama',
  'schema.required': 'zorunlu',
  'schema.nullable': 'null olabilir',
  'schema.enum': 'enum',
  'schema.oneOf': 'oneOf',
  'schema.anyOf': 'anyOf',
  'schema.unresolved': 'çözümlenemedi',
  'schema.readOnly': 'salt okunur',
  'schema.writeOnly': 'salt yazılır',
  'schema.noProperties': 'Özellik tanımlanmamış.',
  'schema.circularReference': 'döngüsel referans kısaltıldı',
  'schema.unresolvedReference': 'Referans çözümlenemedi.',
  'schema.depthLimit': 'derinlik sınırına ulaşıldı',
  'schema.rowLimit': 'Şemanın ilk {limit} satırı gösteriliyor.',
  'schema.valuesLimit': 'İlk {limit} değer gösteriliyor.',
  'schema.variantOption': '{variant} seçeneği {index}',
  'schema.variantsLimit': 'İlk {limit} varyant gösteriliyor.',
  'schema.items': 'öğeler',
  'schema.propertiesLimit': 'İlk {limit} özellik gösteriliyor.',
  'schema.additionalProperties': 'ek özellikler',

  // JSON tree
  'json.depthLimit': 'JSON derinliği sınırına ulaşıldı.',

  // Try-it panel
  'tryIt.kicker': 'Proxy deneme alanı',
  'tryIt.title': 'Dene',
  'tryIt.loadingProfiles': 'Profiller yükleniyor…',
  'tryIt.disabled': 'Dene özelliği devre dışı',
  'tryIt.method': 'Metot',
  'tryIt.path': 'Yol',
  'tryIt.server': 'Sunucu',
  'tryIt.baseUrl': 'Temel URL',
  'tryIt.baseUrlPlaceholder': 'https://api.example.com',
  'tryIt.resolvedBaseUrl': 'Çözümlenen temel URL',
  'tryIt.editTarget': 'Hedefi düzenle',
  'tryIt.resolvedBaseUrlPlaceholder': 'https://example.test/api',
  'tryIt.pathParameters': 'Yol parametreleri',
  'tryIt.query': 'Sorgu',
  'tryIt.header': 'Başlık',
  'tryIt.headers': 'Header’lar',
  'tryIt.jsonBody': 'JSON gövdesi',
  'tryIt.invalidJson': 'Geçersiz JSON.',
  'tryIt.authenticationProfile': 'Kimlik doğrulama profili',
  'tryIt.noProfile': 'Profil yok',
  'tryIt.deleteProfile': 'Profili sil',
  'tryIt.shortLivedProfile': 'Kısa ömürlü profil',
  'tryIt.createShortLivedProfile': 'Kısa ömürlü profil oluştur',
  'tryIt.label': 'Etiket',
  'tryIt.baseUrlOptional': 'Temel URL (isteğe bağlı not)',
  'tryIt.baseUrlOptionalPlaceholder': 'Bu kimlik bilgisinin ait olduğu API',
  'tryIt.serverVariables': 'Sunucu değişkenleri',
  'tryIt.valueFromProfile': 'Profilden geldi',
  'tryIt.profileValueOverridden': 'Geçersiz kılındı; profil değeri: {value}',
  'tryIt.scheme': 'Kimlik doğrulama türü',
  'tryIt.bearer': 'Bearer',
  'tryIt.basic': 'Basic',
  'tryIt.customHeader': 'Özel header',
  'tryIt.credentialHeader': 'Kimlik bilgisi header’ı',
  'tryIt.credentialWriteOnly': 'Kimlik bilgisi (salt yazılır)',
  'tryIt.saveProfile': 'Profili kaydet',
  'tryIt.profileLoadError': 'Profiller yüklenemedi.',
  'tryIt.credentialRequired': 'Kimlik bilgisi zorunludur.',
  'tryIt.profileCreateError': 'Profil oluşturulamadı.',
  'tryIt.profileDeleteError': 'Profil silinemedi.',
  'tryIt.targetHint': 'Bu isteği başka bir yere — kiracı alt alan adına veya hazırlık sunucusuna — göndermek için düzenleyin. Proxy buraya yazdığınız değeri yeniden denetler.',
  'tryIt.send': 'Gönder',
  'tryIt.sending': 'Gönderiliyor…',
  'tryIt.sendThroughProxy': 'Proxy üzerinden gönder',
  'tryIt.sendError': 'Dene isteği gönderilemedi.',
  'tryIt.proxyHttpError': 'Proxy HTTP {status} yanıtını döndürdü.',
  'tryIt.currentCurl': 'Geçerli curl örneği',
  'tryIt.copyCurl': 'curl komutunu kopyala',
  'tryIt.copied': 'Kopyalandı',
  'tryIt.credentialNote': 'Örnek yalnızca {hint} içerir. Gerçek kimlik bilgisi sunucu tarafında tutulur; başka bir yerde çalıştırırken kendi kimlik bilginizi kullanın.',
  'tryIt.request': 'İstek',
  'tryIt.responseHeaders': 'Yanıt header’ları',
  'tryIt.responseBody': 'Yanıt gövdesi',
  'tryIt.proxyTruncated': 'Proxy, kaynak sunucunun yanıtını kısalttı.',
  'tryIt.responseHeadersTruncated': 'Yanıt header’larının ilk {limit} tanesi gösteriliyor.',
  'tryIt.responseBodyTruncated': 'Sayfanın yanıt verebilir kalması için ilk {limit} karakter gösteriliyor.',

  // Settings
  'settings.title': 'Ayarlar',
  'settings.intro': 'Profiller, istek hedefi ve paketin nasıl kullanılacağı burada.',
  'settings.profilesTitle': 'Profiller',
  'settings.profilesHint': 'Kimlik bilgisi sunucuda, şifrelenmiş ve bu oturuma bağlı olarak kalır; tarayıcı yalnızca seçili profilin kimliğini tutar. Profil ayrıca kiracıyı (sunucu değişkenleri) ve temel URL’yi taşır, böylece her operasyon bunları yeniden kullanır.',
  'settings.noProfiles': 'Henüz profil oluşturulmadı.',
  'settings.newProfile': 'Yeni profil',
  'settings.profileActive': 'seçili',
  'settings.selectProfile': 'Kullan',
  'settings.baseUrlOverride': 'Temel URL geçersiz kılma',
  'settings.baseUrlOverrideHint': 'Çözümlenen sunucu URL’sini kullanmak için boş bırakın. Buraya yazılan değer, sunucu şablonu uygulanmadan olduğu gibi gönderilir.',
  'settings.targetTitle': 'İstek hedefi',
  'settings.targetHint': 'Bu değerler her dene isteğinin URL’sini oluşturur; profil seçmek bunları doldurur.',
  'settings.usageTitle': 'Nasıl kullanılır',
  'settings.usageIntro': 'Kimlik bilgisinden canlı yanıta dört adım.',
  'settings.usageStep1': 'Buradan bir profil seçin veya yeni bir profil oluşturun.',
  'settings.usageStep2': 'Soldan bir endpoint seçin ve parametrelerini “Dene” sekmesinde doldurun.',
  'settings.usageStep3': 'Gönder, isteği uygulamanın kendi proxy’si üzerinden çalıştırır — kimlik bilgisi tarayıcıya hiç ulaşmaz; curl örneğinde yalnızca maskelenmiş ipucu bulunur.',
  'settings.usageStep4': '“Spec farkı” sekmesi, kayıtlı anlık görüntüye göre neyin değiştiğini gösterir.',
  'settings.commandsTitle': 'Artisan komutları',
  'settings.commandsIntro': 'Bu komutlar burada değil, uygulamanın kendi projesinde çalıştırılır.',
  'settings.commandSync': 'OpenAPI belgesini yeniden üretir, kayıtlı anlık görüntüyle karşılaştırır ve kaydeder. --check, geriye uyumsuz bir değişiklikte sıfırdan farklı çıkış kodu döndürür; CI içindir.',
  'settings.commandDiff': 'Hiçbir şey yazmadan karşılaştırır; makine çıktısı için --json.',
  'settings.commandExport': 'AI çıktılarını yazar: --llms llms.txt paketini, --mcp MCP araç tanımlarını, --openapi üretilen belgeyi yazar; --output= dizini değiştirir. Bunu bir asistana verirsiniz, endpoint’leri her seferinde anlatmadan API’yi çağırabilir.',
  'settings.commandAgentGuide': 'API Dock yazım kurallarını projenin ajan yönerge dosyalarına (AGENTS.md, CLAUDE.md, GEMINI.md) kurar; böylece kodlama ajanı endpoint’leri kendiliğinden doğru biçimde belgeler.',

  // AI panel
  'ai.kicker': 'Ajan kullanımına hazır bağlam',
  'ai.title': 'AI prompt',
  'ai.copyFullPrompt': 'Prompt’un tamamını kopyala',
  'ai.promptCopied': 'Kopyalandı',
  'ai.noHint': 'Bu operasyon için AI ipucu tanımlanmamış.',
  'ai.hintLabel': 'AI ipucu',
  'ai.pitfalls': 'Dikkat edilmesi gerekenler',
  'ai.examples': 'Örnekler',
  'ai.request': 'İstek',
  'ai.response': 'Yanıt',
  'ai.renderTruncated': 'İlk {limit} karakter gösteriliyor.',
  'ai.copyMcpDefinition': 'MCP araç tanımını kopyala',
  'ai.mcpCopied': 'MCP aracı kopyalandı',
  'ai.copyLlmsSection': 'llms.txt bölümünü kopyala',
  'ai.llmsCopied': 'llms.txt kopyalandı',
  'ai.operationChangelog': 'Operasyon değişiklik günlüğü',
  'ai.changelogHint': 'Yukarıdaki prompt her zaman günceldir; ilk kez entegrasyon yapanlar bu geçmişi atlayabilir.',
  'ai.breaking': 'Geriye uyumsuz',
  'ai.requirements': 'Gereksinimler',
  'ai.scopes': 'Yetki kapsamları',
  'ai.rateLimit': 'İstek sınırı',
  'ai.per': 'başına',
  'ai.stability': 'Kararlılık',
  'ai.deprecated': 'Kullanımdan kaldırıldı',
  'ai.parameters': 'Parametreler',
  'ai.parameterLocation': 'Konum',
  'ai.required': 'Zorunlu',
  'ai.optional': 'isteğe bağlı',
  'ai.type': 'Tür',
  'ai.requestBody': 'İstek gövdesi',
  'ai.responses': 'Yanıtlar',
  'ai.changelog': 'Değişiklik günlüğü',
  'ai.authenticationRequired': 'Kimlik doğrulama: zorunlu ({guard})',
  'ai.authenticationNotRequired': 'Kimlik doğrulama: zorunlu değil',
  'ai.noParameters': 'Parametre yok.',
  'ai.noRequestBody': 'Belgelenmiş JSON istek gövdesi yok.',
  'ai.noResponses': 'Belgelenmiş yanıt yok.',
  'ai.noResponseBody': 'Belgelenmiş JSON yanıt gövdesi yok.',

  // Spec diff
  'diff.kicker': 'Sözleşme değişimi',
  'diff.title': 'Spec farkı',
  'diff.description': 'php artisan api-dock:diff --json komutunun ürettiği JSON’ı yapıştırın. Dokümantasyon uygulaması bir fark endpoint’ine istek göndermez.',
  'diff.jsonLabel': 'Fark JSON’ı',
  'diff.jsonPlaceholder': '{ "has_breaking": false, "changes": [] }',
  'diff.invalidJson': 'Fark JSON’ı geçersiz.',
  'diff.invalidShape': 'has_breaking ve changes alanlarını içeren api-dock:diff JSON’ı bekleniyordu.',
  'diff.invalidChange': '{index}. değişiklik api-dock:diff çıktı biçimiyle eşleşmiyor.',
  'diff.noChanges': 'Sözleşme değişikliği bildirilmedi.',
  'diff.breaking': 'geriye uyumsuz',
  'diff.additive': 'eklemeli',
  'diff.cosmetic': 'görsel',
  'diff.noSeverityChanges': '{severity} değişiklik yok.',
  'diff.showingChanges': 'Toplam {total} değişikliğin ilk {limit} tanesi gösteriliyor.',

  // Shared controls and notices
  'common.copy': 'Kopyala',
  'common.copied': 'Kopyalandı',
  'common.close': 'Kapat',
  'common.cancel': 'İptal',
  'common.yes': 'Evet',
  'common.no': 'Hayır',
  'common.unknown': 'bilinmiyor',
  'common.showingFirst': 'İlk {limit} öğe gösteriliyor.',
  'common.showingFirstOf': 'Toplam {total} öğenin ilk {limit} tanesi gösteriliyor.',
}

function normalizeLocale(candidate: string): Locale {
  return candidate === 'tr' || candidate === 'en' ? candidate : 'en'
}

function storedLocale(): string | undefined {
  try {
    return typeof localStorage === 'undefined'
      ? undefined
      : localStorage.getItem(STORAGE_KEY) ?? undefined
  } catch {
    return undefined
  }
}

export function resolveInitialLocale(fallback?: string): Locale {
  const stored = storedLocale()

  if (stored !== undefined) {
    return normalizeLocale(stored)
  }

  if (fallback !== undefined) {
    return normalizeLocale(fallback)
  }

  const browserLocale = typeof navigator === 'undefined'
    ? undefined
    : navigator.language.split('-')[0]

  return browserLocale === undefined ? 'en' : normalizeLocale(browserLocale)
}

export const locale = ref<Locale>(resolveInitialLocale())

export function setLocale(next: Locale): void {
  locale.value = next

  try {
    if (typeof localStorage !== 'undefined') {
      localStorage.setItem(STORAGE_KEY, next)
    }
  } catch {
    // Storage can be unavailable without making localization unavailable.
  }

  if (typeof document !== 'undefined') {
    document.documentElement.lang = next
  }
}

const messages: Record<Locale, Record<MessageKey, string>> = { en, tr }

export function t(
  key: MessageKey,
  params?: Record<string, string | number>,
): string {
  const message = messages[locale.value][key]

  if (params === undefined) {
    return message
  }

  return message.replace(/\{([^{}]+)\}/g, (placeholder, name: string) =>
    Object.prototype.hasOwnProperty.call(params, name) ? String(params[name]) : placeholder,
  )
}
