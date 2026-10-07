#!/usr/bin/env bash
# يشغل كل الاختبارات بالترتيب، على ملف ZIP المبني نفسه (ما سيُرفع للاستضافة).
# المخرجات الكاملة في tests/output/*.log
set -uo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
OUT="$ROOT/tests/output"
mkdir -p "$OUT"
export PLAYWRIGHT_BROWSERS_PATH=/opt/pw-browsers
# أسماء ملفات التحميل العربية في Chromium تحتاج لغة نظام UTF-8 (وإلا يسمي الملف «download»)
export LANG=C.UTF-8 LC_ALL=C.UTF-8
status=0
run() {
  local name="$1"; shift
  if "$@" >"$OUT/$name.log" 2>&1; then
    echo "PASS  $name  ($(tail -1 "$OUT/$name.log"))"
  else
    echo "FAIL  $name  (see tests/output/$name.log)"; tail -5 "$OUT/$name.log"; status=1
  fi
}

run build "$ROOT/tools/build-zip.sh"
ZIP="$(ls -t "$ROOT"/dist/wood-inventory-*-upload.zip | head -1)"
run unit php "$ROOT/tests/unit.php"
run unit-totp php "$ROOT/tests/unit_totp.php"
run unit-security php "$ROOT/tests/unit_security.php"
run parity node "$ROOT/tests/parity.cjs"
run live-timing node "$ROOT/tests/live_timing.cjs"
run eslint /opt/node22/bin/eslint --no-config-lookup -c "$ROOT/tests/eslint.config.mjs" "$ROOT/public_html/assets/js/app.js" "$ROOT/public_html/assets/js/twofactor.js" "$ROOT/public_html/assets/js/payment.js" "$ROOT/public_html/assets/js/tables.js"
run integration php "$ROOT/tests/integration.php"
run integration-auth php "$ROOT/tests/integration_auth_events.php"
run users-audit php "$ROOT/tests/integration_users_audit.php"
run accounting-foundation php "$ROOT/tests/accounting_foundation.php"
run xlsx php "$ROOT/tests/xlsx.php"
run sales-purchases php "$ROOT/tests/integration_sales_purchases.php"
run acct-reports php "$ROOT/tests/integration_acct_reports.php"
run vouchers php "$ROOT/tests/integration_vouchers.php"
run branches php "$ROOT/tests/integration_branches.php"
run accounting-e2e php "$ROOT/tests/accounting_e2e.php"
run pdf php "$ROOT/tests/pdf.php"
run deploy-http "$ROOT/tests/deploy.sh" "$ZIP"
run http python3 -I "$ROOT/tests/http_security.py"
run deploy-http-auth "$ROOT/tests/deploy.sh" "$ZIP"
run http-auth python3 -I "$ROOT/tests/http_auth_events.py"
run deploy-http-permissions "$ROOT/tests/deploy.sh" "$ZIP"
run http-permissions python3 -I "$ROOT/tests/http_permissions.py"
run deploy-http-branches "$ROOT/tests/deploy.sh" "$ZIP"
run http-branches python3 -I "$ROOT/tests/http_branches.py"
run deploy-http-exports "$ROOT/tests/deploy.sh" "$ZIP"
run http-exports python3 -I "$ROOT/tests/http_exports.py"
run deploy-e2e "$ROOT/tests/deploy.sh" "$ZIP"
run e2e node "$ROOT/tests/e2e.mjs"
# على نفس الموقع بعد e2e (يحتاج بيانات): كل الصفحات على 7 مقاسات
run ui-responsive node "$ROOT/tests/ui_responsive.mjs"
exit $status
