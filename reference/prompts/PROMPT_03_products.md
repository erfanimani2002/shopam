Step 3: Products with images, driven by the shop-type preset. Run this ONLY after the "Scope update (v1)" section is in AGENTS.md and the preset system (app/ShopTypes/) is implemented and its tests pass.

Read AGENTS.md again. Reuse the existing tenant scoping (BaseModel), central cost stripping (Response), middleware stack, and the CSS/theme from step 2. Reference ./reference/delsana for product screen ideas (search, low-stock highlight, bulk price update, archive instead of delete), but NOT for its price model.

## 1. Backend: products
Routes (declarative, with middleware: auth + tenant, writes also RequireActiveSubscription + csrf):
- products_list (GET): search by name/sku/brand, filters: category, archived, low_stock, expiring_soon (days), pagination (page, per_page max 50), sort by name/created/stock/sell_price. Each product includes its images (thumb + medium URLs) and cover. Sorting/filtering by buy_price/profit/margin is refused for users without can_see_cost (use the existing assertCanSortOrFilter).
- product_get, product_create, product_update, product_archive, product_unarchive (never hard delete).
- products_bulk_price (POST): apply percent or fixed delta to sell_price (and buy_price only if the user can see cost), round with the shop's rounding step, clamp at 0, all in ONE transaction, with an audit_log entry.
Rules:
- Fields: name (required), sku/barcode, category, buy_price, sell_price (integers, Toman), stock, low_stock_threshold, plus extra_fields validated against the tenant's field_schema (types: text, number, select, date; required flag; max lengths). Unknown extra fields are rejected. The cosmetics preset defines: brand, volume/weight + unit, expiry date, batch number, skin type.
- Staff without can_see_cost cannot read OR write buy_price: reject any attempt to send it (FORBIDDEN_FIELD); on create it defaults to 0 and the owner fixes it later.
- Enforce plan limits (max_products) with a clear Persian error.
- Expiry-aware: list can flag expired and expiring-soon items from extra_fields.expiry_date (stored Gregorian).

## 2. Backend: product images
Routes: product_image_upload (multipart, one or several files), product_image_delete, product_image_set_cover, product_image_reorder.
Hardening (all server-side, never trust the client):
- Real type check with finfo/getimagesize; allow only jpeg/png/webp; no SVG/GIF; max 3 MB and max pixel dimensions (e.g. 6000x6000); decode with GD, auto-rotate from EXIF, re-encode (this strips EXIF/metadata and any embedded payload).
- Generate thumb (~400px) and medium (~1000px) as webp or jpeg; random file names (random_bytes), never use the original name or extension; store under public/uploads/{tenant_id}/{product_id}/.
- Max images per product = min(5, plan max_images); position 1 = cover; deleting the cover promotes the next one.
- Deleting an image removes DB rows and files; archiving a product keeps its images.
- Tenant isolation: an image/product id from another shop must behave as "not found". Expired subscription: uploads and deletes are rejected like other writes.
- If the GD extension is missing, fail with a clear error code and a message telling the admin to enable GD (do not crash).
- Put the logic in app/Helpers/Uploads.php (or ImageProcessor) so it can be unit tested.

## 3. Frontend: app shell + Products screen
- After login show an app shell (RTL, light/dark, mobile-first): top bar with shop name, theme toggle, logout; bottom/side tab bar with only "محصولات" active now. Other tabs may be placeholders that are visibly disabled.
- Products screen: responsive card grid (cover image with lazy loading and a neutral placeholder when none, name, brand, sell price, stock badge, low-stock and expiry badges; owner/can_see_cost users also see buy price and profit per unit). Search box, filters (category, low stock, expiring soon, archived), "add product" button.
- Add/Edit dialog is GENERATED from the tenant's field_schema (do not hardcode cosmetics fields in JS). Show buy price only if can_see_cost. Persian digit input accepted and normalized; thousands separators shown while typing.
- Image manager inside the dialog: pick several files, instant preview, upload progress, set cover, delete, reorder (up/down buttons are enough; drag and drop optional). Show friendly Persian errors for type/size/limit problems.
- Bulk price update dialog (percent or fixed, preview of a few results before applying).
- Dates: show Jalali in the UI, store Gregorian. Write a small Jalali<->Gregorian helper in JS (and PHP if needed) with unit tests, and a simple Jalali date input (yyyy/mm/dd with validation).
- Use classic scripts, no build step, no CDN. All text in Persian. Escape all dynamic output.

## 4. Tests (plain PHP + a tests.html for JS helpers, PASS/FAIL style, DB name gate _test)
- Product CRUD scoped by tenant; isolation test extended to product_images and product_variants models.
- Extra fields validation against field_schema (unknown field, wrong type, missing required).
- Staff without can_see_cost: never receives buy_price/profit anywhere in the product responses (including nested lists and CSV if exists) and cannot write buy_price.
- Plan limits (max_products, max images) enforced.
- Image pipeline: a PHP file renamed to .jpg is rejected; a polyglot (valid image header + PHP code) is re-encoded and contains no PHP after processing; oversize rejected; non-image rejected; 6th image rejected; EXIF stripped; output files have random names and live under the right tenant folder; a user from shop B cannot delete or reorder shop A's images; expired tenant cannot upload.
- Bulk price update: percent/fixed, rounding, clamp at 0, atomic rollback on error.
- Jalali helper round-trip tests on known dates, including leap years.
- Re-run isolation_test and auth_test; all must stay green.

## Rules
Prepared statements only, escape output, no secrets in code, comments in English. If PHP is not available on this machine say so and list the exact commands I must run. Do not start step 4 (orders). When done give me: routes with middleware, files added/changed, assumptions, questions, and the exact commands to run.
