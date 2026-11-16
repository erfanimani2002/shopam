<?php

declare(strict_types=1);

namespace App\Models;

/** Manual expense entries (ads, packaging, rent, shipping cost, ...). */
final class ExpenseModel extends BaseModel
{
    protected string $table = 'expenses';

    /** @return array<int, array<string, mixed>> */
    public function between(string $fromDate, string $toDate): array
    {
        return $this->scoped(
            "SELECT * FROM `expenses`
             WHERE tenant_id = :tenant_id
               AND expense_date BETWEEN :from AND :to
             ORDER BY expense_date DESC",
            ['from' => $fromDate, 'to' => $toDate]
        );
    }
}
