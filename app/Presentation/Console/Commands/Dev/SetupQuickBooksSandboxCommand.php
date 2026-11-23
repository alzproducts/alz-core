<?php

declare(strict_types=1);

namespace App\Presentation\Console\Commands\Dev;

use App\Application\QuickBooks\OrderSync\Enums\MappingEntity;
use App\Application\QuickBooks\OrderSync\Enums\MappingKey;
use App\Application\QuickBooks\Services\QuickBooksTokenService;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\UnexpectedApiResultException;
use App\Domain\Exceptions\DomainException;
use App\Domain\QuickBooks\Exceptions\QuickBooksFaultException;
use Illuminate\Console\Command;

/**
 * Sets up the connected QBO sandbox company for the order sync and proves the UK VAT and shipping behaviours it relies
 * on. A re-run creates nothing. Every API step runs before any instruction prints, and a pending one skips the proofs.
 */
final class SetupQuickBooksSandboxCommand extends Command
{
    protected $signature = 'dev:setup-quickbooks-sandbox
                            {--dry-run : Report what is not set up and change no QBO entity or mapping; a cold token cache can still refresh the access token}';

    protected $description = 'Set up the QuickBooks sandbox company for the order sync';

    public function handle(): int
    {
        // intentional: before any service is resolved, since a token read can refresh through Intuit and write.
        if (!\app()->environment('local', 'testing')) {
            return $this->fails('This command only runs in a local or testing environment', 'APP_ENV');
        }

        try {
            return $this->option('dry-run') === true ? $this->report() : $this->setUp();
        } catch (DomainException $e) {
            return $this->stopped($e);
        }
    }

    /**
     * @throws DomainException
     */
    private function setUp(): int
    {
        $company = \app(QuickBooksSandboxCompanyFactory::class);
        $instructions = [
            ...$this->preferenceInstructions($company),
            ...$this->itemInstructions($company),
            ...\array_map(self::mappingInstruction(...), \app(QuickBooksSandboxMappingFactory::class)->resolveAll()),
        ];

        return $instructions === [] ? $this->prove() : $this->instruct($instructions);
    }

    /**
     * @throws DomainException
     */
    private function report(): int
    {
        $company = \app(QuickBooksSandboxCompanyFactory::class);
        $items = $company->unusableItems();
        $pending = [
            ...\array_map(static fn(string $name): string => "preference {$name} is off", $company->preferencesOff()),
            ...\array_map(static fn(string $sku): string => "Item {$sku} is missing", $items['missing']),
            ...\array_map(static fn(string $sku): string => "Item {$sku} is inactive", $items['inactive']),
            ...\array_map(
                static fn(array $left): string => "{$left[0]->value}: {$left[1]}",
                \app(QuickBooksSandboxMappingFactory::class)->pending(),
            ),
        ];

        $this->info($pending === [] ? 'Dry run: everything is set up' : 'Dry run: not set up');
        \array_walk($pending, fn(string $line) => $this->line("  {$line}"));

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     *
     * @throws DomainException
     */
    private function preferenceInstructions(QuickBooksSandboxCompanyFactory $company): array
    {
        $off = $company->preferencesOff();
        if ($off !== []) {
            $refused = $company->turnOnPreferences();
            if ($refused !== null) {
                $this->warn("QuickBooks refused the Preferences update: {$refused}");
            }
            $off = $company->preferencesOff();
        }

        $this->info('Preferences on: ' . (2 - \count($off)) . ' of 2');

        return \array_map(
            static fn(string $name): string => match ($name) {
                'AllowShipping' => 'Account and settings > Sales > Sales form content: turn on Shipping (AllowShipping)',
                default => "Account and settings > Sales > Sales form content: turn on Custom transaction numbers ({$name})",
            },
            $off,
        );
    }

    /**
     * @return list<string>
     *
     * @throws DomainException
     */
    private function itemInstructions(QuickBooksSandboxCompanyFactory $company): array
    {
        $items = $company->unusableItems();
        $company->createItems($items['missing']);
        $this->info('Items created: ' . \count($items['missing']));

        return \array_map(
            static fn(string $sku): string => "Products and services: make {$sku} active",
            $items['inactive'],
        );
    }

    /**
     * @param array{MappingKey, string} $left
     */
    private static function mappingInstruction(array $left): string
    {
        [$key, $reason] = $left;
        $name = QuickBooksSandboxMapper::nameFor($key);

        $step = match ($key->entity()) {
            MappingEntity::TaxCode => "Taxes > VAT: set up the VAT code \"{$name}\"",
            MappingEntity::CustomField => "Custom fields: add a Text sales field labelled exactly \"{$name}\", on for all sales forms",
            MappingEntity::Account,
            MappingEntity::PaymentMethod,
            MappingEntity::Department,
            MappingEntity::SalesClass,
            MappingEntity::Term => "Settle the {$key->entity()->value} \"{$name}\"",
        };

        return "{$step} ({$key->value}: {$reason})";
    }

    /**
     * @param non-empty-list<string> $instructions
     */
    private function instruct(array $instructions): int
    {
        $this->warn('Do these in the QuickBooks UI, then run the command again:');
        foreach ($instructions as $index => $instruction) {
            $this->line('  ' . ($index + 1) . ". {$instruction}");
        }

        return $this->fails('Setup is incomplete, so no proof ran', 'the steps above');
    }

    /**
     * @throws DomainException
     */
    private function prove(): int
    {
        $failed = 0;
        foreach (\app(QuickBooksSandboxProofFactory::class)->run() as $proof => $reason) {
            $failed += $reason === null ? 0 : 1;
            $this->line($reason === null ? "PASS {$proof}" : "FAIL {$proof}: {$reason}");
        }

        return $failed === 0
            ? self::SUCCESS
            : $this->fails("{$failed} proof(s) failed", 'the proof documents in QuickBooks');
    }

    private function stopped(DomainException $e): int
    {
        if ($e instanceof AuthenticationExpiredException) {
            $this->forgetToken();
        }

        $reason = match (true) {
            $e instanceof QuickBooksFaultException => 'QuickBooks fault ' . QuickBooksSandboxMapper::faultText($e->fault),
            $e instanceof UnexpectedApiResultException => \class_basename($e) . ": {$e->reason}",
            default => \class_basename($e),
        };

        return $this->fails("Stopped: {$reason}", $e instanceof AuthenticationExpiredException
            ? 'the QuickBooks connection (quickbooks:connect)'
            : 'the log, then run the command again; finished steps are kept');
    }

    private function forgetToken(): void
    {
        try {
            \app(QuickBooksTokenService::class)->forgetAccessToken();
        } catch (DomainException $e) {
            $this->warn('The cached access token was not forgotten: ' . \class_basename($e));
        }
    }

    private function fails(string $error, string $check): int
    {
        $this->error($error);
        $this->line("  Check: {$check}");

        return self::FAILURE;
    }
}
