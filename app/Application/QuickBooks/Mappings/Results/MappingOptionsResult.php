<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\Mappings\Results;

use App\Application\QuickBooks\Mappings\DTOs\MappingCandidateDTO;
use App\Application\QuickBooks\Mappings\DTOs\MappingOptionDTO;
use App\Domain\Exceptions\ValidationFailedException;

final readonly class MappingOptionsResult
{
    /**
     * @param list<MappingOptionDTO> $options active candidates only
     */
    public function __construct(public array $options) {}

    /**
     * @throws ValidationFailedException when no active candidate has the id
     */
    public function pick(string $refId): MappingCandidateDTO
    {
        $reason = 'The ref_id is not an active QuickBooks candidate for this mapping key.';

        return \array_find($this->options, static fn(MappingOptionDTO $option): bool => $option->candidate->id === $refId)->candidate
            ?? throw new ValidationFailedException($reason, ['ref_id' => [$reason]]);
    }
}
