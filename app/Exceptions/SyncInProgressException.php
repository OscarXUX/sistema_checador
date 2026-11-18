<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Ya hay una sincronización con la terminal en curso (auditoría SE-13).
 */
class SyncInProgressException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Ya hay una sincronización en curso. Intenta de nuevo en unos segundos.');
    }
}
