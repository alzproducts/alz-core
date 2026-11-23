<?php

declare(strict_types=1);

namespace App\Presentation\Console\Commands;

use App\Application\QuickBooks\Mappings\DTOs\MappingCandidateDTO;
use App\Application\QuickBooks\Mappings\DTOs\MappingFindingDTO;
use App\Application\QuickBooks\Mappings\Results\MappingAutoResolveResult;
use App\Application\QuickBooks\Mappings\UseCases\AutoResolveMappingsUseCase;
use App\Application\QuickBooks\Mappings\UseCases\CheckMappingsUseCase;
use App\Application\QuickBooks\Mappings\UseCases\ListMappingCandidatesUseCase;
use App\Application\QuickBooks\Mappings\UseCases\ListUnresolvedMappingsUseCase;
use App\Application\QuickBooks\Mappings\UseCases\RecordMappingPickUseCase;
use App\Application\QuickBooks\OrderSync\Enums\MappingKey;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\RecordNotFoundException;
use App\Domain\Exceptions\DomainException;
use App\Domain\QuickBooks\Exceptions\QuickBooksFaultException;
use Illuminate\Console\Command;

/**
 * Points mapping keys at entities of the configured QuickBooks company.
 *
 * ⚠️ PRODUCTION ONLY — run by a human in production. Locally it resolves keys for the sandbox company.
 */
final class QuickBooksMappingsResolveCommand extends Command
{
    private const string SKIP = 'Skip';

    private const string SKIPPED = 'skipped';

    private const string NO_CANDIDATE = 'no active candidate';

    private const string CANNOT_PROMPT = 'a pick is needed but this run cannot prompt';

    protected $signature = 'quickbooks:mappings:resolve
                            {--key= : Prompt for this key alone, even when it is resolved}
                            {--check : Report problems with the stored mappings and change none}
                            {--auto : Resolve every unresolved key without prompts, in the sandbox only}';

    protected $description = 'Resolve QuickBooks mapping keys to entities of the configured company';

    public function handle(
        ListUnresolvedMappingsUseCase $unresolved,
        ListMappingCandidatesUseCase $candidates,
        RecordMappingPickUseCase $record,
    ): int {
        if ($this->optionsClash()) {
            return $this->fails('Use only one of --key, --check and --auto', 'the options given');
        }

        try {
            return match (true) {
                $this->option('check') === true => $this->check(\app(CheckMappingsUseCase::class)),
                $this->option('auto') === true => $this->auto(\app(AutoResolveMappingsUseCase::class)),
                $this->option('key') !== null => $this->resolveKey($candidates, $record),
                default => $this->resolveAll($unresolved, $candidates, $record),
            };
        } catch (DomainException $e) {
            return $this->stopped($e);
        }
    }

    /**
     * @throws DomainException
     */
    private function check(CheckMappingsUseCase $check): int
    {
        $findings = $check->execute()->findings;
        if ($findings === []) {
            $this->info('Every mapping matches QuickBooks');

            return self::SUCCESS;
        }

        $this->table(['Key', 'Finding', 'Stored name', 'QuickBooks name'], \array_map(
            static fn(MappingFindingDTO $finding): array => [
                $finding->key->value,
                $finding->kind->value,
                $finding->storedName ?? '-',
                $finding->currentName ?? '-',
            ],
            $findings,
        ));

        return self::FAILURE;
    }

    private function optionsClash(): bool
    {
        return \count(\array_filter([
            $this->option('key') !== null,
            $this->option('check') === true,
            $this->option('auto') === true,
        ])) > 1;
    }

    /**
     * @throws DomainException
     */
    private function auto(AutoResolveMappingsUseCase $auto): int
    {
        $result = $auto->execute();
        if ($result->refused) {
            return $this->fails('Auto mode runs in the sandbox only', 'QUICKBOOKS_ENVIRONMENT');
        }

        return $this->reportAuto($result);
    }

    private function reportAuto(MappingAutoResolveResult $result): int
    {
        foreach ($result->resolved as $key) {
            $this->info("Resolved {$key->value}");
        }
        if ($result->unmatched === []) {
            $this->info('No unresolved mapping is left');

            return self::SUCCESS;
        }

        foreach ($result->unmatched as $left) {
            $this->line("  {$left->key->value}: {$left->reason}");
        }

        return $this->fails('Some keys are still unresolved', 'the reasons above, then run it again');
    }

    /**
     * @throws DomainException
     */
    private function resolveKey(ListMappingCandidatesUseCase $candidates, RecordMappingPickUseCase $record): int
    {
        $option = $this->option('key');
        $key = \is_string($option) ? MappingKey::tryFrom($option) : null;
        if ($key === null) {
            $keys = \array_map(static fn(MappingKey $case): string => $case->value, MappingKey::cases());

            return $this->fails('Unknown mapping key', 'one of ' . \implode(', ', $keys));
        }

        $reason = $this->settle($key, $candidates, $record);

        return $reason === null
            ? self::SUCCESS
            : $this->fails("{$key->value}: {$reason}", self::hint($reason));
    }

    /**
     * @throws DomainException
     */
    private function resolveAll(
        ListUnresolvedMappingsUseCase $unresolved,
        ListMappingCandidatesUseCase $candidates,
        RecordMappingPickUseCase $record,
    ): int {
        $left = [];
        foreach ($unresolved->execute() as $key) {
            $reason = $this->settle($key, $candidates, $record);
            if ($reason === self::CANNOT_PROMPT) {
                return $this->fails("{$key->value}: {$reason}", self::hint($reason));
            }
            if ($reason !== null) {
                $left[] = "  {$key->value}: {$reason}";
            }
        }

        $this->info($left === [] ? 'No unresolved mapping is left' : 'Still unresolved:');
        \array_walk($left, fn(string $line) => $this->line($line));

        return self::SUCCESS;
    }

    /**
     * @return string|null why the key was not recorded
     *
     * @throws DomainException
     */
    private function settle(MappingKey $key, ListMappingCandidatesUseCase $candidates, RecordMappingPickUseCase $record): ?string
    {
        $active = $candidates->execute($key);
        if ($active === []) {
            return self::NO_CANDIDATE;
        }
        if (!$this->canPrompt()) {
            return self::CANNOT_PROMPT;
        }

        $pick = $this->pick($key, $active);
        if ($pick === null) {
            return self::SKIPPED;
        }

        $this->info($record->execute($key, $pick) ? "Resolved {$key->value}" : "{$key->value} already holds that pick");

        return null;
    }

    /**
     * @param non-empty-list<MappingCandidateDTO> $candidates
     */
    private function pick(MappingKey $key, array $candidates): ?MappingCandidateDTO
    {
        $options = [];
        foreach ($candidates as $candidate) {
            $options[self::label($candidate)] = $candidate;
        }

        $answer = $this->choice(
            "Pick the QuickBooks {$key->entity()->value} for {$key->value}",
            [self::SKIP, ...\array_keys($options)],
        );

        return \is_string($answer) ? $options[$answer] ?? null : null;
    }

    private static function label(MappingCandidateDTO $candidate): string
    {
        $name = $candidate->name === '' ? '(unnamed)' : $candidate->name;
        $detail = $candidate->detail === null ? '' : " ({$candidate->detail})";

        return "{$name}{$detail} [id {$candidate->id}]";
    }

    // intentional: a test run has no TTY but answers prompts, as in Laravel's ConfiguresPrompts.
    private function canPrompt(): bool
    {
        return $this->input->isInteractive()
            && ($this->laravel->runningUnitTests() || (\defined('STDIN') && \stream_isatty(\STDIN)));
    }

    private static function hint(string $reason): string
    {
        return match ($reason) {
            self::CANNOT_PROMPT => 'run it in a terminal, without --no-interaction',
            self::NO_CANDIDATE => 'QuickBooks holds an active entity for this key',
            default => 'run it again to pick one',
        };
    }

    /**
     * intentional: never the fault's message, which is Intuit's text and can hold customer names.
     */
    private function stopped(DomainException $e): int
    {
        $reason = $e instanceof QuickBooksFaultException
            ? "QuickBooks fault {$e->fault->kind->value}, code " . ($e->fault->code ?? 'none')
            : \class_basename($e);

        return $this->fails("Stopped: {$reason}", match (true) {
            $e instanceof AuthenticationExpiredException => 'the QuickBooks connection (quickbooks:connect)',
            $e instanceof RecordNotFoundException => 'quickbooks.mappings holds a row for every key',
            default => 'the log, then run the command again; stored picks are kept',
        });
    }

    private function fails(string $error, string $check): int
    {
        $this->error($error);
        $this->line("  Check: {$check}");

        return self::FAILURE;
    }
}
