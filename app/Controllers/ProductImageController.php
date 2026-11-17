<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\ApiException;
use App\Core\Request;
use App\Helpers\Audit;
use App\Helpers\Uploads;
use App\Models\ProductImageModel;
use App\Models\ProductModel;
use App\Models\TenantModel;
use Throwable;

/**
 * Product image operations: upload (multipart), delete, set cover, reorder.
 * Every operation verifies the product belongs to the session tenant; ids
 * from another shop behave as "not found". Expired tenants are rejected by
 * the subscription middleware (these routes are POST).
 */
final class ProductImageController
{
    private ProductModel $products;
    private ProductImageModel $images;

    public function __construct()
    {
        $this->products = new ProductModel();
        $this->images = new ProductImageModel();
    }

    /** POST product_image_upload — multipart field "files", 1..5 per request. */
    public function product_image_upload(): array
    {
        Uploads::assertRequestReadable(); // post_max_size overflow (empty $_FILES)

        $productId = $this->productId();
        $this->assertProductExists($productId);

        $files = $_FILES['files'] ?? null;
        if (!is_array($files)) {
            throw new ApiException('UPLOAD_NO_FILE', 400, 'فایلی برای بارگذاری انتخاب نشده است.');
        }
        // Normalize single/multi uploads into a list of $_FILES-shaped rows.
        if (is_array($files['name'] ?? null)) {
            $list = [];
            foreach ($files['name'] as $i => $ignored) {
                $list[] = [
                    'tmp_name' => $files['tmp_name'][$i],
                    'name'     => $files['name'][$i],
                    'size'     => $files['size'][$i],
                    'error'    => $files['error'][$i],
                ];
            }
        } else {
            $list = [$files];
        }
        if ($list === []) {
            throw new ApiException('UPLOAD_NO_FILE', 400, 'فایلی برای بارگذاری انتخاب نشده است.');
        }
        if (count($list) > Uploads::MAX_FILES_PER_REQUEST) {
            throw new ApiException('UPLOAD_TOO_MANY', 400,
                'حداکثر ۵ تصویر در هر درخواست.');
        }

        // Limit = min(config max, plan max).
        $configMax = (int) (\App\Core\Config::get('uploads.max_images_per_product', 5));
        $planMax = (new TenantModel())->planLimits(\App\Core\Auth::tenantId())['max_images'];
        $limit = min($configMax, $planMax);

        $current = $this->images->countForProduct($productId);
        if ($current + count($list) > $limit) {
            throw new ApiException('IMAGE_LIMIT_REACHED', 403,
                "حداکثر {$limit} تصویر برای هر محصول مجاز است.");
        }

        $inserted = 0;
        $failed = [];
        foreach ($list as $file) {
            try {
                $names = Uploads::process($file, \App\Core\Auth::tenantId(), $productId);
                $this->images->insertAtEnd($productId, $names);
                $inserted++;
            } catch (ApiException $e) {
                $failed[] = ['name' => (string) ($file['name'] ?? ''), 'code' => $e->errorCode, 'status' => $e->status, 'message' => $e->getMessage()];
            } catch (Throwable $e) {
                $failed[] = ['name' => (string) ($file['name'] ?? ''), 'code' => 'UPLOAD_SERVER', 'status' => 500, 'message' => 'خطای سرور در پردازش تصویر.'];
            }
        }

        if ($inserted > 0) {
            Audit::log('product_image.upload', 'product', $productId,
                ['inserted' => $inserted, 'failed' => $failed]);
        }
        if ($inserted === 0 && $failed !== []) {
            // Nothing stored: surface the FIRST failure as the response error.
            $first = $failed[0];
            throw new ApiException($first['code'], (int) $first['status'], (string) $first['message']);
        }

        return ['product_id' => $productId, 'inserted' => $inserted, 'failed' => $failed, 'images' => $this->images->listForProduct($productId)];
    }

    /** POST product_image_delete — removes the row, renumbers, deletes files. */
    public function product_image_delete(): array
    {
        [$productId, $imageId] = $this->imageIds();
        $row = $this->images->deleteAndRenumber($productId, $imageId);
        if ($row === null) {
            throw new ApiException('NOT_FOUND', 404);
        }
        Uploads::deleteFiles(\App\Core\Auth::tenantId(), $productId, $row);
        Audit::log('product_image.delete', 'product', $productId, ['image_id' => $imageId]);
        return ['images' => $this->images->listForProduct($productId)];
    }

    /** POST product_image_set_cover. */
    public function product_image_set_cover(): array
    {
        [$productId, $imageId] = $this->imageIds();
        if (!$this->images->moveToCover($productId, $imageId)) {
            throw new ApiException('NOT_FOUND', 404);
        }
        Audit::log('product_image.set_cover', 'product', $productId, ['image_id' => $imageId]);
        return ['images' => $this->images->listForProduct($productId)];
    }

    /** POST product_image_reorder — full new order as "ids": [id1, id2, ...]. */
    public function product_image_reorder(): array
    {
        $productId = $this->productId();
        $this->assertProductExists($productId);
        $ids = array_values(array_filter(array_map('intval', (array) Request::input('ids', [])),
            static fn (int $v): bool => $v > 0));
        if ($ids === []) {
            throw new ApiException('INVALID_INPUT', 400, 'ترتیب جدید ارسال نشده است.');
        }
        if (!$this->images->applyOrder($productId, $ids)) {
            throw new ApiException('INVALID_INPUT', 400,
                'ترتیب ارسال‌شده با تصاویر محصول مطابقت ندارد.');
        }
        Audit::log('product_image.reorder', 'product', $productId, ['order' => $ids]);
        return ['images' => $this->images->listForProduct($productId)];
    }

    /* ------------------------------------------------------------------ */

    /** @return array{0: int, 1: int} [productId, imageId] */
    private function imageIds(): array
    {
        $productId = (int) Request::input('product_id', 0);
        $imageId = (int) Request::input('id', 0);
        if ($productId <= 0 || $imageId <= 0) {
            throw new ApiException('INVALID_INPUT', 400);
        }
        return [$productId, $imageId];
    }

    private function productId(): int
    {
        $id = (int) Request::input('product_id', 0);
        if ($id <= 0) {
            throw new ApiException('INVALID_INPUT', 400);
        }
        return $id;
    }

    /** Cross-tenant product ids behave as "not found". */
    private function assertProductExists(int $productId): void
    {
        if ($this->products->find($productId) === null) {
            throw new ApiException('NOT_FOUND', 404);
        }
    }
}
