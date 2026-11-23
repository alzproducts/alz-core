<?php

declare(strict_types=1);

namespace App\Presentation\Console\Commands;

use App\Application\Contracts\DatabaseConnectivityInterface;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use Illuminate\Console\Command;

/**
 * Exits 0 when the database accepts a connection and 1 with the driver's error otherwise.
 */
final class DatabasePingCommand extends Command
{
    protected $signature = 'db:ping
        {connection? : The database connection to check (defaults to the default connection)}';

    protected $description = 'Check that the database accepts a connection';

    public function handle(DatabaseConnectivityInterface $connectivity): int
    {
        /** @var string|null $connection */
        $connection = $this->argument('connection');

        try {
            $connectivity->verifyConnectivity($connection);
        } catch (ExternalServiceUnavailableException|DuplicateRecordException $e) {
            $this->error('Database connection failed: ' . ($e->getPrevious()?->getMessage() ?? $e->getMessage()));

            return self::FAILURE;
        } catch (DatabaseOperationFailedException $e) {
            $this->error('Database connection failed: ' . $e->reason);

            return self::FAILURE;
        }

        $this->info('Database connection OK');

        return self::SUCCESS;
    }
}
