Read AGENTS.md in the project root first. It is the full brief for this project.

Then study the reference project in ./reference/delsana/ (READ-ONLY, never modify it). Look at README.md, calc.js, screens.js, ui.js, style.css, setup.sql, app.js and sw.js. Extract what is reusable: screen layout, RTL/Persian UI patterns, order status flow, tracking code logic, CSV export, PWA setup, and the test style in tests.html. Ignore everything related to Supabase, the Mahdi/Helia roles, and the profit-split logic.

Your task in this first step is ONLY the foundation. Do not build any UI screens yet.

1. Write a short plan (max 1 page) of the folder structure and how the PHP backend will be organized (router, controllers, models/helpers, middleware). Keep it simple enough to deploy on cPanel shared hosting by uploading files. Wait for my approval of the plan only if something in the brief is unclear or contradictory; otherwise continue.

2. Create the project skeleton:
   - /api (entry point and routing), /app (core classes), /public (frontend, PWA files, assets), /uploads (with .htaccess blocking script execution), /cron, /tests
   - config.example.php (DB credentials, base URL, upload limits) and add config.php to .gitignore
   - a PDO database class with prepared statements only

3. Write schema.sql (MySQL/MariaDB, utf8mb4) with these tables, all tenant-scoped via tenant_id except plans and super admins:
   plans, tenants (with subscription status and expiry date), users (role: owner/staff, permission flags such as can_see_cost), super_admins, products, product_images, product_variants (size/color/stock), orders, order_items (snapshot of sell_price and buy_price at the time of sale), payment_methods (per shop: enabled flag, multiplier or percent fee, rounding step, who bears the fee), shop_settings, expenses, expense_categories, and an audit/activity log.
   Add proper indexes and foreign keys. Money fields are integers (Toman, no decimals).

4. Write the central tenant-scoping helper. Every query must get tenant_id from the authenticated session, never from client input. Show one example of how a model uses it.

5. Write a tenant isolation test in /tests that creates two shops and proves shop A cannot read or modify shop B's data through any model.

When done, give me a summary: the folder tree, the list of tables with one line each, any assumption you made, and any question you have. Do not continue to the next step until I confirm.

Rules: prepared statements only, output escaping, no secrets in code, comments in English, and ask me before changing any money rule from the brief.
