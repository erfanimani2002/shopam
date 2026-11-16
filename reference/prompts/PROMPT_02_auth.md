Step 1 is approved. Continue with STEP 2: authentication, CSRF, rate limiting, middleware, and the first real API routes.

Read AGENTS.md again before starting. Keep all money rules and tenant-scoping rules from it.

## Scope (do only this, no product/order screens yet)

1. Auth routes in /api (JSON):
   - POST login (tenant users: shop identifier + email/phone + password), POST logout, GET me (current user, role, permission flags, tenant name, subscription state, days left).
   - POST super-admin login, logout, me (separate session namespace, separate routes, never mixable with tenant sessions).
   - POST change_password (requires current password).
   - GET csrf (returns a fresh token for the session).

2. Security:
   - password_hash/password_verify (PASSWORD_DEFAULT), rehash on login if needed.
   - CSRF token required on every state-changing request (POST/PUT/DELETE), checked in one place (middleware), constant-time compare.
   - Rate limiting using the login_attempts table: per IP and per account, lock for a short time after repeated failures, with a generic error message (never reveal whether the account exists). Add a cleanup of old rows.
   - session_regenerate_id(true) on login, full session destroy on logout, idle timeout and absolute lifetime from config.
   - All responses JSON with a consistent shape: { ok, data, error: { code, message } }. Error messages for users are in Persian, codes in English.

3. Middleware (app/Middleware):
   - RequireAuth, RequireTenant (sets the tenant context used by BaseModel), RequireSuperAdmin, RequireActiveSubscription (expired/disabled shops can still log in, read and export, but every write is rejected with a clear Persian message and code SUBSCRIPTION_EXPIRED).
   - A permission helper: can('see_cost') etc. Owners always pass. Staff depend on user flags. Provide a helper to strip buy_price/profit fields from a response when the user lacks can_see_cost, and use it in one central place so later controllers cannot forget it.

4. Router: finish the action map so controllers are registered declaratively with their middleware stack, e.g. 'login' => [AuthController::class, 'login', []], 'me' => [..., ['auth']].

5. CLI scripts in /cron or /tools (not web accessible):
   - create_super_admin.php: asks for email and password interactively (hidden input), never hardcode credentials anywhere.
   - seed_demo.php: creates a plan, one demo tenant (cosmetics preset), one owner and one staff user with passwords printed ONCE to the console. For local testing only, refuse to run if config says production.

6. Minimal frontend only to test auth in the browser: public/index.html with a Persian RTL login screen (tenant login) and a tiny /public/admin.html super-admin login, plus a "who am I" screen showing /me output. Reuse the visual style and CSS variables (light/dark) from the Delsana reference. No other screens.

7. Tests (in /tests, plain PHP, PASS/FAIL table like the isolation test, must also refuse to run on a non *_test database):
   - login success/failure, generic error messages, rate limit lockout and release
   - CSRF missing/wrong token rejected, correct token accepted
   - session fixation: session id changes after login
   - tenant session cannot call super-admin routes and vice versa
   - expired tenant: reads allowed, writes rejected
   - staff without can_see_cost never receives buy_price/profit fields
   - rerun the isolation test from step 1 and keep it passing

## Rules
- Prepared statements only, escape all output, no secrets in code, comments in English.
- If PHP is not installed on this machine, say so and do not pretend tests ran. Write the tests anyway and list the exact commands I must run.
- Do not start step 3. When done, give me: the list of routes with their middleware, the files you added or changed, assumptions, and any question.
