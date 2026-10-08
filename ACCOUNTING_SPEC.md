# Phase 2 accounting: binding specification

Customer decisions (binding):
- Phase 2 ships together with Phase 1.
- Costing uses the **moving weighted average**.
- Sale invoices are **cash, credit, or partial**, with the customer chosen from a list.
- The document date is **automatic**. **Only an admin** may enter a past date, and it is audited.

Foundation already on the branch (do not redesign it, build on it):
- `public_html/app/migrations/006_accounting.sql`: all accounting tables and columns. Do not add new migrations without the lead's approval.
- `public_html/app/lib/accounting.php`: money helpers, permission and audit shims, locks, ledgers, reversal, doc date, closing date, select lists. Read it fully.
- `documents.php`: `insert_document()` and `insert_line()` already accept these keys:
  - `doc_date`, `party_id`, `payment_type`, `paid_amount`, `cash_box_id`, `cash_box_name`, `total_cost`;
  - lines: `cost_per_m3`, `cost_amount`.
- `tests/accounting_foundation.php`: example test style.

## 1. Money and invariants
1. **Money representation:**
   - PHP holds money as **int piasters**. Use `money_to_piasters()`, `piasters_to_money()`, `fmt_piasters()`, `fmt_party_balance()` and `parse_money_input()`.
   - The DB uses `DECIMAL(24,2)`. Never use floats.
2. **Stored balances:** `parties.balance` and `cash_boxes.balance` change **only** through `ledger_party()` and `ledger_cash()`. Each one inserts the ledger row and updates the balance in the same statement sequence, inside the caller's transaction.
   - Invariant: balance = opening_balance + SUM(ledger.amount).
   - `acct_verify_balances()` must return `[]` at the end of every test.
3. **Sign convention:**
   - For the party ledger, a positive amount increases what is owed. For a customer that is what the customer owes us; for a supplier, what we owe the supplier.
   - For the cash ledger, positive means money in and negative means money out.
   - A cash box can never go negative: `ledger_cash` throws a `ValidationException`, and the DB also has a CHECK.
4. **Cancellation:** never delete or update ledger rows. Call `reverse_ledgers()` with the cancellation date.
   - Lock the parties and cash boxes from `ledger_sources_of()` **before** reversing, in the lock order.
   - Cancellation is refused if the original doc date is in a closed period (`period_is_open`).
5. **Lock order:** the full order is in the header of `accounting.php`. It extends the order in `documents.php`, so update that header comment too.
   - Party row → cash boxes (ascending) → items → stock → counter → inserts → ledgers → `acct_audit` → `data_version_bump` last.
   - Items are locked **FOR UPDATE** when inventory value changes: receipt, sale, and cancelling either. They are locked in **share mode** for transfers.
6. **Idempotency:** same as Phase 1, with `request_token` and `request_hash`.
   - The hash must include the new fields (party, payment type, paid amount, cash box, doc date, costs). The same token with different content gives a clear error.

## 2. Weighted average cost (owner: Agent A)
For each item, `items.inventory_value` holds V, the value of the item's total stock across all warehouses, in piasters (DECIMAL). Q is the item's total pieces, `SUM(stock.qty_on_hand)` over all warehouses. Read Q with a **locking read** (`... LOCK IN SHARE MODE`) after locking the item row.

**Receipt** of q pieces, with `cost_per_m3` c (optional, > 0):
- **With cost:** the line value is A = `sale_amount_piasters(total_um3, c)`, the same rounding as sales. V += A. The line stores `cost_per_m3` = c and `cost_amount` = A.
- **Without cost:**
  - If Q > 0: A = `muldiv_half_up(V, q, Q)`, so the receipt is valued at the current average. V += A.
  - If Q = 0: A = 0 and the stock is "unvalued".
  - The line stores `cost_per_m3` = NULL and `cost_amount` = A.
- **Document total:** `total_cost` = sum of the line `cost_amount` values.

**Sale** of q pieces: COGS = `muldiv_half_up(V, q, Q)` (when q = Q, COGS = V exactly, so there is no residue). V -= COGS. The sale line stores `cost_amount` = COGS, and the document `total_cost` = sum of line COGS.
- Profit = `total_amount` − `total_cost`.

**Cancel sale:** for each line, V += `cost_amount` (the stock returns at its snapshot cost).

**Cancel receipt:** for each line, V -= `cost_amount` (stock is already validated to stay ≥ 0).
- If the result is V < 0, or Q becomes 0 with V ≠ 0, set V to the boundary: 0 when V < 0, and 0 when Q = 0.
- Insert a `cost_adjustments` row (item, document, the amount written off, Arabic reason) so the profit report shows it.

**Transfers** do not change V.

**Opening valuation** (admin page): lists items with Q > 0 and V = 0. The admin enters a cost per m³, and V is set to `sale_amount_piasters(Q × piece_um3, c)`. This is audited, done in a transaction with the item locked FOR UPDATE, and allowed only while V = 0.

Back-dated documents affect the average in **posting order** (not date order). Document this in the handover.

## 3. Documents with payment (owner: Agent A)
**Sale form:**
- **Customer:** a select of active customers plus «عميل نقدي (بدون حساب)».
- **Payment type:** radio نقدي / آجل / دفع جزئي.
- **Paid amount:** shown for partial.
- **Cash box:** a select of `cash_boxes_for_user()`, defaulting to the first.
- **Date:** shown to admins only (`acct_can('manual_date')`), empty = today.

Validation rules:
- **Cash:** paid = total, the cash box is required, the customer is optional.
- **Credit:** a customer is required and paid = 0.
- **Partial:** a customer is required and 0 < paid < total. The cash box is required.
- **Credit limit:** if the customer has `credit_limit` and the new balance would exceed it, reject with the available amount.

Ledgers, when a customer is set:
- party +total (`sale`, «فاتورة بيع رقم N»).
- If paid > 0: party −paid (`sale_payment`) and cash +paid (`sale`).

Without a customer (cash): cash +total only.

Review/confirm page shows customer, payment type, paid, remaining and new customer balance. Print shows customer, payment type, paid and remaining. Never print cost or profit. The document detail page shows cost and profit **only** when `acct_can('reports.profit')`.

**Receipt form:**
- **Supplier:** a select of active suppliers, or none. The free-text `party_name` stays for receipts without an account.
- **Cost per m³:** optional.
- **If a supplier and a cost are given:** the payment type is cash / credit / partial with a paid amount and cash box.
- Ledger: supplier +total_cost (`purchase`). If paid > 0: supplier −paid (`purchase_payment`) and cash −paid (`purchase`); cash cannot go negative.
- **Cash purchase without a supplier account** (cost given, no supplier): cash −total_cost.
- **Without a cost:** `payment_type` is NULL.

**Cancel any sale/receipt:**
- Lock the document, then the party and cash boxes from `ledger_sources_of`, then items FOR UPDATE, then stock.
- Reverse the ledgers, revert V, and keep the existing stock rules.
- Refund rules:
  - Cancelling a sale whose cash would make the box negative is refused («رصيد الخزنة لا يكفي لرد المبلغ»).
  - Cancelling a credit sale reduces the customer balance (it may become negative, which is a credit balance; that is allowed).

**Manual date:** documents get `doc_date` from `acct_doc_date($in, $errors)`. The documents log and reports filter by `doc_date`. Cancellation uses `now()` for reversal entries.

## 4. Parties, cash boxes, vouchers, statements (owner: Agent B)
**Parties.** Route `parties&kind=customer|supplier`, with NAV labels «العملاء» / «الموردون».
- **List:** name, phone, balance (`fmt_party_balance`), credit limit, status, and a link to the statement.
- **Create** (staff may create): name with `name_key` uniqueness per kind, phone, address, notes, opening balance (≥ 0, with direction «عليه/له»), credit limit.
- **Edit** (admin only): opening balance edits shift `balance` by the delta under the party row lock. Audited.
- **Deactivate/activate** (admin): deactivate instead of delete. Delete is allowed only when there are no ledger rows and no documents.

**Statement** `party_statement&id=N&from&to`:
- An opening line: opening_balance + SUM(amount) before `from`.
- Rows ordered by (entry_date, id): date, description with a link to the document/voucher, debit/credit columns in plain Arabic («عليه» / «له»), running balance.
- Totals and closing balance.
- Printable (print CSS), with a live region.

**Cash boxes** (admin manages): route `cash_boxes`.
- **List:** name, branch (if branches exist), balance.
- **Create/rename/deactivate**, with opening balance ≥ 0 at creation and audited.
- **Cash box statement** `cash_statement&id&from&to`: daily movement with an opening line, in/out columns and running balance, plus a «حركة اليوم» summary.

**Expense categories** (admin): route `expense_categories`, with create/rename/deactivate.

**Vouchers.** One service `app/lib/vouchers.php`: `record_voucher(PDO, int $userId, string $kind, array $in): array` and `cancel_voucher(PDO, int $userId, int $id, string $reason): array`. Kinds:
- **collect (سند قبض):** customer + cash box + amount. Party −amount (`collect`), cash +amount (`collect`). Collections larger than the balance are allowed (advance payment) but the page warns first.
- **pay (سند صرف):** supplier + cash box + amount. Party −amount (`pay`), cash −amount (`pay`); the cash cannot go negative.
- **expense (مصروف):** category + cash box + amount + notes. Cash −amount (`expense`).
- **cash_transfer:** from box → to box. Cash −amount (`transfer_out`) and +amount (`transfer_in`). Lock both boxes ascending.

Each voucher kind has:
- Gapless numbering through counters `doc_collect`, `doc_pay`, `doc_expense` and `doc_cash_transfer` with `counter_next()`.
- `voucher_date` via `acct_doc_date($in, $errors, 'voucher_date')`.
- Idempotency (`request_token`/`request_hash`, same pattern as `existing_request`).
- Audit, then `data_version_bump`.
- Branch snapshot: `branch_id`/`branch_name` = the creating user's branch when that user is restricted to one branch (used by the home page "today's collections" of that branch), NULL when the user sees all branches (a company-wide voucher). Balances and statements ignore it: accounts stay shared.

Pages:
- `vouchers` log: filters by kind, date, cash box, party, status, number; totals.
- `voucher&id=N` detail with cancel (admin only, reason, and the closed-period check).
- `voucher_print&id=N`: a printable voucher with the amount also in words (Arabic `tafqeet` for whole pounds and piasters; implement `amount_in_words_ar(int $piasters): string` with tests).
- Forms: `collect`, `pay`, `expense`, `cash_transfer`.

## 5. Accounting reports and dashboard (owner: Agent C)
Read only. Use the ledgers and documents (`status` = 'active' rows only, plus reversals are already netted in ledgers). For document reports, use `doc_date` and exclude cancelled documents.

Each report is a function returning the dataset contract (`title`, `subtitle`, `columns` [key, label, type text|int|volume|money|date, align], `rows`, `totals`, `generated_at`). Raw money values are decimal strings like "1234.50". Each report also has an HTML page rendering the same dataset. Reports:
1. **Sales by period:** day or month; count, m³, amount, cost, gross profit, margin %.
2. **Sales by** wood type, warehouse, customer, and branch when `branch_id` columns exist (check with information_schema once).
3. **Profit:**
   - Revenue, COGS, gross profit.
   - Minus expenses (by category) and cost adjustments, giving net profit, for a date range.
4. **Inventory valuation:** per item, Q, m³, average cost per m³ (V ÷ total m³) and V.
   - Per warehouse: value = V × q_w ÷ Q, with half-up rounding and residue to the largest row so the totals match exactly.
   - Unvalued items are flagged.
5. **Customer balances and supplier balances:** non-zero by default, with totals. **Receivables aging** (0–30/31–60/61–90/90+) based on unpaid invoice dates (FIFO allocation of payments) is optional; do it only if time permits.
6. **Cash:** the balance of each box, movement for a period by type, and the daily balance.
7. **Expenses** by category and period.

**Dashboard** route `dashboard`, admin, NAV «لوحة التحكم». Contents:
- Today and this month: sales count/amount, collections, expenses, cash in boxes.
- Receivables and payables totals; top 5 customers by balance; gross profit this month.
- Unvalued stock warning.
- A link to every report.
- Live regions `id=live-dashboard-*`.

The route `reports&r=<key>` shows a filters form (dates, warehouse, type, customer, group by) and the table, with an Arabic subtitle describing the filters. Exports go through the reports/export layer being built elsewhere. Expose the datasets through `acct_report(string $key, array $filters): array` and the definitions `ACCT_REPORTS`, so the lead can register them.

## 6. Permissions (shims now; the lead adds them to the PERMISSIONS map)
Use `acct_can($perm)` for UI decisions and `acct_require($perm)` at the top of pages and in POST handlers. Services must also check `acct_can` for state changes.

| Permission | Roles | Notes |
|---|---|---|
| `manual_date` | admin | |
| `parties.create` | admin, staff | |
| `parties.manage` | admin | edit, opening balances, activate |
| `vouchers.collect` | admin, staff | |
| `vouchers.expense` | admin, staff | |
| `vouchers.pay` | admin | |
| `vouchers.cash_transfer` | admin | |
| `vouchers.cancel` | admin | |
| `cash.manage` | admin | cash boxes and categories |
| `statements.view` | admin, staff | |
| `reports.sales` | admin | |
| `reports.profit` | admin | also profit on document page |
| `reports.valuation` | admin | also opening valuation |
| `dashboard` | admin | |

## 7. Rules for every agent
- Arabic UI, RTL, DESIGN_RULES.md (binding).
- `h()` on every output, CSRF on every POST, prepared statements only, POST/redirect/GET, flash messages and field errors as in the existing pages.
- No inline JS or CSS (CSP).
- Add `data-label` attributes to `<td>`, and put live regions in `[data-live][id]`.
- **Do not** edit the NAV array in `view.php` (the lead groups the menu). Do add your routes to the ROUTES map in `index.php`, plus your `require` lines in `bootstrap.php`.
- Tests:
  - Add `tests/integration_<module>.php` using `tests/lib.php`, with exact numeric examples, concurrency barrier tests where state is shared (see `tests/integration.php` and `tests/worker.php`), and `acct_verify_balances() === []` at the end.
  - Keep `tests/unit.php`, `tests/parity.cjs`, `tests/integration.php` and `tests/accounting_foundation.php` passing.
- Commit in your worktree. The message ends with the two attribution lines.
