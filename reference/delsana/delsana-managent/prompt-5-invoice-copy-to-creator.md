# Task: whoever creates an invoice also receives that invoice in their own Telegram

Repo: `delsana-manage` (Supabase Edge Functions in Deno/TypeScript + vanilla JS PWA, Persian UI).
Written against the current `main` (HEAD `3e219f7`). Re-read the files named below before changing anything.

## 1. Current behavior (verified in the code)

- Creating an order inserts a row into `orders`; the trigger `tg_order_events` writes an `outbox` row `kind = 'order_created'` with `actor = NEW.last_actor` (the user who created it).
- The Edge Function `supabase/functions/notify/index.ts` claims outbox rows, merges rows of one order (`F.mergeEvents`), builds the message and sends Telegram DMs.
- `recipientsFor(m, profiles)` removes every actor (`m.actors`) from the recipients ("8.1: the actor is never notified"). So when Helia creates an invoice only Mahdi (and admin) get the full invoice DM; Helia gets nothing. When Mahdi creates one, only Helia (and admin) get it.
- `deliverDm` and `deliverPush` both iterate over `recipientsFor(...)`.
- For `order_created` the DM text is the full invoice (`F.buildEventDm` → `F.buildInvoice(order)`), with an «باز کردن در اپ» button.

## 2. Wanted behavior

**When an invoice is created, the creator ALSO receives the same full-invoice Telegram DM** as the other partners. So the invoice reaches all linked users: the creator, the other partner and the admin.

Keep everything else exactly as it is:
- Only the `order_created` event changes. Status changes, tracking, pin, edit, delete, product events and the reminder still do NOT notify the actor.
- Web Push is NOT changed: the actor still gets no push for their own action (a push saying "you created an order" is noise; the owner asked only for Telegram).
- The private channel post is unchanged.
- Test mode («حالت تست», `telegram_paused = '1'`) still drops everything silently, including this self-copy.
- A user who never linked Telegram still gets nothing (`deliverDm` already returns silently when there is no active `telegram_chats` row).
- No database migration and no frontend change are needed.

## 3. Implementation

1. **Make the recipient logic pure and testable.** Move the selection out of `notify/index.ts` into `supabase/functions/_shared/format.ts` (it already holds `mergeEvents` and its tests), e.g.:
   - `dmRecipients(m: Merged, profiles: Profile[]): Profile[]` → if `m.has('order_created')` return ALL profiles; otherwise the current rule (`m.actors.length === 0` → everyone, else everyone except the actors).
   - `pushRecipients(m, profiles)` → exactly today's `recipientsFor` behavior (actor excluded) — unchanged.
   Export a small `Profile` type from there (or import the one already in `notify/index.ts`) so both files share it.
2. **Use them in `handleGroup`** in `notify/index.ts`: iterate DM recipients for `deliverDm` and push recipients for `deliverPush` separately (today one loop does both — restructure into two loops or filter inside one loop). Keep `actorRole` and all push payload logic untouched.
3. **Merged groups:** several outbox rows of the same order are merged into one message (e.g. `order_created` + `status_changed` when an order is created already paid). If the group contains `order_created`, the DM is the invoice and the creator must get it. If the group has several actors (e.g. A created the order and B changed its status before `notify` ran), everyone still gets the invoice.
4. **Failure isolation (important):** today any failed delivery marks the whole group as failed and `claim_outbox` retries it (up to 5 attempts), which resends the DM to the partners who already received it. Do not add a new way to cause duplicates: send the creator's self-copy AFTER the partners' DMs, and treat a failure of the self-copy as best-effort — `console.error` it with the order id, do NOT add it to `problems` (no retry of the group). Partner delivery failures keep their current behavior. State this choice in your summary.
5. **Admin restore:** `admin_restore_order` re-inserts a deleted order, so the INSERT trigger emits `order_created` again with the restoring admin as actor. With the new rule the admin also gets the invoice DM. That is acceptable; mention it in the summary and README.
6. Do not touch `send-invoice` (the manual «فاکتور در تلگرام من» button) — it keeps working as it is.
7. Keep the existing code style and Persian strings. Don't touch unrelated code.

## 4. Tests (add to `supabase/functions/_shared/format.test.ts`, run with `deno test`)

Use three fake profiles: `mahdi` (user-m), `helia` (user-h), `admin` (user-a).

- `order_created` by user-h → `dmRecipients` = {h, m, a} (creator included); `pushRecipients` = {m, a} (creator excluded).
- `order_created` by user-m → DM {h, m, a}; push {h, a}.
- `order_created` by user-a (admin) → DM {h, m, a}; push {h, m}.
- Merged group `order_created` (actor h) + `status_changed` (actor h) → DM still includes h.
- Merged group with two actors (`order_created` by h, `status_changed` by m) → DM includes everyone.
- `status_changed` by h → DM {m, a} (actor excluded, unchanged); `tracking_changed`, `pin_changed`, `order_edited`, `order_deleted`, `prices_changed`, `availability_changed`, `product_deleted` by an actor → actor excluded (unchanged).
- Event with no actor (`tracking_missing`) → everyone (unchanged).
- The existing tests must all still pass.

Also run `deno check supabase/functions/notify/index.ts` (or the repo's usual type-check command) and show the output. If an end-to-end check is not possible here, say so and describe the manual check below.

## 5. Docs

- `README.md`: in the notifications section and the admin notifications bullet (line ~264: "Recipients: all linked partners except the actor") document the new rule: *a new invoice is also sent to its creator; all other events still skip the actor; push still skips the actor*. Add the 8.1 wording in the code comment above the recipient functions.
- `CHANGELOG.md`: a short entry in the existing style, including the deploy note.
- Deploy note to state in your summary: only the `notify` function must be redeployed — `supabase functions deploy notify --no-verify-jwt` — no migration, no frontend deploy, no service-worker change.

## 6. Manual check to report

1. Both partners and admin have linked Telegram; test mode is OFF.
2. Helia creates an invoice → Helia, Mahdi and admin each receive exactly ONE invoice DM; no push on Helia's phone.
3. Mahdi creates an invoice → same, with roles swapped.
4. Mahdi changes that order's status → Helia gets the status DM, Mahdi gets nothing (unchanged).
5. Turn test mode ON, create an invoice → nobody gets a message, including the creator.
6. A user who has not linked Telegram creates an invoice → no error, the others still get it.

## 7. Process

1. Explore first (no edits): confirm the code paths above, summarize your plan in a few lines, then continue.
2. Implement, run the tests, update docs.
3. At the end list: changed files, the test output, the deploy command, and the choices you made (self-copy sent last and best-effort; admin restore also sends the invoice).
