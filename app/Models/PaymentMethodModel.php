<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Per-shop payment/credit methods. They only affect price/fee calculation
 * (fee type, fee value, rounding step, fee bearer) — no real API integration.
 */
final class PaymentMethodModel extends BaseModel
{
    protected string $table = 'payment_methods';

    /** @return array<int, array<string, mixed>> */
    public function allEnabled(): array
    {
        return $this->scoped(
            "SELECT * FROM `payment_methods`
             WHERE tenant_id = :tenant_id AND enabled = 1
             ORDER BY sort_order, id"
        );
    }

    /** @return array<string, mixed>|null */
    public function findByCode(string $code): ?array
    {
        return $this->scopedOne(
            "SELECT * FROM `payment_methods`
             WHERE tenant_id = :tenant_id AND code = :code",
            ['code' => $code]
        );
    }
}
