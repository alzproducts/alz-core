<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\OrderSync\UseCases;

use App\Application\Contracts\QuickBooks\OrderSyncSettingsInterface;
use App\Application\Contracts\QuickBooks\OrderSyncSourceQueryRepositoryInterface;
use App\Application\Contracts\QuickBooks\QuickBooksOrderPostingInterface;
use App\Application\QuickBooks\OrderSync\DTOs\DuplicateDocNumberDTO;
use App\Application\QuickBooks\OrderSync\Resolvers\DocumentFieldTextResolver;
use App\Application\QuickBooks\OrderSync\Resolvers\OrderSyncCalendarResolver;
use App\Application\QuickBooks\OrderSync\Results\DocumentLookupResult;
use App\Application\QuickBooks\OrderSync\Results\DuplicateDocNumbersResult;
use App\Application\QuickBooks\Services\QuickBooksTokenService;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\CorruptApiKeyException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\KeyEncryptionFailedException;
use App\Domain\Exceptions\Api\UnexpectedApiResultException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\Exceptions\Infrastructure\LockAcquisitionException;
use App\Domain\Exceptions\InvalidConfigurationException;
use App\Domain\QuickBooks\Exceptions\QuickBooksDeadlineExceededException;
use App\Domain\QuickBooks\Exceptions\QuickBooksFaultException;
use App\Domain\Shared\ValueObjects\Deadline;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;

/**
 * Finds order document numbers that QBO holds on more than one invoice or sales receipt, writing no QBO document.
 */
final readonly class AuditDuplicateDocNumbersUseCase
{
    private const int CHUNK_SIZE = 100;

    /** No lease bounds this run, so the deadline only stops one chunk's lookup waiting for ever. */
    private const int LOOKUP_SECONDS = 300;

    public function __construct(
        private OrderSyncSourceQueryRepositoryInterface $sourceOrders,
        private OrderSyncSettingsInterface $settings,
        private QuickBooksTokenService $tokens,
        private QuickBooksOrderPostingInterface $posting,
        private LoggerInterface $logger,
    ) {}

    /**
     * @param DateTimeImmutable $from the first Europe/London day
     * @param DateTimeImmutable $to   the last Europe/London day
     *
     * @throws AuthenticationExpiredException
     * @throws CorruptApiKeyException
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     * @throws InvalidApiResponseException
     * @throws InvalidConfigurationException
     * @throws KeyEncryptionFailedException
     * @throws LockAcquisitionException
     * @throws QuickBooksDeadlineExceededException
     * @throws QuickBooksFaultException
     * @throws UnexpectedApiResultException
     */
    public function execute(DateTimeImmutable $from, DateTimeImmutable $to): DuplicateDocNumbersResult
    {
        $this->logger->info('Auditing QuickBooks duplicate document numbers', ['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')]);

        [$start, $before] = OrderSyncCalendarResolver::rangeBounds($from, $to);
        $docNumbers = $this->docNumbers($this->sourceOrders->referencesPlacedIn($start, $before));

        $duplicates = [];
        foreach (\array_chunk($docNumbers, self::CHUNK_SIZE) as $chunk) {
            \array_push($duplicates, ...self::duplicatesAmong($chunk, $this->lookUp($chunk)));
        }
        $result = new DuplicateDocNumbersResult(\count($docNumbers), $duplicates);

        $this->logger->info('Audited QuickBooks duplicate document numbers', ['counts' => $result->counts()]);

        return $result;
    }

    /**
     * @param list<int> $references
     *
     * @return list<string> each as the sync would post it, run label prefix included
     *
     * @throws InvalidConfigurationException
     */
    private function docNumbers(array $references): array
    {
        $prefix = $this->settings->runLabels()->docNumberPrefix;

        return \array_map(
            static fn(int $reference): string => $prefix . DocumentFieldTextResolver::referenceNumber((string) $reference),
            $references,
        );
    }

    /**
     * @param list<string> $docNumbers
     *
     * @throws AuthenticationExpiredException
     * @throws CorruptApiKeyException
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     * @throws InvalidApiResponseException
     * @throws KeyEncryptionFailedException
     * @throws LockAcquisitionException
     * @throws QuickBooksDeadlineExceededException
     * @throws QuickBooksFaultException
     * @throws UnexpectedApiResultException
     */
    private function lookUp(array $docNumbers): DocumentLookupResult
    {
        // intentional: asked for per chunk, so a long range outlives one access token.
        $accessToken = $this->tokens->accessToken();

        try {
            return $this->posting->findDocuments($accessToken, $docNumbers, Deadline::after(new DateTimeImmutable(), self::LOOKUP_SECONDS));
        } catch (AuthenticationExpiredException $e) {
            $this->tokens->forgetAccessToken();

            throw $e;
        }
    }

    /**
     * @param list<string> $docNumbers
     *
     * @return list<DuplicateDocNumberDTO>
     */
    private static function duplicatesAmong(array $docNumbers, DocumentLookupResult $found): array
    {
        $duplicates = [];
        foreach ($docNumbers as $docNumber) {
            $documents = $found->forDocNumber($docNumber);
            if (\count($documents) > 1) {
                $duplicates[] = new DuplicateDocNumberDTO($docNumber, $documents);
            }
        }

        return $duplicates;
    }
}
