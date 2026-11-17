<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\ApiException;
use App\Core\Auth;
use App\Core\Request;
use App\Helpers\Audit;
use App\Helpers\FieldSchema;
use App\Helpers\Text;
use App\Models\ProductModel;
use App\Models\TenantModel;

/**
 * Product CRUD + list + bulk price. All writes are audited; staff without
 * can_see_cost can never send buy_price (FORBIDDEN_FIELD) and cost fields
 * never reach them through Response::ok()'s central stripper.
 */
final class ProductController
{
    private ProductModel $products;

    public function __construct()
    {
        $this->products = new ProductModel();
    }

    /** GET products_list — search/filter/sort/paginate, images included. */
    public function products_list(): array
    {
        return [
            'list'   => $this->products->list([
                'q'           => (string) Request::input('q', ''),
                'category'    => (string) Request::input('category', ''),
                'archived'    => (string) Request::input('archived', '0'),
                'low_stock'   => (bool) Request::input('low_stock', false),
                'expiring'    => (bool) Request::input('expiring_soon', false),
                'days'        => (int) Request::input('days', 30),
                'page'        => (int) Request::input('page', 1),
                'per_page'    => (int) Request::input('per_page', 20),
                'sort'        => (string) Request::input('sort', 'created'),
                'dir'         => (string) Request::input('dir', 'desc'),
            ]),
            // Field schema for data-driven dialogs (never hardcoded in JS).
            'schema' => FieldSchema::forTenant(Auth::tenantId()),
        ];
    }

    /** GET product_get — one product with images. */
    public function product_get(): array
    {
        $product = $this->products->findWithImages($this->productId());
        if ($product === null) {
            throw new ApiException('NOT_FOUND', 404);
        }
        return ['product' => $product];
    }

    /** POST product_create. */
    public function product_create(): array
    {
        $plan = (new TenantModel())->planLimits(Auth::tenantId());
        if ($this->products->countActive() >= $plan['max_products']) {
            throw new ApiException('PLAN_LIMIT_REACHED', 403);
        }

        $data = $this->validatedCore();
        $extra = $this->validatedExtra($data['extra_input']);
        $row = $data['columns'] + [
            'extra_fields' => $extra['extra'] === [] ? null : json_encode($extra['extra'], JSON_UNESCAPED_UNICODE),
        ];
        if ($extra['native'] !== []) {
            $row += $extra['native'];
        }
        // Variants only when the tenant's preset allows them (cosmetics: off).
        $row['has_variants'] = $this->products->variantsAllowed()
            && !empty(Request::input('has_variants')) ? 1 : 0;

        $id = $this->products->insert($row);
        Audit::log('product.create', 'product', $id, ['name' => (string) $row['name']]);
        return ['product' => $this->products->findWithImages($id)];
    }

    /** POST product_update (partial). */
    public function product_update(): array
    {
        $id = $this->productId();
        $existing = $this->products->find($id);
        if ($existing === null) {
            throw new ApiException('NOT_FOUND', 404);
        }

        $row = $this->validatedCore(false)['columns'];
        if (Request::input('extra_fields') !== null) {
            $extra = $this->validatedExtra(Request::input('extra_fields'));
            if ($extra['extra'] === []) {
                $row['extra_fields'] = null;
            } else {
                $row['extra_fields'] = json_encode($extra['extra'], JSON_UNESCAPED_UNICODE);
            }
            if ($extra['native'] !== []) {
                $row += $extra['native'];
            }
        }
        if (Request::input('archived') !== null) {
            $row['archived'] = ((string) Request::input('archived')) === '1' ? 1 : 0;
        }
        if ($row === []) {
            throw new ApiException('INVALID_INPUT', 400, 'چیزی برای به‌روزرسانی ارسال نشده است.');
        }

        $this->products->update($id, $row);
        Audit::log('product.update', 'product', $id, ['fields' => array_keys($row)]);
        return ['product' => $this->products->findWithImages($id)];
    }

    /** POST product_archive — never hard delete. */
    public function product_archive(): array
    {
        return $this->setArchived(1, 'product.archive');
    }

    /** POST product_unarchive. */
    public function product_unarchive(): array
    {
        return $this->setArchived(0, 'product.unarchive');
    }

    /**
     * POST products_bulk_price — OWNER ONLY (staff gets FORBIDDEN).
     * mode: percent | fixed; value may be negative (discount) but results
     * clamp at 0; rounding uses the shop's rounding step; one transaction.
     */
    public function products_bulk_price(): array
    {
        if (Auth::role() !== 'owner') {
            throw new ApiException('FORBIDDEN', 403);
        }
        $mode = (string) Request::input('mode', '');
        if (!in_array($mode, ['percent', 'fixed'], true)) {
            throw new ApiException('INVALID_INPUT', 400, 'حالت تغییر قیمت باید درصد یا مقدار ثابت باشد.');
        }
        $raw = Text::normalizeDigits((string) Request::input('value', ''));
        if ($raw === '' || !is_numeric($raw)) {
            throw new ApiException('INVALID_INPUT', 400, 'مقدار تغییر قیمت نامعتبر است.');
        }
        $value = (float) $raw;

        $all = (string) Request::input('scope', '') === 'all';
        $ids = [];
        if (!$all) {
            $ids = array_values(array_filter(array_map('intval', (array) Request::input('ids', [])),
                static fn (int $v): bool => $v > 0));
            if ($ids === []) {
                throw new ApiException('INVALID_INPUT', 400, 'هیچ محصولی انتخاب نشده است.');
            }
            if (count($ids) > 500) {
                throw new ApiException('INVALID_INPUT', 400, 'حداکثر ۵۰۰ محصول در هر درخواست.');
            }
        }

        // Caller is the owner (checked above) and owners may see cost, so
        // touching buy_price is allowed when explicitly requested.
        $includeBuy = (bool) (Request::input('include_buy_price') ?? false);

        $count = $this->products->bulkAdjust($all ? [] : $ids, $mode, $value, $includeBuy, $all ? 'all' : null);
        Audit::log('products.bulk_price', 'product', null, [
            'mode' => $mode, 'value' => $value, 'include_buy_price' => $includeBuy,
            'scope' => $all ? 'all' : $ids, 'updated' => $count,
        ]);
        return ['updated' => $count];
    }

    /* ------------------------------------------------------------------ */

    private function productId(): int
    {
        $id = (int) Request::input('id', 0);
        if ($id <= 0) {
            throw new ApiException('INVALID_INPUT', 400);
        }
        return $id;
    }

    private function setArchived(int $flag, string $auditAction): array
    {
        $id = $this->productId();
        if ($this->products->find($id) === null) {
            throw new ApiException('NOT_FOUND', 404);
        }
        $this->products->update($id, ['archived' => $flag]);
        Audit::log($auditAction, 'product', $id);
        return ['product' => $this->products->findWithImages($id)];
    }

    /**
     * Validate name/sku/category/prices/stock; prices are Toman integers.
     * buy_price in the payload WITHOUT can_see_cost is refused outright.
     * With $requireName=false (update path) absent fields are skipped, but
     * every PRESENT field is still validated.
     *
     * @return array{columns: array<string, mixed>, extra_input: mixed}
     */
    private function validatedCore(bool $requireName = true): array
    {
        $nameInput = Request::input('name');
        if ($nameInput !== null || $requireName) {
            $name = trim((string) $nameInput);
            if ($name === '' || mb_strlen($name) > 190) {
                throw new ApiException('INVALID_INPUT', 400, 'نام محصول الزامی است (حداکثر ۱۹۰ کاراکتر).');
            }
            $columns = ['name' => $name];
        } else {
            $columns = [];
        }

        $buyInput = Request::input('buy_price');
        if ($buyInput !== null && !Auth::canSeeCost()) {
            // Staff without can_see_cost can never read OR write cost.
            throw new ApiException('FORBIDDEN_FIELD', 403);
        }

        $sku = Request::input('sku');
        if ($sku !== null && trim((string) $sku) !== '') {
            $sku = trim((string) $sku);
            if (strlen($sku) > 50) {
                throw new ApiException('INVALID_INPUT', 400, 'بارکد/SKU حداکثر ۵۰ کاراکتر.');
            }
            $columns['sku'] = $sku;
        }

        $category = Request::input('category');
        if ($category !== null && trim((string) $category) !== '') {
            $category = trim((string) $category);
            if (mb_strlen($category) > 100) {
                throw new ApiException('INVALID_INPUT', 400, 'دسته‌بندی حداکثر ۱۰۰ کاراکتر.');
            }
            $columns['category'] = $category;
        }

        foreach (['buy_price' => $buyInput, 'sell_price' => Request::input('sell_price')] as $key => $input) {
            if ($input === null) {
                continue; // create: buy_price defaults 0 via schema
            }
            $raw = Text::normalizeDigits(trim((string) $input));
            if (!is_numeric($raw)) {
                throw new ApiException('INVALID_INPUT', 400, 'قیمت باید عدد باشد.');
            }
            $value = (int) $raw;
            if ($value < 0) {
                throw new ApiException('INVALID_INPUT', 400, 'قیمت نمی‌تواند منفی باشد.');
            }
            $columns[$key] = $value;
        }

        $stock = Request::input('stock');
        if ($stock !== null) {
            $raw = Text::normalizeDigits(trim((string) $stock));
            if (!is_numeric($raw)) {
                throw new ApiException('INVALID_INPUT', 400, 'موجودی باید عدد باشد.');
            }
            $value = (int) $raw;
            if ($value < 0) {
                throw new ApiException('INVALID_INPUT', 400, 'موجودی نمی‌تواند منفی باشد.');
            }
            $columns['stock'] = $value;
        }

        $threshold = Request::input('low_stock_threshold');
        if ($threshold !== null) {
            $raw = Text::normalizeDigits(trim((string) $threshold));
            if (!is_numeric($raw)) {
                throw new ApiException('INVALID_INPUT', 400, 'حد هشدار موجودی باید عدد باشد.');
            }
            $value = (int) $raw;
            if ($value < 0) {
                throw new ApiException('INVALID_INPUT', 400, 'حد هشدار موجودی نمی‌تواند منفی باشد.');
            }
            $columns['low_stock_threshold'] = $value;
        }

        return ['columns' => $columns, 'extra_input' => Request::input('extra_fields', [])];
    }

    /** @return array{extra: array<string, mixed>, native: array<string, mixed>} */
    private function validatedExtra(mixed $input): array
    {
        $extra = is_array($input) ? $input : json_decode((string) $input, true);
        if (!is_array($extra)) {
            throw new ApiException('INVALID_INPUT', 400, 'فیلدهای اضافی نامعتبر است.');
        }
        return FieldSchema::validate($extra, FieldSchema::forTenant(Auth::tenantId()));
    }
}
