<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\ApiException;
use App\Core\Auth;
use App\Core\Database;
use App\Core\Permissions;
use App\ShopTypes\ShopTypes;
use Throwable;

/**
 * Products. Only buy_price and sell_price exist (Toman integers); profit is
 * sell - buy. extra_fields is validated against the tenant's field schema
 * (App\Helpers\FieldSchema) — never stored raw from the client.
 *
 * The expiring-soon FILTER runs in SQL (JSON_UNQUOTE(JSON_EXTRACT) with a
 * REGEXP date guard then CAST to DATE), which works identically on MySQL 8
 * and MariaDB 10.4+; missing/invalid dates simply never match.
 */
final class ProductModel extends BaseModel
{
    protected string $table = 'products';

    /** Whitelist of sortable columns; 'profit' maps to (sell_price - buy_price). */
    private const SORTABLE = ['name' => 'name', 'created' => 'created_at', 'stock' => 'stock', 'sell_price' => 'sell_price'];

    /**
     * SQL expression for a product's expiry date from extra_fields, safe on
     * both MySQL 8 and MariaDB: only well-formed YYYY-MM-DD values are cast,
     * everything else (NULL, garbage) becomes NULL and never matches.
     */
    private const EXPR_EXPIRY = "CAST(CASE WHEN JSON_UNQUOTE(JSON_EXTRACT(extra_fields, '$.expiry_date')) REGEXP '^[0-9]{4}-[0-9]{2}-[0-9]{2}$' THEN JSON_UNQUOTE(JSON_EXTRACT(extra_fields, '$.expiry_date')) END AS DATE)";

    /**
     * Paginated + filtered list. Each item carries its images (single query,
     * no N+1) and PHP-computed expiry flags.
     *
     * @param array{q?: string, category?: string, archived?: string, low_stock?: bool,
     *              expiring?: bool, days?: int, page?: int, per_page?: int,
     *              sort?: string, dir?: string} $args
     * @return array{items: list<array<string, mixed>>, page: int, per_page: int, total: int, total_pages: int}
     */
    public function list(array $args): array
    {
        $where = ['tenant_id = :tenant_id'];
        $params = [];

        $q = trim((string) ($args['q'] ?? ''));
        if ($q !== '') {
            // name OR sku OR brand (brand lives in extra_fields JSON).
            // Placeholders must be unique within one statement (emulation off).
            $like = '%' . $q . '%';
            $where[] = "(name LIKE :q1 OR sku LIKE :q2 OR JSON_UNQUOTE(JSON_EXTRACT(extra_fields, '$.brand')) LIKE :q3)";
            $params['q1'] = $like;
            $params['q2'] = $like;
            $params['q3'] = $like;
        }

        $category = trim((string) ($args['category'] ?? ''));
        if ($category !== '') {
            $where[] = 'category = :category';
            $params['category'] = $category;
        }

        $archived = (string) ($args['archived'] ?? '0');
        if ($archived === 'all') {
            // no archived filter
        } else {
            $where[] = 'archived = :archived';
            $params['archived'] = $archived === '1' ? 1 : 0;
        }

        if (!empty($args['low_stock'])) {
            // low_stock_threshold = 0 means "use the shop setting". The
            // subquery is correlated (no placeholder, so :tenant_id in the
            // main WHERE is not duplicated).
            $where[] = 'stock <= CASE WHEN low_stock_threshold > 0 THEN low_stock_threshold
                ELSE COALESCE((SELECT s.low_stock_threshold FROM shop_settings s
                    WHERE s.tenant_id = products.tenant_id), 3) END';
        }

        if (!empty($args['expiring'])) {
            $days = max(1, min(365, (int) ($args['days'] ?? 30)));
            // "Soon" means not already expired: from today up to +days.
            $where[] = self::EXPR_EXPIRY . ' IS NOT NULL
                AND ' . self::EXPR_EXPIRY . ' >= CURDATE()
                AND ' . self::EXPR_EXPIRY . ' <= DATE_ADD(CURDATE(), INTERVAL :days DAY)';
            $params['days'] = $days;
        }

        $whereSql = implode(' AND ', $where);
        $params['tenant_id'] = Auth::tenantId(); // the raw COUNT below bypasses withTenant

        // Sort: whitelist + cost fields behind assertCanSortOrFilter.
        $sort = (string) ($args['sort'] ?? 'created');
        $dir = strtolower((string) ($args['dir'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';
        if ($sort === 'profit' || $sort === 'margin' || $sort === 'buy_price') {
            Permissions::assertCanSortOrFilter($sort); // throws for staff without can_see_cost
            $orderSql = $sort === 'profit'
                ? '(sell_price - buy_price)'
                : ($sort === 'margin' ? 'CASE WHEN sell_price > 0 THEN (sell_price - buy_price) / sell_price ELSE 0 END' : 'buy_price');
        } elseif (isset(self::SORTABLE[$sort])) {
            $orderSql = '`' . self::SORTABLE[$sort] . '`';
        } else {
            $orderSql = '`created_at`';
        }

        $page = max(1, (int) ($args['page'] ?? 1));
        $perPage = min(50, max(1, (int) ($args['per_page'] ?? 20)));
        $offset = ($page - 1) * $perPage;

        $total = (int) $this->db->value(
            "SELECT COUNT(*) FROM products WHERE $whereSql",
            $params
        );

        // Named placeholders can't repeat (emulation off): the order/limit
        // parts are whitelisted integers/identifiers, never client strings.
        $listParams = $params;
        unset($listParams['tenant_id']); // withTenant binds it from the session
        $rows = $this->scoped(
            "SELECT * FROM products WHERE $whereSql ORDER BY $orderSql $dir
             LIMIT $perPage OFFSET $offset",
            $listParams
        );
        $items = [];
        $imagesMap = $rows === [] ? [] : ProductImageModel::imagesForProducts(array_map(
            static fn (array $r): int => (int) $r['id'],
            $rows
        ));
        $days = max(1, min(365, (int) ($args['days'] ?? 30)));
        foreach ($rows as $row) {
            $row['extra_fields'] = self::decodeExtra($row['extra_fields']);
            $row['images'] = $imagesMap[$row['id']] ?? [];
            $row['cover'] = $row['images'][0] ?? null;
            $row += self::expiryFlags($row['extra_fields'], $days);
            $items[] = $row;
        }

        return [
            'items'       => $items,
            'page'        => $page,
            'per_page'    => $perPage,
            'total'       => $total,
            'total_pages' => (int) ceil($total / $perPage),
        ];
    }

    /** One product with images + decoded extra fields; null when not found. */
    public function findWithImages(int $id): ?array
    {
        $row = $this->find($id);
        if ($row === null) {
            return null;
        }
        $row['extra_fields'] = self::decodeExtra($row['extra_fields']);
        $row['images'] = (new ProductImageModel())->listForProduct($id);
        $row['cover'] = $row['images'][0] ?? null;
        $row += self::expiryFlags($row['extra_fields'], 30);
        return $row;
    }

    /** Number of non-archived products (used for the plan limit). */
    public function countActive(): int
    {
        return (int) $this->db->value(
            'SELECT COUNT(*) FROM products WHERE tenant_id = :tenant_id AND archived = 0',
            ['tenant_id' => Auth::tenantId()]
        );
    }

    /**
     * Bulk price adjustment in ONE transaction. Percent or fixed delta,
     * rounded to the shop's rounding step, clamped at 0. buy_price is touched
     * only for callers that can see cost.
     *
     * @param list<int> $ids
     * @return int number of products updated
     */
    public function bulkAdjust(array $ids, string $mode, float $value, bool $includeBuy, ?string $scopeAll = null): int
    {
        $rounding = (int) (Database::get()->one(
            'SELECT rounding_step FROM shop_settings WHERE tenant_id = ?',
            [Auth::tenantId()]
        )['rounding_step'] ?? 0);
        if ($rounding <= 0) {
            $rounding = 1000;
        }

        // Named placeholders must be unique: build :id0..:idN.
        $marks = [];
        $idParams = [];
        foreach (array_values(array_unique(array_map('intval', $ids))) as $i => $id) {
            $marks[] = ':id' . $i;
            $idParams["id$i"] = $id;
        }
        $idFilter = $scopeAll === 'all' ? 'archived = 0' : 'id IN (' . implode(', ', $marks) . ')';

        // ROUND(x / step) * step = nearest multiple of the step (0.5 away from zero).
        // :value must appear at most ONCE per statement (emulation off), so
        // when buy_price is included too the second expression uses :vbuy.
        $step = $rounding;
        if ($mode === 'percent') {
            $sellExpr = "GREATEST(0, ROUND((sell_price * (1 + (:value / 100))) / $step) * $step)";
            $buyExpr  = "GREATEST(0, ROUND((buy_price  * (1 + (:vbuy / 100))) / $step) * $step)";
        } else {
            $sellExpr = "GREATEST(0, ROUND((sell_price + :value) / $step) * $step)";
            $buyExpr  = "GREATEST(0, ROUND((buy_price  + :vbuy) / $step) * $step)";
        }
        if ($includeBuy) {
            $setSql = "sell_price = $sellExpr, buy_price = $buyExpr";
        } else {
            $setSql = "sell_price = $sellExpr";
        }

        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $params = array_merge($idParams, ['value' => $value, 'tenant_id' => Auth::tenantId()]);
            if ($includeBuy) {
                $params['vbuy'] = $value;
            }
            $stmt = $this->db->query(
                "UPDATE products SET $setSql WHERE $idFilter AND tenant_id = :tenant_id",
                $params
            );
            $count = $stmt->rowCount();
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return $count;
    }

    /** Decoded extra_fields (array) from a raw row value. */
    public static function decodeExtra(mixed $raw): array
    {
        $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : $raw;
        return is_array($decoded) ? $decoded : [];
    }

    /** @return array{expired: bool, expiring: bool, expiry_date: ?string} */
    public static function expiryFlags(array $extra, int $days): array
    {
        $expiry = $extra['expiry_date'] ?? null;
        $expiry = is_string($expiry) ? $expiry : null;
        if ($expiry === null || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiry)) {
            return ['expired' => false, 'expiring' => false, 'expiry_date' => null];
        }
        $ts = strtotime($expiry . ' 23:59:59');
        if ($ts === false) {
            return ['expired' => false, 'expiring' => false, 'expiry_date' => $expiry];
        }
        return [
            'expired'     => $ts < time(),
            'expiring'    => $ts >= time() && $ts <= time() + $days * 86400,
            'expiry_date' => $expiry,
        ];
    }

    /** Whether the session tenant's preset allows per-product variants. */
    public function variantsAllowed(): bool
    {
        try {
            return ShopTypes::preset(
                (string) ($this->db->value(
                    'SELECT shop_type FROM tenants WHERE id = ?',
                    [Auth::tenantId()]
                ) ?? '')
            )->hasFeature('variants');
        } catch (\Throwable) {
            return false;
        }
    }
}
