<?php

declare(strict_types=1);

namespace App\Infrastructure\QuickBooks;

use App\Application\Contracts\QuickBooks\QuickBooksEnvironmentInterface;
use App\Application\QuickBooks\Mappings\DTOs\MappingCandidateDTO;
use App\Application\QuickBooks\OrderSync\Enums\MappingEntity;
use App\Domain\Access\ValueObjects\ApiKeyToken;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\UnexpectedApiResultException;
use App\Domain\QuickBooks\Exceptions\QuickBooksDeadlineExceededException;
use App\Domain\QuickBooks\Exceptions\QuickBooksFaultException;
use App\Domain\Shared\ValueObjects\Deadline;
use App\Infrastructure\QuickBooks\Mappers\QuickBooksMappingEntityMapper;
use DateTimeImmutable;
use LogicException;
use Psr\Log\LoggerInterface;
use QuickBooksOnline\API\Data\IPPIntuitEntity;
use SensitiveParameter;
use Webmozart\Assert\Assert;

/**
 * Lists and creates the QBO entities mapping keys point at. Rows are read as untyped properties: the SDK's PHPDoc types
 * are not what its XML parse leaves.
 */
final readonly class QuickBooksMappingCandidateReader
{
    private const int CALL_SECONDS = 60;

    // intentional: entries are nested and unordered, so the slot is the name's trailing digit, never a position.
    private const string SLOT_PATTERN = '/(UseSalesCustom|SalesCustomName)([1-3])$/';

    public function __construct(
        private QuickBooksTransport $transport,
        private QuickBooksEnvironmentInterface $environment,
        private LoggerInterface $logger,
    ) {}

    /**
     * @return list<MappingCandidateDTO> active and inactive
     *
     * @throws AuthenticationExpiredException
     * @throws ExternalServiceUnavailableException
     * @throws QuickBooksFaultException
     * @throws UnexpectedApiResultException
     */
    public function candidates(#[SensitiveParameter] ApiKeyToken $accessToken, MappingEntity $entity): array
    {
        return $entity === MappingEntity::CustomField
            ? $this->customFields($accessToken)
            : $this->entities($accessToken, $entity);
    }

    /**
     * @throws AuthenticationExpiredException
     * @throws ExternalServiceUnavailableException
     * @throws QuickBooksFaultException            when QBO refuses the create
     * @throws UnexpectedApiResultException
     */
    public function create(#[SensitiveParameter] ApiKeyToken $accessToken, MappingEntity $entity, string $name): MappingCandidateDTO
    {
        if (!$this->environment->isSandbox()) {
            throw new LogicException('Mapping entities are created in the QuickBooks sandbox only');
        }

        Assert::false(
            \in_array($entity, [MappingEntity::TaxCode, MappingEntity::CustomField], true),
            'Tax codes and custom fields are never created',
        );

        return $this->candidate($entity, $this->add($accessToken, QuickBooksMappingEntityMapper::toEntity($entity, $name)));
    }

    /**
     * @return list<MappingCandidateDTO>
     *
     * @throws AuthenticationExpiredException
     * @throws ExternalServiceUnavailableException
     * @throws QuickBooksFaultException
     * @throws UnexpectedApiResultException
     */
    private function entities(#[SensitiveParameter] ApiKeyToken $accessToken, MappingEntity $entity): array
    {
        $candidates = [];
        $start = 1;

        do {
            $rows = $this->query(
                $accessToken,
                "SELECT * FROM {$entity->value} WHERE Active IN (true, false)"
                    . " STARTPOSITION {$start} MAXRESULTS " . QuickBooksQueryEscaper::MAX_RESULTS,
            );
            foreach ($rows as $row) {
                $candidates[] = $this->candidate($entity, $row);
            }
            $start += QuickBooksQueryEscaper::MAX_RESULTS;
        } while (\count($rows) === QuickBooksQueryEscaper::MAX_RESULTS);

        return $candidates;
    }

    /**
     * @throws UnexpectedApiResultException when the row has no id or name
     */
    private function candidate(MappingEntity $entity, object $row): MappingCandidateDTO
    {
        $fields = \get_object_vars($row);
        $id = self::text($fields['Id'] ?? null);
        $name = self::text($fields[self::nameField($entity)] ?? null);
        if ($id === null || $name === null) {
            throw $this->unexpected('Mapping candidate name or id blank');
        }

        return new MappingCandidateDTO(
            id: $id,
            name: $name,
            active: !\in_array($fields['Active'] ?? null, [false, 'false'], true),
            detail: $entity === MappingEntity::Account ? self::enumText($fields['AccountType'] ?? null) : null,
        );
    }

    private static function nameField(MappingEntity $entity): string
    {
        return match ($entity) {
            MappingEntity::Account, MappingEntity::Department, MappingEntity::SalesClass => 'FullyQualifiedName',
            MappingEntity::TaxCode, MappingEntity::PaymentMethod, MappingEntity::Term, MappingEntity::CustomField => 'Name',
        };
    }

    /**
     * @return list<MappingCandidateDTO> one per slot with an on/off entry, in slot order
     *
     * @throws AuthenticationExpiredException
     * @throws ExternalServiceUnavailableException
     * @throws QuickBooksFaultException
     * @throws UnexpectedApiResultException        when Preferences lacks its sales form section
     */
    private function customFields(#[SensitiveParameter] ApiKeyToken $accessToken): array
    {
        $enabled = [];
        $labels = [];
        foreach (self::slotEntries($this->salesForms($accessToken)) as [$kind, $slot, $fields]) {
            if ($kind === 'UseSalesCustom') {
                $enabled[$slot] = \in_array($fields['BooleanValue'] ?? null, [true, 'true'], true);
            } else {
                $labels[$slot] = self::text($fields['StringValue'] ?? null) ?? '';
            }
        }
        \ksort($enabled);

        $candidates = [];
        foreach ($enabled as $slot => $active) {
            $candidates[] = new MappingCandidateDTO((string) $slot, $labels[$slot] ?? '', $active);
        }

        return $candidates;
    }

    /**
     * @throws AuthenticationExpiredException
     * @throws ExternalServiceUnavailableException
     * @throws QuickBooksFaultException
     * @throws UnexpectedApiResultException        when Preferences lacks its sales form section
     */
    private function salesForms(#[SensitiveParameter] ApiKeyToken $accessToken): object
    {
        $rows = $this->query($accessToken, 'SELECT * FROM Preferences');
        $salesForms = isset($rows[0]) ? \get_object_vars($rows[0])['SalesFormsPrefs'] ?? null : null;
        if (!\is_object($salesForms)) {
            throw $this->unexpected('Preferences has no SalesFormsPrefs');
        }

        return $salesForms;
    }

    /**
     * @return list<array{string, int, array<array-key, mixed>}> kind, slot and the entry's properties
     */
    private static function slotEntries(mixed $node): array
    {
        if (!\is_object($node) && !\is_array($node)) {
            return [];
        }

        $fields = \is_object($node) ? \get_object_vars($node) : $node;
        $entries = [];
        if (\preg_match(self::SLOT_PATTERN, self::text($fields['Name'] ?? null) ?? '', $match) === 1) {
            $entries[] = [$match[1], (int) $match[2], $fields];
        }

        foreach ($fields as $child) {
            $entries = [...$entries, ...self::slotEntries($child)];
        }

        return $entries;
    }

    /**
     * @return list<object>
     *
     * @throws AuthenticationExpiredException
     * @throws ExternalServiceUnavailableException
     * @throws QuickBooksFaultException
     * @throws UnexpectedApiResultException
     */
    private function query(#[SensitiveParameter] ApiKeyToken $accessToken, string $query): array
    {
        try {
            return $this->transport->query($accessToken, $query, self::deadline());
        } catch (QuickBooksDeadlineExceededException $e) {
            throw $this->unavailable($e);
        }
    }

    /**
     * @throws AuthenticationExpiredException
     * @throws ExternalServiceUnavailableException
     * @throws QuickBooksFaultException
     * @throws UnexpectedApiResultException
     */
    private function add(#[SensitiveParameter] ApiKeyToken $accessToken, IPPIntuitEntity $entity): IPPIntuitEntity
    {
        try {
            return $this->transport->add($accessToken, $entity, $entity::class, self::deadline());
        } catch (QuickBooksDeadlineExceededException $e) {
            throw $this->unavailable($e);
        }
    }

    private static function deadline(): Deadline
    {
        return Deadline::after(new DateTimeImmutable(), self::CALL_SECONDS);
    }

    private function unavailable(QuickBooksDeadlineExceededException $e): ExternalServiceUnavailableException
    {
        $this->logger->error('QuickBooks mapping call ran out of time', ['seconds' => self::CALL_SECONDS]);

        return new ExternalServiceUnavailableException(QuickBooksOAuthHttpTransport::SERVICE_NAME, previous: $e);
    }

    /**
     * @return non-empty-string|null
     */
    private static function enumText(mixed $value): ?string
    {
        return self::text(\is_object($value) ? \get_object_vars($value)['value'] ?? null : $value);
    }

    /**
     * @return non-empty-string|null
     */
    private static function text(mixed $value): ?string
    {
        if (!\is_string($value) && !\is_int($value)) {
            return null;
        }

        $text = \mb_trim((string) $value);

        return $text === '' ? null : $text;
    }

    private function unexpected(string $reason): UnexpectedApiResultException
    {
        $this->logger->error('QuickBooks API returned an unexpected mapping result', ['reason' => $reason]);

        return new UnexpectedApiResultException(QuickBooksOAuthHttpTransport::SERVICE_NAME, $reason);
    }
}
