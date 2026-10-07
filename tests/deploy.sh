#!/usr/bin/env bash
# ينشر نسخة من الموقع على خادم الاختبار المحلي (Apache على المنفذ 8080) بقاعدة بيانات جديدة فارغة.
# الاستخدام: tests/deploy.sh [مسار ملف ZIP]   (بدون ملف: ينسخ public_html مباشرة)
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SITE=/srv/wood
DB=wood_e2e

rm -rf "$SITE"
mkdir -p "$SITE"
if [ "${1:-}" != "" ]; then
  unzip -q "$1" -d "$SITE"
else
  rsync -a --exclude 'app/config.php' --exclude 'app/storage/sessions/sess_*' --exclude 'app/storage/logs/*.log' \
    --exclude 'app/storage/installed.lock' "$ROOT/public_html/" "$SITE/"
fi
cat > "$SITE/app/config.php" <<EOF
<?php
defined('APP_ROOT') || exit;
return [
    'db' => ['host' => '127.0.0.1', 'port' => 3306, 'name' => '$DB', 'user' => 'wood_app', 'password' => 'Test-Pass-2026'],
    'install_key' => 'k7Hq2Zp9Lw4Xv1Nb8Rt5',
    'force_https' => false,
    'base_url' => '',
    'timezone' => 'Africa/Cairo',
    'session_idle_minutes' => 120,
];
EOF
# مثل الاستضافة: ملفات الموقع ملك نفس مستخدم PHP
chown -R www-data:www-data "$SITE"
mariadb -uroot -e "DROP DATABASE IF EXISTS $DB; CREATE DATABASE $DB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
: > /var/log/apache2/wood-error.log
echo "deployed to $SITE (db $DB)"
