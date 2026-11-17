# Project Brief: Multi-Shop SaaS (based on Delsana Management)

## Goal
A configurable, multi-tenant web app (Persian UI, RTL, light/dark, mobile-first PWA) that any online shop
(clothing, cosmetics, hygiene, anything else) can use. I will sell it as a monthly subscription.

## Reference project
The folder `./reference/delsana/` (read-only) is the previous single-shop app (vanilla JS PWA + Supabase).
Reuse ideas, UI structure, screen layout, order status flow, tracking code logic, CSV export, PWA setup,
and tests style from it. DO NOT copy its Supabase code, its Mahdi/Helia roles, or its profit-split logic.

## Stack (decided)
- Backend: PHP 8+ and MySQL/MariaDB (must run on cPanel shared hosting AND on a VPS; no SSH assumed, no Node on server).
- Frontend: plain HTML/CSS/vanilla JS PWA (no build step), calling a JSON API under /api.
- Local dev: Laragon/XAMPP on Windows. Config via a single `config.php` (DB credentials, base URL).
- One shared database, every table has `tenant_id`. EVERY query must be scoped by tenant_id taken from the
  authenticated session, never from client input. Write one central helper for this and use it everywhere.

## Big changes compared to Delsana
1. NO personal profit per user/role. Remove "page price" (قیمت پیج) and "shop price" (قیمت مغازه), the
   Mahdi/Helia split, `hp`, `mp`, discount bearer, and the "owe" accounting.
2. Each product has only: `buy_price` (قیمت خرید) and `sell_price` (قیمت فروش). Profit = sell - buy (per line, per order).
3. Roles per shop: `owner` (sees buy price and profit, settings, reports) and `staff` (configurable by the owner:
   can/can't see buy price and profit). Roles are about permissions only, never about profit sharing.
4. Product images: shops (especially clothing) can upload images for each product and show them on the product card.
   - Multiple images per product (max 5), first = cover.
   - Server-side validation: real MIME check (jpg/png/webp), max 3 MB each, re-encode with GD, generate a thumbnail
     (~400px) and a medium image (~1000px), random file names, stored in `uploads/{tenant_id}/...`, never trust the original name.
   - Lazy loading on cards, graceful placeholder when no image.
5. Shop type presets (clothing, cosmetics, generic) that change the product fields. Clothing: variants (size + color)
   with stock per variant. Cosmetics/generic: simple stock. Keep it data-driven (a JSON field schema per tenant), not hardcoded screens.
6. Payment/credit methods are per-shop toggles (cash, card-to-card, Snap Pay, Digi Pay, Torob Pay, custom...).
   They ONLY affect price and fee calculation (multiplier or percent fee, rounding step, who bears the fee). No real API integration.
   Keep all money math in ONE pure function file (JS `calc.js`) and mirror it on the server (PHP) because the server recomputes
   everything and never trusts client numbers. Include a `tests.html` with worked examples.

## Core features (v1)
- Auth: email/phone + password (password_hash), sessions or signed tokens, rate-limited login, CSRF protection.
  No public sign-up: the platform admin creates shops.
- Products (CRUD, archive instead of delete, search, low-stock highlight, bulk price update by % or fixed).
- New order, orders list with status flow (new -> paid -> shipped -> delivered, cancel restores stock), tracking code,
  pin order, CSV export (UTF-8 BOM).
- Reports: today / 7d / 30d / custom: sales, profit, top products, payment method breakdown, shipping breakdown.
- Accounts (simple bookkeeping, replaces Delsana's debt screen; NO partner debts):
  - Receivables: orders paid via a credit/BNPL method (Snap Pay, Digi Pay, Torob Pay...) that are not yet settled by the provider,
    plus unpaid orders. Buttons to mark "money received" (single and bulk). Show expected amount after the provider fee.
  - Expenses: manual entries (category, amount, date, note), e.g. ads, packaging, rent, shipping cost. Categories are editable per shop.
  - Summary per period: revenue, cost of goods, gross profit, expenses, net profit, pending receivables.
- Analytics dashboard (owner only, charts):
  - KPIs: revenue, gross profit, net profit, profit margin %, order count, average order value.
  - Line chart: revenue / profit / expenses over time (day / week / month toggle).
  - Doughnut: sales share per payment method; bar: top products by quantity and by profit; bar: sales per category.
  - Heatmap or bar: orders by weekday and hour (to know when customers buy).
  - Table with highlights: low-margin products, dead stock (no sales in N days), best repeat customers (by phone number).
  - Order status funnel and cancellation rate.
  - Date range filter and CSV export of each table.
  - Use ONE lightweight chart library (e.g. Chart.js) SELF-HOSTED in the project (no CDN, must work offline/in Iran).
  - Aggregations are done in SQL on the server (scoped by tenant_id), the client only draws.
- Per-shop settings: shop name, logo, shop type, enabled payment methods and their fees, shipping fee, rounding step.

## Platform admin panel (me, the SaaS owner)
- Separate super-admin login. Create/disable shops, set plan, set expiry date, renew manually (I collect card-to-card).
- Expired shops become read-only (can log in and export, can't create orders). Show days left to the shop owner.
- A daily cron script (`cron/check_subscriptions.php`) updates expiry state.
- Plans table (name, monthly price, limits: max products, max staff users, max images).

## Non-functional
- Security first: prepared statements only (PDO), output escaping, upload hardening (no executable files,
  `.htaccess` blocking PHP in uploads), tenant isolation tests (a test that proves shop A can't read shop B).
- Persian digits formatting, Jalali date display (store dates as UTC/Gregorian in DB).
- Keep the project simple to deploy: upload folder + import `schema.sql` + edit `config.php`.
- Provide `schema.sql`, `README.md` (Persian) with local setup on Laragon and deploy steps for cPanel.

## Suggested build order
1. schema.sql (tenants, plans, users, products, product_images, product_variants, orders, order_items, settings, payment_methods).
2. Auth + tenant scoping helper + isolation test.
3. Products with images (upload pipeline).
4. Orders + calc (JS + PHP) + tests.html.
5. Reports, accounts (receivables + expenses), analytics dashboard with charts, settings, payment method toggles.
6. Super-admin panel + subscription cron.
7. PWA polish, README.

Ask me before making any decision that changes the money rules above.

## Scope update (v1)
- v1 ships ONLY the cosmetics/hygiene shop type. Do NOT build clothing-specific UI (size/color variant screens) now.
- The architecture must make adding a new shop type trivial:
    * tenants.shop_type becomes VARCHAR(30) (no ENUM); the list of valid types lives in code (app/ShopTypes/).
    * Each type is one Preset class defining: product field schema, default categories, default expense categories, feature flags (variants, expiry_tracking...), and default payment methods.
    * Product forms, lists and reports are rendered from the preset/field_schema. No hardcoded `shop_type === 'cosmetics'` checks outside a preset class.
    * Keep the product_variants table and the variants code path, but the cosmetics preset has variants OFF by default (can be turned on per shop for shades/colors).
- Cosmetics preset fields: brand, volume/weight with unit, expiry date, batch number, skin type, barcode/SKU. Add an "expiring soon" list to the dashboard.
- Images stay in v1 for all types.
- 