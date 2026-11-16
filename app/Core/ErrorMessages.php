<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Central map of API error code -> user-facing Persian message.
 * Codes are English (machine-readable), messages are Persian (user-facing).
 */
final class ErrorMessages
{
    private const MESSAGES = [
        'UNAUTHENTICATED'      => 'برای این درخواست باید وارد شوید.',
        'SESSION_EXPIRED'      => 'مدت زمان ورود شما به پایان رسیده است؛ دوباره وارد شوید.',
        'AUTH_FAILED'          => 'اطلاعات ورود نامعتبر است.',
        'AUTH_LOCKED'          => 'تلاش‌های ناموفق زیاد بود؛ کمی بعد دوباره امتحان کنید.',
        'INVALID_INPUT'        => 'اطلاعات ارسالی کامل نیست.',
        'INVALID_CREDENTIALS'  => 'رمز فعلی درست نیست.',
        'WEAK_PASSWORD'        => 'رمز جدید باید حداقل ۸ کاراکتر باشد.',
        'CSRF_INVALID'         => 'درخواست نامعتبر است؛ صفحه را دوباره بارگذاری کنید.',
        'SUBSCRIPTION_EXPIRED' => 'اشتراک فروشگاه منقضی شده است؛ فقط مشاهده و خروجی گرفتن فعال است.',
        'FORBIDDEN'            => 'شما به این بخش دسترسی ندارید.',
        'FORBIDDEN_FIELD'      => 'شما به این اطلاعات دسترسی ندارید.',
        'UNKNOWN_ACTION'       => 'درخواست ناشناخته است.',
        'SERVER_ERROR'         => 'خطای غیرمنتظره در سرور رخ داد.',
    ];

    public static function get(string $code): string
    {
        return self::MESSAGES[$code] ?? self::MESSAGES['SERVER_ERROR'];
    }
}
