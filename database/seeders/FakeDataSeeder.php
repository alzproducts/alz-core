<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Infrastructure\Database\DatabaseGateway;
use Database\Seeders\OrderSlice\OrderSliceSeeder;
use Database\Seeders\ProductSlice\ProductSliceSeeder;
use Illuminate\Database\Seeder;

/**
 * `dev:seed-fake`'s entry point: the products slice, then the orders slice, in one transaction, so a refusal by
 * either leaves both slices as they were.
 */
final class FakeDataSeeder extends Seeder
{
    public function __construct(
        private readonly DatabaseGateway $database,
    ) {}

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function run(): void
    {
        $this->database->transact(function (): void {
            $this->call([ProductSliceSeeder::class, OrderSliceSeeder::class]);
        });
    }
}
