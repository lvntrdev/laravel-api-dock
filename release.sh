#!/usr/bin/env bash
#
# release.sh — api-dock sürüm yayınla (git tag).
#
# Composer paketlerinde sürüm composer.json'da TUTULMAZ; paket Packagist'te
# olmadığı için tüketici uygulama depoyu VCS repository olarak ekler ve sürümü
# doğrudan git tag'inden çözer. Bu script tag'i üretir, öncesinde kaliteyi ve
# build çıktısını doğrular.
#
# Ön koşul: yayınlanacak bütün değişiklikler ZATEN commit'lenmiş olmalı.
# Script commit atmaz; yalnızca doğrular, tag'ler ve (onayla) push eder.
#
# Kullanım:
#   ./release.sh                     # interaktif sürüm menüsü
#   ./release.sh patch               # 0.1.0 -> 0.1.1
#   ./release.sh minor               # 0.1.0 -> 0.2.0
#   ./release.sh major               # 0.1.0 -> 1.0.0
#   ./release.sh 0.4.2               # tam sürümü elle ver
#   ./release.sh patch --no-verify   # test/build kapısını atla (dikkat)
#   ./release.sh patch --no-push     # yalnız lokal tag at
#   ./release.sh --dry-run           # hiçbir şey yazma, ne olacağını göster
#
set -euo pipefail

cd "$(dirname "$0")"

BRANCH_MAIN="main"

# --- argümanlar ---
BUMP=""
NO_VERIFY=0
NO_PUSH=0
DRY_RUN=0
for arg in "$@"; do
  case "$arg" in
    --no-verify) NO_VERIFY=1 ;;
    --no-push)   NO_PUSH=1 ;;
    --dry-run)   DRY_RUN=1 ;;
    patch|minor|major) BUMP="$arg" ;;
    v[0-9]*|[0-9]*)
      # Sürüm burada KESİN doğrulanır; '1.2.3-rc1' gibi bir form aşağıda sessizce
      # mevcut sürüme düşerdi.
      _arg_version="${arg#v}"
      [[ "$_arg_version" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] \
        || { echo "Geçersiz sürüm: $arg (x.y.z bekleniyor)" >&2; exit 1; }
      BUMP="$_arg_version"
      ;;
    *) echo "Bilinmeyen argüman: $arg" >&2; exit 1 ;;
  esac
done

# renkler (yalnız interaktif tty)
if [ -t 1 ]; then
  C_HEAD=$'\033[1m'; C_VER=$'\033[33m'; C_DIM=$'\033[2m'; C_ERR=$'\033[31m'; C_OK=$'\033[32m'; C_RST=$'\033[0m'
else
  C_HEAD=''; C_VER=''; C_DIM=''; C_ERR=''; C_OK=''; C_RST=''
fi

die()  { printf '%s✗ %s%s\n' "$C_ERR" "$1" "$C_RST" >&2; exit 1; }
ok()   { printf '%s✓%s %s\n' "$C_OK" "$C_RST" "$1"; }
step() { printf '\n%s→ %s%s\n' "$C_HEAD" "$1" "$C_RST"; }
onay() { # onay <soru>  → 0 = evet
  local _a
  read -r -p "$1 [e/H]: " _a
  [[ "${_a:-h}" =~ ^[eEyY]$ ]]
}

# --- 1. git ön koşulları ---
git rev-parse --git-dir >/dev/null 2>&1 || die "Burası bir git deposu değil."

# Senkron kontrolü, tag ve push AYNI ref üzerinde çalışır: HEAD doğrulanıp
# $BRANCH_MAIN push edilirse tag'lenen commit ile gönderilen commit ayrışır.
RELEASE_BRANCH=$(git rev-parse --abbrev-ref HEAD)
[ "$RELEASE_BRANCH" != "HEAD" ] || die "Detached HEAD — release bir branch üzerinde çalıştırılmalı."
if [ "$RELEASE_BRANCH" != "$BRANCH_MAIN" ]; then
  printf '%sUyarı:%s %s değil, %s üzerindesin; tag ve push bu branch üzerinden gider.\n' \
    "$C_ERR" "$C_RST" "$BRANCH_MAIN" "$RELEASE_BRANCH"
  onay "Yine de devam edilsin mi?" || exit 1
fi

if [ -n "$(git status --porcelain)" ]; then
  git status --short
  die "Çalışma ağacı temiz değil. Önce commit'le, sonra release'i çalıştır."
fi

step "origin ile senkron kontrolü"
# Uzakta branch henüz yoksa (boş depo, ilk push) karşılaştıracak bir ref yok;
# fetch başarısız olur ve release burada takılırdı.
if git ls-remote --exit-code --heads origin "$RELEASE_BRANCH" >/dev/null 2>&1; then
  git fetch --quiet origin "$RELEASE_BRANCH" || die "git fetch başarısız."
  BEHIND=$(git rev-list --count "HEAD..origin/$RELEASE_BRANCH")
  AHEAD=$(git rev-list --count "origin/$RELEASE_BRANCH..HEAD")
  [ "$BEHIND" -eq 0 ] || die "Lokal $BEHIND commit geride. Önce 'git pull --rebase'."
  if [ "$AHEAD" -gt 0 ]; then
    ok "Lokal $AHEAD commit ileride (push'ta gönderilecek)."
  else
    ok "origin/$RELEASE_BRANCH ile aynı noktadasın."
  fi
else
  ok "origin'de $RELEASE_BRANCH yok — ilk push bu release ile gidecek."
fi

# --- 2. mevcut sürüm (en son v* tag) ---
CUR=$(git tag --list 'v[0-9]*' --sort=-v:refname | head -n 1 || true)
CUR="${CUR#v}"
FIRST_RELEASE=0
if [ -z "$CUR" ]; then
  FIRST_RELEASE=1
  CUR="0.0.0"
fi

# --- 3. sürüm seçimi ---
if [ -z "$BUMP" ]; then
  IFS='.' read -r _MA _MI _PA <<< "$CUR"
  V_PATCH="$_MA.$_MI.$((_PA + 1))"
  V_MINOR="$_MA.$((_MI + 1)).0"
  V_MAJOR="$((_MA + 1)).0.0"

  printf '\n%sapi-dock Yayın%s\n\n' "$C_HEAD" "$C_RST"
  if [ "$FIRST_RELEASE" -eq 1 ]; then
    printf '%-32s%s(tag yok — ilk yayın)%s\n\n' "Mevcut versiyon" "$C_DIM" "$C_RST"
  else
    printf '%-32s%sv%s%s\n\n' "Mevcut versiyon" "$C_VER" "$CUR" "$C_RST"
  fi
  printf 'Versiyon artırma tipi:\n'
  printf '  1) Patch (hata düzeltme)    %s→ %s%s\n' "$C_DIM" "$V_PATCH" "$C_RST"
  printf '  2) Minor (yeni özellik)     %s→ %s%s\n' "$C_DIM" "$V_MINOR" "$C_RST"
  printf '  3) Major (kıran değişiklik) %s→ %s%s\n' "$C_DIM" "$V_MAJOR" "$C_RST"
  printf '  4) Özel versiyon\n\n'

  DEFAULT_CHOICE=1
  [ "$FIRST_RELEASE" -eq 1 ] && DEFAULT_CHOICE=2   # ilk yayın için 0.1.0

  read -r -p "Seçim [1-4, varsayılan $DEFAULT_CHOICE]: " _choice
  case "${_choice:-$DEFAULT_CHOICE}" in
    1) BUMP="patch" ;;
    2) BUMP="minor" ;;
    3) BUMP="major" ;;
    4)
      read -r -p "Tam sürüm (x.y.z): " BUMP
      BUMP="${BUMP#v}"
      [[ "$BUMP" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || die "Geçersiz sürüm: $BUMP"
      ;;
    *) die "Geçersiz seçim: $_choice" ;;
  esac
elif [ "$FIRST_RELEASE" -eq 1 ]; then
  echo "Mevcut sürüm: (tag yok — ilk yayın)"
else
  echo "Mevcut sürüm: v$CUR"
fi

# --- 4. yeni sürümü hesapla ---
if [[ "$BUMP" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
  NEW="$BUMP"
else
  IFS='.' read -r MA MI PA <<< "$CUR"
  case "$BUMP" in
    major) MA=$((MA + 1)); MI=0; PA=0 ;;
    minor) MI=$((MI + 1)); PA=0 ;;
    patch) PA=$((PA + 1)) ;;
  esac
  NEW="$MA.$MI.$PA"
fi
TAG="v$NEW"

if git rev-parse -q --verify "refs/tags/$TAG" >/dev/null; then
  die "$TAG tag'i zaten var."
fi
echo "Yeni sürüm:   $TAG"

# --- 5. kalite kapısı ---
# PHPStan bilerek DIŞARIDA: src'de önceden var olan hatalar release'i bloklamamalı.
if [ "$NO_VERIFY" -eq 1 ]; then
  printf '%s--no-verify → test/build kapısı atlandı.%s\n' "$C_DIM" "$C_RST"
else
  step "Pint (kod stili)"
  vendor/bin/pint --test || die "Pint başarısız. 'vendor/bin/pint' çalıştır, commit'le."

  step "Pest (PHP testleri)"
  vendor/bin/pest --testsuite="Api Dock" || die "PHP testleri başarısız."

  step "Vitest (frontend testleri)"
  npm run test --silent || die "Frontend testleri başarısız."

  # Build resources/dist'i BOŞALTIP yeniden yazar — çalışma ağacına dokunan tek
  # adım bu, bu yüzden --dry-run'da atlanır ("hiçbir şey yazma" sözleşmesi).
  if [ "$DRY_RUN" -eq 1 ]; then
    printf '%s--dry-run → Vite build ve dist tazelik kontrolü atlandı.%s\n' "$C_DIM" "$C_RST"
  else
    step "Vite build (resources/dist)"
    npm run build --silent || die "Build başarısız."

    if [ -n "$(git status --porcelain resources/dist)" ]; then
      git status --short resources/dist
      die "Build sonrası resources/dist değişti — derlenmiş varlıklar commit'lenmemiş. Önce onları commit'le."
    fi
    ok "dist, commit'li sürümle aynı."
  fi
fi

# --- 6. yayın özeti + onay ---
if [ "$FIRST_RELEASE" -eq 1 ]; then
  RANGE_DESC="ilk yayın (tüm geçmiş)"
  LOG=$(git log --pretty='  - %s' -n 20)
else
  RANGE_DESC="v$CUR..HEAD"
  LOG=$(git log --pretty='  - %s' "v$CUR..HEAD")
fi

printf '\n%sYayın özeti%s\n' "$C_HEAD" "$C_RST"
printf '  Tag      : %s%s%s\n' "$C_VER" "$TAG" "$C_RST"
printf '  Commit   : %s\n' "$(git rev-parse --short HEAD)"
printf '  Kapsam   : %s\n\n' "$RANGE_DESC"
printf '%s\n\n' "${LOG:-  (yeni commit yok)}"

if [ "$DRY_RUN" -eq 1 ]; then
  printf '%s--dry-run → tag atılmadı, push edilmedi.%s\n' "$C_DIM" "$C_RST"
  exit 0
fi

onay "$TAG tag'i atılsın mı?" || { echo "İptal edildi."; exit 1; }

git tag -a "$TAG" -m "Release $TAG"
ok "$TAG tag'i oluşturuldu (lokal)."

# --- 7. push ---
if [ "$NO_PUSH" -eq 1 ]; then
  printf '%s--no-push → push atlandı. Hazır olduğunda:%s\n' "$C_DIM" "$C_RST"
  printf '  git push origin %s && git push origin %s\n' "$RELEASE_BRANCH" "$TAG"
  exit 0
fi

printf '\nPush edilecek: %s + %s → origin\n' "$RELEASE_BRANCH" "$TAG"
if ! onay "Push edilsin mi?"; then
  printf '%sPush atlandı. Tag lokalde duruyor; geri almak için: git tag -d %s%s\n' "$C_DIM" "$TAG" "$C_RST"
  exit 0
fi

git push origin "$RELEASE_BRANCH"
git push origin "$TAG"
ok "$TAG push edildi."

# --- 8. GitHub release (opsiyonel) ---
if command -v gh >/dev/null 2>&1; then
  if onay "GitHub release oluşturulsun mu?"; then
    gh release create "$TAG" --title "$TAG" --generate-notes && ok "GitHub release oluşturuldu."
  fi
fi

# Paket Packagist'te DEĞİL: tüketici uygulama composer.json'undaki VCS
# repositories girdisiyle doğrudan bu depodan çeker; submit edilecek yer yok.
printf '\n%sTüketici tarafı%s\n' "$C_HEAD" "$C_RST"
printf '  Depo: %s\n' "$(git remote get-url origin)"
printf '  Uygulamada: composer update lvntr/api-dock\n'
printf '  Yeni tag görünmüyorsa: composer clear-cache\n'
