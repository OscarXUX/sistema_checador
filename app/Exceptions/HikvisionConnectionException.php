<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Excepción lanzada cuando falla la comunicación ISAPI con la terminal Hikvision.
 */
class HikvisionConnectionException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $statusCode = null,
        public readonly ?string $endpoint = null,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }
}

