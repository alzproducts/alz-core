<?php

declare(strict_types=1);

namespace App\Presentation\Http\QuickBooks\Controllers;

use App\Application\QuickBooks\Results\QuickBooksCallbackResult;
use App\Application\QuickBooks\UseCases\CompleteQuickBooksConnectionUseCase;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\KeyEncryptionFailedException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\Exceptions\Infrastructure\LockAcquisitionException;
use App\Presentation\Http\QuickBooks\DTOs\QuickBooksCallbackRequestDTO;
use App\Presentation\Http\QuickBooks\QuickBooksCallbackExceptionMapper;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Intuit's OAuth redirect target. Unauthenticated: the single-use `state` is the only guard.
 * A failure renders through {@see QuickBooksCallbackExceptionMapper}.
 *
 * @throws LockAcquisitionException
 * @throws AuthenticationExpiredException
 * @throws ExternalServiceUnavailableException
 * @throws InvalidApiResponseException
 * @throws DatabaseOperationFailedException
 * @throws DuplicateRecordException
 * @throws KeyEncryptionFailedException
 */
final readonly class QuickBooksCallbackController
{
    public function __construct(
        private CompleteQuickBooksConnectionUseCase $useCase,
    ) {}

    /**
     * @throws LockAcquisitionException
     * @throws AuthenticationExpiredException
     * @throws ExternalServiceUnavailableException
     * @throws InvalidApiResponseException
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws KeyEncryptionFailedException
     */
    public function __invoke(Request $request): Response
    {
        $query = QuickBooksCallbackRequestDTO::fromQuery($request);

        $outcome = $this->useCase->execute($query->state, $query->code, $query->realmId);

        return $outcome === QuickBooksCallbackResult::Connected
            ? self::text('QuickBooks connected. You can close this window.', Response::HTTP_OK)
            : self::text('Invalid QuickBooks callback.', Response::HTTP_BAD_REQUEST);
    }

    private static function text(string $body, int $status): Response
    {
        return new Response($body, $status, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
