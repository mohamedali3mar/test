#!/usr/bin/env bash
# تثبيت جديد من حزمة Hostinger بنفس خطوات صاحب الموقع، على Apache المحلي، في تخطيطين:
#   1) النظام على النطاق مباشرة (/srv/wood)   2) في مجلد فرعي (/srv/wood/wood)
# ثم فحص التحويل إلى https مع force_https كما في الحزمة.
# config.php يُولد بنفس tools/hostinger-config.php ببيانات قاعدة الاختبار. الفرق الوحيد عن الاستضافة:
# force_https=false أثناء التثبيت لأن الخادم المحلي بلا شهادة.
# الاستخدام: tests/install_bundle.sh [ملف ZIP]
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
ZIP="${1:-$(ls -t "$ROOT"/dist/wood-inventory-*-upload.zip | head -1)}"
SITE=/srv/wood
DB=wood_e2e
status=0

for layout in root subfolder; do
  rm -rf "$SITE"
  mkdir -p "$SITE"
  dir="$SITE"
  base="http://localhost:8080/"
  if [ "$layout" = subfolder ]; then
    dir="$SITE/wood"
    base="http://localhost:8080/wood/"
    mkdir -p "$dir"
  fi
  unzip -q "$ZIP" -d "$dir"
  WOOD_DB_NAME=$DB WOOD_DB_USER=wood_app WOOD_DB_PASS='Test-Pass-2026' \
    php "$ROOT/tools/hostinger-config.php" "$dir/app/config.sample.php" "$dir/app/config.php"
  sed -i "s/'host'     => 'localhost'/'host'     => '127.0.0.1'/; s/'force_https' => true/'force_https' => false/" "$dir/app/config.php"
  key="$(php -r 'define("APP_ROOT", 1); echo (require $argv[1])["install_key"];' "$dir/app/config.php")"
  chown -R www-data:www-data "$SITE"
  mariadb -uroot -e "DROP DATABASE IF EXISTS $DB; CREATE DATABASE $DB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
  WOOD_BASE="$base" WOOD_DB="$DB" WOOD_KEY="$key" python3 -I "$ROOT/tests/http_install_bundle.py" || status=1

  # force_https كما في الحزمة: طلب http يُحوَّل إلى https على نفس المسار.
  # الانتظار 3 ثوانٍ: OPcache يعيد فحص الملفات المعدلة كل ثانيتين (opcache.revalidate_freq)
  sed -i "s/'force_https' => false/'force_https' => true/" "$dir/app/config.php"
  sleep 3
  loc="$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' -A 'Mozilla/5.0 (Windows NT 10.0) Chrome/129.0' "${base}index.php?r=login")"
  want="301 https://localhost:8080/${base#http://localhost:8080/}index.php?r=login"
  if [ "$loc" = "$want" ]; then echo "  PASS  http يُحوَّل إلى https على نفس المسار ($layout)"; else echo "  FAIL  التحويل إلى https ($layout): $loc (المتوقع $want)"; status=1; fi
  # مع base_url (كما في حزمة صاحب الموقع): التحويل إلى النطاق المضبوط وليس ترويسة Host
  sed -i "s#'base_url' => ''#'base_url' => 'https://wood.example.com'#" "$dir/app/config.php"
  sleep 3
  loc="$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' -H 'Host: attacker.test' -A 'Mozilla/5.0 (Windows NT 10.0) Chrome/129.0' "${base}index.php?r=login")"
  want="301 https://wood.example.com/${base#http://localhost:8080/}index.php?r=login"
  if [ "$loc" = "$want" ]; then echo "  PASS  التحويل يستخدم base_url وليس Host ($layout)"; else echo "  FAIL  التحويل مع base_url ($layout): $loc (المتوقع $want)"; status=1; fi
done
exit $status
