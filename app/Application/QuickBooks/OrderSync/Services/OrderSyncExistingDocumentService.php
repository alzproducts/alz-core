<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\OrderSync\Services;

use App\Application\Contracts\QuickBooks\QuickBooksOrderPostingInterface;
use App\Application\QuickBooks\OrderSync\DTOs\BuiltOrderDTO;
use App\Application\QuickBooks\OrderSync\DTOs\ExistingDocumentDTO;
use App\Application\QuickBooks\OrderSync\DTOs\OrderSyncOutcomeDTO;
use App\Application\QuickBooks\OrderSync\Resolvers\UnsentOrderResolver;
use App\Application\QuickBooks\OrderSync\Results\DocumentLookupResult;
use App\Application\QuickBooks\OrderSync\Results\OrderPreparationResult;
use App\Domain\Access\ValueObjects\ApiKeyToken;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\UnexpectedApiResultException;
use App\Domain\QuickBooks\Exceptions\QuickBooksDeadlineExceededException;
use App\Domain\QuickBooks\Exceptions\QuickBooksFaultException;
use App\Domain\Shared\ValueObjects\Deadline;
use SensitiveParameter;

/**
 * Settles each built order QuickBooks already holds a document for.
 */
final readonly class OrderSyncExistingDocumentService
{
    public function __construct(
        private QuickBooksOrderPostingInterface $posting,
    ) {}

    /**
     * @param list<BuiltOrderDTO> $built
     *
     * @throws AuthenticationExpiredException
     * @throws QuickBooksFaultException
     * @throws UnexpectedApiResultException
     */
    public function withoutExisting(#[SensitiveParameter] ApiKeyToken $accessToken, array $built, Deadline $deadline): OrderPreparationResult
    {
        if ($built === []) {
            return OrderPreparationResult::of([]);
        }

        $docNumbers = \array_map(static fn(BuiltOrderDTO $order): string => $order->draft->core->docNumber, $built);

        try {
            $documents = $this->posting->findDocuments($accessToken, $docNumbers, $deadline);
        } catch (ExternalServiceUnavailableException|QuickBooksDeadlineExceededException $e) {
            return OrderPreparationResult::of(UnsentOrderResolver::outcomes($built, $e));
        }

        return OrderPreparationResult::of(\array_map(
            static fn(BuiltOrderDTO $order): BuiltOrderDTO|OrderSyncOutcomeDTO => self::unlessExisting($order, $documents),
            $built,
        ));
    }

    private static function unlessExisting(BuiltOrderDTO $order, DocumentLookupResult $documents): BuiltOrderDTO|OrderSyncOutcomeDTO
    {
        $matches = $documents->forDocNumber($order->draft->core->docNumber);
        $ownType = $order->draft->docType();
        $match = \array_find($matches, static fn(ExistingDocumentDTO $document): bool => $document->type === $ownType) ?? $matches[0] ?? null;

        return $match === null ? $order : OrderSyncOutcomeDTO::found($order->order, $match);
    }
}
