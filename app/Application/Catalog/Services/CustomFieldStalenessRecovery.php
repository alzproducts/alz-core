<?php

declare(strict_types=1);

namespace App\Application\Catalog\Services;

use App\Application\Contracts\Shopwired\ShopwiredSyncDispatcherInterface;
use App\Domain\Catalog\CustomFields\Exceptions\InvalidCustomFieldValueException;
use Closure;
use Psr\Log\LoggerInterface;

/**
 * Self-heals custom field staleness: when reading enriched custom fields fails
 * because a stored value no longer matches its synced definition, dispatch a
 * definitions resync so the next request realigns, then rethrow.
 *
 * The mismatch means local definitions lag a ShopWired admin field-type change.
 * Recovery is best-effort — the exception still surfaces to the caller; the
 * dispatch just shortens the window before the retry succeeds.
 */
final readonly class CustomFieldStalenessRecovery
{
    public function __construct(
        private ShopwiredSyncDispatcherInterface $dispatcher,
        private LoggerInterface $logger,
    ) {}

    /**
     * @template T
     *
     * @param-immediately-invoked-callable $work
     *
     * @param Closure(): T $work
     *
     * @return T
     *
     * @throws InvalidCustomFieldValueException Rethrown after dispatching the resync
     */
    public function withRecovery(Closure $work): mixed
    {
        try {
            return $work();
        } catch (InvalidCustomFieldValueException $e) {
            $this->logger->warning('Custom field staleness detected — dispatching definitions resync', $e->context());
            $this->dispatcher->dispatchCustomFieldsSync();

            throw $e;
        }
    }
}
