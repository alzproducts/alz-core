<?php

declare(strict_types=1);

namespace App\Presentation\Http\QuickBooks\DTOs;

use Illuminate\Http\Request;
use SensitiveParameter;
use Spatie\LaravelData\Data;

/**
 * Built only through fromQuery(), never validated: this route is outside `api/*`, so a validation
 * failure would answer 302 instead of 400 (P14). The use case does every rejection.
 */
final class QuickBooksCallbackRequestDTO extends Data
{
    public function __construct(
        public readonly ?string $state = null,
        #[SensitiveParameter]
        public readonly ?string $code = null,
        public readonly ?string $realmId = null,
    ) {}

    public static function fromQuery(Request $request): self
    {
        return new self(
            state: self::stringOrNull($request->query('state')),
            code: self::stringOrNull($request->query('code')),
            realmId: self::stringOrNull($request->query('realmId')),
        );
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return \is_string($value) ? $value : null;
    }
}
