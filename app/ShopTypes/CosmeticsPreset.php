<?php

declare(strict_types=1);

namespace App\ShopTypes;

/**
 * Cosmetics / hygiene shop preset — the ONLY shop type shipped in v1
 * (AGENTS.md "Scope update (v1)").
 *
 * Fields per brief: brand, volume/weight with unit, expiry date, batch
 * number, skin type, barcode/SKU. Variants (shades/colors) stay OFF by
 * default but can be enabled per shop; expiry tracking is ON, which powers
 * the "expiring soon" dashboard list in a later step.
 */
final class CosmeticsPreset extends ShopTypePreset
{
    public function code(): string
    {
        return 'cosmetics';
    }

    public function label(): string
    {
        return 'آرایشی و بهداشتی';
    }

    public function featureFlags(): array
    {
        return [
            'variants'        => false, // per-shop opt-in for shades/colors
            'expiry_tracking' => true,  // "expiring soon" list on the dashboard
        ];
    }

    public function fieldSchema(): array
    {
        return [
            // Volume/weight is a number + a separate unit choice so both stay
            // searchable/filterable in SQL later.
            ['key' => 'volume', 'label' => 'حجم/وزن', 'type' => 'number', 'unit' => 'volume_unit', 'in_list' => true],
            ['key' => 'volume_unit', 'label' => 'واحد حجم/وزن', 'type' => 'choice',
                'options' => ['میلی‌لیتر', 'گرم', 'عدد'], 'in_list' => false],
            ['key' => 'brand', 'label' => 'برند', 'type' => 'text', 'in_list' => true, 'in_search' => true],
            // barcode/SKU maps to the native products.sku column ('native'),
            // so it is never duplicated into extra_fields.
            ['key' => 'barcode', 'label' => 'بارکد/SKU', 'type' => 'text', 'native' => 'sku', 'in_search' => true],
            ['key' => 'expiry_date', 'label' => 'تاریخ انقضا', 'type' => 'date', 'in_list' => false],
            ['key' => 'batch_number', 'label' => 'شماره بچ', 'type' => 'text', 'in_list' => false],
            ['key' => 'skin_type', 'label' => 'نوع پوست', 'type' => 'text', 'in_list' => false],
        ];
    }

    public function defaultExpenseCategories(): array
    {
        return [
            ['name' => 'تبلیغات و بازاریابی', 'sort_order' => 1],
            ['name' => 'بسته‌بندی', 'sort_order' => 2],
            ['name' => 'هزینه ارسال', 'sort_order' => 3],
            ['name' => 'اجاره', 'sort_order' => 4],
            ['name' => 'حقوق و دستمزد', 'sort_order' => 5],
            ['name' => 'لوازم مصرفی', 'sort_order' => 6],
            ['name' => 'متفرقه', 'sort_order' => 7],
        ];
    }

    public function defaultPaymentMethods(): array
    {
        return [
            ['code' => 'cash', 'label' => 'نقدی', 'enabled' => 1, 'is_credit' => 0,
                'fee_type' => 'none', 'fee_value' => 0, 'rounding_step' => 1000,
                'fee_bearer' => 'customer', 'sort_order' => 1],
            ['code' => 'card_to_card', 'label' => 'کارت به کارت', 'enabled' => 1, 'is_credit' => 0,
                'fee_type' => 'none', 'fee_value' => 0, 'rounding_step' => 1000,
                'fee_bearer' => 'customer', 'sort_order' => 2],
            // All credit/BNPL methods are seeded DISABLED with no fee: the
            // owner must explicitly enable them and enter their own contract
            // fee (multiplier/percent) before customers can pay through them.
            ['code' => 'snap_pay', 'label' => 'اسنپ پی', 'enabled' => 0, 'is_credit' => 1,
                'fee_type' => 'none', 'fee_value' => 0, 'rounding_step' => 1000,
                'fee_bearer' => 'customer', 'sort_order' => 3],
            ['code' => 'digi_pay', 'label' => 'دیجی پی', 'enabled' => 0, 'is_credit' => 1,
                'fee_type' => 'none', 'fee_value' => 0, 'rounding_step' => 1000,
                'fee_bearer' => 'customer', 'sort_order' => 4],
            ['code' => 'torob_pay', 'label' => 'ترب پی', 'enabled' => 0, 'is_credit' => 1,
                'fee_type' => 'none', 'fee_value' => 0, 'rounding_step' => 1000,
                'fee_bearer' => 'customer', 'sort_order' => 5],
        ];
    }
}
