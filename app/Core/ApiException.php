<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * An error the API answers with a clean JSON error (English code + Persian
 * message). Thrown by controllers and middleware; caught in Router::dispatch.
 */
final class ApiException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly int $status = 400,
        string $message = '', // empty = use the central Persian message for the code
    ) {
        parent::__construct($message);
    }
}
