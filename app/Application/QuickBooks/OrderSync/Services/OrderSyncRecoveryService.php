<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\OrderSync\Services;

use App\Application\Contracts\QuickBooks\OrderSyncRepositoryInterface;
use App\Application\QuickBooks\OrderSync\DTOs\ClaimedOrderDTO;
use App\Application\QuickBooks\Services\QuickBooksTokenService;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Cleans up after a throw: releases every claimed row, then drops a rejected access token. It never throws, so the
 * caller can rethrow the original error. A recorded row no longer holds its claim token, so releasing it changes nothing.
 */
final readonly class OrderSyncRecoveryService
{
    public function __construct(
        private OrderSyncRepositoryInterface $orderSyncs,
        private QuickBooksTokenService $tokens,
        private LoggerInterface $logger,
    ) {}

    /**
     * @param list<ClaimedOrderDTO> $claimed
     */
    public function recover(array $claimed, Throwable $cause): void
    {
        foreach ($claimed as $order) {
            $this->release($order);
        }

        if ($cause instanceof AuthenticationExpiredException) {
            $this->forgetAccessToken();
        }
    }

    private function release(ClaimedOrderDTO $order): void
    {
        try {
            $this->orderSyncs->release($order->claim->orderSyncId, $order->claim->claimToken);
        } catch (Throwable $e) { // @ignoreException - a failed release must not hide the original error
            $this->logger->error('QuickBooks order sync row could not be released', [
                'reference' => $order->row->reference,
                'exception' => $e::class,
            ]);
        }
    }

    private function forgetAccessToken(): void
    {
        try {
            $this->tokens->forgetAccessToken();
        } catch (Throwable $e) { // @ignoreException - a failed drop must not hide the authentication error
            $this->logger->error('Rejected QuickBooks access token could not be dropped', ['exception' => $e::class]);
        }
    }
}
