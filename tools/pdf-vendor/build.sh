#!/usr/bin/env bash
# Rebuilds the vendored PDF engine and the PDF fonts. Run on a development machine (never on the host).
#
#   tools/pdf-vendor/build.sh vendor   -> public_html/app/vendor  (mPDF + dependencies, pruned)
#   tools/pdf-vendor/build.sh fonts    -> public_html/app/fonts   (Cairo Regular/Bold static TTF + OFL.txt)
#   tools/pdf-vendor/build.sh          -> both
#
# Requirements: PHP 8.1+, composer, curl, python3 with fontTools (pip install fonttools).
# See README.md in this folder for details and licensing notes.
set -euo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"
ROOT="$(cd "$HERE/../.." && pwd)"
APP="$ROOT/public_html/app"
STEP="${1:-all}"
CAIRO_BASE="https://raw.githubusercontent.com/google/fonts/main/ofl/cairo"

guard_index() {
  # Same guard as the other app/ folders (app/.htaccess already denies HTTP access)
  printf '%s\n' '<?php http_response_code(403); exit;' > "$1/index.php"
}

build_vendor() {
  cd "$HERE"
  rm -rf "$HERE/vendor"
  # composer.lock pins the exact versions; "composer update" only when upgrading on purpose
  COMPOSER_ALLOW_SUPERUSER="${COMPOSER_ALLOW_SUPERUSER:-0}" \
    composer install --no-dev --optimize-autoloader --classmap-authoritative --no-interaction --no-progress

  local V="$HERE/vendor"
  # 1) VCS metadata, CI files, tests, docs and dev tooling of every package
  find "$V" -depth \( -name .git -o -name .github \) -exec rm -rf {} +
  find "$V" -mindepth 3 -maxdepth 3 -type d \
    \( -name tests -o -name test -o -name Tests -o -name doc -o -name docs -o -name fixtures \
       -o -name utils -o -name local-tests -o -name scratches \) -exec rm -rf {} +
  find "$V" -mindepth 3 -maxdepth 3 -type f \
    \( -name '.git*' -o -name '.scrutinizer.yml' -o -name '.travis.yml' -o -name 'phpunit*' \
       -o -name 'phpstan*' -o -name 'phpcs.xml*' -o -name 'ruleset.xml' -o -name 'psalm*' \
       -o -name 'build-phar.sh' -o -name 'composer.lock' -o -name 'CHANGELOG.md' -o -name 'README.md' \
       -o -name 'RATIONALE.md' -o -name 'SECURITY.md' \) -delete
  rm -rf "$V/paragonie/random_compat/dist" "$V/paragonie/random_compat/other"

  # 2) mPDF: no bundled temp dir (the app uses app/storage/pdf-cache), and only the fallback font
  local M="$V/mpdf/mpdf"
  rm -rf "$M/tmp"
  find "$M/ttfonts" -type f ! -name 'DejaVuSans.ttf' ! -name 'DejaVuSans-Bold.ttf' ! -name 'DejaVuinfo.txt' -delete

  # 3) Sanity: the licenses must still be there
  for f in "$M/LICENSE.txt" "$V/setasign/fpdi/LICENSE.txt" "$V/myclabs/deep-copy/LICENSE" \
           "$V/psr/log/LICENSE" "$V/psr/http-message/LICENSE" "$V/paragonie/random_compat/LICENSE" \
           "$V/composer/LICENSE" "$M/ttfonts/DejaVuinfo.txt"; do
    [ -f "$f" ] || { echo "missing license file: $f" >&2; exit 1; }
  done

  rm -rf "$APP/vendor"
  cp -a "$V" "$APP/vendor"
  guard_index "$APP/vendor"
  echo "vendor: $(du -sh "$APP/vendor" | cut -f1) in $APP/vendor"
}

build_fonts() {
  local TMP
  TMP="$(mktemp -d)"
  trap 'rm -rf "$TMP"' RETURN
  mkdir "$TMP/src"
  curl -sSfL -o "$TMP/src/Cairo-VF.ttf" "$CAIRO_BASE/Cairo%5Bslnt%2Cwght%5D.ttf"
  curl -sSfL -o "$TMP/src/OFL.txt" "$CAIRO_BASE/OFL.txt"
  mkdir -p "$APP/fonts"
  python3 -I "$HERE/cairo_instance.py" "$TMP/src/Cairo-VF.ttf" "$APP/fonts"
  cp "$TMP/src/OFL.txt" "$APP/fonts/OFL.txt"
  guard_index "$APP/fonts"
  echo "fonts: $(du -sh "$APP/fonts" | cut -f1) in $APP/fonts"
}

case "$STEP" in
  vendor) build_vendor ;;
  fonts) build_fonts ;;
  all) build_vendor; build_fonts ;;
  *) echo "usage: $0 [vendor|fonts|all]" >&2; exit 2 ;;
esac
