<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\Mappings\Resolvers;

use App\Application\QuickBooks\Mappings\DTOs\MappingCandidateDTO;
use App\Application\QuickBooks\Mappings\Services\MappingCandidateService;
use App\Application\QuickBooks\OrderSync\Enums\MappingEntity;
use App\Application\QuickBooks\OrderSync\Enums\MappingKey;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\CorruptApiKeyException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\KeyEncryptionFailedException;
use App\Domain\Exceptions\Api\UnexpectedApiResultException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\Exceptions\Infrastructure\LockAcquisitionException;
use App\Domain\QuickBooks\Exceptions\QuickBooksFaultException;

/**
 * The sandbox entity each key points at: an existing tax code or custom field, or a synthetic entity of the key's own.
 */
final readonly class MappingAutoTargetResolver
{
    public function __construct(
        private MappingCandidateService $service,
    ) {}

    /**
     * @param list<MappingCandidateDTO> $candidates active and inactive, of the key's entity
     *
     * @return MappingCandidateDTO|string the entity, or why there is none
     *
     * @throws AuthenticationExpiredException
     * @throws ExternalServiceUnavailableException
     * @throws UnexpectedApiResultException
     * @throws LockAcquisitionException
     * @throws InvalidApiResponseException
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws CorruptApiKeyException
     * @throws KeyEncryptionFailedException
     */
    public function resolve(MappingKey $key, array $candidates): MappingCandidateDTO|string
    {
        $name = self::name($key);
        $matches = \array_filter($candidates, static fn(MappingCandidateDTO $candidate): bool => $candidate->name === $name);
        $active = \array_values(\array_filter($matches, static fn(MappingCandidateDTO $candidate): bool => $candidate->active));

        return match (true) {
            \count($active) === 1 => $active[0],
            $active !== [] => "several active matches for \"{$name}\"",
            $matches !== [] => "the match for \"{$name}\" is inactive",
            self::isMatchOnly($key) => "no active match for \"{$name}\"",
            default => $this->create($key, $name),
        };
    }

    /**
     * @throws AuthenticationExpiredException
     * @throws ExternalServiceUnavailableException
     * @throws UnexpectedApiResultException
     * @throws LockAcquisitionException
     * @throws InvalidApiResponseException
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws CorruptApiKeyException
     * @throws KeyEncryptionFailedException
     */
    private function create(MappingKey $key, string $name): MappingCandidateDTO|string
    {
        try {
            return $this->service->create($key->entity(), $name);
        } catch (QuickBooksFaultException $e) {
            return "QuickBooks refused the create: {$e->fault->kind->value}, code " . ($e->fault->code ?? 'none');
        }
    }

    private static function isMatchOnly(MappingKey $key): bool
    {
        return \in_array($key->entity(), [MappingEntity::TaxCode, MappingEntity::CustomField], true);
    }

    private static function name(MappingKey $key): string
    {
        return match ($key) {
            MappingKey::TaxStandard => '20.0% S',
            MappingKey::TaxZero => '0.0% Z',
            MappingKey::TaxEuGoods => '0.0% ECG',
            MappingKey::TaxEuShipping => '0.0% ECS',
            MappingKey::PoNumberField => 'P.O. Number',
            MappingKey::TransactionIdField => 'Transaction ID',
            MappingKey::DepositAccountPayPal => 'Sync Test PayPal',
            MappingKey::DepositAccountCardOpayo => 'Sync Test Card Opayo',
            MappingKey::DepositAccountShopWiredPayments => 'Sync Test ShopWired Payments',
            MappingKey::PaymentMethodPayPal => 'Sync Test PP',
            MappingKey::PaymentMethodCardOpayo => 'Sync Test Opayo',
            MappingKey::PaymentMethodShopWiredPayments => 'Sync Test SWP',
            MappingKey::Department => 'Sync Test Department',
            MappingKey::SalesClass => 'Sync Test Class',
            MappingKey::DefaultTerms => 'Sync Test Terms',
        };
    }
}
