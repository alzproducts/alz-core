<?php

declare(strict_types=1);

namespace App\Domain\Catalog\CustomFields\Enums;

/**
 * Local presentation-layer validation rule attached to a custom field definition.
 *
 * Int-backed so that future reordering of the DB-stored values can be handled by
 * data migration without touching the symbolic names used throughout the code.
 */
enum CustomFieldValidationRule: int
{
    case Url = 1;
    case AlphaNumeric = 2;
    case Integer = 3;
}
