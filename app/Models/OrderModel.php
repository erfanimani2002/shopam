<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Orders model. Business logic (status flow, recalculation via Calc.php,
 * stock restore on cancel) is added in step 4. The scoping pattern is final.
 */
final class OrderModel extends BaseModel
{
    protected string $table = 'orders';
}
