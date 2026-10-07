#!/usr/bin/env bash
# يجهز حزمة الرفع على Hostinger في dist/hostinger/:
#   wood-inventory-<الإصدار>-upload.zip   ← يُرفع ويُفك داخل public_html
#   config.php                            ← ملف الإعدادات بمفتاح تثبيت عشوائي جديد (وبيانات القاعدة إن مُررت في البيئة)
#   دليل-الرفع-على-Hostinger.pdf          ← الخطوات مختصرة (التفاصيل في INSTALL_HOSTINGER_AR.md)
#   wood-inventory-<الإصدار>-ready.zip    ← فقط إذا مُررت بيانات القاعدة: نفس ملف الرفع ومعه app/config.php،
#                                            يُرفع مرة واحدة من «رفع ملفات الموقع» في hPanel أو يُفك داخل public_html
#   wood-inventory-<الإصدار>-docs.zip     ← الوثائق (دليل الاستخدام والتثبيت الكامل والتقارير)، لا تُرفع للاستضافة
#   SHA256SUMS
# تنبيه: كل بناء يولد مفتاح تثبيت جديدًا. بعد تسليم حزمة ورفعها، أعد البناء بنفس المفتاح: WOOD_INSTALL_KEY=<المفتاح من app/config.php على الموقع>
# config.php والملف الجاهز سريان (فيهما بيانات القاعدة ومفتاح التثبيت) ولا يدخلان git أبدًا (dist/ في .gitignore).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
VERSION="$(grep -oP "APP_VERSION = '\K[0-9.]+" "$ROOT/public_html/app/lib/core.php")"
OUT="$ROOT/dist/hostinger"
ZIP="wood-inventory-$VERSION-upload.zip"
DOCS="wood-inventory-$VERSION-docs.zip"

# بيانات القاعدة كلها أو لا شيء: ملف إعدادات ناقص مع دليل يقول «لا تعدّله» يفشل التثبيت
if [ -n "${WOOD_DB_NAME:-}${WOOD_DB_USER:-}${WOOD_DB_PASS:-}" ] \
   && { [ -z "${WOOD_DB_NAME:-}" ] || [ -z "${WOOD_DB_USER:-}" ] || [ -z "${WOOD_DB_PASS:-}" ]; }; then
  echo "set all of WOOD_DB_NAME, WOOD_DB_USER and WOOD_DB_PASS, or none" >&2
  exit 1
fi

"$ROOT/tools/build-zip.sh" >/dev/null
rm -rf "$OUT"
mkdir -p "$OUT"
cp "$ROOT/dist/$ZIP" "$OUT/$ZIP"
cp "$ROOT/dist/$DOCS" "$OUT/$DOCS"

# config.php من النموذج نفسه: مفتاح تثبيت عشوائي جديد، وبيانات القاعدة من البيئة إن وُجدت
# (WOOD_DB_NAME و WOOD_DB_USER و WOOD_DB_PASS و WOOD_BASE_URL)، انظر tools/hostinger-config.php
php "$ROOT/tools/hostinger-config.php" "$ROOT/public_html/app/config.sample.php" "$OUT/config.php"
php -l "$OUT/config.php" >/dev/null

# الملف الجاهز: ملف الرفع نفسه + app/config.php (صلاحيات 644 مثل باقي الملفات على الاستضافة)
FILES=("$ZIP" "$DOCS")
if [ -n "${WOOD_DB_NAME:-}" ]; then
  READY="wood-inventory-$VERSION-ready.zip"
  STAGE="$(mktemp -d)"
  mkdir -p "$STAGE/app"
  install -m 644 "$OUT/config.php" "$STAGE/app/config.php"
  cp "$OUT/$ZIP" "$OUT/$READY"
  (cd "$STAGE" && zip -q -X -9 "$OUT/$READY" app/config.php)
  rm -rf "$STAGE"
  # التحقق: نفس محتوى ملف الرفع بالضبط، وزيادة app/config.php فقط
  diff <(unzip -Z1 "$OUT/$ZIP" | sort) <(unzip -Z1 "$OUT/$READY" | grep -vx 'app/config.php' | sort) >/dev/null \
    || { echo "ready zip content mismatch" >&2; exit 1; }
  unzip -p "$OUT/$READY" app/config.php | cmp -s - "$OUT/config.php" || { echo "ready zip config mismatch" >&2; exit 1; }
  FILES+=("$READY")
fi

SITE_ARG=""
if [ -n "${WOOD_BASE_URL:-}" ]; then SITE_ARG="--site=$(sed -E 's#^https?://##; s#/.*$##' <<<"$WOOD_BASE_URL")"; fi
php "$ROOT/tools/hostinger-guide.php" "$OUT/دليل-الرفع-على-Hostinger.pdf" ${WOOD_DB_NAME:+--prefilled} $SITE_ARG >/dev/null

(cd "$OUT" && sha256sum "${FILES[@]}" "دليل-الرفع-على-Hostinger.pdf" > SHA256SUMS)
echo "bundle ready: $OUT"
ls -la "$OUT"
