<?php

declare(strict_types=1);

namespace App\Presentation\Http\Controllers\Shopwired;

use App\Domain\Catalog\Product\Commands\SetFreeDeliveryCommand;
use App\Domain\Catalog\Product\Enums\FreeDeliveryType;
use App\Presentation\Concerns\DispatchesChunkedJobsTrait;
use App\Presentation\Http\Requests\SetFreeDeliveryRequest;
use App\Presentation\Jobs\Shopwired\SetProductFreeDeliveryJob;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use ValueError;

/**
 * HTTP endpoints for ShopWired product updates.
 *
 * All endpoints require Supabase JWT authentication.
 */
final class ProductUpdateController
{
    use DispatchesChunkedJobsTrait;

    /**
     * Update free delivery type on multiple products.
     *
     * Dispatches jobs to update the free_delivery custom field.
     * Returns 202 Accepted with job dispatch summary.
     *
     * @throws ValueError When free delivery type is invalid (should not happen after validation)
     */
    public function updateFreeDelivery(SetFreeDeliveryRequest $request): JsonResponse
    {
        /** @var list<array{identifier: string|int, type: string}> $updates */
        $updates = $request->validated('updates');

        $commands = \array_map(
            static fn(array $update): SetFreeDeliveryCommand => new SetFreeDeliveryCommand(
                $update['identifier'],
                FreeDeliveryType::fromString($update['type']),
            ),
            $updates,
        );

        $jobsDispatched = $this->dispatchInChunks($commands, SetProductFreeDeliveryJob::class);

        return new JsonResponse(
            [
                'message' => 'Updates queued for processing',
                'total' => \count($commands),
                'jobs_dispatched' => $jobsDispatched,
            ],
            Response::HTTP_ACCEPTED,
        );
    }
}
