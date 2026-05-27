<?php

declare(strict_types=1);

namespace App\Infrastructure\Conversion\CallTracking\Repositories;

use App\Application\Contracts\Conversion\CallTracking\CallTrackingNumberRepositoryInterface;
use App\Domain\Conversion\CallTracking\ValueObjects\PhoneNumberE164;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Data\InvalidFormatException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Infrastructure\Conversion\CallTracking\Models\CallTrackingNumberModel;
use App\Infrastructure\Database\DatabaseGateway;
use Illuminate\Database\MultipleColumnsSelectedException;
use LogicException;
use Webmozart\Assert\Assert;

final readonly class EloquentCallTrackingNumberRepository implements CallTrackingNumberRepositoryInterface
{
    public function __construct(
        private DatabaseGateway $dbGateway,
    ) {}

    /**
     * @return list<PhoneNumberE164>
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     * @throws InvalidFormatException If a stored phone number bypasses the column-length guard
     */
    public function findAllActive(): array
    {
        /** @var list<PhoneNumberE164> */
        return $this->dbGateway->query(static function (): array {
            $rows = CallTrackingNumberModel::query()
                ->where('active', true)
                ->orderBy('sort_order')
                ->pluck('phone_number')
                ->all();

            $result = [];
            foreach ($rows as $phone) {
                Assert::string($phone);
                $result[] = PhoneNumberE164::from($phone);
            }

            return $result;
        });
    }

    /**
     * `UPDATE … RETURNING` is inherently atomic at the row-lock level, and this
     * method is always called inside the use case's outer `transact()` — so
     * `query()` (no nested savepoint) is sufficient.
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function incrementAndGetCounter(): int
    {
        return $this->dbGateway->query(function (): int {
            try {
                $counter = $this->dbGateway->connection()->scalar(
                    'UPDATE customer_service.call_tracking_rotation_counter SET counter = counter + 1, updated_at = NOW() WHERE id = 1 RETURNING counter',
                );
            } catch (MultipleColumnsSelectedException $e) {
                throw new LogicException('RETURNING clause is hard-coded to a single column — unreachable', 0, $e);
            }

            Assert::integerish($counter, 'rotation counter row missing or non-numeric — seed row is guaranteed by migration');

            return (int) $counter;
        });
    }
}
