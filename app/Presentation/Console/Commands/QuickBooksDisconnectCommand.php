<?php

declare(strict_types=1);

namespace App\Presentation\Console\Commands;

use App\Application\QuickBooks\Results\QuickBooksDisconnectResult;
use App\Application\QuickBooks\UseCases\DisconnectQuickBooksUseCase;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\CorruptApiKeyException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\Exceptions\Infrastructure\LockAcquisitionException;
use Illuminate\Console\Command;

/**
 * Revokes the QBO grant at Intuit and deletes the stored connection.
 *
 * ⚠️ PRODUCTION ONLY — run by a human in production (COR-292). Locally it disconnects the sandbox company.
 */
final class QuickBooksDisconnectCommand extends Command
{
    protected $signature = 'quickbooks:disconnect
        {--force : Delete the stored connection even when Intuit cannot revoke it}
        {--dry-run : Report what would happen without revoking or deleting}';

    protected $description = 'Revoke the QuickBooks grant and delete the stored connection';

    public function handle(DisconnectQuickBooksUseCase $useCase): int
    {
        try {
            $result = $useCase->execute((bool) $this->option('force'), (bool) $this->option('dry-run'));
        } catch (AuthenticationExpiredException|ExternalServiceUnavailableException $e) {
            return $this->failWith('Intuit did not revoke the grant: ' . $e->getMessage(), 'Intuit is reachable, or rerun with --force to delete the connection anyway');
        } catch (CorruptApiKeyException $e) {
            return $this->failWith('The stored connection is unreadable: ' . $e->getMessage(), 'API_KEY_ENCRYPTION_KEY matches the key the connection was stored with, or rerun with --force');
        } catch (LockAcquisitionException|DatabaseOperationFailedException|DuplicateRecordException $e) {
            return $this->failWith('Could not disconnect QuickBooks: ' . $e->getMessage(), 'the database and the QuickBooks cache store are reachable, then rerun');
        }

        $this->report($result);

        return self::SUCCESS;
    }

    private function failWith(string $error, string $check): int
    {
        $this->error($error);
        $this->line('  Check: ' . $check);

        return self::FAILURE;
    }

    private function report(QuickBooksDisconnectResult $result): void
    {
        match ($result) {
            QuickBooksDisconnectResult::NotConnected => $this->info('QuickBooks is not connected; nothing to do.'),
            QuickBooksDisconnectResult::DryRun => $this->info('Dry run: the stored QuickBooks connection would be revoked and deleted.'),
            QuickBooksDisconnectResult::Revoked => $this->info('Revoked the QuickBooks grant and deleted the stored connection.'),
            QuickBooksDisconnectResult::RemovedWithoutRevoke => $this->warn('Deleted the stored connection without revoking it: revoke the grant in the Intuit app (Connected apps) by hand.'),
        };
    }
}
