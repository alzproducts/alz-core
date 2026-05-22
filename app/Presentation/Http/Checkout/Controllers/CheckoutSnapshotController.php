<?php

declare(strict_types=1);

namespace App\Presentation\Http\Checkout\Controllers;

use App\Application\Checkout\UseCases\CaptureBasketSnapshotUseCase;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Data\MalformedStoredDataException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Presentation\Http\Checkout\Mappers\BasketSnapshotMapper;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Captures a pre-checkout basket snapshot.
 *
 * Frontend fires fetch() with `keepalive: true` on checkout click (fire-and-forget).
 * Workaround for ShopWired losing `basket_comments` on Safari/Apple submissions.
 *
 * IP address and user agent are captured server-side — never trusted from the client.
 */
final readonly class CheckoutSnapshotController
{
    public function __construct(
        private BasketSnapshotMapper $mapper,
        private CaptureBasketSnapshotUseCase $useCase,
    ) {}

    /**
     * @throws DatabaseOperationFailedException On insert failure (permanent)
     * @throws DuplicateRecordException On unique constraint violation (permanent)
     * @throws ExternalServiceUnavailableException On transient database failure (retry)
     * @throws MalformedStoredDataException When delivery_date bypasses DTO validation
     */
    public function __invoke(Request $request): Response
    {
        $this->useCase->execute($this->mapper->toCommand($request));

        return new Response('', Response::HTTP_CREATED);
    }
}
