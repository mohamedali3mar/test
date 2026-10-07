#!/usr/bin/env bash
# يجهز حزمة الرفع على Hostinger في dist/hostinger/:
#   wood-inventory-<الإصدار>-upload.zip   ← يُرفع ويُفك داخل public_html
#   config.php                            ← ملف الإعدادات بمفتاح تثبيت عشوائي جديد (وبيانات القاعدة إن مُررت في البيئة)
#   دليل-الرفع-على-Hostinger.pdf          ← الخطوات مختصرة (التفاصيل في INSTALL_HOSTINGER_AR.md)
#   SHA256SUMS
# config.php هنا سري (فيه مفتاح التثبيت) ولا يدخل git أبدًا (dist/ في .gitignore).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
VERSION="$(grep -oP "APP_VERSION = '\K[0-9.]+" "$ROOT/public_html/app/lib/core.php")"
OUT="$ROOT/dist/hostinger"
ZIP="wood-inventory-$VERSION-upload.zip"

"$ROOT/tools/build-zip.sh" >/dev/null
rm -rf "$OUT"
mkdir -p "$OUT"
cp "$ROOT/dist/$ZIP" "$OUT/$ZIP"

# config.php من النموذج نفسه: مفتاح تثبيت عشوائي جديد، وبيانات القاعدة من البيئة إن وُجدت
# (WOOD_DB_NAME و WOOD_DB_USER و WOOD_DB_PASS و WOOD_BASE_URL)، انظر tools/hostinger-config.php
php "$ROOT/tools/hostinger-config.php" "$ROOT/public_html/app/config.sample.php" "$OUT/config.php"
php -l "$OUT/config.php" >/dev/null

SITE_ARG=""
if [ -n "${WOOD_BASE_URL:-}" ]; then SITE_ARG="--site=$(sed -E 's#^https?://##; s#/.*$##' <<<"$WOOD_BASE_URL")"; fi
php "$ROOT/tools/hostinger-guide.php" "$OUT/دليل-الرفع-على-Hostinger.pdf" ${WOOD_DB_NAME:+--prefilled} $SITE_ARG >/dev/null

(cd "$OUT" && sha256sum "$ZIP" "دليل-الرفع-على-Hostinger.pdf" > SHA256SUMS)
echo "bundle ready: $OUT"
ls -la "$OUT"
