<?php

declare(strict_types=1);

namespace App\Presentation\Console\Commands\Dev;

use App\Application\Enums\FixtureSku;
use App\Application\QuickBooks\Mappings\DTOs\MappingCandidateDTO;
use App\Application\QuickBooks\Mappings\Services\MappingCandidateService;
use App\Application\QuickBooks\OrderSync\Enums\MappingEntity;
use App\Application\QuickBooks\Services\QuickBooksTokenService;
use App\Domain\Exceptions\Api\UnexpectedApiResultException;
use App\Domain\Exceptions\DomainException;
use App\Domain\QuickBooks\Exceptions\QuickBooksFaultException;
use App\Domain\Shared\ValueObjects\Deadline;
use App\Infrastructure\QuickBooks\QuickBooksQueryEscaper;
use App\Infrastructure\QuickBooks\QuickBooksTransport;
use DateTimeImmutable;
use QuickBooksOnline\API\Data\IPPIntuitEntity;
use QuickBooksOnline\API\Data\IPPItem;
use QuickBooksOnline\API\Data\IPPPreferences;

/**
 * The sandbox company's sales form preferences and fixture Items.
 */
final readonly class QuickBooksSandboxCompanyFactory
{
    private const int CALL_SECONDS = 60;

    private const array PREFERENCES = ['CustomTxnNumbers', 'AllowShipping'];

    public function __construct(
        private QuickBooksTransport $transport,
        private QuickBooksTokenService $tokens,
        private MappingCandidateService $candidates,
    ) {}

    /**
     * @return list<string> each preference that reads off
     *
     * @throws DomainException
     */
    public function preferencesOff(): array
    {
        $salesForms = QuickBooksSandboxRowParser::field($this->preferences(), 'SalesFormsPrefs');

        return \array_values(\array_filter(
            self::PREFERENCES,
            static fn(string $name): bool => !QuickBooksSandboxRowParser::isTrue($salesForms, $name),
        ));
    }

    /**
     * An update QBO accepts may still change nothing, so only a read-back proves it.
     *
     * @return string|null why QBO refused the update
     *
     * @throws DomainException
     */
    public function turnOnPreferences(): ?string
    {
        $update = $this->preferencesUpdate();

        try {
            $this->transport->add($this->tokens->accessToken(), $update, IPPPreferences::class, self::deadline());
        } catch (QuickBooksFaultException $e) {
            return QuickBooksSandboxMapper::faultText($e->fault);
        }

        return null;
    }

    /**
     * @return array{missing: list<string>, inactive: list<string>} fixture SKUs without a usable Item
     *
     * @throws DomainException
     */
    public function unusableItems(): array
    {
        $active = [];
        $query = 'SELECT * FROM Item WHERE Active IN (true, false) AND Name IN '
            . QuickBooksQueryEscaper::inList(\array_column(FixtureSku::cases(), 'value'))
            . ' MAXRESULTS ' . QuickBooksQueryEscaper::MAX_RESULTS;
        foreach ($this->transport->query($this->tokens->accessToken(), $query, self::deadline()) as $row) {
            $name = QuickBooksQueryEscaper::fold(QuickBooksSandboxRowParser::text($row, 'Name') ?? '');
            $active[$name] = !QuickBooksSandboxRowParser::isFalse($row, 'Active');
        }

        $state = static fn(string $sku): ?bool => $active[QuickBooksQueryEscaper::fold($sku)] ?? null;

        return [
            'missing' => \array_values(\array_filter(FixtureSku::values(), static fn(string $sku): bool => $state($sku) === null)),
            'inactive' => \array_values(\array_filter(FixtureSku::values(), static fn(string $sku): bool => $state($sku) === false)),
        ];
    }

    /**
     * @param list<string> $skus
     *
     * @throws DomainException
     */
    public function createItems(array $skus): void
    {
        if ($skus === []) {
            return;
        }

        $account = \array_find(
            $this->candidates->candidates(MappingEntity::Account),
            static fn(MappingCandidateDTO $candidate): bool => $candidate->active && $candidate->detail === 'Income',
        ) ?? throw new UnexpectedApiResultException('QuickBooks', 'No active Income account for the Items');

        foreach ($skus as $sku) {
            $this->transport->add(
                $this->tokens->accessToken(),
                QuickBooksSandboxMapper::item($sku, $account->id),
                IPPItem::class,
                self::deadline(),
            );
        }
    }

    /**
     * @throws DomainException
     */
    private function preferencesUpdate(): IPPIntuitEntity
    {
        $row = $this->preferences();
        $id = QuickBooksSandboxRowParser::text($row, 'Id');
        $syncToken = QuickBooksSandboxRowParser::text($row, 'SyncToken');
        if ($id === null || $syncToken === null) {
            throw new UnexpectedApiResultException('QuickBooks', 'Preferences id or sync token blank');
        }

        return QuickBooksSandboxMapper::preferences($id, $syncToken);
    }

    /**
     * @throws DomainException
     */
    private function preferences(): object
    {
        return $this->transport->query($this->tokens->accessToken(), 'SELECT * FROM Preferences', self::deadline())[0]
            ?? throw new UnexpectedApiResultException('QuickBooks', 'Preferences is empty');
    }

    private static function deadline(): Deadline
    {
        return Deadline::after(new DateTimeImmutable(), self::CALL_SECONDS);
    }
}
