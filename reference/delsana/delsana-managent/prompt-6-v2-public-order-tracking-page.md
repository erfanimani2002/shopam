# Task: public order-tracking page for customers («پیگیری سفارش») — lookup by MOBILE NUMBER + NAME

Repo: `delsana-manage` (plain HTML/CSS/vanilla JS PWA, Supabase backend, Persian/RTL UI, no build step).
Written against the current `main` (HEAD `2510f36`). Re-read the files named below before changing anything.
This prompt REPLACES the earlier "phone + postal code" version. The postal code is NOT used anywhere on this page (many customers have none).

Read first: `README.md` (structure, deploy, security sections), `setup.sql` (the `orders` table, `normalize_digits`, `order_history_entry`, the blocks that REVOKE execute on all functions from `PUBLIC`/`anon`/`authenticated` — about lines 1300–1320 and 1775–1790 — and the grant lists at the end), the latest migration `migrations/013_page_claims.sql` (style, idempotency, header comments, `notify pgrst, 'reload schema'`), `style.css` (theme tokens), `index.html` (head, anti-FOUC theme script, fonts), `sw.js`, `ui.js` (`toEnDigits`, `normalizePhone`, date helpers), `parse-customer.js` (existing Persian name/digit normalization ideas) and `tests/mixed_payment.sql` (test style). Ignore `graphify-out/`, `lib/` and the old `delsana-*-prompt.md` files.

First check whether a previous version of this feature was already applied: `grep -rn track_orders .`, look for `track.html`, `migrations/014_*`. If nothing exists (expected), proceed. If a phone+postal `track_orders(text, text)` already exists in the repo or was already run on Supabase, handle it with a NEW migration that does `drop function if exists public.track_orders(text, text)` and recreates it with the new meaning (same signature `(text, text)` but arguments are now phone and name), and remove the postal field/index from the old files. Say which case you found.

---

## 1. What we are building

A separate public page, `track.html`, whose link goes in the Instagram bio or is sent to customers in DM. The customer types their **mobile number AND their name** (the name they gave when ordering). The page shows their orders and the status of each: registration date, ordered items, and shipping state (preparing, or shipped with the tracking code, or shipped without a code yet).

No login. No admin code on that page. Customers never get direct access to any table: they call ONE narrow database function that returns only a whitelisted set of fields.

## 2. Hard privacy rules (non-negotiable)

The owner knowingly accepted that name + phone is a weaker check than phone + postal code: anyone who knows BOTH a customer's phone and name can see the items and status. That is why the response is strictly limited (below) and why rate limiting is stricter.

- Lookup requires BOTH values to match the same order: full mobile number AND name. Never search by name alone, never by phone alone, never partial/"starts with" phone matching.
- The response contains ONLY: order registration time, item names + quantities, a simplified stage, shipping method (`post`/`courier`), the tracking code (only when shipped/delivered and shipping method is `post`), and the dates of the shipped/delivered/cancelled steps. It must NEVER contain: customer name, phone, address, postal code, prices/totals/discount, payment method, notes, pin data, `owe`/`h_share`/`payout`/any money field, raw `status_history` rows (they contain the user id `by` and private notes), `last_actor`, order ids or any other id.
- "Not found" is one generic answer; it must not reveal whether the phone or the name was the wrong half, nor that the phone exists.
- The page and its JS must not store the entered phone/name anywhere (no localStorage, no URL query, no cookies, no analytics, no third-party scripts).
- `<meta name="robots" content="noindex, nofollow">` and `<meta name="referrer" content="no-referrer">`.

## 3. Database — new migration `migrations/014_public_order_tracking.sql` (idempotent), mirrored into `setup.sql`

### 3.1 Function `public.track_orders(p_phone text, p_name text) returns jsonb`
`language plpgsql`, `security definer`, `set search_path = public`, Persian-free JSON codes (the UI owns all texts).

**Phone normalization** (reuse `public.normalize_digits`):
- `v_phone10` = last 10 digits of the digits-only phone. This makes `09121234567`, `+98 912 123 4567`, `0098-912-123-4567`, `912 123 4567` and Persian/Arabic digits all equal. If fewer than 10 digits → `{"status":"invalid"}`.

**Name normalization** — create a small `immutable` helper `public.normalize_person_name(text) returns text` (NOT callable by anon; revoke like the other helpers) that:
1. converts Persian/Arabic digits to ASCII (via `normalize_digits`) and lowercases Latin letters;
2. maps Arabic `ي`→`ی`, `ك`→`ک`, `ة`→`ه`, `أ/إ/ٱ`→`ا`, `ؤ`→`و`;
3. removes tatweel `ـ` and Arabic diacritics (U+064B–U+065F, U+0670);
4. maps ZWNJ (U+200C) and other zero-width/bidi marks (U+200B, U+200D, U+200E, U+200F, U+202A–U+202E, U+2066–U+2069, U+00A0) to a normal space;
5. replaces punctuation with spaces using an EXPLICIT character list (`. , ، ; ؛ : - _ ( ) [ ] { } " ' « » ! ؟ ? / \ | * + = @ # $ % ^ & ~` and digits are kept or dropped — decide, but document it). Do NOT rely on locale-dependent regex classes like `[[:alpha:]]` or `\w` for Persian;
6. collapses whitespace and trims.

**Name match rule** (document it in a comment): split the normalized typed name into words; require at least 1 word and at least 2 characters in total (single-letter input → `invalid`); every typed word must EQUAL (whole word, never substring or prefix) one of the words of the normalized stored `customer_name`, order-insensitive. So `مریم`, `احمدی`, `احمدی مریم`, `مریم احمدی` all match a stored «مریم احمدی»; `مری` or `احمد` do NOT. Implement with arrays (`string_to_array`, `<@` / `unnest` + `bool_and`), not `LIKE '%…%'`.

**Match** (exact on phone, word-set on name):
```
right(regexp_replace(public.normalize_digits(o.phone), '[^0-9]', '', 'g'), 10) = v_phone10
and string_to_array(public.normalize_person_name(o.customer_name), ' ') @> v_typed_words
```
(verify the real column names of `orders` — phone, customer name — in `setup.sql`). Order by `created_at desc`, at most 10 rows. Phone is the selective filter; check there is an index that helps (if not, add `orders_phone10_idx` as an expression index on `right(regexp_replace(public.normalize_digits(phone), '[^0-9]', '', 'g'), 10)` — only if `normalize_digits` is declared `immutable`; otherwise skip the index and say so, the table is small). Do NOT add any postal-code index.

Return shape:
```
{ "status": "ok", "orders": [ {
    "created_at": "...",                       -- timestamptz as ISO string
    "items": [ {"name": "...", "qty": 2} ],     -- only name and qty
    "stage": "preparing" | "shipped" | "delivered" | "cancelled",
    "shipping_method": "post" | "courier",
    "tracking": "..." | null,                  -- only if stage in (shipped, delivered) AND shipping_method = 'post' AND tracking is not blank
    "shipped_at": "..." | null,
    "delivered_at": "..." | null,
    "cancelled_at": "..." | null
} ] }
{ "status": "not_found" }   { "status": "invalid" }   { "status": "rate_limited" }
```
Stage mapping from `orders.status`: `shipped` → `shipped`; `delivered` → `delivered`; `cancelled` → `cancelled`; every other status (`new`, `awaiting_snapp`, `paid`, `settled`) → `preparing` — the customer must never see internal payment states.
The step dates come from the LAST matching entry of `status_history` (`h->>'status'` and `(h->>'at')::timestamptz`); null when absent (admin overrides can skip entries). Never return the history itself.

### 3.2 Abuse protection (rate limit), no captcha — TWO limits
Because the name is easy to guess, a per-phone limit is essential in addition to the per-IP one.

Create `public.track_attempts(id bigserial pk, at timestamptz not null default now(), ip_hash text, phone_hash text, ok boolean not null)` with RLS enabled, NO policies, indexes on `(ip_hash, at)` and `(phone_hash, at)`; `revoke all` from `anon`/`authenticated`, grant to `service_role`. (If an older `track_attempts` without `phone_hash` exists, `alter table … add column if not exists phone_hash text`.)
- Client IP: read `current_setting('request.headers', true)::json` (PostgREST sets it) and take the first address of `x-forwarded-for`, falling back to `cf-connecting-ip` / `x-real-ip`. Store only `md5(ip)`. Check which header Supabase actually provides and say what you found. If no IP is available, skip the IP limit rather than putting everybody into one shared bucket.
- Phone: store only `md5(v_phone10 || a fixed app-specific salt string in the function)` as `phone_hash`.
- Before the lookup:
  - same `ip_hash` has ≥ 10 failed attempts (`ok = false`) in the last 15 minutes → `{"status":"rate_limited"}`;
  - same `phone_hash` has ≥ 8 failed attempts in the last 60 minutes → `{"status":"rate_limited"}` (this is what stops name-guessing against one phone number, across any number of IPs). Return the same `rate_limited` code in both cases, without querying orders.
- `invalid` inputs count as failures for the IP limit (they have no valid phone; `phone_hash` is null then). Successful lookups are not counted.
- After the lookup insert one `track_attempts` row (`ok = (orders found)`).
- Housekeeping: delete rows older than 1 day inside the function.
- Known trade-off to note in README: an attacker who deliberately fails 8 times for someone else's phone can lock that customer out for up to an hour (they can DM instead). Accepted.

### 3.3 Grants (this is where mistakes happen — be careful)
`setup.sql` contains DO blocks that revoke execute on ALL public functions from `PUBLIC`/`anon`/`authenticated`. Define and grant the new function AFTER those blocks (end of `setup.sql`) so it is not stripped:
```
revoke all on function public.track_orders(text, text) from PUBLIC;
grant execute on function public.track_orders(text, text) to anon, authenticated, service_role;
revoke all on function public.normalize_person_name(text) from PUBLIC, anon, authenticated;
notify pgrst, 'reload schema';
```
(`track_orders` is `security definer`, so it can call the helper regardless of the caller's rights.) No other function may become callable by `anon`. Add a SQL assertion in the test that `anon` has execute ONLY on `track_orders` among the application functions (`has_function_privilege`), and that `anon` can't `select` from `orders`, `track_attempts`, `profiles`, `outbox`.

## 4. The page — `track.html`, `track.js`, `track.css`

Theme and colors stay EXACTLY the same as the app: link `style.css`, reuse its tokens/classes (`--brand`, `--surface`, `.card`, `.btn`, `.pill`, field styles, dark mode via `prefers-color-scheme` and the stored `delsana-theme`), copy the anti-FOUC theme script and the same Vazirmatn font links from `index.html`. Page-specific layout goes in a small `track.css` that only uses the existing CSS variables (no new colors). The page must stay fully usable if the web font fails (fallback Tahoma). Mobile-first, RTL, inside the Instagram in-app browser too: no exotic APIs, `navigator.clipboard` needs a fallback (`execCommand('copy')` via a temporary textarea).

Do NOT load `app.js`, `screens.js`, `db.js`, `supabase-js` or `lib/`. Call the function with a plain `fetch` to `POST {supabaseUrl}/rest/v1/rpc/track_orders` with the anon key headers and body `{ "p_phone": "...", "p_name": "..." }`; put `supabaseUrl`, `supabaseAnonKey` (copy the two public values from `app.js` `CONFIG`) and `instagramUsername: 'delsana.co'` in a small `TRACK_CONFIG` at the top of `track.js`. Don't register a manifest or install prompt on this page (it must not offer to install the admin app).

### 4.1 Form
- Title «پیگیری سفارش دلسانا» with the app icon (`icon.svg`), a one-line explanation, and a privacy line: «اطلاعات شما فقط برای پیدا کردن سفارش‌تان استفاده می‌شود».
- Two fields: «شماره موبایل» (`inputmode="tel"`, `autocomplete="tel"`) and «نام و نام خانوادگی» (`type="text"`, `autocomplete="name"`, hint under it: «همان نامی که هنگام ثبت سفارش اعلام کرده‌اید»). Accept Persian/Arabic digits and common phone formats (reuse the logic of `ui.js` `toEnDigits`/`normalizePhone` — copy the few lines, don't import `ui.js`). Enter submits; the button shows a busy state; double submits are ignored.
- Client validation with Persian messages: phone needs at least 10 digits; name needs at least 2 characters.
- No postal-code field anywhere.

### 4.2 Results
Show «N سفارش پیدا شد» and one card per order, newest first. Each card:
- **تاریخ ثبت** in the Jalali calendar, Persian digits, Tehran time (`Intl.DateTimeFormat('fa-IR-u-ca-persian', { timeZone: 'Asia/Tehran', … })`).
- **اقلام سفارش:** `name × qty` list.
- **Status pill + sentence** (exact texts):
  - preparing → pill «در حال آماده‌سازی», «سفارش شما در حال آماده‌سازی است.»
  - shipped → pill «ارسال شد»
  - delivered → pill «تحویل شد»
  - cancelled → pill «لغو شده» + «برای اطلاعات بیشتر در دایرکت پیام دهید.»
- **Shipping block** (only for shipped/delivered):
  - `courier`: «سفارش شما با پیک ارسال شد.» — never mention a tracking code.
  - `post` with a code: show the code large, `dir="ltr"`, selectable, with a «کپی کد رهگیری» button, and a link button «رهگیری در سایت پست» → `https://tracking.post.ir` (`target="_blank"`, `rel="noopener noreferrer"`). Directly next to that link show the notice (warning style using the existing `--warn`/`--warn-soft` tokens): «قبل از ورود به سایت پست، فیلترشکن (VPN) خود را خاموش کنید.»
  - `post` without a code: «کد رهگیری هنوز صادر نشده است. از صبر و شکیبایی شما ممنونیم.»
- **Step tracker** (not for cancelled): ثبت سفارش → آماده‌سازی → ارسال → تحویل; completed steps filled, the current one highlighted, with the date of ثبت/ارسال/تحویل when known (no date shown for آماده‌سازی). Must also be understandable without color (icons/text).
- Render server data with `textContent` / DOM APIs only — never `innerHTML` with item names or codes.

### 4.3 Other states (Persian texts)
- not found: «سفارشی با این مشخصات پیدا نشد. شماره موبایل و نام را همان‌طور که هنگام ثبت سفارش اعلام کرده‌اید وارد کنید، یا در دایرکت پیام دهید.»
- rate limited: «تعداد تلاش‌ها زیاد بود. لطفاً چند دقیقه دیگر دوباره امتحان کنید یا در دایرکت پیام دهید.»
- network/server error and offline: a friendly retry message; never show raw errors or stack traces.
- Always show, below the results/form, a button «پیام در دایرکت» → `https://ig.me/m/<instagramUsername>` (target `_blank`, `noopener`), plus a «جست‌وجوی جدید» button that clears the results AND the two inputs.

## 5. Service worker, docs, admin convenience
- `sw.js`: add `./track.html`, `./track.css`, `./track.js` to `SHELL` and bump the cache name to the next number after whatever it is at that moment (currently `delsana-v18`). Check that the worker never caches the RPC response (POST is not cached, but confirm) and that a stale `track.html` can't linger (the existing strategy for the shell is cache-first with background revalidation — confirm it is acceptable and say so).
- `README.md`: new section "Public order tracking": what it shows, the privacy model (phone AND name must match, whitelisted fields, per-IP and per-phone rate limits, the lock-out trade-off), the name-matching rule, how to share the link (`<your site>/track.html`), the deploy order (run migration 014 → deploy the frontend), and the note that the customers' browser must reach Supabase and the host without VPN. Add `track.*` to the file table. Add a short `CHANGELOG.md` entry in the existing style.
- Optional, only if trivial in `screens.js`: in Settings add a small card «لینک پیگیری مشتری» that shows the full tracking link (`location.origin + '/track.html'`, derive the directory from `location.pathname` if the app is not at the domain root) with a «کپی لینک» button.
- Operational note to put in README: the customer must type the name the same way it was saved in the app (first name or full name both work; nicknames do not). Staff should save real names when registering orders.

## 6. Tests (must be added and pass; show the output)

`tests/track_orders.sql` (same style as the existing SQL tests: rolled back, ends with `ALL PASSED`):
- Phone matching: the same order is found with `09121234567`, `+98 912 123 4567`, `0098-912-123-4567`, `912 123 4567` and Persian digits; with a stored phone that itself contains spaces/dashes.
- Name matching with stored «مریم احمدی»: `مریم`, `احمدی`, `مریم احمدی`, `احمدی مریم`, `  مریم   احمدی  `, `مریم‌ احمدی` (ZWNJ), `مريم احمدي` (Arabic ي), `مریم.` (punctuation) → all found. NOT found: `مری`, `احمد`, `مریم رضایی`, `مریم احمدی‌نژاد`, `علی`; one-letter input → `invalid`; empty name → `invalid`. Stored name with Arabic letters/ZWNJ and typed with Persian letters → found.
- Not matching: right phone + wrong name; wrong phone + right name; phone with only 9 digits → `invalid`; empty strings.
- Two orders of one customer → both returned, newest first; two different customers sharing one phone number but different names → each sees only their own; more than 10 orders → exactly 10.
- Stage mapping for all 7 statuses (`new`, `awaiting_snapp`, `paid`, `settled` → preparing; `shipped`; `delivered`; `cancelled`).
- Tracking: post + shipped + code → code returned; post + shipped + blank code → `tracking` null; courier + shipped → `tracking` null even if the column has a value; `new`/`paid` with a code in the column → `tracking` null.
- Dates: `shipped_at` / `delivered_at` / `cancelled_at` come from `status_history`; null when no entry.
- **Leak test:** the set of keys of every returned order is EXACTLY `created_at, items, stage, shipping_method, tracking, shipped_at, delivered_at, cancelled_at`; each item has exactly `name, qty`; the serialized JSON never contains the customer's name, address, postal code, phone digits, note text, any amount, the user id from `by`, or the order id (insert an order with recognizable marker strings in those columns and assert they are absent).
- Privileges: as `anon` (`set local role anon` if the harness allows, otherwise `has_function_privilege` / `has_table_privilege`): can execute `track_orders`; cannot execute `normalize_person_name` or any other app function (loop over `pg_proc` in `public`); cannot select from `orders`, `track_attempts`, `profiles`, `outbox`.
- Rate limit (IP): 10 failed lookups from one fake IP (set `request.headers` with `set_config`) → the 11th returns `rate_limited`, even with correct data; a different IP is unaffected; successful lookups don't consume the budget; old attempt rows are purged.
- Rate limit (phone): 8 failed lookups (wrong names) against one phone from 8 DIFFERENT fake IPs → the 9th attempt with the CORRECT name from a new IP returns `rate_limited`; a different phone is unaffected; successful lookups don't count.
- Existing SQL tests and `tests.html` still pass.

Browser/manual check to report:
1. Open `track.html` in a phone-width viewport in light and dark mode: colors/fonts match the app.
2. Search with Persian digits and with `+98`, with first name only and with full name: the order appears.
3. Wrong name → the generic not-found text (identical to wrong phone).
4. Courier shipped, post shipped with code (copy button + the VPN notice next to the post link), post shipped without code, preparing, cancelled: all texts exact.
5. Network tab: only one request to `/rest/v1/rpc/track_orders`; the payload/response contain no forbidden fields; nothing is stored in localStorage/cookies.
6. Open the page from an Instagram in-app browser (or emulate): copy button works through the fallback.

## 7. Constraints and process
- Keep the existing code style, Persian UI, file structure. Don't touch unrelated code. Don't edit old migrations.
- No new dependencies, no build step, no external scripts besides the existing font stylesheet.
1. Explore first (no edits): confirm the grant-revoke blocks, the real `orders` column names, whether `normalize_digits` is immutable, the `request.headers` availability and the existing SW strategy; summarize your plan in a few lines; then continue.
2. Implement the migration + `setup.sql` mirror + tests, then the page, then docs.
3. At the end list: changed/added files, the test output, the deploy order (run `014_public_order_tracking.sql` in the Supabase SQL editor → deploy the frontend → share `<site>/track.html`), and anything you could not verify.
