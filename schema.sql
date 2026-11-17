-- ============================================================================
-- Shopam schema.sql — fresh install (MySQL 8+ / MariaDB 10.4+)
--
-- Conventions:
--   * Multi-tenant: every tenant-owned table has tenant_id.
--   * Composite foreign keys (tenant_id, id) between tenant-scoped tables make
--     cross-tenant references impossible at the database level: a child row of
--     tenant A can never point to a parent row of tenant B.
--   * All money is an INTEGER amount of Toman (BIGINT), no decimals.
--   * All dates are stored UTC/Gregorian; display is Jalali on the client.
--   * Payments whose fee is a multiplier/percent (e.g. 1.15) use DECIMAL only
--     for the fee value itself, never for money amounts.
-- ============================================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- ---------------------------------------------------------------------------
-- Drop in reverse dependency order (makes re-import easy; safe on fresh DB)
-- ---------------------------------------------------------------------------
DROP TABLE IF EXISTS login_attempts;
DROP TABLE IF EXISTS audit_log;
DROP TABLE IF EXISTS subscription_payments;
DROP TABLE IF EXISTS expenses;
DROP TABLE IF EXISTS expense_categories;
DROP TABLE IF EXISTS order_items;
DROP TABLE IF EXISTS orders;
DROP TABLE IF EXISTS shop_settings;
DROP TABLE IF EXISTS payment_methods;
DROP TABLE IF EXISTS product_variants;
DROP TABLE IF EXISTS product_images;
DROP TABLE IF EXISTS products;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS super_admins;
DROP TABLE IF EXISTS tenants;
DROP TABLE IF EXISTS plans;

-- ---------------------------------------------------------------------------
-- plans — subscription plans sold by the platform owner
-- ---------------------------------------------------------------------------
CREATE TABLE plans (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(50)  NOT NULL,
    monthly_price   BIGINT       NOT NULL DEFAULT 0,
    max_products    INT UNSIGNED NOT NULL DEFAULT 300,
    max_staff_users INT UNSIGNED NOT NULL DEFAULT 2,
    max_images      INT UNSIGNED NOT NULL DEFAULT 5,   -- images per product
    is_active       TINYINT(1)   NOT NULL DEFAULT 1,
    created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- tenants — one row per shop (created by the platform super-admin)
-- subscription_status: active | expired (read-only) | disabled (read-only)
-- field_schema: data-driven product fields per shop type preset
-- ---------------------------------------------------------------------------
CREATE TABLE tenants (
    id                      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name                    VARCHAR(100) NOT NULL,
    slug                    VARCHAR(40)  NOT NULL,   -- shop identifier used at login: [a-z0-9-], unique, reserved words blocked
    -- shop_type: VARCHAR, NOT an ENUM. The list of valid types lives in code
    -- (app/ShopTypes/); adding a type needs a preset class, not a schema change.
    shop_type               VARCHAR(30)  NOT NULL DEFAULT 'cosmetics',
    field_schema            JSON         NULL,       -- data-driven product fields, written by the shop type preset
    logo_path               VARCHAR(255) NULL,
    plan_id                 INT UNSIGNED NULL,
    subscription_status     ENUM('active','expired','disabled') NOT NULL DEFAULT 'active',
    subscription_expires_at DATETIME     NULL,
    created_at              TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_tenants_slug (slug),
    KEY idx_tenants_subscription (subscription_status, subscription_expires_at),
    CONSTRAINT chk_tenants_slug_format CHECK (slug REGEXP '^[a-z0-9]([a-z0-9-]*[a-z0-9])$'),
    CONSTRAINT chk_tenants_slug_reserved CHECK (slug NOT IN ('admin','api','www','app','static')),
    CONSTRAINT fk_tenants_plan FOREIGN KEY (plan_id) REFERENCES plans (id)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- users — shop accounts. role: owner | staff (permissions only, NO profit split)
-- can_see_cost: staff permission flag; owners always see cost and profit.
-- ---------------------------------------------------------------------------
CREATE TABLE users (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id     INT UNSIGNED NOT NULL,
    role          ENUM('owner','staff') NOT NULL DEFAULT 'staff',
    name          VARCHAR(100) NOT NULL,
    email         VARCHAR(190) NULL,
    phone         VARCHAR(20)  NULL,
    password_hash VARCHAR(255) NOT NULL,
    can_see_cost  TINYINT(1)   NOT NULL DEFAULT 0,
    is_active     TINYINT(1)   NOT NULL DEFAULT 1,
    created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_users_tenant_id (tenant_id, id),
    UNIQUE KEY uq_users_email (tenant_id, email),
    KEY idx_users_phone (tenant_id, phone),
    CONSTRAINT fk_users_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- super_admins — the SaaS owner(s), separate from shop users
-- ---------------------------------------------------------------------------
CREATE TABLE super_admins (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name          VARCHAR(100) NOT NULL,
    email         VARCHAR(190) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_super_admins_email (email)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- products — only buy_price and sell_price. Profit = sell - buy.
-- stock is used when has_variants = 0; with variants, stock lives in
-- product_variants. extra_fields holds data-driven fields from the tenant's
-- field_schema (shop type preset).
-- ---------------------------------------------------------------------------
CREATE TABLE products (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id           INT UNSIGNED NOT NULL,
    name                VARCHAR(190) NOT NULL,
    sku                 VARCHAR(50)  NULL,
    category            VARCHAR(100) NULL,
    buy_price           BIGINT       NOT NULL DEFAULT 0,
    sell_price          BIGINT       NOT NULL DEFAULT 0,
    stock               INT          NOT NULL DEFAULT 0,
    has_variants        TINYINT(1)   NOT NULL DEFAULT 0,
    low_stock_threshold INT UNSIGNED NOT NULL DEFAULT 0,  -- 0 = use shop setting
    extra_fields        JSON         NULL,
    archived            TINYINT(1)   NOT NULL DEFAULT 0,  -- archive, never delete
    created_at          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_products_tenant_id (tenant_id, id),
    KEY idx_products_list (tenant_id, archived, name),
    KEY idx_products_category (tenant_id, category),
    CONSTRAINT fk_products_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- product_images — max 5 per product, position 1 = cover. Random file names,
-- stored under uploads/{tenant_id}/, re-encoded with GD (step 3).
-- ---------------------------------------------------------------------------
CREATE TABLE product_images (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id   INT UNSIGNED NOT NULL,
    product_id  INT UNSIGNED NOT NULL,
    file_name   VARCHAR(64)  NOT NULL,
    thumb_name  VARCHAR(64)  NOT NULL,   -- ~400px
    medium_name VARCHAR(64)  NOT NULL,   -- ~1000px
    position    TINYINT UNSIGNED NOT NULL DEFAULT 1,
    created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_product_images_tenant_id (tenant_id, id),
    UNIQUE KEY uq_product_images_position (tenant_id, product_id, position),
    KEY idx_product_images_product (tenant_id, product_id),
    CONSTRAINT fk_product_images_product
        FOREIGN KEY (tenant_id, product_id) REFERENCES products (tenant_id, id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- product_variants — clothing preset: size + color with stock per variant
-- ---------------------------------------------------------------------------
CREATE TABLE product_variants (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id  INT UNSIGNED NOT NULL,
    product_id INT UNSIGNED NOT NULL,
    size       VARCHAR(50) NOT NULL DEFAULT '',
    color      VARCHAR(50) NOT NULL DEFAULT '',
    stock      INT         NOT NULL DEFAULT 0,
    created_at TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_product_variants_tenant_id (tenant_id, id),
    UNIQUE KEY uq_product_variants_key (tenant_id, product_id, size, color),
    KEY idx_product_variants_product (tenant_id, product_id),
    CONSTRAINT fk_product_variants_product
        FOREIGN KEY (tenant_id, product_id) REFERENCES products (tenant_id, id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- payment_methods — per-shop toggles. They ONLY affect price/fee calculation:
-- fee_type multiplier (e.g. 1.15) or percent (e.g. 2.5), rounding step, and
-- who bears the fee. is_credit marks BNPL methods (Snap/Digi/Torob Pay) whose
-- orders land in receivables. No real API integration.
-- ---------------------------------------------------------------------------
CREATE TABLE payment_methods (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id     INT UNSIGNED NOT NULL,
    code          VARCHAR(30)  NOT NULL,          -- cash | card_to_card | snap_pay | digi_pay | torob_pay | custom_*
    label         VARCHAR(100) NOT NULL,
    enabled       TINYINT(1)   NOT NULL DEFAULT 1,
    is_credit     TINYINT(1)   NOT NULL DEFAULT 0,  -- credit/BNPL -> receivables screen
    fee_type      ENUM('none','multiplier','percent') NOT NULL DEFAULT 'none',
    fee_value     DECIMAL(10,4) NOT NULL DEFAULT 0,
    rounding_step BIGINT       NOT NULL DEFAULT 1000,  -- 0 = no rounding
    fee_bearer    ENUM('customer','shop') NOT NULL DEFAULT 'customer',
    sort_order    INT          NOT NULL DEFAULT 0,
    created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_payment_methods_tenant_id (tenant_id, id),
    UNIQUE KEY uq_payment_methods_code (tenant_id, code),
    KEY idx_payment_methods_enabled (tenant_id, enabled, sort_order),
    CONSTRAINT fk_payment_methods_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- shop_settings — per-shop operational settings (identity lives in tenants).
-- Orders snapshot the values they used, so later changes never alter old orders.
-- ---------------------------------------------------------------------------
CREATE TABLE shop_settings (
    tenant_id           INT UNSIGNED NOT NULL PRIMARY KEY,
    shipping_fee        BIGINT       NOT NULL DEFAULT 0,
    rounding_step       BIGINT       NOT NULL DEFAULT 1000,
    low_stock_threshold INT UNSIGNED NOT NULL DEFAULT 3,
    updated_at          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_shop_settings_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- orders — status flow: new -> paid -> shipped -> delivered, cancel restores
-- stock. Money columns mirror calc (JS calc.js mirrored in PHP Calc.php):
--   total = subtotal - discount + shipping_fee + fee (if customer bears it) + rounding_diff
-- cost_total snapshots buy prices at sale time (profit = subtotal - cost_total).
-- settlement_status/settled_at power the receivables screen (credit/BNPL and
-- unpaid orders pending settlement by the provider).
-- ---------------------------------------------------------------------------
CREATE TABLE orders (
    id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id          INT UNSIGNED NOT NULL,
    order_number       INT UNSIGNED NULL,           -- per-tenant sequence
    status             ENUM('new','paid','shipped','delivered','cancelled') NOT NULL DEFAULT 'new',
    customer_name      VARCHAR(120) NOT NULL,
    customer_phone     VARCHAR(20)  NOT NULL,
    customer_address   VARCHAR(500) NULL,
    note               VARCHAR(500) NULL,
    payment_method_id  INT UNSIGNED NULL,
    -- snapshot of the payment method + settings used at sale time:
    pm_code            VARCHAR(30)  NULL,
    is_credit          TINYINT(1)   NOT NULL DEFAULT 0,
    fee_type           ENUM('none','multiplier','percent') NOT NULL DEFAULT 'none',
    fee_value          DECIMAL(10,4) NOT NULL DEFAULT 0,
    fee_bearer         ENUM('customer','shop') NOT NULL DEFAULT 'customer',
    rounding_step      BIGINT       NOT NULL DEFAULT 1000,
    shipping_fee       BIGINT       NOT NULL DEFAULT 0,
    subtotal           BIGINT       NOT NULL DEFAULT 0,   -- sum of sell lines
    cost_total         BIGINT       NOT NULL DEFAULT 0,   -- sum of buy lines
    discount           BIGINT       NOT NULL DEFAULT 0,
    fee_amount         BIGINT       NOT NULL DEFAULT 0,   -- fee computed on goods subtotal
    rounding_diff      BIGINT       NOT NULL DEFAULT 0,   -- rounding adjustment added to total
    total              BIGINT       NOT NULL DEFAULT 0,   -- what the customer pays
    tracking_code      VARCHAR(60)  NULL,                 -- entered manually (post office)
    pinned             TINYINT(1)   NOT NULL DEFAULT 0,
    settlement_status  ENUM('pending','settled') NOT NULL DEFAULT 'pending',
    settled_at         DATETIME     NULL,
    paid_at            DATETIME     NULL,
    created_by         INT UNSIGNED NULL,
    status_history     JSON         NULL,                 -- [{status, at, by}]
    created_at         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_orders_tenant_id (tenant_id, id),
    UNIQUE KEY uq_orders_number (tenant_id, order_number),
    KEY idx_orders_status (tenant_id, status, created_at),
    KEY idx_orders_settlement (tenant_id, settlement_status),
    KEY idx_orders_tracking (tenant_id, tracking_code),
    KEY idx_orders_customer_phone (tenant_id, customer_phone),
    KEY idx_orders_created (tenant_id, created_at),
    CONSTRAINT fk_orders_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE,
    CONSTRAINT fk_orders_payment_method
        FOREIGN KEY (tenant_id, payment_method_id) REFERENCES payment_methods (tenant_id, id),
    CONSTRAINT fk_orders_created_by
        FOREIGN KEY (tenant_id, created_by) REFERENCES users (tenant_id, id),
    CONSTRAINT chk_orders_money CHECK (
        subtotal >= 0 AND cost_total >= 0 AND discount >= 0 AND shipping_fee >= 0
        AND fee_amount >= 0 AND total >= 0
        AND total = subtotal - discount + shipping_fee
            + (CASE WHEN fee_bearer = 'customer' THEN fee_amount ELSE 0 END)
            + rounding_diff
    )
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- order_items — snapshot of name, sell_price AND buy_price at the time of sale
-- ---------------------------------------------------------------------------
CREATE TABLE order_items (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id     INT UNSIGNED NOT NULL,
    order_id      INT UNSIGNED NOT NULL,
    product_id    INT UNSIGNED NOT NULL,
    variant_id    INT UNSIGNED NULL,
    product_name  VARCHAR(190) NOT NULL,
    variant_label VARCHAR(120) NULL,          -- "Size: L | Color: Red"
    qty           INT UNSIGNED NOT NULL,
    sell_price    BIGINT       NOT NULL,      -- snapshot at sale time
    buy_price     BIGINT       NOT NULL DEFAULT 0,  -- snapshot at sale time
    line_total    BIGINT       NOT NULL,      -- qty * sell_price
    line_cost     BIGINT       NOT NULL DEFAULT 0,  -- qty * buy_price
    UNIQUE KEY uq_order_items_tenant_id (tenant_id, id),
    KEY idx_order_items_order (tenant_id, order_id),
    KEY idx_order_items_product (tenant_id, product_id),
    CONSTRAINT fk_order_items_order
        FOREIGN KEY (tenant_id, order_id) REFERENCES orders (tenant_id, id) ON DELETE CASCADE,
    CONSTRAINT fk_order_items_product
        FOREIGN KEY (tenant_id, product_id) REFERENCES products (tenant_id, id),
    CONSTRAINT fk_order_items_variant
        FOREIGN KEY (tenant_id, variant_id) REFERENCES product_variants (tenant_id, id),
    CONSTRAINT chk_order_items_money CHECK (
        line_total = qty * sell_price AND line_cost = qty * buy_price
    )
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- expense_categories + expenses — simple bookkeeping (NO partner debts)
-- ---------------------------------------------------------------------------
CREATE TABLE expense_categories (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id  INT UNSIGNED NOT NULL,
    name       VARCHAR(100) NOT NULL,
    sort_order INT          NOT NULL DEFAULT 0,
    created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_expense_categories_tenant_id (tenant_id, id),
    UNIQUE KEY uq_expense_categories_name (tenant_id, name),
    CONSTRAINT fk_expense_categories_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE expenses (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id   INT UNSIGNED NOT NULL,
    category_id INT UNSIGNED NULL,
    amount      BIGINT       NOT NULL,
    expense_date DATE        NOT NULL,
    note        VARCHAR(500) NULL,
    created_by  INT UNSIGNED NULL,
    created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_expenses_tenant_id (tenant_id, id),
    KEY idx_expenses_date (tenant_id, expense_date),
    KEY idx_expenses_category (tenant_id, category_id),
    CONSTRAINT fk_expenses_category
        FOREIGN KEY (tenant_id, category_id) REFERENCES expense_categories (tenant_id, id),
    CONSTRAINT fk_expenses_created_by
        FOREIGN KEY (tenant_id, created_by) REFERENCES users (tenant_id, id),
    CONSTRAINT fk_expenses_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE,
    CONSTRAINT chk_expenses_amount CHECK (amount > 0)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- subscription_payments — manual card-to-card renewals recorded by the
-- super-admin; each payment extends the tenant expiry by `months`.
-- ---------------------------------------------------------------------------
CREATE TABLE subscription_payments (
    id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id             INT UNSIGNED NOT NULL,
    plan_id               INT UNSIGNED NULL,
    amount                BIGINT       NOT NULL,
    months                INT UNSIGNED NOT NULL DEFAULT 1,
    paid_at               DATETIME     NOT NULL,
    note                  VARCHAR(500) NULL,
    created_by_super_admin INT UNSIGNED NULL,
    created_at            TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_subscription_payments_tenant (tenant_id, paid_at),
    CONSTRAINT fk_subscription_payments_tenant
        FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE,
    CONSTRAINT fk_subscription_payments_plan FOREIGN KEY (plan_id) REFERENCES plans (id),
    CONSTRAINT fk_subscription_payments_admin
        FOREIGN KEY (created_by_super_admin) REFERENCES super_admins (id),
    CONSTRAINT chk_subscription_payments CHECK (amount >= 0 AND months > 0)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- audit_log — who did what, when (tenant_id NULL = platform-level action)
-- ---------------------------------------------------------------------------
CREATE TABLE audit_log (
    id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id  INT UNSIGNED NULL,
    actor_type ENUM('user','super_admin') NOT NULL,
    actor_id   INT UNSIGNED NOT NULL,
    action     VARCHAR(60)  NOT NULL,     -- e.g. product.create, order.cancel
    entity     VARCHAR(60)  NULL,
    entity_id  INT UNSIGNED NULL,
    details    JSON         NULL,
    created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_audit_log_tenant (tenant_id, created_at),
    KEY idx_audit_log_actor (actor_type, actor_id, created_at),
    CONSTRAINT fk_audit_log_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- login_attempts — rate limiting for login (no SSH/Node needed on server)
-- ---------------------------------------------------------------------------
CREATE TABLE login_attempts (
    id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    identifier VARCHAR(190) NOT NULL,   -- email or phone attempted
    ip         VARCHAR(45)  NOT NULL,
    success    TINYINT(1)   NOT NULL DEFAULT 0,
    created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_login_attempts_identifier (identifier, created_at),
    KEY idx_login_attempts_ip (ip, created_at)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Seed: one default plan so the super-admin can create shops immediately.
-- ---------------------------------------------------------------------------
INSERT INTO plans (name, monthly_price, max_products, max_staff_users, max_images)
VALUES ('پایه', 0, 300, 3, 5);
