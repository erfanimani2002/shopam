<?php

declare(strict_types=1);

namespace App\Models;

/** Expense categories, editable per shop. */
final class ExpenseCategoryModel extends BaseModel
{
    protected string $table = 'expense_categories';

    /** @return array<int, array<string, mixed>> */
    public function all(): array
    {
        return $this->scoped(
            "SELECT * FROM `expense_categories`
             WHERE tenant_id = :tenant_id
             ORDER BY sort_order, name"
        );
    }
}
