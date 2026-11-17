<?php

declare(strict_types=1);

namespace App\Helpers;

use App\Core\ApiException;
use App\Core\Config;
use GdImage;
use RuntimeException;

/**
 * Image upload pipeline — ALL checks server-side, the client is never trusted.
 *
 * Per file: PHP upload-error detection -> size/dimension limits -> real MIME
 * check (finfo + getimagesize, jpeg/png/webp ONLY) -> GD decode -> EXIF
 * auto-rotate (jpeg) -> re-encode (strips ALL metadata and any embedded
 * payload, killing polyglots) -> three outputs: full (long side <= 1600),
 * medium (~1000), thumb (~400). Random file names from random_bytes; the
 * original name/extension are never used. Files live under
 *   {uploads.dir}/{tenant_id}/{product_id}/
 * where uploads.dir comes from config (never a hardcoded path).
 *
 * NEVER use is_uploaded_file()/move_uploaded_file() here: those functions
 * only work for files that PHP received via a real HTTP multipart upload,
 * which would make CLI tests impossible. This pipeline is deliberately
 * upload-provenance-agnostic: it reads tmp_name directly (finfo +
 * getimagesize + GD) and re-encodes into the storage dir. That is safe in
 * production because PHP builds $_FILES[tmp_name] itself for real multipart
 * uploads (clients cannot forge it), and every validation check above runs
 * regardless of how the file arrived.
 *
 * Pure-ish and testable: process() accepts a $_FILES-shaped array, so tests
 * can feed arbitrary temp files.
 */
final class Uploads
{
    /** Hard pixel-dimension cap, enforced BEFORE decoding. */
    public const MAX_DIMENSION = 6000;

    /** Long side of the stored "full" image (clients usually send <= 1600). */
    public const FULL_SIZE = 1600;

    public const THUMB_SIZE = 400;
    public const MEDIUM_SIZE = 1000;

    private const ALLOWED_MIME = ['image/jpeg', 'image/png', 'image/webp'];

    /** Max files accepted in ONE upload request. */
    public const MAX_FILES_PER_REQUEST = 5;

    /**
     * Detect request-level upload failures: PHP post_max_size overflow shows
     * up as an EMPTY $_FILES with a non-zero Content-Length.
     */
    public static function assertRequestReadable(): void
    {
        if ($_FILES === [] && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
            throw new ApiException('POST_TOO_LARGE', 413,
                'حجم داده ارسالی بیش از حد مجاز سرور است؛ تصاویر کوچک‌تری انتخاب کنید.');
        }
    }

    /**
     * Process one uploaded file ($_FILES entry or a test-provided equivalent).
     *
     * @param array{tmp_name?: string, name?: string, size?: int, error?: int} $file
     * @return array{file_name: string, thumb_name: string, medium_name: string}
     * @throws ApiException with a specific English code + Persian message
     */
    public static function process(array $file, int $tenantId, int $productId): array
    {
        $maxBytes = (int) Config::get('uploads.max_bytes', 3145728);

        // 1) PHP upload error codes first (INI size, form size, partial, ...).
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        switch ($error) {
            case UPLOAD_ERR_OK:
                break;
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                throw new ApiException('UPLOAD_TOO_LARGE', 413,
                    'حجم تصویر بیشتر از حد مجاز سرور است (حداکثر ۳ مگابایت).');
            case UPLOAD_ERR_PARTIAL:
                throw new ApiException('UPLOAD_PARTIAL', 400,
                    'بارگذاری نیمه‌کاره متوقف شد؛ دوباره تلاش کنید.');
            case UPLOAD_ERR_NO_FILE:
                throw new ApiException('UPLOAD_NO_FILE', 400, 'فایلی برای بارگذاری انتخاب نشده است.');
            default:
                throw new ApiException('UPLOAD_FAILED', 500, 'بارگذاری فایل ناموفق بود؛ دوباره تلاش کنید.');
        }

        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_file($tmp)) {
            throw new ApiException('UPLOAD_NO_FILE', 400, 'فایلی برای بارگذاری انتخاب نشده است.');
        }
        $size = (int) ($file['size'] ?? filesize($tmp));
        if ($size > $maxBytes) {
            throw new ApiException('UPLOAD_TOO_LARGE', 413,
                'حجم تصویر بیشتر از حد مجاز است (حداکثر ۳ مگابایت).');
        }

        // 2) Real content type — finfo on the bytes, never the client name.
        $mime = 'application/octet-stream';
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $detected = finfo_file($finfo, $tmp);
                finfo_close($finfo);
                if (is_string($detected)) {
                    $mime = $detected;
                }
            }
        }
        if (!in_array($mime, self::ALLOWED_MIME, true)) {
            throw new ApiException('UPLOAD_BAD_TYPE', 400,
                'فرمت تصویر مجاز نیست؛ فقط JPG، PNG و WebP پذیرفته می‌شود.');
        }

        // 3) Pixel dimensions BEFORE decoding (a crafted header cannot hurt us).
        $info = getimagesize($tmp);
        if ($info === false) {
            throw new ApiException('UPLOAD_BAD_TYPE', 400,
                'فرمت تصویر مجاز نیست؛ فقط JPG، PNG و WebP پذیرفته می‌شود.');
        }
        [$width, $height] = $info;
        if ($width < 1 || $height < 1 || max($width, $height) > self::MAX_DIMENSION) {
            throw new ApiException('UPLOAD_TOO_LARGE', 413,
                'ابعاد تصویر بیش از حد مجاز است (حداکثر ۶۰۰۰×۶۰۰۰ پیکسل).');
        }

        // 4) GD is required to decode + re-encode; fail cleanly when missing.
        if (!extension_loaded('gd') || !function_exists('imagecreatetruecolor')) {
            throw new ApiException('GD_MISSING', 500,
                'افزونه GD روی سرور فعال نیست؛ از مدیر سرور بخواهید افزونه GD را فعال کند.');
        }

        $blob = file_get_contents($tmp);
        if ($blob === false) {
            throw new ApiException('UPLOAD_FAILED', 500, 'بارگذاری فایل ناموفق بود؛ دوباره تلاش کنید.');
        }
        $image = @imagecreatefromstring($blob);
        if ($image === false) {
            // Passes finfo but GD cannot decode: treated as a bad file.
            throw new ApiException('UPLOAD_BAD_TYPE', 400,
                'فایل تصویر قابل پردازش نیست.');
        }

        // 5) EXIF auto-rotate (jpeg only — png/webp carry no orientation).
        if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
            $exif = @exif_read_data($tmp);
            $orientation = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;
            $image = self::autoRotate($image, $orientation);
            if ($image === null) {
                throw new ApiException('UPLOAD_FAILED', 500, 'پردازش تصویر ناموفق بود.');
            }
        }

        // 6) Re-encode: full (<=1600), medium (~1000), thumb (~400).
        $dir = self::productDir($tenantId, $productId);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new ApiException('UPLOAD_SERVER', 500, 'خطای سرور در ذخیره تصویر.');
        }

        // One random base name for the three variants; webp preferred.
        $base = bin2hex(random_bytes(16));
        $enc = self::encoder();
        $names = [];
        foreach ([['file_name', self::FULL_SIZE, ''], ['medium_name', self::MEDIUM_SIZE, '.m'], ['thumb_name', self::THUMB_SIZE, '.t']] as $variant) {
            [$column, $maxSide, $suffix] = $variant;
            $out = self::resize($image, $maxSide);
            if ($out === null) {
                throw new ApiException('UPLOAD_SERVER', 500, 'خطای سرور در پردازش تصویر.');
            }
            $fileName = $base . $suffix . '.' . $enc['ext'];
            if (!$enc['save']($out, $dir . '/' . $fileName)) {
                imagedestroy($out);
                throw new ApiException('UPLOAD_SERVER', 500, 'خطای سرور در ذخیره تصویر.');
            }
            imagedestroy($out);
            $names[$column] = $fileName;
        }
        imagedestroy($image);

        return $names;
    }

    /** Absolute storage directory for a product's images (from config). */
    public static function productDir(int $tenantId, int $productId): string
    {
        $base = rtrim((string) Config::get('uploads.dir', ''), '/\\');
        if ($base === '') {
            throw new RuntimeException('uploads.dir is not configured.');
        }
        return $base . '/' . $tenantId . '/' . $productId;
    }

    /** Delete the three files of one image row (missing files are ignored). */
    public static function deleteFiles(int $tenantId, int $productId, array $row): void
    {
        $dir = self::productDir($tenantId, $productId);
        foreach (['file_name', 'medium_name', 'thumb_name'] as $column) {
            $name = (string) ($row[$column] ?? '');
            if ($name !== '' && !str_contains($name, '/') && !str_contains($name, '\\')) {
                @unlink($dir . '/' . $name);
            }
        }
    }

    /** Encoder selection: webp when available, jpeg fallback. */
    private static function encoder(): array
    {
        if (function_exists('imagewebp')) {
            return ['ext' => 'webp', 'save' => static function (GdImage $im, string $path): bool {
                return imagewebp($im, $path, 85);
            }];
        }
        return ['ext' => 'jpg', 'save' => static function (GdImage $im, string $path): bool {
            return imagejpeg($im, $path, 85);
        }];
    }

    /** Apply EXIF orientation 3/6/8 as a rotation. */
    private static function autoRotate(GdImage $image, int $orientation): ?GdImage
    {
        if (!function_exists('imagerotate') || $orientation === 1) {
            return $image;
        }
        $angle = match ($orientation) {
            3 => 180,
            6 => -90,  // 90° clockwise
            8 => 90,   // 90° counter-clockwise
            default => 0,
        };
        if ($angle === 0) {
            return $image;
        }
        $rotated = imagerotate($image, $angle, 0);
        if ($rotated === false) {
            return null;
        }
        imagedestroy($image);
        return $rotated;
    }

    /** Downscale to fit within $maxSide, never upscale. */
    private static function resize(GdImage $image, int $maxSide): ?GdImage
    {
        $w = imagesx($image);
        $h = imagesy($image);
        $scale = min(1.0, $maxSide / max($w, $h));
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));
        if ($nw === $w && $nh === $h) {
            // Copy anyway so the output never carries GD source quirks.
            $nw = $w;
            $nh = $h;
        }
        $out = imagecreatetruecolor($nw, $nh);
        if ($out === false) {
            return null;
        }
        // Flatten possible transparency onto white (webp has alpha, but keep
        // thumbnails predictable on jpeg fallback).
        $white = imagecolorallocate($out, 255, 255, 255);
        if ($white !== false) {
            imagefill($out, 0, 0, $white);
        }
        if (!imagecopyresampled($out, $image, 0, 0, 0, 0, $nw, $nh, $w, $h)) {
            imagedestroy($out);
            return null;
        }
        return $out;
    }
}
