<?php

declare(strict_types=1);

namespace App\Infrastructure\Contracts;

/**
 * Interface for Infrastructure DTOs that convert to Application-layer DTOs.
 *
 * Implemented by API response DTOs whose target lives in App\Application\
 * because the integration data is not modelled by the Domain layer.
 *
 * @internal For use within Infrastructure layer only
 */
interface DtoConvertibleInterface
{
    /**
     * Convert this DTO to its corresponding Application-layer DTO.
     */
    public function toDto(): object;
}
