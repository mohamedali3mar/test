#!/usr/bin/env bash
# يبني ملف الرفع للاستضافة من محتويات public_html، ثم يتحقق من محتواه.
#   dist/wood-inventory-<version>-upload.zip   ← يُرفع ويُفك داخل public_html على Hostinger
#   dist/wood-inventory-<version>-docs.zip     ← الوثائق (لا تُرفع للاستضافة)
#   dist/SHA256SUMS
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
VERSION="$(grep -oP "APP_VERSION = '\K[0-9.]+" "$ROOT/public_html/app/lib/core.php")"
DIST="$ROOT/dist"
UPLOAD="$DIST/wood-inventory-$VERSION-upload.zip"
DOCS="$DIST/wood-inventory-$VERSION-docs.zip"
mkdir -p "$DIST"
rm -f "$UPLOAD" "$DOCS" "$DIST/SHA256SUMS"

# فحوص قبل البناء
fail() { echo "BUILD FAILED: $*" >&2; exit 1; }
while IFS= read -r -d '' f; do
  php -l "$f" >/dev/null || fail "syntax error in $f"
  head -c 3 "$f" | grep -q $'\xEF\xBB\xBF' && fail "UTF-8 BOM in $f"
  head -c 5 "$f" | grep -q '^<?php' || fail "bytes before <?php in $f"
done < <(find "$ROOT/public_html" -name '*.php' -print0)
if grep -rlP '[\x{2013}\x{2014}]' "$ROOT/public_html/app/pages" "$ROOT/public_html/index.php" "$ROOT/public_html/install.php" "$ROOT/public_html/assets/js" >/dev/null 2>&1; then
  fail "long dash found in UI files"
fi

# البناء من داخل المجلد بالنقطة (وليس *) حتى تدخل ملفات .htaccess
(cd "$ROOT/public_html" && zip -q -r -X -9 "$UPLOAD" . \
  -x 'app/config.php' 'app/storage/installed.lock' 'app/storage/reset.allow' \
     'app/storage/logs/*.log' 'app/storage/logs/*.log.1' 'app/storage/sessions/sess_*' '*.DS_Store')

# التحقق من المحتوى
LIST="$(unzip -Z1 "$UPLOAD")"
for must in .htaccess app/.htaccess app/storage/.htaccess app/storage/sessions/.htaccess app/migrations/.htaccess \
            index.php install.php app/config.sample.php app/migrations/001_initial.sql assets/js/app.js assets/css/app.css \
            assets/fonts/cairo-arabic-400-normal.woff2 assets/fonts/OFL.txt app/storage/logs/index.php robots.txt; do
  grep -qx "$must" <<<"$LIST" || fail "missing $must"
done
for mustnot in 'app/config.php' 'installed.lock' 'reset.allow' '\.log$' 'sess_'; do
  if grep -qE "$mustnot" <<<"$LIST"; then fail "must not contain $mustnot"; fi
done

DOC_FILES=()
for d in README.md INSTALL_HOSTINGER_AR.md HANDOVER_AR.md DESIGN_RULES.md TEST_REPORT.md CHANGELOG.md; do
  [ -f "$ROOT/$d" ] && DOC_FILES+=("$d") || echo "warning: $d not found, not included in docs zip" >&2
done
(cd "$ROOT" && zip -q -X -9 "$DOCS" "${DOC_FILES[@]}" public_html/assets/fonts/OFL.txt)
(cd "$DIST" && sha256sum "$(basename "$UPLOAD")" "$(basename "$DOCS")" > SHA256SUMS)

echo "built $UPLOAD ($(du -h "$UPLOAD" | cut -f1), $(wc -l <<<"$LIST") entries)"
echo "built $DOCS"
cat "$DIST/SHA256SUMS"
