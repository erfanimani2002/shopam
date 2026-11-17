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
        // Step 3 (products + images)
        'NOT_FOUND'            => 'مورد موردنظر پیدا نشد.',
        'PLAN_LIMIT_REACHED'   => 'ظرفیت محصولات فروشگاه در طرح فعلی پر شده است؛ برای افزایش ظرفیت با مدیر سامانه تماس بگیرید.',
        'IMAGE_LIMIT_REACHED'  => 'حداکثر تعداد تصاویر برای هر محصول پر شده است.',
        'UPLOAD_TOO_LARGE'     => 'حجم تصویر بیشتر از حد مجاز است (حداکثر ۳ مگابایت).',
        'UPLOAD_BAD_TYPE'      => 'فرمت تصویر مجاز نیست؛ فقط JPG، PNG و WebP پذیرفته می‌شود.',
        'UPLOAD_PARTIAL'       => 'بارگذاری نیمه‌کاره متوقف شد؛ دوباره تلاش کنید.',
        'UPLOAD_NO_FILE'       => 'فایلی برای بارگذاری انتخاب نشده است.',
        'UPLOAD_FAILED'        => 'بارگذاری فایل ناموفق بود؛ دوباره تلاش کنید.',
        'UPLOAD_SERVER'        => 'خطای سرور در پردازش تصویر.',
        'GD_MISSING'           => 'افزونه GD روی سرور فعال نیست؛ از مدیر سرور بخواهید آن را فعال کند.',
        'POST_TOO_LARGE'       => 'حجم داده ارسالی بیش از حد مجاز سرور است؛ تصاویر کوچک‌تری انتخاب کنید.',
        'UPLOAD_TOO_MANY'      => 'حداکثر ۵ تصویر در هر درخواست می‌توانید بفرستید.',
    ];

    public static function get(string $code): string
    {
        return self::MESSAGES[$code] ?? self::MESSAGES['SERVER_ERROR'];
    }
}
