<?php

declare(strict_types=1);

namespace App\Domain\Exceptions;

use RuntimeException;
use Throwable;

final class ExternalServiceUnavailableException extends RuntimeException
{
    public static function fromService(string $serviceName, ?Throwable $previous = null): self
    {
        return new self("External service '{$serviceName}' is unavailable", 0, $previous);
    }
}
