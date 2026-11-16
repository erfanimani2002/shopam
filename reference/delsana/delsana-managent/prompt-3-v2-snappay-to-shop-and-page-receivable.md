# Task: Snap Pay pays the SHOP (not the page) — fix the model, then add a "page's receivable from the shop" ledger with partial receipts

Repo: `delsana-manage` (plain HTML/CSS/vanilla JS PWA, Supabase backend, Persian/RTL UI, no build step).
Written against the current `main` (HEAD `fa55302`). Re-read the files named below before changing anything; line numbers are approximate.

Read first, in this order: `README.md` (sections 7, 8, 9, 12, 13), `CHANGELOG.md` (the v5 / migration 012 report), `calc.js`, `setup.sql` (`orders` table + CHECK constraint, `order_amounts`, `create_order`, `set_order_status`, `bulk_set_status`, `admin_update_order`, `admin_delete_order`, `admin_restore_order`, role helpers `is_member`/`is_mahdi`/`is_admin`/`my_role`), `db.js`, and in `screens.js` the new-order summary, the order card, the admin edit modal, `accounts` / `bucketCard`, `reports` and the CSV export. Ignore `graphify-out/`, `lib/`, and the old `delsana-*-prompt.md` files except as style reference.

---

## 1. The business truth (source of truth for this task)

- Partners: **page** (role `helia`, label «پیج») sells on Instagram; **shop** (role `mahdi`, label «مغازه») owns the stock and ships; `admin` is the owner with all powers.
- **Snap Pay settles to the SHOP's account, always.** The cash/card part of a customer payment is received by the PAGE.
- The app currently assumes the opposite: it treats the Snap deposit as money held by the page, and it makes the page "owe" the shop `owe` for every paid order, including pure Snap Pay orders where the shop already holds the money.
- Per-order entitlements are already correct and must NOT change: shop = `owe` (Σ q·base − shop_discount + shipping_fee), page = `h_share` (Σ q·hp − page_discount), `owe + h_share = payout`.
- **Two completely independent accounts. They are NEVER netted against each other, anywhere (no combined number, no "net balance" line):**
  1. **Page's claim on the shop** («طلب پیج از مغازه»): the page's profit share inside money that Snap Pay paid to the shop.
  2. **Page's debt to the shop** («بدهی پیج به مغازه»): the shop's share inside money the page received in cash/card (plus shipping paid in cash).

### Per-order split (exact definition, integer toman)

```
shipSnap   = shipping_fee if shipping_payment_method = 'snapp' else 0
shipCash   = shipping_fee − shipSnap
net        = (owe − shipping_fee) + h_share                 -- goods value after discount
goodsSnap  = snapp_deposit − shipSnap                        -- goods money that went to the shop via Snap Pay

page_claim = 0                                  if net <= 0 or h_share <= 0 or goodsSnap <= 0
           = min(goodsSnap, round_half_up(h_share * goodsSnap / net))   otherwise
page_debt  = owe − snapp_deposit + page_claim                -- always >= 0, see tests
```

Meaning: the Snap-paid goods money is split between shop and page in the ratio of their entitlements (`owe_goods : h_share`); the cash-paid goods money and the cash-paid shipping are the page's debt to the shop. Conservation holds by construction: `(snapp_deposit − page_claim) + page_debt = owe` (what the shop finally gets) and the page ends with `page_claim + (cash_charge − page_debt) = h_share`.
Use exact integer math for the rounding (`BigInt` in JS, `numeric` in SQL — `h_share * goodsSnap` can exceed 2^53).

Examples: pure cash → claim 0, debt = owe. Pure Snap, courier → claim = h_share, debt 0. Pure Snap + post fee paid in cash → claim = h_share AND debt = the post fee (two separate numbers, not netted). Mixed with a small cash part → a slightly smaller claim and a small debt.

A claim becomes collectable only after Snap Pay has actually settled to the shop, i.e. the order status is `paid`, `settled`, `shipped` or `delivered` (not `new`, `awaiting_snapp`, `cancelled`).

---

## 2. Part A — fix the reversed assumption

**Hard rules for Part A**
- Do NOT change any stored money value, `order_amounts` math, `calcOrder` money math, the `orders_amounts_invariants_check` constraint, or the status machine. This part changes meaning, labels, and how Accounts classifies orders.
- Legacy rows (`fee_in_snapp = false`) must work with the same formulas.

**A1. `calc.js` (single source of truth)**
- Add pure functions, e.g. `Calc.pageSplit(order)` → `{ goods_snapp, page_claim, page_debt }`, working on both a `calcOrder` result and a stored order row (use `Calc.snappDeposit(o)`, `o.owe`, `o.h_share`, `o.shipping_fee`, `Calc.shippingPaymentMethod(o)`).
- Also add `page_claim` and `page_debt` to the `calcOrder` return object (derived only; nothing else changes). Assert `page_claim >= 0`, `page_debt >= 0` and `snapp_deposit − page_claim + page_debt === owe` next to the existing invariants.
- Update the header comment: Snap Pay settles to the shop; `owe` is the shop's entitlement, not a transfer from the page; describe the split above. Bump `Calc.VERSION`.

**A2. Labels and comments (find every occurrence with grep, don't rely on this list alone)**
- «واریزی اسنپ‌پی به پیج» → «واریزی اسنپ‌پی به مغازه» (new-order summary and admin modal summary in `screens.js`).
- Accounts bucket «در انتظار تسویه اسنپ‌پی»: hint «پول این سفارش‌ها هنوز به حساب پیج نرسیده است» → «هنوز به حساب مغازه واریز نشده است»; this bucket's row button «پول رسید» → «واریز شد به مغازه» (same status transition as today); update its confirm text.
- `owe` is no longer "what the page pays the shop". Rename its display labels to «سهم مغازه»: order card cell «به مغازه», summary row «پرداختی به مغازه», Reports KPI «پرداختی به مغازه», admin edit labels, `tests.html` column header, and the Telegram invoice line «به مغازه:» in `supabase/functions/_shared/format.ts` (+ update `format.test.ts`). Keep CSV header names unchanged.
- Invoice line «واریزی اسنپ‌پی:» → «واریزی اسنپ‌پی (به مغازه):» in `format.ts` + `format.test.ts`.
- Fix inaccurate comments in `setup.sql` (`payout` = total entitlement `owe + h_share`, not "what Snapp will deposit"; `snapp_deposit` = what Snap Pay settles to the SHOP; `owe` = the shop's entitlement) and README sections 7, 8 (Accounts row), 9 (assumptions 15 and 17 mention "page account"). Never edit old migration files.
- The new-order status option «پرداخت شده (پول در حساب پیج)» (cash orders; the money really is with the page) may stay; check the wording and report.

**A3. Accounts bucket «بدهی پیج به مغازه» (`accounts.refresh`, status `paid`)**
- Sum and show `page_debt` per order (NOT `owe`). List only orders with `page_debt > 0`.
- A `paid` order with `page_debt === 0` owes the shop nothing, but must still reach `settled` before the shop can ship (the DB state machine stays unchanged). Show those rows in the same card under the sub-heading «بدون بدهی به مغازه» with the row button «آماده ارسال» (same `DB.setStatus(id,'settled')`), excluded from the debt sum; bulk button uses the same `bulk_set_status('settled')`.
- Keep selection persistence (`accSel`, `pruneSel`) working for any new rows.

---

## 3. Part B — ledger «طلب پیج از مغازه» with partial receipts and per-invoice detail

Requirements from the owner:
1. The page sees the total it should still collect from the shop.
2. The page sees WHICH invoice (order) each part of the claim belongs to.
3. The page can record a receipt of any amount, including partial: e.g. the claim is 1,000,000 → the shop pays 500,000 now → the remaining 500,000 stays open until it is paid later.

**B1. Data model — new migration `migrations/013_page_claims.sql` (idempotent) mirrored into `setup.sql`**

Do NOT store the claim per order; derive it with SQL functions that implement the section-1 formulas (`public.order_page_claim(o public.orders)`, `public.order_page_debt(o public.orders)`, `immutable`, exact `numeric` rounding half up). JS (`Calc.pageSplit`) and SQL must agree — add a parity test.

Tables (RLS enabled; `select` policy `using (public.is_member())`; NO insert/update/delete policies — all writes via RPC, like `orders`):
- `public.page_claim_receipts`: `id uuid pk default gen_random_uuid()`, `created_at timestamptz default now()`, `created_by uuid`, `received_on date not null` (the day the shop paid; defaults to today in the UI, editable), `amount bigint not null check (amount > 0)`, `note text not null default ''`, `voided_at timestamptz null`, `voided_by uuid null`.
- `public.page_claim_allocations`: `id uuid pk`, `receipt_id uuid not null references page_claim_receipts(id) on delete cascade`, `order_id uuid not null references orders(id) on delete restrict`, `amount bigint not null check (amount > 0)`, unique `(receipt_id, order_id)`, plus snapshot columns `customer text`, `order_created_at timestamptz` (for display).
- Index on `allocations(order_id)`.
- A receipt's allocations must always sum to the receipt amount (enforced in the RPC; also add a deferred constraint trigger or an RPC-level assertion and test it).
- Voided receipts are ignored in every sum but stay visible in history.

Order bookkeeping per invoice: `claim`, `received = Σ allocations of non-voided receipts`, `remaining = max(0, claim − received)`. If `received > claim` (e.g. admin edited the order later) or an order with allocations is no longer eligible (cancelled / moved back before `paid`), flag it as «مغایرت» in a «نیاز به بررسی» list; never silently change or delete receipt data.

RPCs (`security definer`, `set search_path = public`, Persian error messages, same style as `bulk_set_status`; revoke from public/anon, grant execute to authenticated; `notify pgrst, 'reload schema'` at the end of the migration):
1. `record_page_claim_receipt(p_amount bigint, p_received_on date, p_note text default null, p_allocations jsonb default null) returns uuid`
   - Only roles `helia` and `admin` (use `my_role()`); the shop (`mahdi`) is read-only. Otherwise «فقط پیج یا ادمین می‌تواند دریافتی ثبت کند».
   - Lock eligible orders (`status in ('paid','settled','shipped','delivered')`, `claim > 0`) `for update`, ordered by `created_at`.
   - `p_allocations is null` → automatic: allocate to the oldest eligible invoices with `remaining > 0` first until the amount is exhausted. If the amount exceeds the total remaining, reject and show the maximum allowed.
   - `p_allocations = [{order_id, amount}, …]` → manual: every amount > 0 and ≤ that invoice's remaining, order eligible, sum EXACTLY equals `p_amount`, no duplicate orders; otherwise reject with an exact message.
   - Inserts the receipt + allocations with the snapshot fields; returns the receipt id. `received_on` may not be in the future.
2. `void_page_claim_receipt(p_receipt_id uuid, p_reason text default null) returns void` — `helia`/`admin` only; sets `voided_at/voided_by`; idempotent; the invoices' remaining amounts reappear.
3. `admin_delete_order` must refuse to delete an order that has allocations (clear Persian message: void/move the receipt first); `admin_restore_order` must keep working. `admin_update_order` needs no reset logic (mismatch is flagged, section above).
4. None of these may create an outbox/Telegram event (check `tg_order_events`; they touch other tables, so there should be none). Optional follow-up only: notify the shop when a receipt is recorded — do NOT build it now.

**B2. `db.js`**
- `DB.loadClaims()` loads receipts + allocations (paged like `fetchAll`) into `App.claims`; call it wherever `loadOrders` is refreshed (initial load, realtime/polling refresh). Add the two tables to the realtime subscription only if the existing wiring makes that trivial; otherwise the 45 s polling is enough.
- `DB.recordPageClaimReceipt(amount, receivedOn, note, allocations)` and `DB.voidPageClaimReceipt(id, reason)`.

**B3. Accounts UI — new card at the TOP of «حساب‌ها», visible to everyone (both partners see identical numbers); recording/voiding buttons only for `helia` and `admin`**
- Title «طلب پیج از مغازه». Stats: «جمع طلب» (Σ claim of eligible orders), «دریافت‌شده» (Σ received), «مانده» (Σ remaining, big/accent), and an informational line «در انتظار واریز اسنپ‌پی به مغازه (هنوز قابل مطالبه نیست)» = Σ claim of `awaiting_snapp` orders.
- Button «ثبت دریافتی از مغازه» → dialog: amount (use the app's money parser from `calc.js`, Persian digits accepted), date (default today), note, and a mode switch:
  - «خودکار — قدیمی‌ترین فاکتورها اول» (default). Show a live preview table «این مبلغ به این فاکتورها تخصیص داده می‌شود» (customer, date, amount allocated, remaining after) before confirming.
  - «انتخاب دستی فاکتورها»: list of invoices with remaining > 0, an amount input per invoice (a «همه» shortcut fills the full remaining), live «تخصیص‌یافته / مبلغ دریافتی / باقی‌مانده برای تخصیص». Confirm is disabled until the sum equals the amount.
- Invoice list (default filter «مانده‌دار», others «تسویه‌شده» and «همه», oldest first): each row shows customer, date, order status pill, payment mix, **claim / received / remaining** with a small progress bar. A per-row «دریافت…» button opens the dialog in manual mode with that invoice preselected and its remaining pre-filled.
- Row «جزئیات» expander (this is the "which invoice is it about" requirement) shows:
  - the invoice's items (name × qty, page profit per line);
  - the formula lines: goods paid via Snap Pay (deposited to the shop), invoice goods value after discount, page's share, `claim = share × Snap goods ÷ goods value`;
  - the invoice's receipts: date, amount, note, receipt id short form, with «ابطال‌شده» styling for voided ones.
- Second section «دریافتی‌ها»: receipts newest first: date, amount, note, and the list of invoices it was allocated to (customer · date · amount), «ابطال» button (confirm dialog with the amount) for `helia`/`admin`.
- Third section «نیاز به بررسی»: mismatches (received > claim, ineligible order with allocations), with a plain explanation. Hidden when empty.
- Use the existing helpers/styles (`el`, `UI.confirm`, `UI.fmtMoney`, `UI.toFaDigits`, `UI.fmtDateTime`, `UI.withBusy`, `.card`, `.pill`, `.stat`, `bucketCard` conventions). After any write: `DB.loadOrders()`, `DB.loadClaims()`, repaint, `App$.refresh(false)`.

**B4. Order card, forms, exports**
- Order card: show two SEPARATE cells when applicable: «طلب پیج از مغازه» (with a small «دریافت شده X از Y» pill; «پس از واریز اسنپ‌پی» while `awaiting_snapp`) and «بدهی پیج به مغازه». Never a combined cell.
- New-order summary and admin edit summary: rows «طلب پیج از مغازه» and «بدهی پیج به مغازه» from `calcOrder` (hint: «سهم پیج از پولِ اسنپ‌پی که به مغازه واریز می‌شود»).
- CSV export: append `page_claim`, `page_debt`, `page_claim_received` columns at the END; add a second CSV button for receipts (receipt id, date, amount, note, invoices) if the CSV helper makes it trivial.

---

## 4. Tests (must be added and all pass; show the output)

Use `snapp_multiplier = 1.13`, `rounding_step = 1000`, `post_fee = 190000`; item base 5,000,000 + hp 500,000 unless noted.

| # | Case | Expected |
|---|------|----------|
| T1 | pure Snap, courier | deposit 5,500,000; owe 5,000,000; claim 500,000; debt 0 |
| T2 | pure Snap, post, shipping paid in cash | deposit 5,500,000; owe 5,190,000; claim 500,000; debt 190,000 |
| T3 | pure Snap, post, shipping paid via Snap | deposit 5,690,000; owe 5,190,000; claim 500,000; debt 0 |
| T4 | pure cash, courier | claim 0; debt 5,000,000 |
| T5 | mixed v5: cash 100,000, Snap 6,100,000, courier | deposit 5,400,000; owe 5,000,000; claim 490,909; debt 90,909 |
| T6 | mixed v5: cash 4,000,000, Snap 1,695,000, courier | deposit 1,500,000; owe 5,000,000; claim 136,364; debt 3,636,364 |
| T7 | pure Snap, courier, discount 100,000 borne by shop | owe 4,900,000; h_share 500,000; claim 500,000; debt 0 |
| T8 | pure Snap, courier, discount 100,000 borne by page | owe 5,000,000; h_share 400,000; claim 400,000; debt 0 |
| T9 | cash goods + shipping via Snap, post | deposit 190,000; owe 5,190,000; claim 0; debt 5,000,000 |
| T10 | legacy mixed row (`fee_in_snapp=false`, existing test M2): payout 840,000, owe 690,000, h_share 150,000, snapp_deposit 275,000, cash_charge 565,000, post fee 190,000 paid in cash | claim 63,462; debt 478,462 |

- Sweep (JS in `tests.html`, SQL in `tests/`): for ≥500 random combos of method / shipping / discount bearer / cash part, assert `page_claim >= 0`, `page_debt >= 0`, `(snapp_deposit − page_claim) + page_debt === owe`, `page_claim <= h_share` when `h_share > 0`, and JS value === SQL function value. If `page_debt` ever comes out negative, stop and report it instead of clamping.
- `tests.html`: new section for `pageSplit` (T1–T10 + sweep). Keep all existing sections green.
- `tests/page_claims.sql` (same style as `tests/mixed_payment.sql`, rolled back, ends with `ALL PASSED`): role gate (`mahdi` and non-members rejected, `helia` and `admin` allowed); ineligible orders (claim 0, `awaiting_snapp`, `new`, cancelled) never receive allocations; **partial receipts** (claim 1,000,000: receive 500,000 → remaining 500,000 → receive 500,000 → remaining 0); automatic FIFO allocation across several invoices, including a receipt that splits across two invoices; manual allocation with exact-sum validation and over-allocation rejection; receipt larger than total remaining rejected; future `received_on` rejected; void restores remaining and is idempotent; `admin_delete_order` blocked when allocations exist; `admin_restore_order` still works; over-received mismatch appears after an admin edit that lowers the claim; no outbox rows created by any of these RPCs.
- `deno test supabase/functions/_shared/format.test.ts` updated for the renamed invoice lines.
- Manual walk-through to report: create T5 as the page → it shows under «در انتظار تسویه اسنپ‌پی» and only in the claims card's «در انتظار…» line → press «واریز شد به مغازه» → claim 490,909 appears in the ledger with its invoice details → record a 200,000 receipt (auto) → remaining 290,909 → record the rest manually → invoice shows fully received → void the first receipt → remaining returns.

---

## 5. Constraints and delivery

- Keep the existing code style (classic scripts, `el()` helpers, Persian UI, RTL), file structure and naming. No new dependencies, no build step. Don't touch unrelated code. Don't edit old migrations.
- Money is integers (toman). Money rules live in `calc.js` and in SQL functions/RPCs; the UI never invents its own formula.
- Bump the service worker cache name in `sw.js` to the next number after whatever it is at that moment (another change may have bumped it already).
- Update `README.md` (sections 7, 8 Accounts row, 9 assumptions, 12 upgrade order incl. migration 013 with a "run before pushing the frontend" note) and add a short change report at the top of `CHANGELOG.md` in the same style.
- Rollout order to state in your summary: export CSV backup → run `013_page_claims.sql` → push frontend.

## 6. Process

1. **Explore first, no edits.** Reply with a short summary of: the exact places that assume Snap Pay goes to the page, your plan, and any conflict you find between this prompt and the code. Then continue without waiting unless something blocks.
2. Implement Part A, run tests, then Part B, run tests.
3. At the end list: changed files, what each test run printed, and these points with the default chosen:
   - existing old orders have no receipts and start fully «مانده‌دار» (no backfill; the page records one lump receipt per past period, or the owner decides separately);
   - the proportional split of Snap-paid goods money between shop and page (section 1) is the single rule used everywhere;
   - receipts are recorded by page/admin only; the shop can see everything.
