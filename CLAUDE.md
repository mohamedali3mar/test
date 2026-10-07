# CLAUDE.md: guide for any agent continuing this project

Read this file first, then `PROGRESS_AR.md` (current state and next steps) and `WORKLOG_AR.md` (step-by-step log of every session:
what was done, why, how it was verified, the result and the commit). After every finished step: add it to `WORKLOG_AR.md`,
update `PROGRESS_AR.md`, commit and push.
The owner communicates in Egyptian Arabic; reply in Arabic. Code comments and UI text are Arabic.

## What this is

Arabic (RTL) web system for a timber company: inventory by piece count and exact cubic meters, sales and purchases,
accounting (customers, suppliers, cash boxes, vouchers, statements, weighted average cost), reports, users and roles,
branches, monitoring, 2FA. Version in `APP_VERSION` (`public_html/app/lib/core.php`), currently 2.0.0.

- PHP 8.1+ and MySQL/MariaDB via PDO. No framework, no build step, shared hosting (Hostinger). mPDF vendored in `app/vendor`.
- Entry point `public_html/index.php` (`ROUTES`, `?r=<route>`), pages in `app/pages/`, logic in `app/lib/`.
- Permissions: `PERMISSIONS` and `ROUTE_PERMISSIONS` in `app/lib/users.php`; a route missing there is admin only.
- Branch scope: `allowed_branch_id()` in `app/lib/branches.php`; a restricted user sees only their branch.

## Binding rules (do not break)

- `DESIGN_RULES.md`: one primary color, Cairo font only, font sizes 12/14/16/20/24/32 only, no shadows, no gradients,
  no emoji or decorative icons, no long dashes in UI text, buttons 36 to 44px (44 on mobile), RTL, labels on every field.
  `tests/e2e.mjs` enforces most of them on every page.
- `ACCOUNTING_SPEC.md`: money as integer piasters, volumes as exact decimals (`Num`, `BigInt` in JS), never float;
  balances change only through ledgers; cancellation by reversal entries, nothing is deleted; fixed lock order.
- Every save is one transaction: `audit_record()` then `data_version_bump()` last. Read-only actions (exports) audit but never bump.
- Every new page needs a `ROUTE_PERMISSIONS` entry and server-side checks; never trust the UI.
- JS in `assets/js/` is served only through `index.php?r=asset&f=<name>` (`PROTECTED_SCRIPTS` in `app/lib/security.php`).
- Commit and push after every finished step (the container is ephemeral; unpushed work is lost). Update `PROGRESS_AR.md` too.
- Never put model names in commits or files.

## Where things are

| Area | Files |
|---|---|
| Documents, cancellation | `app/lib/documents.php` |
| Accounting | `accounting.php`, `costing.php`, `parties.php`, `cashboxes.php`, `vouchers.php`, `acct_reports.php` |
| Table tools and exports | `app/lib/tables.php` (sorting, toolbar, inventory/log data), `app/lib/exports.php`, `app/pages/export.php`, `assets/js/tables.js` |
| PDF | `app/lib/pdf.php` (`pdf_document`, `pdf_report`), `app/pages/pdf.php` |
| Excel/CSV | `app/lib/xlsx.php` |
| Live updates | `assets/js/app.js` (polls `data_version`, replaces `[data-live]` regions, fires `wood:regions-updated`) |
| Monitoring | `app/lib/audit.php` (`AUDIT_ACTIONS` must list every action key) |

## Test environment (fresh container)

```bash
apt-get update && DEBIAN_FRONTEND=noninteractive apt-get install -y mariadb-server apache2 libapache2-mod-php8.3
service mariadb start
mariadb -uroot -e "CREATE USER IF NOT EXISTS 'wood_app'@'127.0.0.1' IDENTIFIED BY 'Test-Pass-2026'; CREATE USER IF NOT EXISTS 'wood_app'@'localhost' IDENTIFIED BY 'Test-Pass-2026'; GRANT ALL ON \`wood\_%\`.* TO 'wood_app'@'127.0.0.1'; GRANT ALL ON \`wood\_%\`.* TO 'wood_app'@'localhost'; FLUSH PRIVILEGES;"
cp tests/apache-wood.conf /etc/apache2/sites-available/wood.conf && a2ensite wood && a2enmod rewrite headers expires deflate php8.3 && service apache2 start
bash tests/run_all.sh          # builds the ZIP and runs every suite on it; logs in tests/output/
```

Single suites: see the table in `README.md`. HTTP and browser suites need `tests/deploy.sh [zip]` first (fresh DB `wood_e2e`).
Browser tests need `LANG=C.UTF-8` (set in `run_all.sh`) or Chromium names Arabic downloads "download".
