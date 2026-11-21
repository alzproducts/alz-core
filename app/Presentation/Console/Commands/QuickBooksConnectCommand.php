<?php

declare(strict_types=1);

namespace App\Presentation\Console\Commands;

use App\Application\QuickBooks\UseCases\StartQuickBooksConnectionUseCase;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use Illuminate\Console\Command;

/**
 * Prints Intuit's consent URL; approving it in a browser stores the QBO connection through the callback.
 *
 * ⚠️ PRODUCTION ONLY — run by a human in production (COR-292). Locally it connects the sandbox company.
 */
final class QuickBooksConnectCommand extends Command
{
    protected $signature = 'quickbooks:connect';

    protected $description = 'Print the Intuit consent URL that connects alz-core to the configured QuickBooks company';

    public function handle(StartQuickBooksConnectionUseCase $useCase): int
    {
        try {
            $url = $useCase->execute();
        } catch (ExternalServiceUnavailableException $e) {
            $this->error('Could not start the QuickBooks connection: ' . $e->getMessage());
            $this->line('  Check: the QuickBooks cache store (QUICKBOOKS_CACHE_STORE) is reachable');

            return self::FAILURE;
        }

        $this->info('Open this URL within 10 minutes, sign in to Intuit and approve the company:');
        $this->line($url);

        return self::SUCCESS;
    }
}
